<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $unknown = array_values(array_diff($roles, User::ROLES));

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown role(s) in route middleware: '.implode(', ', $unknown)
            );
        }

        if (! $user->hasRole(...$roles)) {
            return response()->json([
                'message' => 'This action is not available to your role.',
                'error_code' => 'ROLE_FORBIDDEN',
            ], 403);
        }

        return $next($request);
    }
}
