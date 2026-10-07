<?php

namespace App\Jobs;

use App\Jobs\Concerns\WaitsForWorker;
use App\Models\Stream;
use App\Models\Worker;
use App\Services\StreamJobCanceller;
use App\Services\TranscriptionWorker;
use App\Services\WorkerPool;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TranscribeStreamJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WaitsForWorker;

    // Real errors; waiting for a free worker does not count (see WaitsForWorker).
    public int $maxExceptions = 3;

    public int $timeout = 3600;

    public function __construct(public readonly int $streamId) {}

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(TranscriptionWorker $transcriber, WorkerPool $pool, StreamJobCanceller $canceller): void
    {
        $stream = Stream::find($this->streamId);
        // Not startable: running elsewhere, done, or cancelled while queued.
        if ($stream === null || ! in_array($stream->transcription_status, Stream::STARTABLE_STATUSES, true)) {
            return;
        }
        $worker = $this->claimWorkerOrWait($pool, $stream, 'transcribe', 'transcription_status', ['transcription_stage' => 'waiting_for_worker']);
        if ($worker === null) {
            return;
        }

        try {
            $this->transcribe($transcriber, $canceller, $worker, $stream);
        } finally {
            $pool->release($worker);
        }
    }

    private function transcribe(TranscriptionWorker $transcriber, StreamJobCanceller $canceller, Worker $worker, Stream $stream): void
    {
        if (! $this->markAsProcessing($stream)) {
            return;
        }
        // markAsProcessing saved through a locked copy; reload so later status saves are not skipped as "unchanged".
        $stream->refresh();

        if (blank($stream->video_path)) {
            $this->markAsFailed($stream, 'The stream has no video file.');

            return;
        }

        $disk = Storage::disk(config('filesystems.default'));
        if (! $disk->exists($stream->video_path)) {
            $this->markAsFailed($stream, "The stream video does not exist: {$stream->video_path}");

            return;
        }

        $lastProgress = -1;
        $lastUpdateAt = microtime(true);
        $phaseStartedAt = microtime(true);
        $phaseStartedProcessed = 0.0;
        $lastStage = null;
        $durationSeconds = null;
        $speakers = null;
        $cancelCheckedAt = microtime(true);
        try {
            $segments = $transcriber->transcribe($worker, $stream, function (array $event) use ($stream, $canceller, &$cancelCheckedAt, &$lastProgress, &$lastUpdateAt, &$phaseStartedAt, &$phaseStartedProcessed, &$lastStage, &$durationSeconds, &$speakers): void {
                // Cancelled in the UI? Checked every 2 seconds; throwing closes the connection, which stops the worker too.
                if (microtime(true) - $cancelCheckedAt >= 2) {
                    $canceller->throwIfRequested($stream, 'transcription');
                    $cancelCheckedAt = microtime(true);
                }
                $type = $event['type'] ?? null;
                if ($type === 'speakers') {
                    $speakers = $this->speakers($event['speakers'] ?? null);

                    return;
                }
                if ($type === 'warning') {
                    // E.g. diarization failed: the transcript is kept without speakers.
                    Log::warning("Transcription of stream {$stream->id}: ".($event['message'] ?? 'warning'));

                    return;
                }
                $stage = isset($event['stage']) ? (string) $event['stage'] : null;
                $progress = isset($event['progress']) ? max(0, min(100, (int) round((float) $event['progress']))) : null;
                $force = in_array($type, ['stage', 'duration'], true);
                $now = microtime(true);

                if (isset($event['duration_seconds']) && is_numeric($event['duration_seconds'])) {
                    $durationSeconds = max(0, (float) $event['duration_seconds']);
                }

                if ($stage !== null && $stage !== $lastStage) {
                    $lastStage = $stage;
                    $phaseStartedAt = $now;
                    $phaseStartedProcessed = isset($event['processed_seconds']) && is_numeric($event['processed_seconds'])
                        ? max(0, (float) $event['processed_seconds'])
                        : 0.0;
                    $lastProgress = -1;
                }

                if (! $force && $progress !== null && $progress === $lastProgress && ($now - $lastUpdateAt) < 1.0) {
                    return;
                }
                if (! $force && $progress !== null && $progress < $lastProgress && ($now - $lastUpdateAt) < 1.0) {
                    return;
                }

                $updates = [];
                if ($stage !== null) {
                    $updates['transcription_stage'] = $stage;
                }
                if ($progress !== null) {
                    $updates['transcription_progress'] = $progress;
                    $lastProgress = $progress;
                }
                if (isset($event['processed_seconds']) && is_numeric($event['processed_seconds'])) {
                    $updates['transcription_processed_seconds'] = max(0, (float) $event['processed_seconds']);
                }
                if (isset($event['duration_seconds']) && is_numeric($event['duration_seconds'])) {
                    $updates['transcription_duration_seconds'] = max(0, (float) $event['duration_seconds']);
                }
                if (isset($event['segment_count']) && is_numeric($event['segment_count'])) {
                    $updates['transcription_segment_count'] = max(0, (int) $event['segment_count']);
                }
                if ($durationSeconds !== null && isset($event['processed_seconds']) && is_numeric($event['processed_seconds'])) {
                    $duration = $durationSeconds;
                    $processed = max(0, min($duration, (float) $event['processed_seconds']));
                    $elapsed = $now - $phaseStartedAt;
                    $processedInPhase = max(0, $processed - $phaseStartedProcessed);
                    if ($processedInPhase > 0 && $elapsed > 0 && $processed < $duration) {
                        $updates['transcription_eta_seconds'] = max(0, ($duration - $processed) / ($processedInPhase / $elapsed));
                    } elseif ($processed >= $duration && $duration > 0) {
                        $updates['transcription_eta_seconds'] = 0;
                    }
                }
                if ($updates !== []) {
                    Stream::whereKey($stream->id)->update($updates);
                    $lastUpdateAt = $now;
                }
            });
            $canceller->throwIfRequested($stream, 'transcription');
        } catch (JobCancelled) {
            // The previous transcript (if any) stays.
            $canceller->restore($stream, 'transcription');

            return;
        } catch (\Throwable $exception) {
            $this->markAsFailed($stream, $exception->getMessage());
            throw $exception;
        }

        DB::transaction(function () use ($stream, $segments, $speakers): void {
            $latest = Stream::findOrFail($stream->id);
            $latest->transcriptSegments()->delete();
            // New speaker numbers: names given to the old ones no longer apply.
            $latest->speakerNames()->delete();
            $latest->transcriptSegments()->createMany($segments);
            $latest->forceFill([
                'transcription_status' => 'completed',
                'transcription_error' => null,
                'transcribed_at' => now(),
                'transcription_stage' => 'completed',
                'transcription_progress' => 100,
                'transcription_processed_seconds' => $latest->transcription_duration_seconds ?? $latest->transcription_processed_seconds,
                'transcription_segment_count' => count($segments),
                'transcription_eta_seconds' => 0,
                'transcription_speakers' => $speakers,
            ])->save();
        });
    }

    /**
     * The worker's speaker summary: per speaker its number, speaking time and voice embedding.
     *
     * @return array<int, array{speaker: int, seconds: float, embedding: array<int, float>|null}>|null
     */
    private function speakers(mixed $speakers): ?array
    {
        if (! is_array($speakers)) {
            return null;
        }

        return collect($speakers)
            ->filter(fn ($speaker) => is_array($speaker) && is_int($speaker['speaker'] ?? null) && is_numeric($speaker['seconds'] ?? null))
            ->map(fn (array $speaker) => [
                'speaker' => $speaker['speaker'],
                'seconds' => (float) $speaker['seconds'],
                'embedding' => is_array($speaker['embedding'] ?? null) ? array_map('floatval', $speaker['embedding']) : null,
            ])
            ->values()
            ->all();
    }

    public function failed(\Throwable $exception): void
    {
        $stream = Stream::find($this->streamId);
        if ($stream !== null) {
            $this->markAsFailed($stream, $exception->getMessage());
        }
    }

    private function markAsProcessing(Stream $stream): bool
    {
        return DB::transaction(function () use ($stream): bool {
            $locked = Stream::query()->whereKey($stream->id)->lockForUpdate()->first();
            // Completed: a late retry of a killed attempt after the stream was transcribed again; it would replace the segments.
            // Pending: cancelled while queued.
            if ($locked === null || ! in_array($locked->transcription_status, Stream::STARTABLE_STATUSES, true)) {
                return false;
            }
            $locked->forceFill([
                'transcription_status' => 'processing',
                'transcription_error' => null,
                'transcribed_at' => null,
                'transcription_stage' => 'extracting_audio',
                'transcription_progress' => 0,
                'transcription_processed_seconds' => 0,
                'transcription_duration_seconds' => null,
                'transcription_segment_count' => 0,
                'transcription_started_at' => now(),
                'transcription_eta_seconds' => null,
                'transcription_cancel_requested_at' => null,
            ])->save();

            return true;
        });
    }

    private function markAsFailed(Stream $stream, string $message): void
    {
        $stream->forceFill([
            'transcription_status' => 'failed',
            'transcription_stage' => 'failed',
            'transcription_error' => $message !== '' ? $message : 'De transcriptie is mislukt.',
            'transcription_eta_seconds' => null,
        ])->save();
    }
}
