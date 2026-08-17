<?php

namespace App\Http\Controllers;

use App\Models\OtpCode;
use App\Rules\StrongPassword;
use App\Services\Auth\DeviceRegistrar;
use App\Services\Otp\OtpService;
use App\Services\Otp\OtpVerificationResult;
use App\Services\Verification\ChannelRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PasswordResetController extends Controller
{
    private const TOKEN_NAME = 'auth_token';

    public function __construct(
        private OtpService $otp,
        private ChannelRegistry $channels,
    ) {
    }

    public function requestCode(Request $request)
    {
        $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        $generic = response()->json([
            'message' => 'If that phone number or email address is registered, we have sent a reset code to it.',
            'expires_in_minutes' => OtpService::EXPIRY_MINUTES,
            'resend_available_in' => OtpService::RESEND_COOLDOWN_SECONDS,
        ]);

        $identifier = trim((string) $request->input('identifier'));
        $transport = $this->channels->forIdentifier($identifier);

        if ($transport === null) {
            return $generic;
        }

        if (! $transport->isAvailable()) {
            return $generic;
        }

        $user = $transport->findUser($identifier);

        if ($user === null || ! $user->hasVerifiedChannel($transport->channel())) {
            return $generic;
        }

        $identifierHash = $this->channels->hash($transport->channel(), $identifier);

        if ($this->otp->secondsUntilResendAllowed($identifierHash, OtpCode::PURPOSE_PASSWORD_RESET) > 0) {
            return $generic;
        }

        $this->otp->send($user, OtpCode::PURPOSE_PASSWORD_RESET, $request->ip(), $transport->channel());

        return $generic;
    }

    public function reset(Request $request)
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:12'],
            'password' => ['required', 'string', 'confirmed', new StrongPassword()],
        ]);

        $invalid = response()->json([
            'message' => 'That code is not correct.',
            'reason' => 'invalid',
        ], 422);

        $identifier = trim($validated['identifier']);
        $transport = $this->channels->forIdentifier($identifier);

        if ($transport === null) {
            return $invalid;
        }

        $identifierHash = $this->channels->hash($transport->channel(), $identifier);

        $result = $this->otp->verify($identifierHash, OtpCode::PURPOSE_PASSWORD_RESET, $validated['code']);

        if ($result !== OtpVerificationResult::Verified) {
            return response()->json([
                'message' => match ($result) {
                    OtpVerificationResult::Expired => 'That code has expired. Request a new one.',
                    OtpVerificationResult::TooManyAttempts => 'Too many incorrect attempts. Request a new code.',
                    OtpVerificationResult::NoCode => 'Request a reset code before choosing a new password.',
                    default => 'That code is not correct.',
                },
                'reason' => match ($result) {
                    OtpVerificationResult::Expired => 'expired',
                    OtpVerificationResult::TooManyAttempts => 'too_many_attempts',
                    OtpVerificationResult::NoCode => 'no_code',
                    default => 'invalid',
                },
            ], 422);
        }

        $user = $transport->findUser($identifier);

        if ($user === null || ! $user->hasVerifiedChannel($transport->channel())) {
            return $invalid;
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        $user->tokens()->delete();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(array_merge(
                app(TwoFactorController::class)->issueLoginChallenge($user, $request->ip()),
                ['message' => 'Your password has been reset. Enter the code we sent to finish signing in.'],
            ), 200);
        }

        $token = app(DeviceRegistrar::class)->issueToken($user, $request, self::TOKEN_NAME);

        return response()->json([
            'message' => 'Your password has been reset.',
            'user' => $user->fresh(),
            'token' => $token->plainTextToken,
            'device_id' => $token->accessToken->known_device_id,
        ]);
    }
}
