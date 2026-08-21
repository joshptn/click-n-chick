<?php

namespace App\Http\Middleware;

use App\Events\NotificationBroadcast;
use App\Mail\NewDeviceAlertMail;
use App\Models\AuthEvent;
use App\Models\KnownDevice;
use App\Models\Notification;
use App\Models\User;
use App\Services\Auth\DeviceRegistrar;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

        if (config('services.session_security.revoke_on_device_mismatch', false)) {
            $token->delete();
        }

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
            $notification = Notification::create([
                'user_id' => $user->getKey(),
                'title' => 'Session used from a new device',
                'body' => sprintf(
                    'A sign-in created on %s was just used from somewhere else (%s). If this was not you, sign that device out and change your password.',
                    $device->device_name ?? 'another device',
                    $request->ip() ?? 'unknown IP'
                ),
                'is_read' => false,
            ]);

            NotificationBroadcast::dispatch($notification, (int) $user->getKey());
        } catch (Throwable $e) {
            Log::warning('Could not raise a device-mismatch notification.', ['error' => $e->getMessage()]);
        }

        try {
            if (filled($user->email)) {
                Mail::to($user->email)->send(new NewDeviceAlertMail(
                    $device,
                    $user->first_name,
                    NewDeviceAlertMail::CONTEXT_SESSION_MOVED,
                ));
            }
        } catch (Throwable $e) {
            Log::warning('Could not email a device-mismatch alert.', ['error' => $e->getMessage()]);
        }
    }
}
