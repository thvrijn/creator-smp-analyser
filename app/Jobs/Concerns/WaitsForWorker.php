<?php

namespace App\Jobs\Concerns;

use App\Models\Stream;
use App\Models\Worker;
use App\Services\WorkerPool;
use Illuminate\Support\Facades\DB;

/**
 * Claims a free worker for the job. Without one the stream goes to "waiting" and the job is released to check
 * again later; retryUntil() (not tries) bounds that, so waiting never uses up the job's attempts.
 */
trait WaitsForWorker
{
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDays(7);
    }

    /** @param array<string, mixed> $waitingAttributes */
    protected function claimWorkerOrWait(WorkerPool $pool, Stream $stream, string $task, string $statusColumn, array $waitingAttributes = []): ?Worker
    {
        $worker = $pool->claim($task, $stream);
        if ($worker !== null) {
            return $worker;
        }

        $waiting = DB::transaction(function () use ($stream, $statusColumn, $waitingAttributes): bool {
            $locked = Stream::query()->whereKey($stream->id)->lockForUpdate()->first();
            if ($locked === null || in_array($locked->{$statusColumn}, ['processing', 'completed'], true)) {
                return false;
            }
            $locked->forceFill([$statusColumn => 'waiting', ...$waitingAttributes]);
            // Touch on every check: a waiting stream that is not touched any more lost its job (Stream::isStalled).
            $locked->isDirty() ? $locked->save() : $locked->touch();

            return true;
        });
        if ($waiting) {
            $this->release((int) config('services.workers.wait_seconds', 30));
        }

        return null;
    }
}
