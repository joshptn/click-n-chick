<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\DeviceRegistrar;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnforceStaffIdleTimeout
{
    public const IDLE_MINUTES = 480; // 8 hours

    private const WRITE_THRESHOLD_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->slide($request);
        } catch (Throwable $e) {
            Log::warning('Could not slide a staff session expiry.', ['error' => $e->getMessage()]);
        }

        return $next($request);
    }

    private function slide(Request $request): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return;
        }

        if (! $user->hasRole(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN)) {
            return;
        }

        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || $token->created_at === null) {
            return;
        }

        $absoluteDeadline = $token->created_at->copy()->addMinutes(DeviceRegistrar::STAFF_SESSION_MINUTES);
        $idleDeadline = now()->addMinutes(self::IDLE_MINUTES);

        $target = $absoluteDeadline->lessThan($idleDeadline) ? $absoluteDeadline : $idleDeadline;

        $current = $token->expires_at;

        if ($current !== null
            && abs($target->getTimestamp() - $current->getTimestamp()) < self::WRITE_THRESHOLD_SECONDS) {
            return;
        }

        $token->forceFill(['expires_at' => $target])->save();
    }
}
