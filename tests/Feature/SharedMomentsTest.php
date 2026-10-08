<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Player;
use App\Models\Stream;
use App\Services\SharedMoments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SharedMomentsTest extends TestCase
{
    use RefreshDatabase;

    private Player $don;

    private Player $morrog;

    private Stream $donStream;

    private Stream $morrogStream;

    protected function setUp(): void
    {
        parent::setUp();
        $this->don = Player::create(['name' => 'DonKaaklijn']);
        $this->morrog = Player::create(['name' => 'Morrog']);
        // Morrog went live 10 minutes after Don, so the same moment is 600 s earlier in Morrog's file.
        $this->donStream = $this->stream($this->don, '2026-10-05 14:00:00+00');
        $this->morrogStream = $this->stream($this->morrog, '2026-10-05 14:10:00+00');
    }

    private function stream(Player $player, string $startedAt, float $offset = 0): Stream
    {
        return $player->streams()->create([
            'title' => $player->name.' dag 2', 'source' => 'twitch', 'started_at' => $startedAt, 'ended_at' => '2026-10-05 20:00:00+00',
            'video_offset_seconds' => $offset, 'transcription_status' => 'completed',
        ]);
    }

    /** An event over new segments, each [start, end, speaker]. @param list<array{0: float, 1: float, 2: ?int}> $segments */
    private function event(Stream $stream, string $title, array $segments): Event
    {
        $ids = array_map(fn (array $segment) => $stream->transcriptSegments()->create(['start_time' => $segment[0], 'end_time' => $segment[1], 'text' => 'Hoi', 'speaker' => $segment[2]])->id, $segments);
        $event = $stream->events()->create([
            'type' => 'other', 'title' => $title, 'description' => 'Ze praten even.', 'confidence' => 0.8,
            'start_time' => min(array_column($segments, 0)), 'end_time' => max(array_column($segments, 1)),
        ]);
        $event->transcriptSegments()->attach($ids);

        return $event;
    }

    private function moments(Stream $stream): array
    {
        return app(SharedMoments::class)->forStream($stream->fresh());
    }

    public function test_a_voice_named_after_a_player_links_both_streams_at_the_same_server_time(): void
    {
        // 15:00 server time: 3600 s into Don's file, 3000 s into Morrog's.
        $donEvent = $this->event($this->donStream, 'Bouwt aan zijn huis', [[3600, 3610, 0], [3610, 3620, 0]]);
        $morrogEvent = $this->event($this->morrogStream, 'Ontmoeting bij de spawn', [[3005, 3015, 0], [3015, 3025, 1]]);
        $this->morrogStream->speakerNames()->create(['speaker' => 1, 'player_id' => $this->don->id]);

        $fromMorrog = $this->moments($this->morrogStream);
        $this->assertCount(1, $fromMorrog[$morrogEvent->id]);
        $this->assertSame($this->donStream->id, $fromMorrog[$morrogEvent->id][0]['stream_id']);
        $this->assertSame($donEvent->id, $fromMorrog[$morrogEvent->id][0]['event_id']);
        $this->assertSame(['voice'], $fromMorrog[$morrogEvent->id][0]['reasons']);

        // From Don's side it is the same link the other way round.
        $fromDon = $this->moments($this->donStream);
        $this->assertSame($morrogEvent->id, $fromDon[$donEvent->id][0]['event_id']);
        $this->assertSame(['voice_there'], $fromDon[$donEvent->id][0]['reasons']);
    }

    public function test_a_named_player_without_an_event_links_to_the_time_in_their_stream(): void
    {
        $morrogEvent = $this->event($this->morrogStream, 'Morrog krijgt diamanten van DonKaaklijn', [[3000, 3010, 0]]);

        $link = $this->moments($this->morrogStream)[$morrogEvent->id][0];
        $this->assertSame($this->donStream->id, $link['stream_id']);
        $this->assertNull($link['event_id']);
        $this->assertSame(['named'], $link['reasons']);
        $this->assertEqualsWithDelta(3600, $link['at'], 0.001);
    }

    public function test_the_video_offset_is_part_of_the_server_time(): void
    {
        $bas = Player::create(['name' => 'Bas']);
        // Bas' file starts 20 minutes into his VOD, which started at 14:00.
        $basStream = $this->stream($bas, '2026-10-05 14:00:00+00', 1200);
        $event = $this->event($this->morrogStream, 'Bas helpt Morrog', [[3000, 3010, 0]]);

        $link = collect($this->moments($this->morrogStream)[$event->id])->firstWhere('stream_id', $basStream->id);
        $this->assertEqualsWithDelta(2400, $link['at'], 0.001);
    }

    public function test_streams_that_were_only_live_at_the_same_time_are_not_linked(): void
    {
        $bas = Player::create(['name' => 'Bas']);
        $basStream = $this->stream($bas, '2026-10-05 14:00:00+00');
        $this->event($basStream, 'Mijnt in de grot', [[3600, 3620, 0]]);
        $morrogEvent = $this->event($this->morrogStream, 'Praat met de chat', [[3000, 3020, 0]]);

        $this->assertArrayNotHasKey($morrogEvent->id, $this->moments($this->morrogStream));
    }

    public function test_a_stream_that_was_offline_at_that_moment_is_not_linked(): void
    {
        $this->donStream->update(['ended_at' => '2026-10-05 14:30:00+00']);
        $morrogEvent = $this->event($this->morrogStream, 'Wacht op DonKaaklijn', [[3000, 3010, 0]]);

        $this->assertArrayNotHasKey($morrogEvent->id, $this->moments($this->morrogStream));
    }

    public function test_the_stream_page_shows_the_links_and_at_opens_the_transcript_there(): void
    {
        $morrogEvent = $this->event($this->morrogStream, 'Morrog en DonKaaklijn gaan vissen', [[3000, 3010, 0]]);
        $this->donStream->transcriptSegments()->create(['start_time' => 3590, 'end_time' => 3598, 'text' => 'Eerder', 'speaker' => 0]);
        $at = $this->donStream->transcriptSegments()->create(['start_time' => 3598, 'end_time' => 3605, 'text' => 'Hé Morrog!', 'speaker' => 0]);

        $this->get("/streams/{$this->morrogStream->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where("shared_moments.{$morrogEvent->id}.0.stream_id", $this->donStream->id)
                ->where("shared_moments.{$morrogEvent->id}.0.player.name", 'DonKaaklijn'));

        $this->get("/streams/{$this->donStream->id}?tab=transcript&at=3600")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('highlighted_segment_ids', [$at->id]));
    }
}
