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
    ) {}

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

    /** Why a challenge was issued. Governs which gate challenge() applies. */
    public const KIND_TWO_FACTOR = 'two_factor';

    public const KIND_STEP_UP = 'recaptcha_step_up';

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

        return $this->issueChallenge(
            $user,
            $channel,
            OtpCode::PURPOSE_TWO_FACTOR_LOGIN,
            self::KIND_TWO_FACTOR,
            $ip,
            $channel === Channel::Email
                ? 'Enter the code we sent to your email address.'
                : 'Enter the code we sent to your phone.',
        );
    }

    /**
     * Identity step-up after a low reCAPTCHA score at login (FR-01.15, BR-35).
     *
     * Sent over whichever channel this account has actually verified, not the
     * one it registered with - the point is to reach a human who already proved
     * they hold that address or number.
     *
     * Unlike the 2FA challenge, this one respects the OTP resend cooldown. A
     * low score is something an attacker with valid credentials can produce at
     * will, and re-sending on every attempt would let them burn SMS credits;
     * inside the cooldown the outstanding code stays live and only a fresh
     * challenge token is minted.
     *
     * @return array<string, mixed>
     */
    public function issueStepUpChallenge(User $user, ?string $ip = null): array
    {
        $channel = $this->stepUpChannelFor($user);
        $transport = $this->channels->for($channel);

        $wait = $this->otp->secondsUntilResendAllowed(
            $this->channels->hash($channel, $transport->identifierFor($user)),
            OtpCode::PURPOSE_STEP_UP
        );

        return $this->issueChallenge(
            $user,
            $channel,
            OtpCode::PURPOSE_STEP_UP,
            self::KIND_STEP_UP,
            $ip,
            $channel === Channel::Email
                ? 'For your security, enter the code we sent to your email address.'
                : 'For your security, enter the code we sent to your phone.',
            send: $wait === 0,
        );
    }

    /**
     * A verified channel this account can actually be reached on.
     *
     * Prefers the channel chosen at registration, since that one is proven.
     * Falls back to the other only if it has been verified too - never to an
     * unverified one, which would send a code nobody receives and leave the
     * account unable to sign in at all.
     */
    private function stepUpChannelFor(User $user): Channel
    {
        $chosen = Channel::tryFromValue($user->verification_channel) ?? Channel::Email;

        if ($user->hasVerifiedChannel($chosen) && $this->channels->isAvailable($chosen)) {
            return $chosen;
        }

        $other = $chosen === Channel::Email ? Channel::Sms : Channel::Email;

        return $user->hasVerifiedChannel($other) && $this->channels->isAvailable($other)
            ? $other
            : $chosen;
    }

    /**
     * Shared issuance: send the code, mint the challenge token, describe it.
     *
     * @return array<string, mixed>
     */
    private function issueChallenge(
        User $user,
        Channel $channel,
        string $purpose,
        string $kind,
        ?string $ip,
        string $message,
        bool $send = true,
    ): array {
        $transport = $this->channels->for($channel);

        if ($send) {
            $this->otp->send($user, $purpose, $ip, $channel);
        }

        $challengeToken = Str::random(48);

        Cache::put(
            self::CHALLENGE_PREFIX.hash('sha256', $challengeToken),
            // An array, not a bare id: challenge() must know WHY this was
            // issued, or a step-up token would be redeemable through the 2FA
            // gate and vice versa.
            ['user_id' => $user->id, 'kind' => $kind],
            now()->addMinutes(OtpService::EXPIRY_MINUTES)
        );

        return [
            // Kept as the flag the client already branches on, so the existing
            // code-entry screen serves both kinds without a second route.
            'two_factor_required' => true,
            'reason' => $kind,
            'message' => $message,
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
        $cached = Cache::get($cacheKey);

        if ($cached === null) {
            return response()->json([
                'message' => 'This sign-in attempt has expired. Please sign in again.',
                'reason' => 'challenge_expired',
            ], 422);
        }

        // Tolerates the bare-id shape written before step-up challenges existed,
        // so a challenge already in flight across a deploy still redeems.
        $kind = is_array($cached) ? ($cached['kind'] ?? self::KIND_TWO_FACTOR) : self::KIND_TWO_FACTOR;
        $userId = is_array($cached) ? ($cached['user_id'] ?? null) : $cached;

        $user = $userId === null ? null : User::find($userId);

        // The 2FA gate applies only to 2FA challenges. A step-up challenge is
        // issued precisely because the account does NOT have 2FA on, so
        // requiring it here would make every step-up unredeemable.
        $gateFailed = $user === null
            || ($kind === self::KIND_TWO_FACTOR && ! $user->hasTwoFactorEnabled());

        if ($gateFailed) {
            Cache::forget($cacheKey);

            return response()->json([
                'message' => 'This sign-in attempt has expired. Please sign in again.',
                'reason' => 'challenge_expired',
            ], 422);
        }

        $purpose = $kind === self::KIND_STEP_UP
            ? OtpCode::PURPOSE_STEP_UP
            : OtpCode::PURPOSE_TWO_FACTOR_LOGIN;

        $result = $this->otp->verifyForUser($user, $purpose, $request->input('code'));

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
