<?php

namespace App\Http\Middleware;

use App\Services\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs what a logged-in user changes (the routes in ActivityLogger::ACTIONS), with the flash message the app showed
 * as the result. Requests that only failed validation are not logged: nothing happened.
 */
class LogActivity
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethodSafe() || $request->user() === null || ! $request->hasSession()) {
            return $response;
        }
        $session = $request->session();
        if ($session->has('errors') && $session->get('errors')->any() || $response->getStatusCode() >= 400) {
            return $response;
        }

        $error = $session->get('error');
        $this->logger->logRequest($request, $error === null, $error ?? $session->get('success'));

        return $response;
    }
}
