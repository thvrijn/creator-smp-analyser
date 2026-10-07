<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile', [
            'account' => $request->user()->only('username', 'name', 'email'),
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ], [], ['password' => 'nieuw wachtwoord']);

        $user = $request->user();
        $user->forceFill(['password' => $validated['password'], 'remember_token' => Str::random(60)])->save();

        // Log out everywhere else: other browsers' sessions and "Ingelogd blijven" cookies stop working.
        // This browser stays logged in with a fresh session.
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier())
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }
        $request->session()->regenerate();

        return back()->with('success', 'Je wachtwoord is gewijzigd. Andere apparaten zijn uitgelogd.');
    }
}
