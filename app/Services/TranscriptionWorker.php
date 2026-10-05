<?php

namespace App\Services;

use App\Models\Stream;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TranscriptionWorker
{
    /**
     * @param  callable(array<string, mixed>): void|null  $onEvent
     * @return array<int, array{start_time: string, end_time: string, text: string}>
     */
    public function transcribe(Stream $stream, ?callable $onEvent = null): array
    {
        try {
            $response = Http::withOptions(['stream' => true])
                ->timeout((int) config('services.transcription_worker.timeout', 3600))
                ->post(rtrim(config('services.transcription_worker.url'), '/').'/transcribe', [
                    'stream_id' => $stream->id,
                    'video_path' => $stream->video_path,
                    // Only these parts, e.g. the Creator SMP part of a Twitch VOD; segment times stay file times.
                    ...($stream->transcription_ranges !== null ? ['ranges' => $stream->transcription_ranges] : []),
                ]);
        } catch (\Throwable $exception) {
            throw new RuntimeException('De transcriptie-worker is niet bereikbaar: '.$exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException((string) $response->json('error', 'De transcriptie-worker is mislukt.'));
        }

        $segments = [];
        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

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

        return $segments;
    }

    /**
     * @param  array<int, array{start_time: string, end_time: string, text: string}>  $segments
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
            $segment = $this->normaliseSegment($event);
            if ($segment !== null) {
                $segments[] = $segment;
            }
        }

        if ($onEvent !== null) {
            $onEvent($event);
        }
    }

    /** @return array{start_time: string, end_time: string, text: string}|null */
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
        ];
    }
}
