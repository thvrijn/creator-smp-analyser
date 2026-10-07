<?php

namespace App\Services;

use App\Models\Stream;
use App\Models\Worker;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

/** Hands out the registered workers: one task per worker at a time, the best free online one first. */
class WorkerPool
{
    /** A claim the worker no longer confirms with busy=true after this long is released (e.g. a killed queue job). */
    public const STALE_CLAIM_SECONDS = 120;

    public function claim(string $task, Stream $stream): ?Worker
    {
        return DB::transaction(function () use ($task, $stream): ?Worker {
            $worker = Worker::query()
                ->online()
                ->where('enabled', true)
                ->whereNull('current_task')
                ->whereJsonContains('capabilities', $task)
                ->orderByDesc('priority')
                ->orderByRaw('vram_mb desc nulls last')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $worker?->forceFill([
                'current_task' => $task,
                'current_stream_id' => $stream->id,
                'claimed_at' => now(),
            ])->save();

            return $worker;
        });
    }

    public function release(Worker $worker): void
    {
        Worker::whereKey($worker->id)->update(['current_task' => null, 'current_stream_id' => null, 'claimed_at' => null]);
    }

    /** An HTTP request to the worker, with the shared token. */
    public function request(Worker $worker, int $timeout): PendingRequest
    {
        $token = (string) config('services.workers.token');

        return Http::baseUrl(rtrim($worker->url, '/'))
            ->timeout($timeout)
            ->when($token !== '', fn (PendingRequest $request) => $request->withToken($token));
    }

    /** The worker could not be reached: treat it as offline until its next heartbeat, so a retry picks another one. */
    public function markUnreachable(Worker $worker): void
    {
        Worker::whereKey($worker->id)->update(['last_seen_at' => null]);
    }

    /**
     * A temporary URL the worker downloads the stream's audio from when it has no access to the app's storage.
     * Signed relative to the path, so it stays valid whatever host the worker uses to reach the app.
     */
    public function fileUrl(Stream $stream): string
    {
        $path = URL::temporarySignedRoute('worker-files.stream', now()->addHours(12), ['stream' => $stream->id], absolute: false);

        return rtrim((string) config('services.workers.app_url'), '/').$path;
    }
}
