<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClipController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SpeakerController;
use App\Http\Controllers\TranscriptSegmentController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\TwitchVodController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkerController;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

// Everything else needs a logged-in user; guests are sent to /login and back afterwards.
Route::middleware('auth')->group(function (): void {
    Route::get('/', function () {
        return redirect('/dashboard');
    });

    Route::get('/dashboard', [PlayerController::class, 'dashboard'])->name('dashboard');
    Route::get('/overview', fn () => Inertia::render('Overview'))->name('overview');
    Route::get('/streams', [StreamController::class, 'index'])->name('streams.index');
    Route::post('/streams', [StreamController::class, 'store'])->name('streams.store');
    Route::delete('/streams/{stream}', [StreamController::class, 'destroy'])->name('streams.destroy');
    Route::post('/streams/{stream}/transcribe', [StreamController::class, 'transcribe'])->name('streams.transcribe');
    Route::get('/streams/{stream}/transcription-status', [StreamController::class, 'transcriptionStatus'])->name('streams.transcription-status');
    Route::get('/streams/{stream}/transcript', [StreamController::class, 'transcript'])->name('streams.transcript');
    Route::get('/streams/{stream}', [StreamController::class, 'show'])->name('streams.show');
    Route::get('/streams/{stream}/audio', [StreamController::class, 'audio'])->name('streams.audio');
    Route::post('/streams/{stream}/download-audio', [TwitchVodController::class, 'download'])->name('streams.download-audio');
    Route::post('/streams/{stream}/cancel/{task}', [StreamController::class, 'cancel'])
        ->whereIn('task', ['transcription', 'event_extraction', 'video_download'])->name('streams.cancel');
    Route::put('/streams/{stream}/speakers/{speaker}', [SpeakerController::class, 'update'])->whereNumber('speaker')->name('streams.speakers.update');
    Route::post('/streams/{stream}/speakers/{speaker}/merge', [SpeakerController::class, 'merge'])->whereNumber('speaker')->name('streams.speakers.merge');
    Route::put('/segments/{segment}/speaker', [SpeakerController::class, 'segment'])->name('segments.speaker');
    Route::put('/segments/{segment}/text', [TranscriptSegmentController::class, 'update'])->name('segments.text');
    Route::post('/streams/{stream}/extract-events', [StreamController::class, 'extractEvents'])->name('streams.extract-events');
    Route::get('/players', [PlayerController::class, 'index'])->name('players.index');
    Route::post('/players', [PlayerController::class, 'store'])->name('players.store');
    Route::post('/players/sync-vods', [TwitchVodController::class, 'all'])->name('players.sync-vods');
    Route::post('/players/{player}/sync-vods', [TwitchVodController::class, 'player'])->name('players.player-sync-vods');
    Route::get('/players/{player}', [PlayerController::class, 'show'])->name('players.show');
    Route::get('/players/{player}/photo', [PlayerController::class, 'photo'])->name('players.photo');
    Route::put('/players/{player}', [PlayerController::class, 'update'])->name('players.update');
    Route::delete('/players/{player}', [PlayerController::class, 'destroy'])->name('players.destroy');
    Route::get('/timeline', fn () => Inertia::render('Timeline'));
    Route::get('/events', [EventController::class, 'index'])->name('events.index');
    Route::get('/video-builder', [ClipController::class, 'index'])->name('video-builder');
    Route::post('/streams/{stream}/clips', [ClipController::class, 'store'])->name('clips.store');
    Route::put('/clips/{clip}', [ClipController::class, 'update'])->name('clips.update');
    Route::delete('/clips/{clip}', [ClipController::class, 'destroy'])->name('clips.destroy');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    // Only the admin manages accounts (nobody can sign up) and reads the activity log.
    Route::middleware(EnsureUserIsAdmin::class)->group(function (): void {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::get('/activity', [ActivityLogController::class, 'index'])->name('activity.index');
    });
    Route::get('/settings', [WorkerController::class, 'settings'])->name('settings');
    Route::put('/workers/{worker}', [WorkerController::class, 'update'])->name('workers.update');
    Route::delete('/workers/{worker}', [WorkerController::class, 'destroy'])->name('workers.destroy');
});
