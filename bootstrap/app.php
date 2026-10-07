<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // On the Pi, the reverse proxy terminates HTTPS and proxies to the app; trust its X-Forwarded-* headers
        // so URLs and secure cookies use https. The app publishes no port there, only the proxy reaches it.
        $middleware->trustProxies(at: '*');
        // Every page needs a login (routes/web.php); a logged-in user on /login goes to the dashboard.
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\LogActivity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
