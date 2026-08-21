<?php

use App\Http\Middleware\DetectDeviceMismatch;
use App\Http\Middleware\EnforceStaffIdleTimeout;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\RequirePasswordConfirmation;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyRecaptcha;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum', 'device-check']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(HandleCors::class);
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'recaptcha' => VerifyRecaptcha::class,
            'device-check' => DetectDeviceMismatch::class,
            'staff-idle' => EnforceStaffIdleTimeout::class,
            'confirm-password' => RequirePasswordConfirmation::class,
        ]);

        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->expectsJson()) {
                return new JsonResponse(['message' => 'Unauthenticated.'], 401);
            }
        });
        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return new JsonResponse([
                    'message' => 'Too many requests. Please slow down and try again shortly.',
                ], 429, $e->getHeaders());
            }
        });
    })->create();
