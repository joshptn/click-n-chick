<?php

namespace App\Http\Controllers;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\DeviceRegistrar;
use App\Services\Auth\PendingRegistration;
use App\Services\Otp\OtpService;
use App\Services\Otp\OtpVerificationResult;
use App\Services\Verification\Channel;
use App\Services\Verification\ChannelRegistry;
use App\Services\Verification\VerificationChannel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class OtpController extends Controller
{
    private const TOKEN_NAME = 'auth_token';

    public function __construct(
        private OtpService $otp,
        private ChannelRegistry $channels,
        private PendingRegistration $pending,
    ) {}

    /**
     * Pull the identifier out of a request that may name it either way.
     *
     * Callers submit `identifier`, `phone_number` or `email`; the channel
     * follows from the shape of whichever arrives, so there is no separate
     * channel parameter and neither path needs its own endpoint.
     *
     * @return array{0: ?VerificationChannel, 1: ?string}
     */
    private function resolveIdentifier(Request $request): array
    {
        $raw = $request->input('identifier') ?? $request->input('phone_number') ?? $request->input('email');

        if (! is_string($raw) || trim($raw) === '') {
            return [null, null];
        }

        $transport = $this->channels->forIdentifier($raw);
        $canonical = $transport?->normalize($raw);

        return $canonical === null ? [null, null] : [$transport, $canonical];
    }

    /**
     * Step 2 of sign-up, and the first sign-in of an account a Store Manager
     * created. A right code either turns the pending submission into an
     * account, or verifies the channel on the account that already exists.
     */
    public function verify(Request $request)
    {
        $request->validate([
            'identifier' => ['required_without_all:phone_number,email', 'nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:12'],
        ]);

        [$transport, $identifier] = $this->resolveIdentifier($request);

        $invalid = response()->json([
            'message' => 'That code is not correct.',
            'reason' => 'invalid',
        ], 422);

        if ($transport === null) {
            return $invalid;
        }

        $channel = $transport->channel();
        $identifierHash = $this->channels->hash($channel, $identifier);

        $result = $this->otp->verify($identifierHash, OtpCode::PURPOSE_REGISTRATION, $request->input('code'));

        if ($result !== OtpVerificationResult::Verified) {
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

        $user = $transport->findUser($identifier);

        if ($user === null) {
            $user = $this->createFromPending($channel, $identifier);

            if ($user === null) {
                return response()->json([
                    'message' => 'Your sign-up details have expired. Please sign up again.',
                    'reason' => 'registration_expired',
                ], 422);
            }
        } else {
            // The code must be for the channel this account actually registered
            // on, and a suspended account is not lifted by verifying.
            if ($user->verification_channel !== $channel->value || $user->isSuspended()) {
                return $invalid;
            }

            $transport->markVerified($user);
            $user->account_status = User::STATUS_ACTIVE;
            $user->save();
        }

        // Belt and braces: refuse to issue a token unless the specific
        // per-channel timestamp is now set.
        if (! $user->hasVerifiedChannel($channel)) {
            return $invalid;
        }

        $token = app(DeviceRegistrar::class)->issueToken($user, $request, self::TOKEN_NAME);

        return response()->json([
            'user' => $user->fresh(),
            'token' => $token->plainTextToken,
            'device_id' => $token->accessToken->known_device_id,
        ]);
    }

    /**
     * The account, created from the submission the cache was holding. Verified
     * from the first moment it exists; the role comes from the column default.
     * The unique index decides a race between two sign-ups for one identifier:
     * the second insert fails and that person is told it is taken.
     */
    private function createFromPending(Channel $channel, string $identifier): ?User
    {
        $submission = $this->pending->get($channel, $identifier);

        if ($submission === null) {
            return null;
        }

        $user = new User;
        $user->first_name = $submission['first_name'];
        $user->last_name = $submission['last_name'];
        $user->password = $submission['password'];
        $user->verification_channel = $channel->value;
        $user->account_status = User::STATUS_ACTIVE;

        if ($channel === Channel::Email) {
            $user->email = $identifier;
            $user->email_verified_at = now();
        } else {
            $user->phone_number = $identifier;
            $user->phone_number_hash = User::hashPhoneNumber($identifier);
            $user->phone_verified_at = now();
        }

        try {
            $user->save();
        } catch (QueryException $e) {
            $this->pending->forget($channel, $identifier);

            return null;
        }

        $this->pending->forget($channel, $identifier);

        return $user->fresh();
    }

    public function resend(Request $request)
    {
        $request->validate([
            'identifier' => ['required_without_all:phone_number,email', 'nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
        ]);

        [$transport, $identifier] = $this->resolveIdentifier($request);

        $generic = [
            'message' => 'If that sign-up is awaiting verification, a new code is on its way.',
            'resend_available_in' => OtpService::RESEND_COOLDOWN_SECONDS,
            'expires_in_minutes' => OtpService::EXPIRY_MINUTES,
        ];

        if ($transport === null) {
            return response()->json($generic);
        }

        $channel = $transport->channel();
        $identifierHash = $this->channels->hash($channel, $identifier);

        $wait = $this->otp->secondsUntilResendAllowed($identifierHash, OtpCode::PURPOSE_REGISTRATION);

        if ($wait > 0) {
            return response()->json([
                'message' => "Please wait {$wait}s before requesting another code.",
                'resend_available_in' => $wait,
            ], 429);
        }

        $user = $transport->findUser($identifier);

        if ($user === null) {
            // A sign-up still waiting in the cache. Sending refreshes nothing
            // about the submission; it only issues a fresh code.
            if ($this->pending->get($channel, $identifier) !== null) {
                $this->otp->sendTo($channel, $identifier, OtpCode::PURPOSE_REGISTRATION, $request->ip());
            }

            return response()->json($generic);
        }

        // Gate on the specific channel, not account_status, and only resend for
        // the channel this account actually registered on.
        if (! $user->isSuspended()
            && $user->verification_channel === $channel->value
            && ! $user->hasVerifiedChannel($channel)) {
            $this->otp->send($user, OtpCode::PURPOSE_REGISTRATION, $request->ip(), $channel);
        }

        return response()->json($generic);
    }
}
