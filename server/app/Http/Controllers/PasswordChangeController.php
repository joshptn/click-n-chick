<?php

namespace App\Http\Controllers;

use App\Models\OtpCode;
use App\Rules\StrongPassword;
use App\Services\Otp\OtpService;
use App\Services\Otp\OtpVerificationResult;
use App\Services\Verification\Channel;
use App\Services\Verification\ChannelRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;


class PasswordChangeController extends Controller
{
    public function __construct(
        private OtpService $otp,
        private ChannelRegistry $channels,
    ) {
    }

    public function requestCode(Request $request)
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

        $this->otp->send($user, OtpCode::PURPOSE_PASSWORD_CHANGE, $request->ip(), $channel);

        return response()->json([
            'message' => $channel === Channel::Email
                ? 'We sent a confirmation code to your email address.'
                : 'We sent a confirmation code to your phone.',
            'channel' => $channel->value,
            'identifier' => $transport->mask($transport->identifierFor($user)),
            'expires_in_minutes' => OtpService::EXPIRY_MINUTES,
            'resend_available_in' => OtpService::RESEND_COOLDOWN_SECONDS,
        ]);
    }


    public function update(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'confirmed', new StrongPassword()],
            'code' => ['required', 'string', 'max:12'],
        ]);

        $user = $request->user();

        $result = $this->otp->verifyForUser($user, OtpCode::PURPOSE_PASSWORD_CHANGE, $validated['code']);

        if ($result !== OtpVerificationResult::Verified) {
            return response()->json([
                'message' => match ($result) {
                    OtpVerificationResult::Expired => 'That code has expired. Request a new one.',
                    OtpVerificationResult::TooManyAttempts => 'Too many incorrect attempts. Request a new code.',
                    OtpVerificationResult::NoCode => 'Request a confirmation code before changing your password.',
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

        $user->password = Hash::make($validated['password']);
        $user->save();

        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password updated. Please sign in again.',
        ]);
    }
}
