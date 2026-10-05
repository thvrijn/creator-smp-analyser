<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\ClipController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\TwitchVodController;

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
Route::post('/streams/{stream}/download-audio', [TwitchVodController::class, 'download'])->name('streams.download-audio');
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
Route::get('/settings', fn () => Inertia::render('Settings'));
