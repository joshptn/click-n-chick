<?php

namespace App\Services\Auth;

use App\Events\NotificationBroadcast;
use App\Mail\NewDeviceAlertMail;
use App\Models\AuthEvent;
use App\Models\KnownDevice;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\NewAccessToken;
use Throwable;

/**
 * Ties an authentication session (a Sanctum personal access token) to the
 * device that asked for it - the join FR-01.13 needs to exist before a user
 * can be shown "your devices" or revoke one of them.
 *
 * Every token-minting path in the application goes through issueToken() so
 * there is exactly one place where a session acquires a device, and no path
 * that quietly creates an unattributable one.
 */
class DeviceRegistrar
{
    /**
     * Opaque per-install id the SPA generates once and stores locally.
     *
     * It is a DISAMBIGUATOR, never a credential: it distinguishes two Chrome
     * installs that send byte-identical user agents. Nothing is authorised by
     * it. Device rows are unique per (user_id, fingerprint), so a client that
     * sends someone else's value can still only ever address its own account's
     * devices - ownership is decided by the authenticated user, in the
     * controller, against known_devices.user_id.
     */
    public const HINT_HEADER = 'X-Device-Id';

    /** auth_events.event_type written when an account is used from a new device. */
    public const EVENT_NEW_DEVICE = 'login_new_device';

    /**
     * Record this request's device against the user and stamp it as seen.
     *
     * Upsert on (user_id, fingerprint): signing in again from a device the
     * account already knows must reuse the row, not accumulate duplicates.
     */
    public function register(User $user, Request $request): KnownDevice
    {
        $descriptor = $this->describe($request);

        $device = KnownDevice::firstOrNew([
            'user_id' => $user->getKey(),
            'device_fingerprint' => $this->fingerprint($request),
        ]);

        $isUnrecognised = ! $device->exists;

        // Asked BEFORE the row is saved, or the device being registered would
        // count itself and no first login would ever look like a first login.
        $isFirstEverDevice = $isUnrecognised && ! $user->knownDevices()->exists();

        $device->device_name = $descriptor['name'];
        $device->platform = $descriptor['platform'];
        $device->last_ip_address = $request->ip();
        $device->last_seen_at = now();
        $device->save();

        if ($isUnrecognised) {
            $this->recordUnrecognisedLogin($user, $device, $request, alert: ! $isFirstEverDevice);
        }

        return $device;
    }

    /**
     * FR-01.11: record the unrecognised-device login and warn the account holder.
     *
     * Everything here is best-effort and individually guarded. A login must
     * never fail because an alert could not be written or sent - the sign-in
     * itself already succeeded, and throwing now would tell the user their
     * correct password was wrong.
     *
     * `alert` is false for the very first device on an account: there is no
     * prior device to compare against, the user is standing right there, and
     * warning someone about their own first login only teaches them to ignore
     * these emails.
     */
    private function recordUnrecognisedLogin(User $user, KnownDevice $device, Request $request, bool $alert): void
    {
        try {
            AuthEvent::create([
                'user_id' => $user->getKey(),
                'event_type' => self::EVENT_NEW_DEVICE,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not record a new-device auth event.', ['error' => $e->getMessage()]);
        }

        if (! $alert) {
            return;
        }

        // In-app + realtime. Reuses the existing notification pipeline so the
        // bell and the Reverb push work exactly as they do for order updates.
        try {
            $notification = Notification::create([
                'user_id' => $user->getKey(),
                'title' => 'New sign-in detected',
                'body' => sprintf(
                    'Your account was just signed in to from %s (%s). If this was not you, sign that device out and change your password.',
                    $device->device_name ?? 'an unrecognised device',
                    $device->last_ip_address ?? 'unknown IP'
                ),
                'is_read' => false,
            ]);

            NotificationBroadcast::dispatch($notification, (int) $user->getKey());
        } catch (Throwable $e) {
            Log::warning('Could not raise a new-device notification.', ['error' => $e->getMessage()]);
        }

        // Email too: an in-app notice is useless for the case that matters,
        // where the attacker is the one holding the app.
        try {
            if (filled($user->email)) {
                Mail::to($user->email)->send(new NewDeviceAlertMail($device, $user->first_name));
            }
        } catch (Throwable $e) {
            Log::warning('Could not email a new-device alert.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Mint a session for this user and attribute it to this request's device.
     *
     * Wraps Sanctum rather than replacing it: the token is created exactly as
     * before, then linked. Callers keep getting a NewAccessToken and keep
     * reading ->plainTextToken, so existing auth behaviour is unchanged.
     */
    public function issueToken(User $user, Request $request, string $tokenName): NewAccessToken
    {
        $device = $this->register($user, $request);

        $token = $user->createToken($tokenName);

        // Not fillable on Sanctum's model, and it must not be client-settable.
        $token->accessToken->forceFill(['known_device_id' => $device->getKey()])->save();

        return $token;
    }

    /**
     * A stable, per-device value that never leaves the server.
     *
     * Keyed with the app key so the stored value is not a plain hash of a
     * client-supplied string, and so fingerprints are not portable between
     * environments.
     */
    public function fingerprint(Request $request): string
    {
        $hint = trim((string) $request->header(self::HINT_HEADER, ''));

        // Fall back to the user agent when the client sends no hint. Coarser -
        // two identical browsers on one machine collapse into one device - but
        // it degrades to "fewer, broader devices" rather than to a new row per
        // request, which would make the list useless.
        $seed = $hint !== ''
            ? 'hint:'.$hint
            : 'ua:'.(string) $request->userAgent();

        return hash_hmac('sha256', $seed, (string) config('app.key'));
    }

    /**
     * Human-readable labels for the list. Display only - nothing branches on
     * these, so an unrecognised agent degrading to "Unknown" is harmless.
     */
    public function describe(Request $request): array
    {
        $agent = (string) $request->userAgent();

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/'), str_contains($agent, 'Opera') => 'Opera',
            // Order matters: Chrome's UA contains "Safari", Edge's contains
            // "Chrome". Most specific first.
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };

        $platform = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown platform',
        };

        return [
            'name' => $browser.' on '.$platform,
            'platform' => $platform,
        ];
    }
}
