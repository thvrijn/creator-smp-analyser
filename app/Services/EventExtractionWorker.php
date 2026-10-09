<?php

namespace App\Services;

use App\Models\Worker;
use RuntimeException;

class EventExtractionWorker
{
    public function __construct(private readonly WorkerPool $pool)
    {
    }

    /**
     * The story events of one part of a stream, and a short summary of what happens in the game in it.
     *
     * @param  array<int, array<string, mixed>>  $segments
     * @param  array{streamer?: string, players?: list<string>}  $context  who streams (the POV) and which players exist
     * @return array{events: array<int, mixed>, summary: string}
     */
    public function extract(Worker $worker, array $segments, array $context = []): array
    {
        $response = $this->post($worker, '/extract-events', ['segments' => $segments, 'context' => $context]);
        $events = $response['events'] ?? null;
        if (! is_array($events)) {
            throw new RuntimeException('De event-worker gaf een ongeldig antwoord.');
        }

        return ['events' => $events, 'summary' => is_string($response['summary'] ?? null) ? trim($response['summary']) : ''];
    }

    /**
     * The storyline of a whole stream from the summaries of its parts and its events.
     *
     * @param  list<array{start_time: float, end_time: float, summary: string}>  $parts
     * @param  list<array{start_time: float, title: string, description: string}>  $events
     * @param  array{streamer?: string, players?: list<string>}  $context
     * @return array{summary: string, players: list<string>}
     */
    public function summarizeStory(Worker $worker, array $parts, array $events, array $context = []): array
    {
        $response = $this->post($worker, '/summarize-story', ['parts' => $parts, 'events' => $events, 'context' => $context]);
        if (! is_string($response['summary'] ?? null)) {
            throw new RuntimeException('De event-worker gaf een ongeldig antwoord.');
        }
        $players = is_array($response['players'] ?? null) ? $response['players'] : [];

        return [
            'summary' => trim($response['summary']),
            'players' => array_values(array_filter(array_map(fn ($name) => is_string($name) ? trim($name) : '', $players))),
        ];
    }

    /** @return array<string, mixed> */
    private function post(Worker $worker, string $path, array $body): array
    {
        try {
            $response = $this->pool->request($worker, (int) config('services.event_worker.timeout', 1800))->post($path, $body);
        } catch (\Throwable $exception) {
            $this->pool->markUnreachable($worker);
            throw new RuntimeException("Worker {$worker->name} is niet bereikbaar: ".$exception->getMessage(), 0, $exception);
        }

        if ($response->status() === 422) {
            throw new InvalidModelOutputException((string) $response->json('error', 'Het eventmodel gaf onbruikbare output.'));
        }
        if ($response->failed()) {
            throw new RuntimeException((string) $response->json('error', 'De event-worker is mislukt.'));
        }

        return (array) $response->json();
    }
}
