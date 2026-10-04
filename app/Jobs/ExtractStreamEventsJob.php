<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Stream;
use App\Services\EventExtractionWorker;
use App\Services\InvalidModelOutputException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ExtractStreamEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 1800;

    public function __construct(public readonly int $streamId)
    {
    }

    public function backoff(): array
    {
        return [30];
    }

    public function handle(EventExtractionWorker $worker): void
    {
        $stream = Stream::with('transcriptSegments')->find($this->streamId);
        if ($stream === null || ! $this->markAsProcessing($stream)) {
            return;
        }
        // markAsProcessing saved through a locked copy; reload so later status saves are not skipped as "unchanged".
        $stream->refresh();

        try {
            $segments = $stream->transcriptSegments->sortBy(['start_time', 'id'])->values();
            if ($segments->isEmpty()) {
                throw new RuntimeException('The stream has no transcript segments to analyse.');
            }

            $events = [];
            $skippedChunks = [];
            foreach ($this->chunks($segments->all()) as $number => $chunk) {
                $input = collect($chunk)->map(fn ($segment, $index) => [
                    'index' => $index,
                    'start_time' => (float) $segment->start_time,
                    'end_time' => (float) $segment->end_time,
                    'text' => $segment->text,
                ])->values()->all();

                try {
                    $workerEvents = $worker->extract($input);
                } catch (InvalidModelOutputException $exception) {
                    // Unusable model output only costs this chunk; an unreachable worker still fails the job.
                    $skippedChunks[] = sprintf('chunk %d (%s): %s', $number + 1, $this->chunkRange($chunk), $exception->getMessage());
                    continue;
                }

                foreach ($workerEvents as $event) {
                    $validated = $this->validateEvent($event, $chunk);
                    if ($validated === null) {
                        continue;
                    }
                    $this->addEvent($events, $validated);
                }
            }

            $this->replaceEvents($stream, array_values($events));
            $stream->forceFill([
                'event_extraction_status' => 'completed',
                'event_extraction_error' => $skippedChunks === [] ? null : 'Skipped '.count($skippedChunks).' chunk(s) with unusable model output: '.implode('; ', $skippedChunks),
                'event_extraction_completed_at' => now(),
            ])->save();
        } catch (\Throwable $exception) {
            $this->markAsFailed($stream, $exception->getMessage());
            throw $exception;
        }
    }

    /**
     * Replaces the stream's previous events in one transaction, so a re-run or retry never duplicates
     * events and a failed run leaves the previous result intact.
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    private function replaceEvents(Stream $stream, array $events): void
    {
        DB::transaction(function () use ($stream, $events): void {
            $stream->events()->delete();
            foreach ($events as $event) {
                $eventModel = Event::create([
                    'stream_id' => $stream->id,
                    'type' => $event['type'],
                    'title' => $event['title'],
                    'description' => $event['description'],
                    'start_time' => number_format($event['start_time'], 3, '.', ''),
                    'end_time' => number_format($event['end_time'], 3, '.', ''),
                    'confidence' => $event['confidence'],
                ]);
                $eventModel->transcriptSegments()->sync($event['segment_ids']);
            }
        });
    }

    /** @param array<int, \App\Models\TranscriptSegment> $chunk */
    private function chunkRange(array $chunk): string
    {
        $format = fn (float $seconds) => gmdate('H:i:s', (int) $seconds);

        return $format((float) $chunk[0]->start_time).'-'.$format((float) $chunk[array_key_last($chunk)]->end_time);
    }

    public function failed(\Throwable $exception): void
    {
        $stream = Stream::find($this->streamId);
        if ($stream !== null) {
            $this->markAsFailed($stream, $exception->getMessage());
        }
    }

    /** @param array<int, \App\Models\TranscriptSegment> $segments @return array<int, array<int, \App\Models\TranscriptSegment>> */
    private function chunks(array $segments): array
    {
        $size = (float) config('services.event_worker.chunk_seconds', 90);
        $overlap = min((float) config('services.event_worker.overlap_seconds', 15), max(0, $size - 1));
        $chunks = [];
        $cursor = (float) $segments[0]->start_time;
        $lastStart = null;

        while ($cursor <= (float) $segments[array_key_last($segments)]->end_time) {
            $end = $cursor + $size;
            $chunk = array_values(array_filter($segments, fn ($segment) => (float) $segment->end_time > $cursor && (float) $segment->start_time < $end));
            if ($chunk !== []) {
                $chunks[] = $chunk;
            }
            $next = $end - $overlap;
            if ($lastStart !== null && $next <= $lastStart) {
                break;
            }
            $lastStart = $cursor;
            $cursor = $next;
        }

        return $chunks;
    }

    /**
     * Validates one worker event against its chunk. Invalid events are skipped (and logged), never stored.
     * Timestamps are derived from the referenced segments, never taken from the model.
     *
     * @param  array<int, \App\Models\TranscriptSegment>  $chunk
     * @return array<string, mixed>|null
     */
    private function validateEvent(mixed $event, array $chunk): ?array
    {
        $types = ['player_encounter', 'combat', 'death', 'discovery', 'item', 'building', 'destruction', 'conversation', 'statement', 'other'];
        $indexes = is_array($event) ? ($event['segment_indexes'] ?? null) : null;
        $confidence = is_array($event) ? ($event['confidence'] ?? null) : null;

        $reason = match (true) {
            ! is_array($event) => 'not an object',
            ! in_array($event['type'] ?? null, $types, true), blank($event['title'] ?? null), blank($event['description'] ?? null) => 'invalid type, title or description',
            ! is_array($indexes) || $indexes === [] || collect($indexes)->contains(fn ($index) => ! is_int($index) || ! array_key_exists($index, $chunk)) => 'invalid segment index',
            count(array_unique($indexes)) > (int) config('services.event_worker.max_segments', 12) => 'too many segments (chunk summary)',
            ! is_int($confidence) && ! is_float($confidence) || $confidence < 0 || $confidence > 1 => 'confidence outside 0-1',
            default => null,
        };
        if ($reason !== null) {
            Log::warning('Skipped invalid extracted event: '.$reason, ['stream_id' => $this->streamId, 'event' => $event]);

            return null;
        }

        $referenced = collect(array_unique($indexes))->sort()->map(fn (int $index) => $chunk[$index]);

        return [
            'type' => (string) $event['type'],
            'title' => mb_substr(trim((string) $event['title']), 0, 255),
            'description' => trim((string) $event['description']),
            'start_time' => (float) $referenced->min(fn ($segment) => (float) $segment->start_time),
            'end_time' => (float) $referenced->max(fn ($segment) => (float) $segment->end_time),
            'confidence' => (float) $confidence,
            'segment_ids' => $referenced->pluck('id')->sort()->values()->all(),
        ];
    }

    /**
     * Overlapping chunks can report the same moment with a different title or type. Events that share at least
     * half of the smaller event's segments are the same moment; the one with the highest confidence is kept.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @param  array<string, mixed>  $candidate
     */
    private function addEvent(array &$events, array $candidate): void
    {
        foreach ($events as $index => $existing) {
            $shared = count(array_intersect($existing['segment_ids'], $candidate['segment_ids']));
            if ($shared * 2 >= min(count($existing['segment_ids']), count($candidate['segment_ids']))) {
                if ($candidate['confidence'] > $existing['confidence']) {
                    $events[$index] = $candidate;
                }

                return;
            }
        }
        $events[] = $candidate;
    }

    private function markAsProcessing(Stream $stream): bool
    {
        return DB::transaction(function () use ($stream): bool {
            $locked = Stream::query()->whereKey($stream->id)->lockForUpdate()->first();
            if ($locked === null || $locked->event_extraction_status === 'processing') {
                return false;
            }
            $locked->forceFill([
                'event_extraction_status' => 'processing',
                'event_extraction_error' => null,
                'event_extraction_started_at' => now(),
                'event_extraction_completed_at' => null,
            ])->save();
            return true;
        });
    }

    private function markAsFailed(Stream $stream, string $message): void
    {
        $stream->forceFill([
            'event_extraction_status' => 'failed',
            'event_extraction_error' => $message !== '' ? $message : 'The event extraction job failed.',
        ])->save();
    }
}
