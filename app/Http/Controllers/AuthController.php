<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Usernames are stored lowercase, so logging in ignores case.
        $credentials['username'] = Str::lower(trim($credentials['username']));

        // Per username and IP, so guessing a password is slow without locking out others.
        $key = $credentials['username'].'|'.$request->ip();
        // Failed attempts are logged with the name that was tried, so the admin sees someone guessing.
        $failed = function (string $message) use ($request, $activity, $credentials): ValidationException {
            $activity->log($request, 'auth.failed', 'Mislukte inlogpoging', User::where('username', $credentials['username'])->first(), succeeded: false, result: $message, username: Str::limit($credentials['username'], 50, ''));

            return ValidationException::withMessages(['username' => $message]);
        };
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw $failed('Te veel pogingen. Probeer het over '.RateLimiter::availableIn($key).' seconden opnieuw.');
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key);
            throw $failed('Deze combinatie van gebruikersnaam en wachtwoord klopt niet.');
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $activity->log($request, 'auth.login', 'Ingelogd', $request->user());

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $activity->log($request, 'auth.logout', 'Uitgelogd', $request->user());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
