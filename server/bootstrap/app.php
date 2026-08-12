<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Registered explicitly rather than via withRouting(channels:), which would
    // put the auth endpoint on the 'web' group only. This API authenticates with
    // Sanctum bearer tokens and statefulApi() is not enabled, so a token-based
    // client could never authorize a private channel through the web group.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
        $middleware->append(HandleCors::class);

        // Applies the 'api' named limiter (see AppServiceProvider::configureRateLimiting)
        // to every route in routes/api.php as a baseline. Sensitive routes layer an
        // additional, tighter limiter on top of this one.
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->expectsJson()) {
                return new JsonResponse(['message' => 'Unauthenticated.'], 401);
            }
        });

        // The SPA calls these routes with bare fetch(), which does not set the headers
        // expectsJson() looks for, so a throttled request would otherwise receive an
        // HTML error page it cannot parse. Answer every /api 429 with JSON.
        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return new JsonResponse([
                    'message' => 'Too many requests. Please slow down and try again shortly.',
                ], 429, $e->getHeaders());
            }
        });
    })->create();
