<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Stream;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StreamManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_the_streams_page_loads_with_streams_and_players(): void
    {
        $player = Player::create(['name' => 'Sophie']);
        $stream = Stream::create([
            'player_id' => $player->id,
            'title' => 'Evening SMP',
            'started_at' => '2026-10-03 19:00:00+00',
            'ended_at' => '2026-10-03 20:00:00+00',
            'source' => 'youtube',
        ]);

        $this->get('/streams')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Streams/Index')
                ->has('streams', 1)
                ->where('streams.0.id', $stream->id)
                ->where('streams.0.player.name', 'Sophie')
                ->where('streams.0.status', 'Finished')
                ->has('players', 1));
    }

    public function test_the_twitch_link_points_to_the_channel_while_live_and_to_the_vod_afterwards(): void
    {
        $player = Player::create(['name' => 'Sophie', 'twitch_login' => 'sophie_smp']);
        $live = Stream::create([
            'player_id' => $player->id,
            'title' => 'Live SMP',
            'started_at' => '2026-10-04 19:00:00+00',
            'ended_at' => null,
            'source' => 'twitch',
            'twitch_video_id' => '111',
        ]);
        $finished = Stream::create([
            'player_id' => $player->id,
            'title' => 'Evening SMP',
            'started_at' => '2026-10-03 19:00:00+00',
            'ended_at' => '2026-10-03 20:00:00+00',
            'source' => 'twitch',
            'twitch_video_id' => '222',
        ]);

        $this->get('/streams')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('streams.0.id', $live->id)
                ->where('streams.0.twitch_url', 'https://www.twitch.tv/sophie_smp')
                ->where('streams.1.id', $finished->id)
                ->where('streams.1.twitch_url', 'https://www.twitch.tv/videos/222'));
    }

    public function test_a_stream_can_be_created(): void
    {
        $player = Player::create(['name' => 'Lars']);

        $response = $this->post('/streams', [
            'player_id' => $player->id,
            'title' => 'Late night SMP',
            'started_at' => '2026-10-03T21:00',
            'source' => 'twitch',
        ]);

        $response->assertRedirect('/streams');
        $this->assertDatabaseHas('streams', [
            'player_id' => $player->id,
            'title' => 'Late night SMP',
            'source' => 'twitch',
        ]);
        $this->assertNull(Stream::first()->ended_at);
    }

    public function test_a_stream_with_an_invalid_player_is_rejected(): void
    {
        $response = $this->from('/streams')->post('/streams', [
            'player_id' => 999999,
            'title' => 'Invalid player',
            'started_at' => '2026-10-03T21:00',
        ]);

        $response->assertRedirect('/streams')->assertSessionHasErrors('player_id');
        $this->assertDatabaseCount('streams', 0);
    }

    public function test_a_stream_without_a_title_is_rejected(): void
    {
        $player = Player::create(['name' => 'Mark']);

        $response = $this->from('/streams')->post('/streams', [
            'player_id' => $player->id,
            'title' => '',
            'started_at' => '2026-10-03T21:00',
        ]);

        $response->assertRedirect('/streams')->assertSessionHasErrors('title');
        $this->assertDatabaseCount('streams', 0);
    }

    public function test_an_end_time_before_the_start_time_is_rejected(): void
    {
        $player = Player::create(['name' => 'Sophie']);

        $response = $this->from('/streams')->post('/streams', [
            'player_id' => $player->id,
            'title' => 'Invalid timing',
            'started_at' => '2026-10-03T21:00',
            'ended_at' => '2026-10-03T20:00',
        ]);

        $response->assertRedirect('/streams')->assertSessionHasErrors('ended_at');
        $this->assertDatabaseCount('streams', 0);
    }

    public function test_a_stream_can_be_deleted(): void
    {
        $player = Player::create(['name' => 'Lars']);
        $stream = Stream::create([
            'player_id' => $player->id,
            'title' => 'To be removed',
            'started_at' => '2026-10-03 21:00:00+00',
            'source' => 'local',
        ]);

        $this->delete('/streams/'.$stream->id)->assertRedirect('/streams');

        $this->assertDatabaseMissing('streams', ['id' => $stream->id]);
    }

    public function test_a_stream_can_be_created_without_a_video(): void
    {
        $player = Player::create(['name' => 'Sophie']);

        $this->post('/streams', [
            'player_id' => $player->id,
            'title' => 'Audio only metadata',
            'started_at' => '2026-10-03T21:00',
        ])->assertRedirect('/streams');

        $this->assertDatabaseHas('streams', [
            'title' => 'Audio only metadata',
            'video_path' => null,
            'video_original_filename' => null,
        ]);
    }

    public function test_a_stream_can_be_created_with_a_valid_video(): void
    {
        Storage::fake('local');
        $player = Player::create(['name' => 'Sophie']);

        $this->post('/streams', [
            'player_id' => $player->id,
            'title' => 'Stream with video',
            'started_at' => '2026-10-03T21:00',
            'video' => UploadedFile::fake()->create('evening-stream.mp4', 256, 'video/mp4'),
        ])->assertRedirect('/streams');

        $stream = Stream::firstOrFail();

        $this->assertNotNull($stream->video_path);
        $this->assertSame('evening-stream.mp4', $stream->video_original_filename);
        $this->assertSame('video/mp4', $stream->video_mime_type);
        $this->assertGreaterThan(0, $stream->video_file_size);
        Storage::disk('local')->assertExists($stream->video_path);
    }

    public function test_an_invalid_video_type_is_rejected_without_creating_a_stream(): void
    {
        Storage::fake('local');
        $player = Player::create(['name' => 'Sophie']);

        $this->from('/streams')->post('/streams', [
            'player_id' => $player->id,
            'title' => 'Invalid video',
            'started_at' => '2026-10-03T21:00',
            'video' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
        ])->assertRedirect('/streams')->assertSessionHasErrors('video');

        $this->assertDatabaseCount('streams', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_video_over_the_configured_limit_is_rejected(): void
    {
        Config::set('streams.max_upload_mb', 1);
        $player = Player::create(['name' => 'Sophie']);

        $this->from('/streams')->post('/streams', [
            'player_id' => $player->id,
            'title' => 'Too large video',
            'started_at' => '2026-10-03T21:00',
            'video' => UploadedFile::fake()->create('large.mp4', 2048, 'video/mp4'),
        ])->assertRedirect('/streams')->assertSessionHasErrors('video');

        $this->assertDatabaseCount('streams', 0);
    }

    public function test_deleting_a_stream_removes_its_video_from_storage(): void
    {
        Storage::fake('local');
        $player = Player::create(['name' => 'Sophie']);
        $path = 'streams/1/video/recording.mp4';
        Storage::disk('local')->put($path, 'fake video');
        $stream = Stream::create([
            'player_id' => $player->id,
            'title' => 'Video to remove',
            'started_at' => '2026-10-03 21:00:00+00',
            'source' => 'local',
            'video_path' => $path,
            'video_original_filename' => 'recording.mp4',
            'video_mime_type' => 'video/mp4',
            'video_file_size' => 11,
        ]);

        $this->delete('/streams/'.$stream->id)->assertRedirect('/streams');

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('streams', ['id' => $stream->id]);
    }
}
