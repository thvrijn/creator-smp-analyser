<?php

namespace Tests\Feature;

use App\Models\Clip;
use App\Models\Event;
use App\Models\Player;
use App\Models\Stream;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function stream(string $player = 'Duncan', string $startedAt = '2026-10-04 16:46:39'): Stream
    {
        return Player::firstOrCreate(['name' => $player])->streams()->create(['title' => 'Dag 1', 'source' => 'twitch', 'twitch_video_id' => "vod-{$player}", 'started_at' => $startedAt, 'ended_at' => '2026-10-04 22:08:02']);
    }

    private function event(Stream $stream): Event
    {
        return Event::create(['stream_id' => $stream->id, 'type' => 'death', 'title' => 'Duncan valt in lava', 'description' => 'Oeps.', 'start_time' => 100, 'end_time' => 110, 'confidence' => 0.9]);
    }

    public function test_a_clip_is_made_from_an_event_and_opened_in_the_clips_tab(): void
    {
        $stream = $this->stream();
        $event = $this->event($stream);

        $response = $this->post("/streams/{$stream->id}/clips", ['event_id' => $event->id, 'title' => $event->title, 'start_seconds' => 85, 'end_seconds' => 125]);

        $clip = Clip::sole();
        $response->assertRedirect("/streams/{$stream->id}?tab=clips&clip={$clip->id}");
        $this->assertSame([$stream->id, $event->id, 85.0, 125.0], [$clip->stream_id, $clip->event_id, $clip->start_seconds, $clip->end_seconds]);
        $this->get("/streams/{$stream->id}?tab=clips")->assertInertia(fn (Assert $page) => $page->where('clips.0.id', $clip->id)->where('clips.0.event_id', $event->id));
    }

    public function test_a_clip_must_end_after_it_starts_and_use_an_event_of_its_own_stream(): void
    {
        $stream = $this->stream();
        $otherEvent = $this->event($this->stream('Acid'));

        $this->from("/streams/{$stream->id}")->post("/streams/{$stream->id}/clips", ['title' => 'Mis', 'start_seconds' => 50, 'end_seconds' => 40])->assertSessionHasErrors('end_seconds');
        $this->from("/streams/{$stream->id}")->post("/streams/{$stream->id}/clips", ['event_id' => $otherEvent->id, 'title' => 'Mis', 'start_seconds' => 0, 'end_seconds' => 40])->assertSessionHasErrors('event_id');
        $this->assertSame(0, Clip::count());
    }

    public function test_a_clip_can_be_adjusted_and_removed(): void
    {
        $clip = $this->stream()->clips()->create(['title' => 'Oud', 'start_seconds' => 10, 'end_seconds' => 70]);

        $this->put("/clips/{$clip->id}", ['title' => 'Nieuw', 'start_seconds' => 5, 'end_seconds' => 75])->assertSessionDoesntHaveErrors();
        $this->assertSame(['Nieuw', 5.0, 75.0], [$clip->fresh()->title, $clip->fresh()->start_seconds, $clip->fresh()->end_seconds]);

        $this->delete("/clips/{$clip->id}")->assertSessionHas('success', 'Clip verwijderd.');
        $this->assertSame(0, Clip::count());
    }

    public function test_a_clip_survives_a_new_analysis_of_its_stream(): void
    {
        $stream = $this->stream();
        $event = $this->event($stream);
        $clip = $stream->clips()->create(['event_id' => $event->id, 'title' => 'Lava', 'start_seconds' => 85, 'end_seconds' => 125]);

        $stream->events()->delete(); // what a re-analysis does before storing the new events

        $this->assertNull($clip->fresh()->event_id);
    }

    public function test_the_video_builder_lists_clips_of_all_players_in_server_time_order(): void
    {
        // Duncan's stream started 5 minutes before Acid's, so Acid's clip at 60 s happens before Duncan's clip at 400 s.
        $duncan = $this->stream('Duncan', '2026-10-04 16:46:39')->clips()->create(['title' => 'Duncan', 'start_seconds' => 400, 'end_seconds' => 460]);
        $acid = $this->stream('Acid', '2026-10-04 16:51:39')->clips()->create(['title' => 'Acid', 'start_seconds' => 60, 'end_seconds' => 120]);

        $this->get('/video-builder')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('VideoBuilder')
            ->where('clips.0.id', $acid->id)
            ->where('clips.0.starts_at', '2026-10-04T16:52:39+00:00')
            ->where('clips.0.player.name', 'Acid')
            ->where('clips.1.id', $duncan->id));
    }
}
