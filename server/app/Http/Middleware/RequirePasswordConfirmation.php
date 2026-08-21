<?php

namespace App\Http\Middleware;

use App\Services\Auth\PasswordConfirmation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordConfirmation
{
    public function __construct(private PasswordConfirmation $confirmation) {}

    public function handle(Request $request, Closure $next, string $action = 'perform this action'): Response
    {
        $failure = $this->confirmation->challenge($request, $action);

        if ($failure !== null) {
            return $failure;
        }

        return $next($request);
    }
}
