<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * yt-dlp stores a Twitch VOD as its HLS recording: an MP4 of ~200,000 small fragments without an index, whose timestamps
 * start at an arbitrary point (62 s in stream 66). The worker's ffmpeg counts from the first sample, so the transcript is
 * right, but browsers start the player at that offset and cannot seek precisely in it: ▶ on a transcript line played
 * something else. Copying the streams into a plain MP4 (index up front, first sample at 0, no re-encoding, seconds per
 * file) makes file time, transcript time and player time the same.
 */
class AudioRemux
{
    /** Rewrites the file in place; on failure the original is kept and false is returned. */
    public function normalize(string $file): bool
    {
        $temporary = $file.'.remux.mp4';
        $result = Process::timeout(1800)->run([
            'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $file,
            '-map', '0:a?', '-map', '0:v?', '-c', 'copy',
            '-avoid_negative_ts', 'make_zero', '-movflags', '+faststart',
            $temporary,
        ]);
        // The copy drops only Twitch's metadata boxes, so a much smaller file means ffmpeg stopped early.
        if ($result->failed() || ! is_file($temporary) || filesize($temporary) < 0.5 * filesize($file)) {
            @unlink($temporary);
            Log::warning('Rewriting the audio file as a plain MP4 failed; keeping the original', ['file' => $file, 'error' => trim($result->errorOutput())]);

            return false;
        }

        return rename($temporary, $file);
    }

    /** Whether the file still is such a recording: a first timestamp after 0, or an HLS/DASH fragmented MP4. */
    public function needsNormalizing(string $file): bool
    {
        $result = Process::timeout(60)->run(['ffprobe', '-v', 'error', '-show_entries', 'stream=start_time:format_tags=compatible_brands', '-of', 'json', $file]);
        if ($result->failed()) {
            return false;
        }
        $probe = json_decode($result->output(), true) ?: [];
        $starts = array_map(fn (array $stream) => (float) ($stream['start_time'] ?? 0), $probe['streams'] ?? []);
        $brands = (string) ($probe['format']['tags']['compatible_brands'] ?? '');

        return max([0.0, ...$starts]) > 0.01 || str_contains($brands, 'hlsf') || str_contains($brands, 'dash');
    }
}
