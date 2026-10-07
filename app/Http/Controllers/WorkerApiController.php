<?php

namespace App\Http\Controllers;

use App\Models\Stream;
use App\Models\Worker;
use App\Services\WorkerPool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Called by the Python workers: check-in, sign-off and downloading a stream's audio. */
class WorkerApiController extends Controller
{
    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url', 'max:255'],
            'backend' => ['nullable', 'string', Rule::in(['cuda', 'mlx', 'cpu'])],
            'gpu_name' => ['nullable', 'string', 'max:255'],
            'vram_mb' => ['nullable', 'integer', 'min:0'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['string', Rule::in(Worker::TASKS)],
            'priority' => ['nullable', 'integer'],
            'loaded_model' => ['nullable', 'string', 'max:100'],
            'busy' => ['required', 'boolean'],
        ]);

        $worker = Worker::firstOrNew(['name' => $data['name']]);
        $worker->fill([
            ...collect($data)->except('busy')->all(),
            'capabilities' => array_values(array_unique($data['capabilities'])),
            'priority' => $data['priority'] ?? 0,
            'last_seen_at' => now(),
        ]);
        // The worker is idle but still claimed long after the claim: the job that claimed it is gone.
        if (! $data['busy'] && $worker->current_task !== null && $worker->claimed_at?->lt(now()->subSeconds(WorkerPool::STALE_CLAIM_SECONDS))) {
            $worker->forceFill(['current_task' => null, 'current_stream_id' => null, 'claimed_at' => null]);
        }
        $worker->save();

        return response()->json(['status' => 'ok', 'enabled' => $worker->enabled ?? true]);
    }

    public function offline(Request $request): JsonResponse
    {
        $name = (string) $request->validate(['name' => ['required', 'string', 'max:100']])['name'];
        Worker::where('name', $name)->update(['last_seen_at' => null]);

        return response()->json(['status' => 'ok']);
    }

    /** The stream's audio for a worker without access to the app's storage (signed URL from WorkerPool::fileUrl). */
    public function streamFile(Stream $stream): BinaryFileResponse
    {
        $disk = Storage::disk(config('filesystems.default'));
        abort_if(blank($stream->video_path) || ! $disk->exists($stream->video_path), 404);

        return response()->file($disk->path($stream->video_path), ['Content-Type' => $stream->video_mime_type ?: 'application/octet-stream']);
    }
}
