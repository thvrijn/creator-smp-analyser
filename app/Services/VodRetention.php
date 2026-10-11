<?php

namespace App\Services;

use App\Models\Stream;
use Carbon\CarbonInterface;

/**
 * How long Twitch keeps a stream's VOD: 60 days for Partners, 14 for Affiliates, 7 for other channels. After that the
 * audio can no longer be fetched and clips can no longer be watched or downloaded. A channel whose type is not known
 * yet (not synced since the type was stored) is assumed to keep VODs for the shortest time, so a warning is never late.
 */
class VodRetention
{
    public function days(Stream $stream): int
    {
        $days = config('services.twitch.vod_retention_days');

        return $days[$stream->player?->twitch_broadcaster_type] ?? min($days);
    }

    public function known(Stream $stream): bool
    {
        return isset(config('services.twitch.vod_retention_days')[$stream->player?->twitch_broadcaster_type]);
    }

    /** Null for a stream that is no Twitch VOD or has no start. */
    public function expiresAt(Stream $stream): ?CarbonInterface
    {
        if ($stream->twitch_video_id === null || $stream->started_at === null) {
            return null;
        }

        return $stream->started_at->copy()->addDays($this->days($stream));
    }
}
