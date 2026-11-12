<?php

namespace App\Services\Auth;

use App\Jobs\SendNewDeviceAlert;
use App\Models\AuthEvent;
use App\Models\KnownDevice;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\NewAccessToken;
use Throwable;

class DeviceRegistrar
{
    public const HINT_HEADER = 'X-Device-Id';

    public const EVENT_NEW_DEVICE = 'login_new_device';

    public const EVENT_DEVICE_MISMATCH = 'session_device_mismatch';

    public const CUSTOMER_SESSION_MINUTES = 20160; // 14 days

    public const STAFF_SESSION_MINUTES = 840; // 14 hours (BR-46)

    public function register(User $user, Request $request): KnownDevice
    {
        $descriptor = $this->describe($request);

        $device = KnownDevice::firstOrNew([
            'user_id' => $user->getKey(),
            'device_fingerprint' => $this->fingerprint($request),
        ]);

        $isUnrecognised = ! $device->exists;

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
     * The event row is written here, inside the sign-in. The notification and
     * the email go through the queue (SendNewDeviceAlert), so the sign-in does
     * not wait on Reverb or the mail provider.
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

        try {
            SendNewDeviceAlert::dispatch((int) $user->getKey(), (int) $device->getKey(), $request->ip());
        } catch (Throwable $e) {
            Log::warning('Could not queue a new-device alert.', ['error' => $e->getMessage()]);
        }
    }

    public function issueToken(User $user, Request $request, string $tokenName): NewAccessToken
    {
        // Every sign-in path ends here, so a suspended account has no way round it.
        if ($user->isSuspended()) {
            throw new HttpResponseException(response()->json(self::suspendedPayload(), 403));
        }

        $device = $this->register($user, $request);

        $token = $user->createToken($tokenName, ['*'], now()->addMinutes($this->sessionMinutesFor($user)));

        $token->accessToken->forceFill(['known_device_id' => $device->getKey()])->save();

        return $token;
    }

    /** @return array{status: string, error_code: string, message: string} */
    public static function suspendedPayload(): array
    {
        return [
            'status' => User::STATUS_SUSPENDED,
            'error_code' => 'ACCOUNT_SUSPENDED',
            'message' => 'This account has been suspended. Please contact the store.',
        ];
    }

    public function sessionMinutesFor(User $user): int
    {
        return $user->hasRole(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN)
            ? self::STAFF_SESSION_MINUTES
            : self::CUSTOMER_SESSION_MINUTES;
    }

    public function fingerprint(Request $request): string
    {
        $hint = trim((string) $request->header(self::HINT_HEADER, ''));
        $seed = $hint !== ''
            ? 'hint:'.$hint
            : 'ua:'.(string) $request->userAgent();

        return $this->hash($seed);
    }

    /**
     * Every fingerprint this request could legitimately be presenting.
     *
     * Used to decide whether a token is being used by the device it was issued
     * to. The user-agent value is ALWAYS included, even when a hint is present:
     * a session that began before the client could store a hint is legitimately
     * identified by its user agent, and treating that as theft would flag
     * honest users.
     *
     * @return list<string>
     */
    public function fingerprintCandidates(Request $request): array
    {
        $candidates = [];

        $hint = trim((string) $request->header(self::HINT_HEADER, ''));

        if ($hint !== '') {
            $candidates[] = $this->hash('hint:'.$hint);
        }

        $candidates[] = $this->hash('ua:'.(string) $request->userAgent());

        return $candidates;
    }

    private function hash(string $seed): string
    {
        return hash_hmac('sha256', $seed, (string) config('app.key'));
    }

    public function describe(Request $request): array
    {
        $agent = (string) $request->userAgent();

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/'), str_contains($agent, 'Opera') => 'Opera',
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
