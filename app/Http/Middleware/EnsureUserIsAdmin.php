<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Only the admin (User::ADMIN_EMAIL) gets through; everyone else gets a 403. */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Alleen de admin kan accounts beheren.');

        return $next($request);
    }
}
