<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'account.active' => \App\Http\Middleware\EnsureAccountIsActive::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Unauthenticated request handling
        |--------------------------------------------------------------------------
        |
        | API requests must return a JSON 401 response instead of attempting
        | to redirect to a named web route called "login".
        |
        */

        $middleware->redirectGuestsTo(
            function (Request $request): ?string {
                if (
                    $request->is('api/*')
                    || $request->expectsJson()
                ) {
                    return null;
                }

                return '/login';
            }
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
        |--------------------------------------------------------------------------
        | Render API exceptions as JSON
        |--------------------------------------------------------------------------
        */

        $exceptions->shouldRenderJsonWhen(
            function (
                Request $request,
                \Throwable $exception
            ): bool {
                return $request->is('api/*')
                    || $request->expectsJson();
            }
        );
    })
    ->create();