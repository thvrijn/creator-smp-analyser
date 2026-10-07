<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Account management; the routes only let the admin in (EnsureUserIsAdmin). */
class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Users', [
            'users' => User::query()->orderBy('username')->get()->map(fn (User $user) => [
                'id' => $user->id,
                'username' => $user->username,
                'name' => $user->name,
                'is_admin' => $user->isAdmin(),
                'created_at' => $user->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ], ['username.regex' => 'Een gebruikersnaam mag alleen letters, cijfers, punten, streepjes en underscores bevatten.']);

        // No e-mail address: the admin is recognised by theirs, so a new account can never become admin.
        User::create($validated);

        return back()->with('success', "Account {$validated['username']} is aangemaakt.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->isAdmin()) {
            return back()->with('error', 'Het admin-account kan niet verwijderd worden.');
        }

        DB::transaction(function () use ($user): void {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            $user->delete();
        });

        return back()->with('success', "Account {$user->username} is verwijderd.");
    }
}
