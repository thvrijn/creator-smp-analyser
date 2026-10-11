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

    public function test_players_page_shows_a_card_per_player_with_stream_statistics_team_and_deaths(): void
    {
        $alex = Player::create(['name' => 'Alex', 'team' => 'Noord']);
        Player::create(['name' => 'Bram']);
        $alex->deaths()->create(['died_at' => '2026-10-05 20:00:00+00', 'message' => 'Alex was slain by Zombie']);
        $done = $this->createStream($alex, ['transcription_status' => 'completed', 'started_at' => '2026-10-02 19:00:00+00']);
        $this->createStream($alex, ['transcription_status' => 'processing', 'started_at' => '2026-10-03 19:00:00+00']);
        $this->createStream($alex, ['transcription_status' => 'completed', 'event_extraction_status' => 'queued', 'started_at' => '2026-10-01 19:00:00+00']);
        Event::create(['stream_id' => $done->id, 'type' => 'death', 'title' => 'Creeper', 'description' => 'Died.', 'start_time' => 1, 'end_time' => 2, 'confidence' => 0.9]);

        $this->get('/players')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Players/Index')
            ->has('players', 2)
            ->where('players.0.name', 'Alex')
            ->where('players.0.team', 'Noord')
            ->where('players.0.deaths_count', 1)
            ->where('players.0.streams_count', 3)
            ->where('players.0.transcribed_streams_count', 2)
            ->where('players.0.active_streams_count', 2)
            ->where('players.0.events_count', 1)
            ->where('players.0.last_stream_at', fn (string $value) => str_starts_with($value, '2026-10-03'))
            ->where('players.1.name', 'Bram')
            ->where('players.1.streams_count', 0)
            ->where('players.1.deaths_count', 0)
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

    public function test_player_page_tells_the_story_stream_by_stream_with_deaths_and_retention(): void
    {
        $alex = Player::create(['name' => 'Alex', 'team' => 'Zuid', 'twitch_broadcaster_type' => 'affiliate']);
        $alex->deaths()->create(['died_at' => '2026-10-06 20:00:00+00', 'message' => 'Alex was slain by Zombie']);
        $chapter = Stream::query()->forceCreate([
            'player_id' => $alex->id, 'title' => 'Dag 1', 'started_at' => '2026-10-04 15:00:00+00', 'source' => 'twitch', 'twitch_video_id' => '1',
            'event_extraction_status' => 'completed', 'story_summary' => "Alex bouwt een huis.\n\nDaarna loopt hij naar de muur.",
        ]);
        $chapter->clips()->create(['title' => 'Huis', 'start_seconds' => 1, 'end_seconds' => 20]);
        Event::create(['stream_id' => $chapter->id, 'type' => 'building', 'title' => 'Alex bouwt een huis', 'description' => 'Een huis.', 'start_time' => 1, 'end_time' => 20, 'confidence' => 0.8]);
        $this->createStream($alex, ['title' => 'Dag 2', 'started_at' => '2026-10-05 15:00:00+00']);

        $this->get('/players/'.$alex->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('player.team', 'Zuid')
            ->where('player.retention_days', 14)
            ->where('player.clips_count', 1)
            ->where('player.voice_streams', 0)
            ->where('player.deaths.0.message', 'Alex was slain by Zombie')
            ->has('streams', 2)
            ->where("chapters.{$chapter->id}.paragraphs", ['Alex bouwt een huis.', 'Daarna loopt hij naar de muur.'])
            ->where("chapters.{$chapter->id}.events.0.title", 'Alex bouwt een huis')
            ->where("chapters.{$chapter->id}.vod_expires_at", fn (string $value) => str_starts_with($value, '2026-10-18'))
            ->has('met', 0));
    }

    public function test_player_page_counts_the_moments_shared_with_other_players(): void
    {
        $morrog = Player::create(['name' => 'Morrog']);
        $don = Player::create(['name' => 'DonKaaklijn']);
        $live = ['source' => 'twitch', 'started_at' => '2026-10-05 14:00:00+00', 'ended_at' => '2026-10-05 20:00:00+00', 'transcription_status' => 'completed'];
        $own = $this->createStream($morrog, [...$live, 'event_extraction_status' => 'completed']);
        $this->createStream($don, $live);
        foreach (['Morrog ruilt met DonKaaklijn', 'Morrog en DonKaaklijn bouwen een brug'] as $i => $title) {
            Event::create(['stream_id' => $own->id, 'type' => 'trade', 'title' => $title, 'description' => 'Samen.', 'start_time' => 100 * $i, 'end_time' => 100 * $i + 20, 'confidence' => 0.8]);
        }

        $this->get('/players/'.$morrog->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('met', 1)
            ->where('met.0.name', 'DonKaaklijn')
            ->where('met.0.moments', 2)
            ->where('met.0.streams', 1)
            ->where("chapters.{$own->id}.met.0.name", 'DonKaaklijn'));
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
