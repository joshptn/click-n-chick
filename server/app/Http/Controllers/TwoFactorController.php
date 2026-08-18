<?php

namespace App\Http\Controllers;

use App\Models\AuthEvent;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\DeviceRegistrar;
use App\Services\Otp\OtpService;
use App\Services\Otp\OtpVerificationResult;
use App\Services\Verification\Channel;
use App\Services\Verification\ChannelRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class TwoFactorController extends Controller
{
    private const TOKEN_NAME = 'auth_token';

    private const CHALLENGE_PREFIX = '2fa:challenge:';

    public function __construct(
        private OtpService $otp,
        private ChannelRegistry $channels,
    ) {
    }

    public function enable(Request $request)
    {
        $validated = $request->validate([
            'channel' => ['required', 'string', Rule::in(Channel::values())],
        ]);

        $user = $request->user();
        $channel = Channel::from($validated['channel']);
        $transport = $this->channels->for($channel);

        if (! $transport->isAvailable()) {
            return response()->json([
                'message' => $transport->unavailableReason(),
                'reason' => 'channel_unavailable',
            ], 422);
        }

        if ($transport->identifierFor($user) === null) {
            return response()->json([
                'message' => $channel === Channel::Email
                    ? 'Add an email address to your account first.'
                    : 'Add a mobile number to your account first.',
                'reason' => 'missing_identifier',
            ], 422);
        }

        $this->otp->send($user, OtpCode::PURPOSE_TWO_FACTOR_ENABLE, $request->ip(), $channel);

        return response()->json([
            'message' => $channel === Channel::Email
                ? 'We sent a code to your email address.'
                : 'We sent a code to your phone.',
            'channel' => $channel->value,
            'identifier' => $transport->mask($transport->identifierFor($user)),
            'expires_in_minutes' => OtpService::EXPIRY_MINUTES,
            'resend_available_in' => OtpService::RESEND_COOLDOWN_SECONDS,
        ]);
    }

    public function confirm(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string', 'max:12'],
        ]);

        $user = $request->user();

        $channel = $this->otp->pendingChannelFor($user, OtpCode::PURPOSE_TWO_FACTOR_ENABLE);

        if ($channel === null) {
            return response()->json([
                'message' => 'Start again - there is no code waiting to be confirmed.',
                'reason' => 'no_code',
            ], 422);
        }

        $result = $this->otp->verifyForUser($user, OtpCode::PURPOSE_TWO_FACTOR_ENABLE, $request->input('code'));

        if ($result !== OtpVerificationResult::Verified) {
            return $this->rejection($result);
        }

        $transport = $this->channels->for($channel);

        if (! $user->hasVerifiedChannel($channel)) {
            $transport->markVerified($user);
        }

        $user->two_factor_enabled = true;
        $user->two_factor_channel = $channel->value;
        $user->two_factor_confirmed_at = now();
        $user->save();

        $this->record($user, AuthEvent::TWO_FACTOR_ENABLED, $request);

        return response()->json([
            'message' => $channel === Channel::Email
                ? 'Two-factor authentication is on. Codes will go to your email address.'
                : 'Two-factor authentication is on. Codes will go to your phone.',
            'two_factor_enabled' => true,
            'two_factor_channel' => $channel->value,
            'user' => $user->fresh(),
        ]);
    }

    /**
     * POST /api/2fa/disable - turn the second factor off (UC-PROF-006).
     *
     * Gated on the account password, not on an OTP. Two reasons, and they pull
     * in the same direction:
     *
     * - The threat this guards against is someone who already holds a live
     *   session and wants to strip the second factor before the owner notices.
     *   A stolen token does not carry the password, so the password is the
     *   check that actually costs an attacker something. An OTP would not: it
     *   goes to the account's own channel, which a session thief often cannot
     *   read anyway, so it would mostly just inconvenience the real owner.
     *
     * - Requiring a code from the 2FA channel to switch 2FA OFF is the classic
     *   lockout trap. Lose the phone and the account is permanently stuck with
     *   a factor it can no longer satisfy, with no way out short of support.
     *
     * The password is checked BEFORE the already-off short-circuit, so no
     * request can come back successful on a wrong password.
     */
    public function disable(Request $request)
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'code' => 'PASSWORD_REQUIRED',
                'message' => 'That password is not correct.',
            ], 422);
        }

        // Already off. Report the end state rather than an error - the caller
        // asked for 2FA to be off and it is, and a second tab that raced this
        // one should not see a failure.
        if (! $user->hasTwoFactorEnabled()) {
            return response()->json([
                'success' => true,
                'message' => 'Two-factor authentication is already off.',
                'two_factor_enabled' => false,
                'two_factor_channel' => null,
                'user' => $user->fresh(),
            ]);
        }

        // All three cleared together. Leaving two_factor_channel set behind a
        // false flag would leave the next enable() with a stale channel to
        // disagree with, and hasTwoFactorEnabled() reads both.
        $user->two_factor_enabled = false;
        $user->two_factor_channel = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        $this->record($user, AuthEvent::TWO_FACTOR_DISABLED, $request);

        return response()->json([
            'success' => true,
            'message' => 'Two-factor authentication is off. You will not be asked for a code at sign-in.',
            'two_factor_enabled' => false,
            'two_factor_channel' => null,
            'user' => $user->fresh(),
        ]);
    }

    /**
     * Append to the audit trail.
     *
     * Guarded: the security change is already committed by the time this runs,
     * so a failure to write the log must not turn a completed action into a
     * 500 that tells the user it did not happen.
     */
    private function record(User $user, string $eventType, Request $request): void
    {
        try {
            AuthEvent::create([
                'user_id' => $user->getKey(),
                'event_type' => $eventType,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not record a two-factor auth event.', [
                'event' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Issue the post-password challenge. Called by AuthController::login.
     *
     * The challenge token is a short-lived random value held in the cache, NOT
     * a Sanctum token: an issued Sanctum token would satisfy `auth:sanctum` on
     * every protected route and hand out full access before the second factor
     * was ever supplied.
     *
     * @return array<string, mixed>
     */
    public function issueLoginChallenge(User $user, ?string $ip = null): array
    {
        $channel = Channel::tryFromValue($user->two_factor_channel) ?? Channel::Email;
        $transport = $this->channels->for($channel);

        $this->otp->send($user, OtpCode::PURPOSE_TWO_FACTOR_LOGIN, $ip, $channel);

        $challengeToken = Str::random(48);

        Cache::put(
            self::CHALLENGE_PREFIX.hash('sha256', $challengeToken),
            $user->id,
            now()->addMinutes(OtpService::EXPIRY_MINUTES)
        );

        return [
            'two_factor_required' => true,
            'message' => $channel === Channel::Email
                ? 'Enter the code we sent to your email address.'
                : 'Enter the code we sent to your phone.',
            'two_factor_channel' => $channel->value,
            'identifier' => $transport->mask($transport->identifierFor($user)),
            'challenge_token' => $challengeToken,
            'expires_in_minutes' => OtpService::EXPIRY_MINUTES,
        ];
    }

    public function challenge(Request $request)
    {
        $request->validate([
            'challenge_token' => ['required', 'string', 'max:128'],
            'code' => ['required', 'string', 'max:12'],
        ]);

        $cacheKey = self::CHALLENGE_PREFIX.hash('sha256', $request->input('challenge_token'));
        $userId = Cache::get($cacheKey);

        if ($userId === null) {
            return response()->json([
                'message' => 'This sign-in attempt has expired. Please sign in again.',
                'reason' => 'challenge_expired',
            ], 422);
        }

        $user = User::find($userId);

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            Cache::forget($cacheKey);

            return response()->json([
                'message' => 'This sign-in attempt has expired. Please sign in again.',
                'reason' => 'challenge_expired',
            ], 422);
        }

        $result = $this->otp->verifyForUser($user, OtpCode::PURPOSE_TWO_FACTOR_LOGIN, $request->input('code'));

        if ($result !== OtpVerificationResult::Verified) {
            return $this->rejection($result);
        }

        // Single use: the challenge cannot be replayed with another code.
        Cache::forget($cacheKey);

        $token = app(DeviceRegistrar::class)->issueToken($user, $request, self::TOKEN_NAME);

        return response()->json([
            'user' => $user->fresh(),
            'token' => $token->plainTextToken,
            'device_id' => $token->accessToken->known_device_id,
        ]);
    }

    private function rejection(OtpVerificationResult $result)
    {
        return response()->json([
            'message' => match ($result) {
                OtpVerificationResult::Expired => 'That code has expired. Request a new one.',
                OtpVerificationResult::TooManyAttempts => 'Too many incorrect attempts. Request a new code.',
                default => 'That code is not correct.',
            },
            'reason' => match ($result) {
                OtpVerificationResult::Expired => 'expired',
                OtpVerificationResult::TooManyAttempts => 'too_many_attempts',
                default => 'invalid',
            },
        ], 422);
    }
}
