<?php

namespace App\Http\Controllers;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\Otp\OtpService;
use App\Services\Otp\OtpVerificationResult;
use App\Services\Verification\ChannelRegistry;
use App\Services\Verification\VerificationChannel;
use Illuminate\Http\Request;

class OtpController extends Controller
{
    private const TOKEN_NAME = 'auth_token';

    public function __construct(
        private OtpService $otp,
        private ChannelRegistry $channels,
    ) {
    }

    /**
     * Pull the identifier out of a request that may name it either way.
     *
     * Callers submit `phone_number` or `email`; the channel follows from the
     * shape of whichever arrives, so there is no separate channel parameter and
     * neither path needs its own endpoint.
     *
     * @return array{0: ?VerificationChannel, 1: ?string}
     */
    private function resolveIdentifier(Request $request): array
    {
        $raw = $request->input('phone_number') ?? $request->input('email');

        if (! is_string($raw) || trim($raw) === '') {
            return [null, null];
        }

        return [$this->channels->forIdentifier($raw), trim($raw)];
    }

    public function verify(Request $request)
    {
        $request->validate([
            'phone_number' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'email' => ['required_without:phone_number', 'nullable', 'string', 'max:255'],
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

        $identifierHash = $this->channels->hash($transport->channel(), $identifier);

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

        if (! $user) {
            return $invalid;
        }

        $transport->markVerified($user);
        $user->account_status = User::STATUS_ACTIVE;
        $user->save();

        $token = $user->createToken(self::TOKEN_NAME);

        return response()->json([
            'user' => $user->fresh(),
            'token' => $token->plainTextToken,
        ]);
    }


    public function resend(Request $request)
    {
        $request->validate([
            'phone_number' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'email' => ['required_without:phone_number', 'nullable', 'string', 'max:255'],
        ]);

        [$transport, $identifier] = $this->resolveIdentifier($request);

        $generic = [
            'message' => 'If that account is awaiting verification, a new code is on its way.',
            'resend_available_in' => OtpService::RESEND_COOLDOWN_SECONDS,
            'expires_in_minutes' => OtpService::EXPIRY_MINUTES,
        ];

        if ($transport === null) {
            return response()->json($generic);
        }

        $identifierHash = $this->channels->hash($transport->channel(), $identifier);

        $wait = $this->otp->secondsUntilResendAllowed($identifierHash, OtpCode::PURPOSE_REGISTRATION);

        if ($wait > 0) {
            return response()->json([
                'message' => "Please wait {$wait}s before requesting another code.",
                'resend_available_in' => $wait,
            ], 429);
        }

        $user = $transport->findUser($identifier);

        if ($user !== null && $user->isPendingVerification()) {
            $this->otp->send($user, OtpCode::PURPOSE_REGISTRATION, $request->ip(), $transport->channel());
        }

        return response()->json($generic);
    }
}
