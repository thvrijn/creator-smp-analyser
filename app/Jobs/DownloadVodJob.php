<?php

namespace App\Jobs;

use App\Models\Stream;
use App\Services\ServerHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Downloads the whole audio of a synced Twitch VOD with yt-dlp (~90 MB per hour), for transcription and analysis, and stores
 * which parts are in the Creator SMP category during the server's opening hours: only those are transcribed. The file
 * stays the whole VOD, so its times are VOD times. Video clips are fetched later, only where needed.
 * ponytail: runs on the same queue as transcriptions, so a download makes them wait; give it its own queue worker when that hurts.
 */
class DownloadVodJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // A broken download is restarted by hand, not retried in a loop; yt-dlp already retries fragments itself.
    public int $tries = 1;

    public int $timeout = 4 * 3600;

    public function __construct(public readonly int $streamId) {}

    public function handle(): void
    {
        $stream = Stream::find($this->streamId);
        if ($stream === null || ! $this->markAsProcessing($stream)) {
            return;
        }
        // markAsProcessing saved through a locked copy; reload so later status saves are not skipped as "unchanged".
        $stream->refresh();

        if ($stream->ended_at === null) {
            // yt-dlp would follow a live VOD from its live edge, for hours, and miss the start.
            $this->markAsFailed($stream, "Deze stream is nog live. Haal de audio op als hij voorbij is, en sync de VOD's dan eerst opnieuw.");

            return;
        }
        $vodSeconds = (int) $stream->started_at->diffInSeconds($stream->ended_at);
        $directory = "streams/{$stream->id}/video";
        Storage::makeDirectory($directory);
        // Keep 5 GB free for the database and the rest.
        $needed = $vodSeconds / 3600 * config('services.twitch.download_gb_per_hour') + 5;
        $free = disk_free_space(Storage::path($directory)) / 1e9;
        if ($free < $needed) {
            $this->markAsFailed($stream, sprintf('Niet genoeg schijfruimte: nodig ongeveer %.0f GB, vrij %.0f GB.', $needed, $free));

            return;
        }

        $base = Storage::path($directory).'/'.$this->baseName($stream);
        $result = Process::timeout($this->timeout - 300)->run([
            'yt-dlp', '--no-playlist', '--no-part', '--concurrent-fragments', '8',
            // One line per downloaded fragment, read by trackProgress().
            '--progress', '--newline', '--progress-template', 'download:progress %(progress.fragment_index)s/%(progress.fragment_count)s',
            '-f', config('services.twitch.audio_format'),
            '-o', $base.'.full.%(ext)s',
            // The chapters are the categories with their times; vcodec "none" means the audio-only rendition.
            '--print', 'after_move:%(chapters)j',
            '--print', 'after_move:%(vcodec)s %(filepath)s',
            'https://www.twitch.tv/videos/'.$stream->twitch_video_id,
        ], $this->trackProgress($stream));
        $lines = array_values(array_filter(explode("\n", trim($result->output())), fn (string $line) => self::progressPercent($line) === null));
        [$videoCodec, $full] = explode(' ', (string) array_pop($lines), 2) + ['', ''];
        if ($result->failed() || $full === '' || ! is_file($full)) {
            $this->failWithOutput($stream, $result->errorOutput());

            return;
        }

        $ranges = $this->creatorSmpRanges($stream, json_decode((string) array_pop($lines), true));
        if ($ranges === []) {
            $this->deletePartialFiles($stream);
            $this->markAsFailed($stream, sprintf('Deze VOD heeft geen deel in de categorie %s tussen %02d:00 en %02d:00.', config('services.twitch.category'), config('services.twitch.server_opens_hour'), config('services.twitch.server_closes_hour') % 24));

            return;
        }

        $file = $base.'.'.pathinfo($full, PATHINFO_EXTENSION);
        rename($full, $file);
        $whole = count($ranges) === 1 && $ranges[0][0] === 0 && $ranges[0][1] >= $vodSeconds;

        $path = Str::after($file, Storage::path(''));
        $stream->forceFill([
            'video_path' => $path,
            'video_original_filename' => basename($path),
            'video_mime_type' => $videoCodec === 'none' ? 'audio/mp4' : 'video/mp4',
            'video_file_size' => Storage::size($path),
            'video_offset_seconds' => 0,
            'transcription_ranges' => $whole ? null : $ranges,
            'video_download_status' => 'completed',
            'video_download_progress' => 100,
            'video_download_error' => null,
        ])->save();
    }

    public function failed(\Throwable $exception): void
    {
        $stream = Stream::find($this->streamId);
        if ($stream !== null) {
            $this->deletePartialFiles($stream);
            $this->markAsFailed($stream, 'Downloaden is mislukt: '.$exception->getMessage());
        }
    }

    /**
     * The parts of the VOD in the Creator SMP category during the server's opening hours, in seconds from the VOD start.
     *
     * @param  list<array{start_time: float, end_time: float, title: string}>|null  $chapters  null when Twitch has none
     * @return list<array{0: int, 1: int}> sorted, adjacent parts merged
     */
    private function creatorSmpRanges(Stream $stream, ?array $chapters): array
    {
        $vodStart = $stream->started_at->toImmutable();
        $vodEnd = $stream->ended_at?->toImmutable() ?? $vodStart;
        // Without chapters the category is unknown, so only the opening hours count.
        $category = $chapters === null || $chapters === []
            ? [[$vodStart, $vodEnd]]
            : collect($chapters)
                ->filter(fn (array $chapter) => strcasecmp($chapter['title'] ?? '', config('services.twitch.category')) === 0)
                ->map(fn (array $chapter) => [$vodStart->addSeconds((int) $chapter['start_time']), $vodStart->addSeconds((int) ceil($chapter['end_time']))])
                ->all();

        $parts = [];
        foreach (ServerHours::within($vodStart, $vodEnd) as [$open, $close]) {
            foreach ($category as [$start, $end]) {
                $start = max($start, $open);
                $end = min($end, $close);
                if ($start->lt($end)) {
                    $parts[] = [(int) $vodStart->diffInSeconds($start), (int) $vodStart->diffInSeconds($end)];
                }
            }
        }
        sort($parts);

        $ranges = [];
        foreach ($parts as [$start, $end]) {
            if ($ranges !== [] && $start <= $ranges[array_key_last($ranges)][1]) {
                $ranges[array_key_last($ranges)][1] = max($ranges[array_key_last($ranges)][1], $end);
            } else {
                $ranges[] = [$start, $end];
            }
        }

        return $ranges;
    }

    /** The percentage in a progress line from yt-dlp ("progress 120/1100"), or null for any other line. */
    public static function progressPercent(string $line): ?int
    {
        if (! preg_match('/^progress (\d+)\/(\d+)$/', trim($line), $parts) || (int) $parts[2] === 0) {
            return null;
        }

        // 100 is saved once the file is cut and stored.
        return min(99, intdiv(100 * (int) $parts[1], (int) $parts[2]));
    }

    /** Saves yt-dlp's progress at most every 2 seconds, so the table can show it. */
    private function trackProgress(Stream $stream): \Closure
    {
        $buffer = '';
        $saved = 0;
        $savedAt = 0.0;

        return function (string $type, string $output) use ($stream, &$buffer, &$saved, &$savedAt): void {
            $buffer .= $output;
            while (($end = strpos($buffer, "\n")) !== false) {
                $percent = self::progressPercent(substr($buffer, 0, $end));
                $buffer = substr($buffer, $end + 1);
                if ($percent !== null && $percent > $saved && microtime(true) - $savedAt >= 2) {
                    Stream::query()->whereKey($stream->id)->update(['video_download_progress' => $percent]);
                    [$saved, $savedAt] = [$percent, microtime(true)];
                }
            }
        };
    }

    private function failWithOutput(Stream $stream, string $errorOutput): void
    {
        $this->deletePartialFiles($stream);
        $messages = array_filter(explode("\n", trim($errorOutput)), fn (string $line) => self::progressPercent($line) === null);
        $error = trim((string) end($messages));
        $this->markAsFailed($stream, 'Downloaden is mislukt'.($error !== '' ? ': '.$error : '.'));
    }

    private function baseName(Stream $stream): string
    {
        return 'twitch-'.$stream->twitch_video_id;
    }

    // A failed or killed download leaves half files behind.
    private function deletePartialFiles(Stream $stream): void
    {
        foreach (glob(Storage::path("streams/{$stream->id}/video").'/'.$this->baseName($stream).'.*') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function markAsProcessing(Stream $stream): bool
    {
        return DB::transaction(function () use ($stream): bool {
            $locked = Stream::query()->whereKey($stream->id)->lockForUpdate()->first();
            if ($locked === null || $locked->video_download_status === 'processing' || $locked->video_path !== null) {
                return false;
            }

            $locked->forceFill(['video_download_status' => 'processing', 'video_download_progress' => 0, 'video_download_error' => null])->save();

            return true;
        });
    }

    private function markAsFailed(Stream $stream, string $message): void
    {
        $stream->forceFill(['video_download_status' => 'failed', 'video_download_error' => $message])->save();
    }
}
