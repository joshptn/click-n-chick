<?php

namespace App\Http\Middleware;

use App\Jobs\SendNewDeviceAlert;
use App\Mail\NewDeviceAlertMail;
use App\Models\AuthEvent;
use App\Models\KnownDevice;
use App\Models\User;
use App\Services\Auth\DeviceRegistrar;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DetectDeviceMismatch
{
    /** One alert per token+fingerprint pair per this many minutes. */
    private const ALERT_COOLDOWN_MINUTES = 360;

    /** The request carried no usable X-Device-Id. */
    private const FAILURE_HEADER_MISSING = 'DEVICE_HEADER_REQUIRED';

    /** The request carried a device hint for a different device. */
    private const FAILURE_MISMATCH = 'DEVICE_MISMATCH';

    public function __construct(private DeviceRegistrar $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $failure = $this->inspect($request);
        } catch (Throwable $e) {
            Log::warning('Device check failed to evaluate.', ['error' => $e->getMessage()]);

            $failure = null;
        }

        if ($failure !== null) {
            return response()->json([
                'message' => $failure === self::FAILURE_HEADER_MISSING
                    ? 'This request could not be attributed to a device. Reload the page and try again.'
                    : 'This session was ended because it was used from an unrecognised device.',
                'error_code' => $failure,
            ], 401);
        }

        return $next($request);
    }

    /** @return string|null the failure code, or null to allow the request */
    private function inspect(Request $request): ?string
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || $token->known_device_id === null) {
            return null;
        }

        $device = KnownDevice::find($token->known_device_id);

        if ($device === null) {
            return null;
        }

        if (in_array($device->device_fingerprint, $this->devices->fingerprintCandidates($request), true)) {
            return null;
        }

        $hasHint = trim((string) $request->header(DeviceRegistrar::HINT_HEADER, '')) !== '';

        if (! $hasHint) {
            return self::FAILURE_HEADER_MISSING;
        }

        $this->raise($user, $device, $request, (int) $token->getKey());

        // BR-46 / FR-01.17: a session is bound to the device it began on, so one
        // presented from another device is ended, for the real device too.
        $token->delete();

        return self::FAILURE_MISMATCH;
    }

    private function raise(User $user, KnownDevice $device, Request $request, int $tokenId): void
    {
        AuthEvent::create([
            'user_id' => $user->getKey(),
            'event_type' => DeviceRegistrar::EVENT_DEVICE_MISMATCH,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $seen = hash('sha256', implode('|', $this->devices->fingerprintCandidates($request)));
        $cacheKey = "device-mismatch:{$tokenId}:{$seen}";

        if (! Cache::add($cacheKey, true, now()->addMinutes(self::ALERT_COOLDOWN_MINUTES))) {
            return;
        }

        try {
            SendNewDeviceAlert::dispatch(
                (int) $user->getKey(),
                (int) $device->getKey(),
                $request->ip(),
                NewDeviceAlertMail::CONTEXT_SESSION_MOVED,
            );
        } catch (Throwable $e) {
            Log::warning('Could not queue a device-mismatch alert.', ['error' => $e->getMessage()]);
        }
    }
}
