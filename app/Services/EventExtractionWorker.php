<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class EventExtractionWorker
{
    /** @param array<int, array<string, mixed>> $segments */
    public function extract(array $segments): array
    {
        try {
            $response = Http::timeout((int) config('services.event_worker.timeout', 1800))
                ->post(rtrim(config('services.event_worker.url'), '/').'/extract-events', [
                    'segments' => $segments,
                ]);
        } catch (\Throwable $exception) {
            throw new RuntimeException('The event extraction worker could not be reached: '.$exception->getMessage(), 0, $exception);
        }

        if ($response->status() === 422) {
            throw new InvalidModelOutputException((string) $response->json('error', 'The event model returned unusable output.'));
        }
        if ($response->failed()) {
            throw new RuntimeException((string) $response->json('error', 'The event extraction worker failed.'));
        }

        $events = $response->json('events');
        if (! is_array($events)) {
            throw new RuntimeException('The event extraction worker returned an invalid events payload.');
        }

        return $events;
    }
}
