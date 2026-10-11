<?php

namespace Tests\Feature;

use App\Jobs\DownloadVodJob;
use App\Jobs\TranscribeStreamJob;
use App\Models\Event;
use App\Models\Player;
use App\Models\Stream;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->travelTo('2026-10-11 12:00:00');
    }

    public function test_it_shows_the_day_of_the_season_and_which_episode_is_running(): void
    {
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('season.day', 8)
            ->where('season.days', 22)
            ->has('season.episodes', 4)
            ->where('season.episodes.0.state', 'done')
            ->where('season.episodes.1.state', 'current')
            ->where('season.episodes.2.state', 'upcoming')
            ->where('season.episodes.3.title', 'Finale'));
    }

    public function test_it_counts_the_streams_ready_for_each_step_and_the_failed_ones(): void
    {
        $player = Player::create(['name' => 'Alex', 'twitch_broadcaster_type' => 'affiliate']);
        $this->vod($player, '1', ['started_at' => '2026-10-09 15:00:00+00']);
        $failed = $this->vod($player, '2', ['started_at' => '2026-10-08 15:00:00+00', 'video_download_status' => 'failed', 'video_download_error' => 'Geen CreatorSMP-deel']);
        $this->vod($player, '3', ['started_at' => '2026-10-11 10:00:00+00', 'ended_at' => null]); // still live
        $this->vod($player, '4', ['started_at' => '2026-09-20 15:00:00+00']); // expired: 14 days for an affiliate
        $this->stream($player, ['video_path' => 'streams/a.m4a']);
        $transcribed = $this->stream($player, ['video_path' => 'streams/b.m4a', 'transcription_status' => 'completed']);
        $transcribed->transcriptSegments()->create(['start_time' => 0, 'end_time' => 2, 'text' => 'Hallo']);

        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('steps.download.ready', 1)
            ->where('steps.download.failed', 1)
            ->where('steps.download.streams.0.id', $failed->id)
            ->where('steps.download.streams.0.error', 'Geen CreatorSMP-deel')
            ->where('steps.transcribe.ready', 1)
            ->where('steps.extract.ready', 1)
            ->where('steps.extract.streams.0.id', $transcribed->id)
            ->where('live_count', 1)
            ->where('expired_without_audio', 1));
    }

    public function test_queueing_a_step_starts_every_ready_stream_but_not_the_failed_ones(): void
    {
        Queue::fake();
        Storage::fake('local');
        $player = Player::create(['name' => 'Alex']);
        $ready = collect(['a', 'b'])->map(function (string $name) use ($player) {
            Storage::disk('local')->put("streams/{$name}.m4a", 'audio');

            return $this->stream($player, ['video_path' => "streams/{$name}.m4a"]);
        });
        $failed = $this->stream($player, ['video_path' => 'streams/c.m4a', 'transcription_status' => 'failed']);

        $this->from('/dashboard')->post('/dashboard/queue/transcribe')
            ->assertRedirect('/dashboard')
            ->assertSessionHas('success', '2 streams in de wachtrij gezet.');

        Queue::assertPushed(TranscribeStreamJob::class, 2);
        $ready->each(fn (Stream $stream) => $this->assertSame('queued', $stream->fresh()->transcription_status));
        $this->assertSame('failed', $failed->fresh()->transcription_status);
    }

    public function test_queueing_a_step_with_nothing_ready_says_so(): void
    {
        Queue::fake();

        $this->from('/dashboard')->post('/dashboard/queue/download')->assertSessionHas('error', 'Er stond niets klaar voor deze stap.');
        Queue::assertNotPushed(DownloadVodJob::class);
        $this->post('/dashboard/queue/everything')->assertNotFound();
    }

    public function test_it_warns_about_vods_that_expire_soon_while_they_still_need_something(): void
    {
        $affiliate = Player::create(['name' => 'Alex', 'twitch_broadcaster_type' => 'affiliate']);
        $partner = Player::create(['name' => 'Bram', 'twitch_broadcaster_type' => 'partner']);
        $unknown = Player::create(['name' => 'Cas']);
        $soon = $this->vod($affiliate, '1', ['started_at' => '2026-09-29 15:00:00+00']); // 14 days: gone on 13 October
        $this->vod($affiliate, '2', ['started_at' => '2026-09-29 18:00:00+00', 'video_path' => 'streams/x.m4a']); // has its audio, no clips
        $withClips = $this->vod($affiliate, '3', ['started_at' => '2026-09-30 09:00:00+00', 'video_path' => 'streams/y.m4a']);
        $withClips->clips()->create(['title' => 'Ruzie', 'start_seconds' => 10, 'end_seconds' => 40]);
        $this->vod($partner, '4', ['started_at' => '2026-09-29 15:00:00+00']); // 60 days
        $this->vod($unknown, '5', ['started_at' => '2026-10-05 15:00:00+00']); // assumed 7 days: gone on 12 October

        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('expiring_count', 3)
            ->where('expiring.0.player.name', 'Cas')
            ->where('expiring.0.retention_known', false)
            ->where('expiring.0.retention_days', 7)
            ->where('expiring.1.id', $soon->id)
            ->where('expiring.1.has_audio', false)
            ->where('expiring.2.id', $withClips->id)
            ->where('expiring.2.clips_count', 1));
    }

    public function test_it_shows_the_latest_analysed_streams_and_how_the_teams_are_doing(): void
    {
        $alex = Player::create(['name' => 'Alex', 'team' => 'Noord']);
        Player::create(['name' => 'Bram', 'team' => 'Noord']);
        Player::create(['name' => 'Cas', 'team' => 'Zuid']);
        $alex->deaths()->create(['died_at' => '2026-10-05 20:00:00+00', 'message' => 'Alex was slain by Zombie']);
        $alex->deaths()->create(['died_at' => '2026-10-05 19:00:00+00', 'message' => 'Alex fell from a high place']);
        $stream = $this->stream($alex, ['event_extraction_status' => 'completed', 'story_summary' => "Alex bouwt een basis.\n\nDaarna gaat hij naar de muur."]);
        $this->stream($alex, ['event_extraction_status' => 'pending']);
        Event::create(['stream_id' => $stream->id, 'type' => 'building', 'title' => 'Alex bouwt een basis', 'description' => 'Een huis.', 'start_time' => 1, 'end_time' => 20, 'confidence' => 0.6]);
        Event::create(['stream_id' => $stream->id, 'type' => 'discovery', 'title' => 'Alex vindt de muur', 'description' => 'De muur.', 'start_time' => 30, 'end_time' => 50, 'confidence' => 0.9]);

        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('chapters', 1)
            ->where('chapters.0.summary', 'Alex bouwt een basis.')
            ->where('chapters.0.events_count', 2)
            ->where('chapters.0.events.0.title', 'Alex vindt de muur')
            ->where('teams.0', ['name' => 'Noord', 'players' => 2, 'died' => 1])
            ->where('teams.1', ['name' => 'Zuid', 'players' => 1, 'died' => 0])
            ->has('deaths', 2)
            ->where('deaths.0.message', 'Alex was slain by Zombie')
            ->where('deaths.0.player.team', 'Noord'));
    }

    private function vod(Player $player, string $videoId, array $attributes = []): Stream
    {
        return $this->stream($player, ['twitch_video_id' => $videoId, 'source' => 'twitch', 'ended_at' => '2026-10-11 09:00:00+00', ...$attributes]);
    }

    private function stream(Player $player, array $attributes = []): Stream
    {
        return Stream::query()->forceCreate(array_merge([
            'player_id' => $player->id,
            'title' => 'Stream',
            'started_at' => '2026-10-10 15:00:00+00',
            'source' => 'test',
        ], $attributes));
    }
}
