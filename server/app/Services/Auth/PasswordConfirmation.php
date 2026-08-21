<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

class PasswordConfirmation
{
    public const WINDOW_MINUTES = 5;

    public const CODE_REQUIRED = 'PASSWORD_CONFIRMATION_REQUIRED';

    public const CODE_INVALID = 'PASSWORD_CONFIRMATION_INVALID';

    public const CODE_THROTTLED = 'PASSWORD_CONFIRMATION_THROTTLED';

    private const MAX_ATTEMPTS = 5;

    private const DECAY_MINUTES = 15;

    public function __construct(private DeviceRegistrar $devices) {}

    /**
     * Gate one sensitive action.
     *
     * @return JsonResponse|null a response to return to the caller, or null to proceed
     */
    public function challenge(Request $request, string $action): ?JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        if ($this->isConfirmed($request)) {
            return null;
        }

        $password = $request->input('password');

        if (! is_string($password) || $password === '') {
            return response()->json([
                'success' => false,
                'error_code' => self::CODE_REQUIRED,
                'message' => 'Confirm your account password to '.$action.'.',
                'window_minutes' => self::WINDOW_MINUTES,
            ], 422);
        }

        $throttleKey = 'pwconfirm:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            return response()->json([
                'success' => false,
                'error_code' => self::CODE_THROTTLED,
                'message' => 'Too many incorrect password attempts. Try again shortly.',
                'retry_after' => RateLimiter::availableIn($throttleKey),
            ], 429);
        }

        if (! Hash::check($password, $user->password)) {
            RateLimiter::hit($throttleKey, self::DECAY_MINUTES * 60);

            return response()->json([
                'success' => false,
                'error_code' => self::CODE_INVALID,
                'message' => 'That password is not correct.',
            ], 422);
        }

        RateLimiter::clear($throttleKey);

        $this->remember($request);

        return null;
    }

    public function isConfirmed(Request $request): bool
    {
        return Cache::has($this->cacheKey($request));
    }

    public function remember(Request $request): void
    {
        Cache::put($this->cacheKey($request), true, now()->addMinutes(self::WINDOW_MINUTES));
    }

    private function cacheKey(Request $request): string
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        $sessionPart = $token instanceof PersonalAccessToken
            ? 'token:'.$token->getKey()
            : 'user:'.$user?->getKey();

        $devicePart = hash('sha256', implode('|', $this->devices->fingerprintCandidates($request)));

        return 'password-confirmed:'.$sessionPart.':'.$devicePart;
    }
}
