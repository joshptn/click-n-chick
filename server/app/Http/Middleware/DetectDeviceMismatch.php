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

/**
 * Notices when a token is presented by a device it was not issued to.
 *
 * A bearer token is bearer: whoever holds it is the user, and the API is right
 * to honour it. What was missing is that a COPIED token was completely
 * invisible - it kept reporting the device it was born on, so "Your devices"
 * could never show it. This closes the observability gap.
 *
 * Be clear about the limit: the fingerprint is derived from a client-supplied
 * hint and the user agent, both of which an attacker who copied localStorage
 * also has. This reliably catches a token moved WITHOUT its device id - which
 * is what casual theft looks like - and does not catch a careful attacker who
 * copies everything. It is a detective control, not a boundary.
 *
 * Detection only by default. Auto-revoking on mismatch would log people out
 * whenever a legitimate request arrives without the hint header, so it is
 * behind SESSION_REVOKE_ON_DEVICE_MISMATCH and off until header coverage is
 * proven in production.
 */
class DetectDeviceMismatch
{
    /** One alert per token+fingerprint pair per this many minutes. */
    private const ALERT_COOLDOWN_MINUTES = 360;

    public function __construct(private DeviceRegistrar $devices)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Never let detection break a request. The user is legitimately
        // authenticated as far as Sanctum is concerned; this is bookkeeping.
        try {
            $revoked = $this->inspect($request);

            if ($revoked) {
                return response()->json([
                    'message' => 'This session was ended because it was used from an unrecognised device.',
                ], 401);
            }
        } catch (Throwable $e) {
            Log::warning('Device mismatch check failed.', ['error' => $e->getMessage()]);
        }

        return $next($request);
    }

    /** @return bool whether the session was revoked */
    private function inspect(Request $request): bool
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        $token = $user->currentAccessToken();

        // TransientToken, or a token issued before device tracking: nothing to
        // compare against, so nothing to say.
        if (! $token instanceof PersonalAccessToken || $token->known_device_id === null) {
            return false;
        }

        $device = KnownDevice::find($token->known_device_id);

        if ($device === null) {
            return false;
        }

        // Inconclusive without a device hint, so say nothing.
        //
        // A request with no X-Device-Id can only be fingerprinted by user
        // agent, which does not match a device that was identified by hint -
        // so treating "absent" as "different" flags every legitimate call that
        // forgets the header. That is not hypothetical: it fired on real
        // browser traffic the moment this shipped, because the Echo authorizer
        // was not sending it yet. An alert that cries wolf is worse than no
        // alert, and if revocation is ever armed it would log people out.
        //
        // Nothing is lost against the case this exists for: a copied session
        // running in another browser carries THAT browser's hint, which is
        // present and different.
        if (! $request->hasHeader(DeviceRegistrar::HINT_HEADER)
            || trim((string) $request->header(DeviceRegistrar::HINT_HEADER)) === '') {
            return false;
        }

        if (in_array($device->device_fingerprint, $this->devices->fingerprintCandidates($request), true)) {
            return false;
        }

        $this->raise($user, $device, $request, (int) $token->getKey());

        if (! config('services.session_security.revoke_on_device_mismatch', false)) {
            return false;
        }

        $token->delete();

        return true;
    }

    private function raise(User $user, KnownDevice $device, Request $request, int $tokenId): void
    {
        // Always recorded - the audit trail should not be rate limited.
        AuthEvent::create([
            'user_id' => $user->getKey(),
            'event_type' => DeviceRegistrar::EVENT_DEVICE_MISMATCH,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Alerts ARE rate limited: without this every request in a browsing
        // session would email the user, and an alert that arrives 200 times is
        // an alert nobody reads.
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
