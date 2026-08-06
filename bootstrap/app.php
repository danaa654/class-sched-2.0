<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Belt-and-suspenders alongside the Route::fallback() in web.php:
        // any 404 that still slips through (e.g. a route with a parameter
        // that doesn't resolve, like /subjects/999) gets redirected
        // instead of showing Laravel's raw "404 | NOT FOUND" page.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) {
            if (! $request->expectsJson()) {
                return redirect(auth()->check() ? route('dashboard') : route('login'))
                    ->with('error', 'That page could not be found.');
            }
        });

        // Admin-only pages (e.g. User Management) abort(403) for any
        // other role that reaches them directly by URL. Redirect to the
        // dashboard with a flash message instead of Laravel's raw
        // "403 | FORBIDDEN" page, consistent with the 404 handling above.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, \Illuminate\Http\Request $request) {
            if (! $request->expectsJson()) {
                return redirect(auth()->check() ? route('dashboard') : route('login'))
                    ->with('error', 'You do not have permission to access that page.');
            }
        });
    })->create();