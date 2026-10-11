<?php

namespace App\Http\Middleware;

use App\Http\Resources\StreamResource;
use App\Models\Stream;
use App\Models\Worker;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'appName' => config('app.name'),
            'auth' => [
                'user' => fn () => $request->user() === null ? null : [
                    ...$request->user()->only('id', 'username', 'name'),
                    'is_admin' => $request->user()->isAdmin(),
                ],
            ],
            // Shown in the header on every page and listed on the Settings page.
            'workers' => fn () => $request->user() === null ? [] : Worker::query()->with('currentStream:id,title')->orderBy('name')->get()->map(fn (Worker $worker) => [
                ...$worker->payload(),
                'current_stream_title' => $worker->currentStream?->title,
            ]),
            // The "Bezig" block in the sidebar: what is downloaded, transcribed or analysed right now (it polls this prop).
            'active_jobs' => fn () => $request->user() === null ? [] : StreamResource::collection(Stream::activeList()->take(5))->resolve(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ]);
    }
}
