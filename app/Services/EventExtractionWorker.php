<?php

namespace App\Services;

use App\Models\Worker;
use RuntimeException;

class EventExtractionWorker
{
    public function __construct(private readonly WorkerPool $pool)
    {
    }

    /** @param array<int, array<string, mixed>> $segments */
    public function extract(Worker $worker, array $segments): array
    {
        try {
            $response = $this->pool->request($worker, (int) config('services.event_worker.timeout', 1800))
                ->post('/extract-events', ['segments' => $segments]);
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

        $events = $response->json('events');
        if (! is_array($events)) {
            throw new RuntimeException('De event-worker gaf een ongeldig antwoord.');
        }

        return $events;
    }
}
