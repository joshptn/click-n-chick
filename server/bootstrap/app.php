<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
        $middleware->append(HandleCors::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->expectsJson()) {
                return new JsonResponse(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
