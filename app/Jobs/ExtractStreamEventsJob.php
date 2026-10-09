<?php

namespace App\Jobs;

use App\Jobs\Concerns\WaitsForWorker;
use App\Models\Event;
use App\Models\Player;
use App\Models\Stream;
use App\Models\TranscriptSegment;
use App\Models\Worker;
use App\Services\EventExtractionWorker;
use App\Services\InvalidModelOutputException;
use App\Services\StreamJobCanceller;
use App\Services\StreamSpeakers;
use App\Services\WorkerPool;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WaitsForWorker;

    // Real errors; waiting for a free worker does not count (see WaitsForWorker).
    public int $maxExceptions = 2;

    public int $timeout = 1800;

    /** An event this close after another, told with this share of the same word pairs, continues it (addEvent). */
    private const CONTINUE_GAP_SECONDS = 60;

    private const CONTINUE_MIN_SHARED = 0.35;

    public function __construct(public readonly int $streamId) {}

    public function backoff(): array
    {
        return [30];
    }

    public function handle(EventExtractionWorker $extractor, WorkerPool $pool, StreamJobCanceller $canceller): void
    {
        $stream = Stream::find($this->streamId);
        // Not startable: running elsewhere, done, or cancelled while queued.
        if ($stream === null || ! in_array($stream->event_extraction_status, Stream::STARTABLE_STATUSES, true)) {
            return;
        }
        // One worker for all chunks, so its model stays loaded.
        $worker = $this->claimWorkerOrWait($pool, $stream, 'extract', 'event_extraction_status');
        if ($worker === null) {
            return;
        }

        try {
            $this->extract($extractor, $canceller, $worker, $stream->load(['transcriptSegments', 'player']));
        } finally {
            $pool->release($worker);
        }
    }

    private function extract(EventExtractionWorker $extractor, StreamJobCanceller $canceller, Worker $worker, Stream $stream): void
    {
        if (! $this->markAsProcessing($stream)) {
            return;
        }
        // markAsProcessing saved through a locked copy; reload so later status saves are not skipped as "unchanged".
        $stream->refresh();

        try {
            $segments = $stream->transcriptSegments->sortBy(['start_time', 'id'])->values();
            if ($segments->isEmpty()) {
                throw new RuntimeException('Deze stream heeft geen transcriptsegmenten om te analyseren.');
            }

            $events = [];
            $parts = [];
            $skippedChunks = [];
            $chunks = $this->chunks($segments->all());
            $context = $this->context($stream);
            $speakerNames = collect(app(StreamSpeakers::class)->list($stream))->pluck('name', 'speaker');
            // One step per chunk, plus the storyline of the whole stream at the end.
            $stream->forceFill(['event_extraction_chunks_done' => 0, 'event_extraction_chunks_total' => count($chunks) + 1])->save();
            foreach ($chunks as $number => $chunk) {
                // Cancelled in the UI? Stops between chunks.
                $canceller->throwIfRequested($stream, 'event_extraction');
                $input = collect($chunk)->map(fn ($segment, $index) => [
                    'index' => $index,
                    'start_time' => (float) $segment->start_time,
                    'end_time' => (float) $segment->end_time,
                    'text' => $segment->text,
                    // Who speaks, so the model knows who says what (the streamer, a recognised player, "Spreker n").
                    'speaker' => $segment->speaker === null ? null : ($speakerNames[$segment->speaker] ?? "Spreker {$segment->speaker}"),
                ])->values()->all();

                try {
                    $result = $extractor->extract($worker, $input, $context);
                } catch (InvalidModelOutputException $exception) {
                    // Unusable model output only costs this chunk; an unreachable worker still fails the job.
                    $skippedChunks[] = sprintf('chunk %d (%s): %s', $number + 1, $this->chunkRange($chunk), $exception->getMessage());
                    $result = ['events' => [], 'summary' => ''];
                }
                if ($result['summary'] !== '') {
                    $parts[] = ['start_time' => (float) $chunk[0]->start_time, 'end_time' => (float) $chunk[array_key_last($chunk)]->end_time, 'summary' => $result['summary']];
                }

                foreach ($result['events'] as $event) {
                    $validated = $this->validateEvent($event, $chunk);
                    if ($validated === null) {
                        continue;
                    }
                    $this->addEvent($events, $validated);
                }
                // Progress; saving also touches the stream, so it does not count as stalled (Stream::isStalled()).
                $stream->forceFill(['event_extraction_chunks_done' => $number + 1])->save();
            }

            $canceller->throwIfRequested($stream, 'event_extraction');
            $events = collect($events)->sortBy('start_time')->values()->all();
            $notes = $skippedChunks === [] ? [] : [count($skippedChunks).' chunk(s) overgeslagen door onbruikbare modeloutput: '.implode('; ', $skippedChunks)];
            $story = $this->story($extractor, $worker, $parts, $events, $context, $notes);
            $canceller->throwIfRequested($stream, 'event_extraction');

            DB::transaction(function () use ($stream, $events, $parts, $story): void {
                $this->replaceEvents($stream, $events);
                $stream->forceFill(['story_summary' => $story['summary'], 'story_players' => $story['players'], 'story_parts' => $parts])->save();
            });
            $stream->forceFill([
                'event_extraction_status' => 'completed',
                'event_extraction_error' => $notes === [] ? null : implode(' ', $notes),
                'event_extraction_completed_at' => now(),
                'event_extraction_chunks_done' => count($chunks) + 1,
            ])->save();
        } catch (JobCancelled) {
            // The previous events (if any) stay.
            $canceller->restore($stream, 'event_extraction');
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

    /**
     * The storyline of the whole stream from the part summaries and events. A failure here costs only the story: the
     * events are kept and the reason is added to the notes.
     *
     * @param  list<array{start_time: float, end_time: float, summary: string}>  $parts
     * @param  list<array<string, mixed>>  $events
     * @param  list<string>  $notes
     * @return array{summary: ?string, players: list<string>}
     */
    private function story(EventExtractionWorker $extractor, Worker $worker, array $parts, array $events, array $context, array &$notes): array
    {
        if ($parts === [] && $events === []) {
            return ['summary' => null, 'players' => []];
        }
        try {
            $story = $extractor->summarizeStory($worker, $parts, array_map(fn (array $event) => [
                'start_time' => $event['start_time'],
                'title' => $event['title'],
                'description' => $event['description'],
            ], $events), $context);

            return ['summary' => $story['summary'] !== '' ? $story['summary'] : null, 'players' => $story['players']];
        } catch (\Throwable $exception) {
            Log::warning('Writing the storyline failed', ['stream_id' => $this->streamId, 'error' => $exception->getMessage()]);
            $notes[] = 'Het verhaal van de stream kon niet worden geschreven: '.$exception->getMessage();

            return ['summary' => null, 'players' => []];
        }
    }

    /**
     * Who streams (the POV) and every player on the server, so the model names players with the right spelling.
     *
     * @return array{streamer: string, players: list<string>}
     */
    private function context(Stream $stream): array
    {
        return [
            'streamer' => $stream->player->name,
            'players' => Player::query()->orderByRaw('lower(name)')->pluck('name')->all(),
        ];
    }

    /** @param array<int, TranscriptSegment> $chunk */
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

    /** @param array<int, TranscriptSegment> $segments @return array<int, array<int, \App\Models\TranscriptSegment>> */
    private function chunks(array $segments): array
    {
        $size = (float) config('services.event_worker.chunk_seconds', 300);
        $overlap = min((float) config('services.event_worker.overlap_seconds', 60), max(0, $size - 1));
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
     * @param  array<int, TranscriptSegment>  $chunk
     * @return array<string, mixed>|null
     */
    private function validateEvent(mixed $event, array $chunk): ?array
    {
        // Same list as EVENT_TYPES in worker/event_extractor.py.
        $types = ['player_encounter', 'conversation', 'teamwork', 'conflict', 'combat', 'death', 'trade', 'discovery', 'building', 'destruction', 'plot', 'other'];
        $indexes = is_array($event) ? ($event['segment_indexes'] ?? null) : null;
        $confidence = is_array($event) ? ($event['confidence'] ?? null) : null;

        $reason = match (true) {
            ! is_array($event) => 'not an object',
            ! in_array($event['type'] ?? null, $types, true), blank($event['title'] ?? null), blank($event['description'] ?? null) => 'invalid type, title or description',
            ! is_array($indexes) || $indexes === [] || collect($indexes)->contains(fn ($index) => ! is_int($index) || ! array_key_exists($index, $chunk)) => 'invalid segment index',
            count(array_unique($indexes)) < (int) config('services.event_worker.min_segments', 2) => 'too few segments (a remark, not an event)',
            count(array_unique($indexes)) > (int) config('services.event_worker.max_segments', 200) => 'too many segments (chunk summary)',
            self::coversWholeChunk(count(array_unique($indexes)), count($chunk)) => 'covers the whole chunk (that is its summary)',
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
     * Same rule as validate_event in worker/event_extractor.py: an event over (almost) all lines of a real part is the
     * part's summary again, not a happening in it. Short parts (the end of a stream) are exempt.
     */
    public static function coversWholeChunk(int $segments, int $chunkSize): bool
    {
        return $chunkSize >= 40 && $segments > 0.8 * $chunkSize;
    }

    /**
     * Overlapping chunks can report the same moment with a different title or type. Events that share at least
     * half of the smaller event's segments are the same moment; the one with the highest confidence is kept.
     * A long happening (a meeting of half an hour) is reported again in each next chunk: an event that starts by
     * the end of an earlier one and is described with mostly the same words continues it, and they become one event.
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
        foreach ($events as $index => $existing) {
            if ($this->continues($existing, $candidate)) {
                $events[$index] = [
                    ...$existing,
                    'start_time' => min($existing['start_time'], $candidate['start_time']),
                    'end_time' => max($existing['end_time'], $candidate['end_time']),
                    'confidence' => max($existing['confidence'], $candidate['confidence']),
                    'segment_ids' => collect([...$existing['segment_ids'], ...$candidate['segment_ids']])->unique()->sort()->values()->all(),
                ];

                return;
            }
        }
        $events[] = $candidate;
    }

    /** @param array<string, mixed> $existing @param array<string, mixed> $candidate */
    private function continues(array $existing, array $candidate): bool
    {
        $adjacent = $candidate['start_time'] <= $existing['end_time'] + self::CONTINUE_GAP_SECONDS
            && $existing['start_time'] <= $candidate['end_time'] + self::CONTINUE_GAP_SECONDS;
        if (! $adjacent) {
            return false;
        }
        $a = $this->wordPairs($existing['title'].' '.$existing['description']);
        $b = $this->wordPairs($candidate['title'].' '.$candidate['description']);
        $smaller = min(count($a), count($b));

        return $smaller > 0 && count(array_intersect_key($a, $b)) >= self::CONTINUE_MIN_SHARED * $smaller;
    }

    /** @return array<string, true> */
    private function wordPairs(string $text): array
    {
        preg_match_all('/\w+/u', mb_strtolower($text), $matches);
        $words = $matches[0];
        $pairs = [];
        for ($i = 0; $i + 1 < count($words); $i++) {
            $pairs[$words[$i].' '.$words[$i + 1]] = true;
        }

        return $pairs;
    }

    private function markAsProcessing(Stream $stream): bool
    {
        return DB::transaction(function () use ($stream): bool {
            $locked = Stream::query()->whereKey($stream->id)->lockForUpdate()->first();
            // Completed: a late retry of a killed attempt after the stream was analysed again. Pending: cancelled while queued.
            if ($locked === null || ! in_array($locked->event_extraction_status, Stream::STARTABLE_STATUSES, true)) {
                return false;
            }
            $locked->forceFill([
                'event_extraction_status' => 'processing',
                'event_extraction_error' => null,
                'event_extraction_chunks_done' => 0,
                'event_extraction_chunks_total' => null,
                'event_extraction_started_at' => now(),
                'event_extraction_completed_at' => null,
                'event_extraction_cancel_requested_at' => null,
            ])->save();

            return true;
        });
    }

    private function markAsFailed(Stream $stream, string $message): void
    {
        $stream->forceFill([
            'event_extraction_status' => 'failed',
            'event_extraction_error' => $message !== '' ? $message : 'De event-extractie is mislukt.',
        ])->save();
    }
}
