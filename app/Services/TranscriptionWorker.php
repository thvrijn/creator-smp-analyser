<?php

namespace App\Services;

use App\Models\Stream;
use App\Models\Worker;
use RuntimeException;

class TranscriptionWorker
{
    private int $segmentIndex = 0;

    public function __construct(private readonly WorkerPool $pool)
    {
    }

    /**
     * Segments get the speaker from the worker's `speakers` event (null without diarization); $onEvent sees that event too.
     *
     * @param  callable(array<string, mixed>): void|null  $onEvent
     * @return array<int, array{start_time: string, end_time: string, text: string, speaker: int|null}>
     */
    public function transcribe(Worker $worker, Stream $stream, ?callable $onEvent = null): array
    {
        try {
            $response = $this->pool->request($worker, (int) config('services.transcription_worker.timeout', 3600))
                ->withOptions(['stream' => true])
                ->post('/transcribe', [
                    'stream_id' => $stream->id,
                    // A worker that shares the app's storage reads video_path; any other downloads video_url.
                    'video_path' => $stream->video_path,
                    'video_url' => $this->pool->fileUrl($stream),
                    'video_size' => $stream->video_file_size,
                    // Only these parts, e.g. the Creator SMP part of a Twitch VOD; segment times stay file times.
                    ...($stream->transcription_ranges !== null ? ['ranges' => $stream->transcription_ranges] : []),
                ]);
        } catch (\Throwable $exception) {
            $this->pool->markUnreachable($worker);
            throw new RuntimeException("Worker {$worker->name} is niet bereikbaar: ".$exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException((string) $response->json('error', 'De transcriptie-worker is mislukt.'));
        }

        $this->segmentIndex = 0;
        // Keyed by the worker's segment index, so the `speakers` event lines up even when a segment is dropped here.
        $segments = [];
        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        try {
            while (! $body->eof()) {
                $buffer .= $body->read(8192);

                while (($newline = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $newline));
                    $buffer = substr($buffer, $newline + 1);
                    if ($line === '') {
                        continue;
                    }
                    $event = json_decode($line, true);
                    if (! is_array($event)) {
                        throw new RuntimeException('De transcriptie-worker gaf ongeldige voortgangsdata.');
                    }
                    $this->handleEvent($event, $segments, $onEvent);
                }
            }
        } finally {
            // Also when $onEvent throws (e.g. a cancelled job): the worker notices the closed connection and stops.
            $body->close();
        }

        if (trim($buffer) !== '') {
            $event = json_decode(trim($buffer), true);
            if (! is_array($event)) {
                throw new RuntimeException('De transcriptie-worker gaf onvolledige voortgangsdata.');
            }
            $this->handleEvent($event, $segments, $onEvent);
        }

        if ($segments === []) {
            throw new RuntimeException('De transcriptie leverde geen tekst op.');
        }

        return array_values($segments);
    }

    /**
     * @param  array<int, array{start_time: string, end_time: string, text: string, speaker: int|null}>  $segments
     * @param  callable(array<string, mixed>): void|null  $onEvent
     */
    private function handleEvent(array $event, array &$segments, ?callable $onEvent): void
    {
        // Keep the Step 8 JSON response compatible with local/debug fakes.
        if (isset($event['segments']) && is_array($event['segments'])) {
            foreach ($event['segments'] as $segment) {
                if (is_array($segment)) {
                    $normalised = $this->normaliseSegment($segment);
                    if ($normalised !== null) {
                        $segments[] = $normalised;
                    }
                }
            }
            return;
        }

        if (($event['type'] ?? null) === 'error') {
            throw new RuntimeException((string) ($event['error'] ?? 'De transcriptie-worker is mislukt.'));
        }

        if (($event['type'] ?? null) === 'segment') {
            $index = $this->segmentIndex++;
            $segment = $this->normaliseSegment($event);
            if ($segment !== null) {
                $segments[$index] = $segment;
            }
        }

        if (($event['type'] ?? null) === 'speakers' && is_array($event['segment_speakers'] ?? null)) {
            foreach ($event['segment_speakers'] as $index => $speaker) {
                if (isset($segments[$index])) {
                    $segments[$index]['speaker'] = is_int($speaker) && $speaker >= 0 ? $speaker : null;
                }
            }
        }

        if ($onEvent !== null) {
            $onEvent($event);
        }
    }

    /** @return array{start_time: string, end_time: string, text: string, speaker: int|null}|null */
    private function normaliseSegment(array $segment): ?array
    {
        if (! is_numeric($segment['start'] ?? null) || ! is_numeric($segment['end'] ?? null)) {
            return null;
        }

        $text = trim((string) ($segment['text'] ?? ''));
        if ($text === '') {
            return null;
        }

        return [
            'start_time' => number_format((float) $segment['start'], 3, '.', ''),
            'end_time' => number_format((float) $segment['end'], 3, '.', ''),
            'text' => $text,
            'speaker' => is_int($segment['speaker'] ?? null) && $segment['speaker'] >= 0 ? $segment['speaker'] : null,
        ];
    }
}
