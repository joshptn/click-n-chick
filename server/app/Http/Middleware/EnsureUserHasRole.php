<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side role gate: `->middleware('role:admin')`.
 *
 * The listed roles are an EXACT allowlist. There is deliberately no hierarchy
 * and no implicit inheritance - `role:admin` does not admit a super_admin.
 * That is what makes BR-29 expressible: the Store Manager (super_admin) is
 * excluded from Store-Agent-only stock operations, so a privilege ladder would
 * defeat the requirement rather than implement it.
 *
 * Where both staff roles are intended, list both: `role:admin,super_admin`.
 *
 * This is the only role boundary that counts. The React guards under
 * client/src/providers are UX affordances gating on a value the client itself
 * holds; they are not a security boundary and nothing here consults them.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // Belt and braces: every group applying this also applies auth:sanctum
        // first. If that is ever dropped, fail closed rather than fall through
        // to a null-user role check.
        if (! $user instanceof User) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        // A typo'd role in a route definition would otherwise deny everyone and
        // read as a permissions bug instead of the wiring bug it is.
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
