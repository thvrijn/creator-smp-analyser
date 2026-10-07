<?php

namespace App\Http\Middleware;

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
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ]);
    }
}
