<?php

namespace App\Console\Commands;

use App\Models\Stream;
use App\Services\AudioRemux;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Rewrites downloaded Twitch audio as plain MP4s (see AudioRemux), so ▶ on the stream page plays the right moment. */
class NormalizeStreamAudio extends Command
{
    protected $signature = 'streams:normalize-audio {stream?* : stream ids (default: every stream with a media file)}';

    protected $description = 'Rewrite Twitch HLS audio files as plain MP4s that browsers can seek in (no re-encoding)';

    public function handle(AudioRemux $remux): int
    {
        $streams = Stream::query()->whereNotNull('video_path')
            ->when($this->argument('stream'), fn ($query, array $ids) => $query->whereKey($ids))
            ->orderBy('id')->get();

        foreach ($streams as $stream) {
            $file = Storage::path($stream->video_path);
            if (! is_file($file)) {
                $this->warn("Stream {$stream->id}: file missing");

                continue;
            }
            // The worker or yt-dlp may be reading or writing it.
            if (in_array('processing', [$stream->transcription_status, $stream->video_download_status], true)) {
                $this->warn("Stream {$stream->id}: busy, skipped");

                continue;
            }
            if (! $remux->needsNormalizing($file)) {
                $this->line("Stream {$stream->id}: already fine");

                continue;
            }
            if ($remux->normalize($file)) {
                clearstatcache(true, $file);
                $stream->forceFill(['video_file_size' => filesize($file)])->save();
                $this->info("Stream {$stream->id}: rewritten");
            } else {
                $this->error("Stream {$stream->id}: failed, original kept (see the log)");
            }
        }

        return self::SUCCESS;
    }
}
