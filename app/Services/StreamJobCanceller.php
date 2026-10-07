<?php

namespace App\Services;

use App\Jobs\DownloadVodJob;
use App\Jobs\JobCancelled;
use App\Models\Stream;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a stream's transcription, analysis or audio download. A job still in the queue (or waiting for a worker) is
 * cancelled at once: its status goes back and the job skips itself when it starts. A running job gets a cancel request
 * that it checks while working (throwIfRequested); it then stops and calls restore(). Either way the previous result
 * stays: a transcription or analysis only replaces the old segments or events when it completes.
 *
 * Tasks are the status column prefixes: transcription, event_extraction, video_download.
 */
class StreamJobCanceller
{
    public const TASKS = ['transcription', 'event_extraction', 'video_download'];

    /** @return array{0: bool, 1: string} whether it worked, and the message for the user */
    public function cancel(Stream $stream, string $task): array
    {
        $status = "{$task}_status";

        $outcome = DB::transaction(function () use ($stream, $task, $status): string {
            $locked = Stream::query()->whereKey($stream->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->{$status}, ['queued', 'waiting'], true) || $locked->isStalled($status)) {
                $this->restore($locked, $task);

                return 'cancelled';
            }
            if ($locked->{$status} === 'processing') {
                $locked->forceFill(["{$task}_cancel_requested_at" => now()])->save();

                return 'requested';
            }

            return 'idle';
        });

        return match ($outcome) {
            'cancelled' => [true, 'Geannuleerd.'],
            'requested' => [true, 'Wordt geannuleerd. De job stopt binnen enkele seconden.'],
            default => [false, 'Er loopt hier niets om te annuleren.'],
        };
    }

    /** Called by a running job now and then; throws once the run was cancelled. */
    public function throwIfRequested(Stream $stream, string $task): void
    {
        if (Stream::query()->whereKey($stream->id)->whereNotNull("{$task}_cancel_requested_at")->exists()) {
            throw new JobCancelled;
        }
    }

    /** Puts the task back where it was before it was queued, as far as the stored result tells. */
    public function restore(Stream $stream, string $task): void
    {
        // A running job saves its progress with queries, so the model may be stale; dirty checks need the real values.
        $stream->refresh();
        $stream->forceFill(["{$task}_cancel_requested_at" => null, ...match ($task) {
            'transcription' => $this->transcriptionState($stream),
            'event_extraction' => $this->eventExtractionState($stream),
            'video_download' => $this->videoDownloadState($stream),
        }])->save();
    }

    /** @return array<string, mixed> */
    private function transcriptionState(Stream $stream): array
    {
        $segments = $stream->transcriptSegments();
        $count = $segments->count();
        if ($count === 0) {
            return [
                'transcription_status' => 'pending', 'transcription_stage' => 'not_started', 'transcription_error' => null,
                'transcription_progress' => 0, 'transcription_processed_seconds' => 0, 'transcription_duration_seconds' => null,
                'transcription_segment_count' => 0, 'transcription_started_at' => null, 'transcription_eta_seconds' => null,
                'transcribed_at' => null,
            ];
        }

        // The old transcript stays; its length is that of the transcribed parts, or up to the last segment.
        $duration = $stream->transcription_ranges !== null
            ? array_sum(array_map(fn (array $range) => max(0, $range[1] - $range[0]), $stream->transcription_ranges))
            : (float) $segments->max('end_time');

        return [
            'transcription_status' => 'completed', 'transcription_stage' => 'completed', 'transcription_error' => null,
            'transcription_progress' => 100, 'transcription_processed_seconds' => $duration, 'transcription_duration_seconds' => $duration,
            'transcription_segment_count' => $count, 'transcription_eta_seconds' => 0,
            'transcribed_at' => $stream->transcriptSegments()->max('created_at'),
        ];
    }

    /** @return array<string, mixed> */
    private function eventExtractionState(Stream $stream): array
    {
        $hasEvents = $stream->events()->exists();

        return [
            'event_extraction_status' => $hasEvents ? 'completed' : 'pending',
            'event_extraction_error' => null,
            'event_extraction_chunks_done' => 0,
            'event_extraction_chunks_total' => null,
            'event_extraction_completed_at' => $hasEvents ? $stream->events()->max('created_at') : null,
        ];
    }

    /** @return array<string, mixed> */
    private function videoDownloadState(Stream $stream): array
    {
        DownloadVodJob::deletePartialFiles($stream);

        return ['video_download_status' => 'pending', 'video_download_progress' => 0, 'video_download_error' => null];
    }
}
