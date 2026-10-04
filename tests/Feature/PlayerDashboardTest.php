<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Player;
use App\Models\Stream;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlayerDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_overview_page_still_renders(): void
    {
        $this->get('/overview')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Overview'));
    }

    public function test_dashboard_shows_a_card_per_player_with_stream_statistics(): void
    {
        $alex = Player::create(['name' => 'Alex']);
        Player::create(['name' => 'Bram']);
        $done = $this->createStream($alex, ['transcription_status' => 'completed', 'started_at' => '2026-10-02 19:00:00+00']);
        $this->createStream($alex, ['transcription_status' => 'processing', 'started_at' => '2026-10-03 19:00:00+00']);
        $this->createStream($alex, ['transcription_status' => 'completed', 'event_extraction_status' => 'queued', 'started_at' => '2026-10-01 19:00:00+00']);
        Event::create(['stream_id' => $done->id, 'type' => 'death', 'title' => 'Creeper', 'description' => 'Died.', 'start_time' => 1, 'end_time' => 2, 'confidence' => 0.9]);

        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('players', 2)
            ->where('players.0.name', 'Alex')
            ->where('players.0.streams_count', 3)
            ->where('players.0.transcribed_streams_count', 2)
            ->where('players.0.active_streams_count', 2)
            ->where('players.0.events_count', 1)
            ->where('players.0.last_stream_at', fn (string $value) => str_starts_with($value, '2026-10-03'))
            ->where('players.1.name', 'Bram')
            ->where('players.1.streams_count', 0)
            ->where('players.1.last_stream_at', null));
    }

    public function test_player_page_lists_only_that_players_streams(): void
    {
        $alex = Player::create(['name' => 'Alex']);
        $bram = Player::create(['name' => 'Bram']);
        $own = $this->createStream($alex, ['title' => 'Alex stream']);
        $this->createStream($bram, ['title' => 'Bram stream']);

        $this->get('/players/'.$alex->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Players/Show')
            ->where('player.name', 'Alex')
            ->where('player.streams_count', 1)
            ->has('streams', 1)
            ->where('streams.0.id', $own->id)
            ->where('streams.0.transcription_status', 'pending')
            ->has('players', 2));
    }

    public function test_unknown_player_returns_404(): void
    {
        $this->get('/players/999')->assertNotFound();
    }

    public function test_stream_actions_return_to_the_player_page_they_came_from(): void
    {
        Queue::fake();
        Storage::fake('local');
        $alex = Player::create(['name' => 'Alex']);
        $stream = $this->createStream($alex, ['video_path' => 'streams/1/video/stream.mp4']);
        Storage::disk('local')->put($stream->video_path, 'video');

        $this->from('/players/'.$alex->id)
            ->post('/streams/'.$stream->id.'/transcribe')
            ->assertRedirect('/players/'.$alex->id);
    }

    private function createStream(Player $player, array $attributes = []): Stream
    {
        return Stream::create(array_merge([
            'player_id' => $player->id,
            'title' => 'Stream',
            'started_at' => '2026-10-03 19:00:00+00',
            'source' => 'test',
        ], $attributes));
    }
}
