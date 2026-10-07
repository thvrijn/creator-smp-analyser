<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The workers section of the Settings page. */
class WorkerController extends Controller
{
    public function settings(): Response
    {
        // The worker list itself is the shared "workers" prop (HandleInertiaRequests).
        return Inertia::render('Settings', ['onlineSeconds' => Worker::onlineSeconds()]);
    }

    public function update(Request $request, Worker $worker): RedirectResponse
    {
        $worker->update($request->validate(['enabled' => ['required', 'boolean']]));

        return back()->with('success', $worker->enabled ? "Worker {$worker->name} wordt weer gebruikt." : "Worker {$worker->name} wordt niet meer gebruikt.");
    }

    public function destroy(Worker $worker): RedirectResponse
    {
        if ($worker->isOnline() || $worker->current_task !== null) {
            return back()->with('error', 'Alleen een offline worker zonder taak kan worden vergeten.');
        }
        $worker->delete();

        return back()->with('success', "Worker {$worker->name} vergeten. Hij verschijnt weer zodra hij zich aanmeldt.");
    }
}
