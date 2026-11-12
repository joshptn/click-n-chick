<?php

namespace App\Http\Controllers;

use App\Http\Middleware\VerifyRecaptcha;
use App\Models\OtpCode;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\Auth\DeviceRegistrar;
use App\Services\Auth\PendingRegistration;
use App\Services\Otp\OtpService;
use App\Services\Verification\Channel;
use App\Services\Verification\ChannelRegistry;
use App\Services\Verification\VerificationChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class AuthController extends Controller
{
    private const TOKEN_NAME = 'auth_token';

    /**
     * Compared against when no account matches the identifier, so a sign-in
     * that fails takes the same time whether or not the account exists. A
     * bcrypt hash of a random value nobody holds; never a valid credential.
     */
    private const NO_ACCOUNT_HASH = '$2y$12$Qn8s3Yk2W5jE7bRzT1vUe.XlJ0mPqC4hA9rN6dF2sG8kL3wB7yZ1a';

    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    /**
     * Step 1 of sign-up (BR-02, FR-01.1 to FR-01.3).
     *
     * One identifier, a phone number or an email address, and the code goes
     * to it. Nothing is written to `users` here: the submission waits in the
     * cache (PendingRegistration) and OtpController::verify creates the account
     * once the code comes back. No token is issued and there is no skip path.
     */
    public function register(Request $request, OtpService $otp, PendingRegistration $pending)
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', new StrongPassword],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
        ]);

        $registry = app(ChannelRegistry::class);
        $typed = trim($validated['identifier']);
        $channel = Channel::forIdentifier($typed);
        $transport = $channel === null ? null : $registry->for($channel);
        $identifier = $transport?->normalize($typed);

        if ($identifier === null) {
            throw ValidationException::withMessages([
                'identifier' => 'Enter a valid Philippine mobile number or email address.',
            ]);
        }

        // A channel the deployment cannot deliver on is refused here, rather
        // than at the send, so nobody waits on a code that cannot arrive.
        if (! $transport->isAvailable()) {
            return response()->json([
                'message' => $transport->unavailableReason(),
                'reason' => 'channel_unavailable',
            ], 422);
        }

        if ($transport->findUser($identifier) !== null) {
            throw ValidationException::withMessages([
                'identifier' => $channel === Channel::Email
                    ? 'This email address is already registered.'
                    : 'This phone number is already registered.',
            ]);
        }

        $pending->put([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'identifier' => $identifier,
            'channel' => $channel->value,
            'password' => Hash::make($validated['password']),
        ]);

        // Submitting again inside the cooldown replaces the submission but
        // does not send another code: the one already sent still works, and
        // the resend rules are the same here as on the code screen.
        $wait = $otp->secondsUntilResendAllowed($registry->hash($channel, $identifier), OtpCode::PURPOSE_REGISTRATION);

        if ($wait === 0) {
            $otp->sendTo($channel, $identifier, OtpCode::PURPOSE_REGISTRATION, $request->ip());
            $wait = OtpService::RESEND_COOLDOWN_SECONDS;
        }

        return response()->json([
            'status' => self::STATUS_PENDING_VERIFICATION,
            'message' => $channel === Channel::Email
                ? 'We sent a verification code to your email address.'
                : 'We sent a verification code to your phone.',
            'verification_channel' => $channel->value,
            'identifier' => $transport->mask($identifier),
            'expires_in_minutes' => OtpService::EXPIRY_MINUTES,
            'resend_available_in' => $wait,
        ], 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'login' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'email' => ['required_without:login', 'nullable', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $identifier = trim((string) ($validated['login'] ?? $validated['email'] ?? ''));
        $channel = Channel::forIdentifier($identifier);
        $registry = app(ChannelRegistry::class);
        $user = $channel === null ? null : $registry->for($channel)->findUser($identifier);

        // Checked against a fixed hash when there is no account, so the time a
        // failure takes does not say whether the identifier is registered.
        $passwordMatches = Hash::check($validated['password'], $user?->password ?? self::NO_ACCOUNT_HASH);

        if (! $user || ! $passwordMatches) {
            return response()->json(['message' => 'The credentials are wrong'], 401);
        }

        // After the password check, so it cannot reveal which accounts exist.
        if ($user->isSuspended()) {
            return response()->json(DeviceRegistrar::suspendedPayload(), 403);
        }

        /**
         * Only an identifier this account has verified signs it in. The gate is
         * the per-channel timestamp, never account_status, which is
         * mass-assignable.
         */
        if (! $user->hasVerifiedChannel($channel)) {
            return response()->json($this->unverifiedIdentifier($user, $channel, $registry, $request), 403);
        }

        // Password checked out and the account is fully active. If 2FA is on,
        // the second factor goes to the channel fixed at enable-time - the user
        // does not choose one per login.
        if ($user->hasTwoFactorEnabled()) {
            return response()->json(
                app(TwoFactorController::class)->issueLoginChallenge($user, $request->ip()),
                200
            );
        }

        /**
         * FR-01.15 / BR-35: this browser scored below the reCAPTCHA threshold,
         * so prove the human another way rather than refusing outright.
         *
         * Deliberately after the credential check. Issuing a challenge before
         * knowing the password was right would turn this into an account
         * enumeration oracle - and would send an OTP to someone whose account
         * is merely being guessed at.
         *
         * An account with 2FA on never reaches here: it was already challenged
         * above, which is the same proof.
         */
        if ($request->attributes->get(VerifyRecaptcha::LOW_SCORE_ATTRIBUTE) === true) {
            return response()->json(
                app(TwoFactorController::class)->issueStepUpChallenge($user, $request->ip()),
                200
            );
        }

        // Attributed to the requesting device so the account holder can see and
        // revoke this session later (FR-01.13). Same Sanctum token as before.
        $token = app(DeviceRegistrar::class)->issueToken($user, $request, self::TOKEN_NAME);

        return [
            'user' => $user,
            'token' => $token->plainTextToken,
            // Lets this browser recognise itself in a session.revoked broadcast
            // and leave immediately when another device signs it out.
            'device_id' => $token->accessToken->known_device_id,
        ];
    }

    /**
     * The password was right, but the identifier typed is not one this account
     * has verified. Two cases:
     *
     *  - The account has verified its other identifier (it registered with the
     *    other one, or an older account verified only one of two). It signs in
     *    with that one, and is told so.
     *  - Nothing is verified yet: an account a Store Manager created. They were
     *    not sent a code at creation, so the first correct password on the
     *    chosen channel is the moment, and they go to the code screen.
     *
     * @return array<string, mixed>
     */
    private function unverifiedIdentifier(User $user, Channel $typed, ChannelRegistry $registry, Request $request): array
    {
        $chosen = Channel::tryFromValue($user->verification_channel) ?? $typed;
        $verified = $user->hasVerifiedChosenChannel() ? $chosen : null;

        if ($verified === null) {
            $other = $typed === Channel::Email ? Channel::Sms : Channel::Email;
            $verified = $user->hasVerifiedChannel($other) ? $other : null;
        }

        if ($verified !== null) {
            return [
                'status' => self::STATUS_PENDING_VERIFICATION,
                'sign_in_with_channel' => true,
                'verification_channel' => $verified->value,
                'message' => $verified === Channel::Email
                    ? 'Sign in with your email address, the one this account verified.'
                    : 'Sign in with your phone number, the one this account verified.',
            ];
        }

        $transport = $registry->for($chosen);

        // The code screen needs the identifier the code goes to, and it was not typed.
        if ($typed !== $chosen) {
            return [
                'status' => self::STATUS_PENDING_VERIFICATION,
                'sign_in_with_channel' => true,
                'verification_channel' => $chosen->value,
                'message' => $chosen === Channel::Email
                    ? 'Sign in with your email address so we can send your verification code.'
                    : 'Sign in with your phone number so we can send your verification code.',
            ];
        }

        return $this->firstSignInCode($user, $transport, $request) + [
            'status' => self::STATUS_PENDING_VERIFICATION,
            // Always names the specific channel - never "verify your account".
            'message' => $chosen === Channel::Email
                ? 'Verify your email address to finish setting up your account.'
                : 'Verify your phone number to finish setting up your account.',
            'verification_channel' => $chosen->value,
            'identifier' => $transport->mask($transport->identifierFor($user)),
        ];
    }

    /**
     * Code for an account a Store Manager created: `active`, never verified.
     *
     * @return array<string, mixed>
     */
    private function firstSignInCode(User $user, VerificationChannel $transport, Request $request): array
    {
        if ($user->account_status !== User::STATUS_ACTIVE || $transport->identifierFor($user) === null) {
            return [];
        }

        $otp = app(OtpService::class);
        $hash = app(ChannelRegistry::class)->hash($transport->channel(), $transport->identifierFor($user));
        $wait = $otp->secondsUntilResendAllowed($hash, OtpCode::PURPOSE_REGISTRATION);

        if ($wait > 0) {
            return ['resend_available_in' => $wait];
        }

        try {
            $otp->send($user, OtpCode::PURPOSE_REGISTRATION, $request->ip(), $transport->channel());
        } catch (Throwable $e) {
            // They still reach the code screen, and Resend can try again.
            Log::warning('Could not send a first sign-in code.', ['error' => $e->getMessage()]);

            return ['resend_available_in' => 0];
        }

        return ['resend_available_in' => OtpService::RESEND_COOLDOWN_SECONDS];
    }

    public function userDetails(Request $request)
    {
        return $request->user();
    }

    public function logout(Request $request)
    {

        $token = $request->user()->currentAccessToken();

        // Only THIS session. Previously this deleted every token on the
        // account, which signed the user out of every other device too - the
        // exact opposite of what the devices screen (FR-01.13) promises, and
        // indistinguishable from a bug once that screen exists. Signing out
        // everywhere is what "Your devices" is for.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            // TransientToken (no persisted row) - nothing to revoke.
            $request->user()->tokens()->delete();
        }

        return [
            'message' => 'You are logged out',
        ];
    }

    public function updateUser(Request $request)
    {
        try {
            $user = $request->user();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access. Please log in again.',
                ], 401);
            }

            $validated = $request->validate([
                'first_name' => 'sometimes|nullable|string|max:255',
                'last_name' => 'sometimes|nullable|string|max:255',
                'phone_number' => 'sometimes|nullable|string|max:20',

                // Password changes are NOT handled here. BR-33 requires an OTP
                // re-verification first, so they go through
                // PasswordChangeController (POST /api/user/password).
            ]);

            $changed = false;

            if (array_key_exists('first_name', $validated)) {
                $user->first_name = $validated['first_name'];
                $changed = true;
            }
            if (array_key_exists('last_name', $validated)) {
                $user->last_name = $validated['last_name'];
                $changed = true;
            }
            if (array_key_exists('phone_number', $validated)) {
                $phoneHash = User::hashPhoneNumber($validated['phone_number']);

                if ($phoneHash !== null
                    && User::where('phone_number_hash', $phoneHash)
                        ->whereKeyNot($user->getKey())
                        ->exists()
                ) {
                    throw ValidationException::withMessages([
                        'phone_number' => 'This phone number is already registered.',
                    ]);
                }

                $user->phone_number = $validated['phone_number'];
                $user->phone_number_hash = $phoneHash;
                $changed = true;
            }
            if (! $changed) {
                return response()->json([
                    'success' => false,
                    'message' => 'No data provided to update.',
                    'user' => $user,
                ], 400);
            }

            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully.',
                'user' => $user->fresh(),
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while updating the profile.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
