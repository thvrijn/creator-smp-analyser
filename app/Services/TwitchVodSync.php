<?php

namespace App\Services;

use App\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Turns the players' Twitch past broadcasts since the season start, during the server's opening hours, into streams.
 * Only the list: the audio is fetched separately. The Twitch API has no category per VOD; DownloadVodJob checks that.
 */
class TwitchVodSync
{
    private const API = 'https://api.twitch.tv/helix/';

    /**
     * @param  Collection<int, Player>  $players
     * @return array{new: int, failed: list<string>} the number of new streams, and the players whose channel could not be synced
     */
    public function sync(Collection $players): array
    {
        $players = $players->filter(fn (Player $player) => $player->twitch_login !== null)->values();
        if ($players->isEmpty()) {
            return ['new' => 0, 'failed' => []];
        }

        $userIds = $this->userIds($players->pluck('twitch_login')->all());
        [$found, $missing] = $players->partition(fn (Player $player) => isset($userIds[strtolower($player->twitch_login)]));
        $failed = $missing->pluck('name')->all();
        $liveStreamIds = $this->liveStreamIds(array_values($userIds));

        // ponytail: one page of 100 VODs per channel covers a three-week season; paginate if a season ever gets longer.
        $responses = Http::pool(fn (Pool $pool) => $found->map(fn (Player $player) => $this->api($pool->as((string) $player->id))
            ->get(self::API.'videos', ['user_id' => $userIds[strtolower($player->twitch_login)], 'type' => 'archive', 'first' => 100]))->all());

        $since = CarbonImmutable::parse(config('services.twitch.vods_since'));
        $new = 0;
        foreach ($found as $player) {
            $response = $responses[(string) $player->id] ?? null;
            if (! $response instanceof Response || $response->failed()) {
                $failed[] = $player->name;

                continue;
            }

            foreach ($response->json('data') as $video) {
                $startedAt = CarbonImmutable::parse($video['created_at']);
                $endedAt = $startedAt->addSeconds($this->seconds($video['duration']));
                // Before the season, or entirely outside the server's opening hours: not a Creator SMP stream.
                if ($startedAt->lt($since) || ServerHours::within($startedAt, $endedAt) === []) {
                    continue;
                }

                $stream = $player->streams()->firstOrNew(['twitch_video_id' => $video['id']]);
                $new += $stream->exists ? 0 : 1;
                $stream->fill([
                    'title' => $stream->title ?? ($video['title'] ?: 'Twitch-VOD'), // keep a title that was changed in the app
                    'started_at' => $startedAt,
                    // The VOD of a stream that is still live keeps growing: no end yet, so its audio is not fetched half.
                    'ended_at' => in_array($video['stream_id'] ?? null, $liveStreamIds, true) ? null : $endedAt,
                    'source' => 'twitch',
                ])->save();
            }
            $player->update(['vods_synced_at' => now()]);
        }

        return ['new' => $new, 'failed' => $failed];
    }

    /**
     * @param  list<string>  $logins
     * @return array<string, string> lowercase login => Twitch user ID
     */
    private function userIds(array $logins): array
    {
        $ids = [];
        foreach (array_chunk($logins, 100) as $chunk) { // Twitch takes at most 100 logins per request, as repeated login= parameters
            $url = self::API.'users?'.implode('&', array_map(fn (string $login) => 'login='.urlencode($login), $chunk));
            $response = $this->api(Http::acceptJson())->get($url);
            if ($response->status() === 401) { // the cached token was revoked, for example by a new client secret
                Cache::forget($this->tokenKey());
                $response = $this->api(Http::acceptJson())->get($url);
            }
            foreach ($response->throw()->json('data') as $user) {
                $ids[strtolower($user['login'])] = $user['id'];
            }
        }

        return $ids;
    }

    /**
     * @param  list<string>  $userIds
     * @return list<string> the IDs of the streams that are live right now
     */
    private function liveStreamIds(array $userIds): array
    {
        $ids = [];
        foreach (array_chunk($userIds, 100) as $chunk) {
            $url = self::API.'streams?first=100&'.implode('&', array_map(fn (string $id) => 'user_id='.urlencode($id), $chunk));
            foreach ($this->api(Http::acceptJson())->get($url)->throw()->json('data') as $stream) {
                $ids[] = (string) $stream['id'];
            }
        }

        return $ids;
    }

    private function api(PendingRequest $request): PendingRequest
    {
        return $request->timeout(15)->withToken($this->token())->withHeaders(['Client-Id' => config('services.twitch.client_id')]);
    }

    private function token(): string
    {
        $clientId = config('services.twitch.client_id');
        $secret = config('services.twitch.client_secret');
        if (! $clientId || ! $secret) {
            throw new RuntimeException('Twitch is niet ingesteld. Zet TWITCH_CLIENT_ID en TWITCH_CLIENT_SECRET in .env.');
        }

        if ($token = Cache::get($this->tokenKey())) {
            return $token;
        }

        $response = Http::asForm()->timeout(15)->post('https://id.twitch.tv/oauth2/token', [
            'client_id' => $clientId,
            'client_secret' => $secret,
            'grant_type' => 'client_credentials',
        ])->throw();
        Cache::put($this->tokenKey(), $token = $response->json('access_token'), max(60, $response->json('expires_in') - 300));

        return $token;
    }

    private function tokenKey(): string
    {
        return 'twitch_token:'.config('services.twitch.client_id');
    }

    /** Twitch durations look like "5h16m37s". */
    private function seconds(string $duration): int
    {
        preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $duration, $parts);

        return (int) ($parts[1] ?? 0) * 3600 + (int) ($parts[2] ?? 0) * 60 + (int) ($parts[3] ?? 0);
    }
}
