<?php

namespace App\Services;

use App\Models\Stream;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The work left in the pipeline ("Te doen" on the dashboard): per step the streams that are ready for it or failed in
 * it, oldest first (a stream's story continues the previous one), and the Twitch VODs that are about to disappear.
 */
class PipelineBacklog
{
    /** step => the status column of that step on streams */
    public const STEPS = [
        'download' => 'video_download_status',
        'transcribe' => 'transcription_status',
        'extract' => 'event_extraction_status',
    ];

    /** A VOD is "about to expire" this many days before Twitch removes it. */
    public const EXPIRY_WARNING_DAYS = 3;

    public function __construct(private readonly VodRetention $retention) {}

    /**
     * The streams that are ready for the step (pending) or failed in it, oldest first. A VOD whose audio can no longer be
     * fetched (expired) or not yet (still live) is left out of the download step.
     *
     * @return Collection<int, Stream>
     */
    public function streams(string $step): Collection
    {
        $query = Stream::query()
            ->with('player:id,name,photo_path,twitch_broadcaster_type,updated_at')
            ->whereIn(self::STEPS[$step], ['pending', 'failed'])
            ->orderBy('started_at')
            ->orderBy('id');

        match ($step) {
            'download' => $query->whereNotNull('twitch_video_id')->whereNotNull('ended_at')->whereNull('video_path'),
            'transcribe' => $query->whereNotNull('video_path'),
            'extract' => $query->where('transcription_status', 'completed')->has('transcriptSegments'),
        };

        $streams = $query->get();

        return $step === 'download' ? $streams->filter(fn (Stream $stream) => ! $this->expired($stream))->values() : $streams;
    }

    /**
     * Twitch VODs that disappear within EXPIRY_WARNING_DAYS while something still needs them: their audio is not fetched
     * yet, or they have clips (video is only fetched for clips, from the VOD). Soonest first.
     *
     * @return Collection<int, Stream>
     */
    public function expiring(): Collection
    {
        $warnUntil = now()->addDays(self::EXPIRY_WARNING_DAYS);

        return $this->vods()
            ->filter(fn (Stream $stream) => $stream->video_path === null || $stream->clips_count > 0)
            ->filter(fn (Stream $stream) => ! $this->expired($stream) && $this->retention->expiresAt($stream)->lte($warnUntil))
            ->sortBy(fn (Stream $stream) => $this->retention->expiresAt($stream)->timestamp)
            ->values();
    }

    /** VODs that Twitch has removed before their audio was fetched: their story is lost. */
    public function expiredWithoutAudio(): int
    {
        return Stream::query()
            ->with('player:id,twitch_broadcaster_type')
            ->whereNotNull('twitch_video_id')
            ->whereNull('video_path')
            ->get(['id', 'player_id', 'twitch_video_id', 'started_at'])
            ->filter(fn (Stream $stream) => $this->expired($stream))
            ->count();
    }

    /** @return Collection<int, Stream> */
    private function vods(): Collection
    {
        // The longest retention bounds the search: anything older has expired for every channel.
        $oldest = now()->subDays(max(config('services.twitch.vod_retention_days')) + 1);

        return Stream::query()
            ->with('player:id,name,photo_path,twitch_broadcaster_type,updated_at')
            ->withCount('clips')
            ->whereNotNull('twitch_video_id')
            ->where(fn (Builder $query) => $query->whereNull('video_path')->orHas('clips'))
            ->where('started_at', '>=', $oldest)
            ->get();
    }

    private function expired(Stream $stream): bool
    {
        return $this->retention->expiresAt($stream)?->isPast() ?? false;
    }
}
