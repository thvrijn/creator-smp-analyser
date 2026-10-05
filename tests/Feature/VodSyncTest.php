<?php

namespace Tests\Feature;

use App\Models\Player;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VodSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config(['services.twitch.client_id' => 'test-client', 'services.twitch.client_secret' => 'test-secret', 'services.twitch.vods_since' => '2026-10-04']);
    }

    /** @var array<string, list<array<string, string>>> Twitch user ID => their VODs, read on every fake request */
    private array $videos = [];

    private function fakeTwitch(array $videosByUserId, array $users, array $liveStreams = []): void
    {
        $this->videos = $videosByUserId;
        Http::fake([
            'id.twitch.tv/oauth2/token' => Http::response(['access_token' => 'token', 'expires_in' => 5000000]),
            'api.twitch.tv/helix/users*' => Http::response(['data' => $users]),
            'api.twitch.tv/helix/streams*' => Http::response(['data' => $liveStreams]),
            'api.twitch.tv/helix/videos*' => fn (Request $request) => Http::response(['data' => $this->videos[$request['user_id']] ?? []]),
        ]);
    }

    private function video(string $id, string $createdAt, string $duration, string $title = 'Dag 1'): array
    {
        return ['id' => $id, 'stream_id' => 's'.$id, 'title' => $title, 'created_at' => $createdAt, 'duration' => $duration];
    }

    public function test_the_vod_of_a_stream_that_is_still_live_gets_no_end(): void
    {
        $player = Player::create(['name' => 'jactha', 'twitch_login' => 'jactha']);
        $this->fakeTwitch(['33' => [
            $this->video('4001', '2026-10-05T12:00:08Z', '2h6m13s', 'Dag 2'),
            $this->video('4000', '2026-10-04T16:30:00Z', '5h0m0s'),
        ]], [['id' => '33', 'login' => 'jactha']], [['id' => 's4001', 'user_id' => '33']]);

        $this->post('/players/'.$player->id.'/sync-vods')->assertSessionHas('success', "2 nieuwe VOD's toegevoegd.");

        $this->assertNull($player->streams()->where('twitch_video_id', '4001')->value('ended_at'));
        $this->assertNotNull($player->streams()->where('twitch_video_id', '4000')->value('ended_at'));
    }

    public function test_syncing_a_player_adds_their_season_vods_once_without_video(): void
    {
        $player = Player::create(['name' => 'Royalistiq', 'twitch_login' => 'royalistiq']);
        $this->fakeTwitch(['11' => [
            $this->video('2001', '2026-10-04T16:54:44Z', '5h16m37s'),
            $this->video('1999', '2026-09-23T17:44:50Z', '2h38m15s', 'Training'), // before the season: skipped
            $this->video('2002', '2026-10-05T07:00:00Z', '3h0m0s', 'Ochtendstream'), // 09:00-12:00, server closed: skipped
        ]], [['id' => '11', 'login' => 'royalistiq']]);

        $this->from('/players/'.$player->id)->post('/players/'.$player->id.'/sync-vods')
            ->assertRedirect('/players/'.$player->id)->assertSessionHas('success', '1 nieuwe VOD toegevoegd.');

        $stream = $player->streams()->sole();
        $this->assertSame('2001', $stream->twitch_video_id);
        $this->assertSame('twitch', $stream->source);
        $this->assertNull($stream->video_path);
        $this->assertSame('2026-10-04 16:54:44', $stream->started_at->toDateTimeString());
        $this->assertSame('2026-10-04 22:11:21', $stream->ended_at->toDateTimeString());
        $this->assertNotNull($player->fresh()->vods_synced_at);

        // A re-sync keeps a title changed in the app and updates the length of a VOD that was still live.
        $stream->update(['title' => 'Eigen titel']);
        $this->videos = ['11' => [$this->video('2001', '2026-10-04T16:54:44Z', '5h30m0s')]];
        $this->post('/players/'.$player->id.'/sync-vods')->assertSessionHas('success', "Geen nieuwe VOD's.");

        $stream = $player->streams()->sole();
        $this->assertSame('Eigen titel', $stream->title);
        $this->assertSame('2026-10-04 22:24:44', $stream->ended_at->toDateTimeString());
    }

    public function test_syncing_everyone_skips_players_without_twitch_and_reports_unknown_channels(): void
    {
        $duncan = Player::create(['name' => 'Duncan', 'twitch_login' => 'duncan']);
        Player::create(['name' => 'Mick']);
        Player::create(['name' => 'Hernoemd', 'twitch_login' => 'oude_naam']);
        $this->fakeTwitch(['22' => [
            $this->video('3001', '2026-10-04T16:46:39Z', '5h21m23s'),
            $this->video('3002', '2026-10-05T16:40:00Z', '1h0m0s', 'Dag 2'),
        ]], [['id' => '22', 'login' => 'Duncan']]);

        $this->post('/players/sync-vods')->assertRedirect('/dashboard')
            ->assertSessionHas('error', "2 nieuwe VOD's toegevoegd. Niet gelukt voor: Hernoemd.");

        $this->assertSame(2, $duncan->streams()->count());
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'login=mick'));
    }

    public function test_a_revoked_token_is_replaced_once(): void
    {
        $player = Player::create(['name' => 'Duncan', 'twitch_login' => 'duncan']);
        Http::fake([
            'id.twitch.tv/oauth2/token' => Http::response(['access_token' => 'token', 'expires_in' => 5000000]),
            'api.twitch.tv/helix/users*' => Http::sequence()->push(['message' => 'Invalid OAuth token'], 401)->push(['data' => [['id' => '22', 'login' => 'duncan']]]),
            'api.twitch.tv/helix/streams*' => Http::response(['data' => []]),
            'api.twitch.tv/helix/videos*' => Http::response(['data' => [$this->video('3001', '2026-10-04T16:46:39Z', '1h0m0s')]]),
        ]);

        $this->post('/players/'.$player->id.'/sync-vods')->assertSessionHas('success', '1 nieuwe VOD toegevoegd.');
    }

    public function test_sync_without_twitch_credentials_explains_what_is_missing(): void
    {
        config(['services.twitch.client_secret' => null]);
        $player = Player::create(['name' => 'Duncan', 'twitch_login' => 'duncan']);
        Http::fake();

        $this->post('/players/'.$player->id.'/sync-vods')
            ->assertSessionHas('error', 'Twitch is niet ingesteld. Zet TWITCH_CLIENT_ID en TWITCH_CLIENT_SECRET in .env.');
        Http::assertNothingSent();
    }
}
