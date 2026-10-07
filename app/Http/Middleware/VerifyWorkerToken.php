<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Worker API calls carry the shared WORKER_TOKEN as a Bearer token. */
class VerifyWorkerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('services.workers.token');
        if ($token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['error' => 'Ongeldig of ontbrekend worker-token.'], 401);
        }

        return $next($request);
    }
}
