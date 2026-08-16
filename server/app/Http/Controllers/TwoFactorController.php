<?php

namespace App\Http\Controllers;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\Otp\OtpService;
use App\Services\Otp\OtpVerificationResult;
use App\Services\Verification\Channel;
use App\Services\Verification\ChannelRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Two-factor authentication (FR-01.7).
 *
 * The channel is chosen once, at enable time, and recorded on the account.
 * Every later login challenge reuses it - there is deliberately no per-login
 * choice, so an attacker who has the password cannot steer the second factor
 * to whichever channel they happen to control.
 */
class TwoFactorController extends Controller
{
    private const TOKEN_NAME = 'auth_token';

    /** Cache prefix for the short-lived post-password login challenge. */
    private const CHALLENGE_PREFIX = '2fa:challenge:';

    public function __construct(
        private OtpService $otp,
        private ChannelRegistry $channels,
    ) {
    }

    /**
     * Step 1 of enabling: send a code to the channel the user picked.
     */
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

    /**
     * Step 2: confirming the code turns 2FA on and fixes the delivery channel.
     *
     * If that channel had not been verified before, this confirmation verifies
     * it too - the user has just proven control of it, and re-proving the same
     * fact through the registration flow would be busywork. The verification is
     * recorded through the same VerificationChannel::markVerified() the
     * registration path uses rather than a second implementation.
     */
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

        // Proving control of the channel verifies it, if it was not already.
        if (! $user->hasVerifiedChannel($channel)) {
            $transport->markVerified($user);
        }

        $user->two_factor_enabled = true;
        $user->two_factor_channel = $channel->value;
        $user->two_factor_confirmed_at = now();
        $user->save();

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

    /**
     * Complete the login by answering the challenge.
     *
     * Unauthenticated by necessity - no token exists yet. The challenge token
     * stands in for having already passed the password check.
     */
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

        $token = $user->createToken(self::TOKEN_NAME);

        return response()->json([
            'user' => $user->fresh(),
            'token' => $token->plainTextToken,
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
