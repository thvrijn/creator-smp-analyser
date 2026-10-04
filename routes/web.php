<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\EventController;

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
Route::post('/streams/{stream}/extract-events', [StreamController::class, 'extractEvents'])->name('streams.extract-events');
Route::get('/players', [PlayerController::class, 'index'])->name('players.index');
Route::post('/players', [PlayerController::class, 'store'])->name('players.store');
Route::get('/players/{player}', [PlayerController::class, 'show'])->name('players.show');
Route::put('/players/{player}', [PlayerController::class, 'update'])->name('players.update');
Route::delete('/players/{player}', [PlayerController::class, 'destroy'])->name('players.destroy');
Route::get('/timeline', fn () => Inertia::render('Timeline'));
Route::get('/events', [EventController::class, 'index'])->name('events.index');
Route::get('/video-builder', fn () => Inertia::render('VideoBuilder'));
Route::get('/settings', fn () => Inertia::render('Settings'));
