<?php

use App\Http\Controllers\WorkerApiController;
use App\Http\Middleware\VerifyWorkerToken;
use Illuminate\Support\Facades\Route;

// Called by the Python workers (no session or CSRF).
Route::middleware(VerifyWorkerToken::class)->group(function (): void {
    Route::post('/workers/heartbeat', [WorkerApiController::class, 'heartbeat'])->name('workers.heartbeat');
    Route::post('/workers/offline', [WorkerApiController::class, 'offline'])->name('workers.offline');
});
// A worker without access to the app's storage downloads the audio here; the URL is signed and expires.
Route::get('/worker-files/streams/{stream}', [WorkerApiController::class, 'streamFile'])
    ->middleware('signed:relative')
    ->name('worker-files.stream');
