<?php

namespace App\Http\Controllers;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\Otp\OtpService;
use App\Services\Otp\OtpVerificationResult;
use Illuminate\Http\Request;

class OtpController extends Controller
{
    private const TOKEN_NAME = 'auth_token';

    public function __construct(private OtpService $otp)
    {
    }

    /**
     * Step 2 of the blocking registration flow.
     *
     * On success the account becomes active and this endpoint issues the token
     * itself, in the same shape login returns, so the client never has to make a
     * second round trip to /login.
     */
    public function verify(Request $request)
    {
        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'max:12'],
        ]);

        // Every OTP lookup goes through the hash; phone_number is encrypted with a
        // random IV and can never be matched in a WHERE clause.
        $phoneHash = User::hashPhoneNumber($validated['phone_number']);

        $result = $this->otp->verify($phoneHash, OtpCode::PURPOSE_REGISTRATION, $validated['code']);

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

        $user = User::where('phone_number_hash', $phoneHash)->first();

        if (! $user) {
            return response()->json(['message' => 'That code is not correct.', 'reason' => 'invalid'], 422);
        }

        $user->phone_verified_at = now();
        $user->account_status = User::STATUS_ACTIVE;
        $user->save();

        $token = $user->createToken(self::TOKEN_NAME);

        return response()->json([
            'user' => $user->fresh(),
            'token' => $token->plainTextToken,
        ]);
    }

    /**
     * Re-send the registration code.
     *
     * Answers the same way whether or not the number is awaiting verification,
     * so this cannot be used to test which numbers have signed up. The only
     * distinct response is the cooldown refusal.
     */
    public function resend(Request $request)
    {
        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'max:20'],
        ]);

        $phoneHash = User::hashPhoneNumber($validated['phone_number']);

        $generic = [
            'message' => 'If that number is awaiting verification, a new code is on its way.',
            'resend_available_in' => OtpService::RESEND_COOLDOWN_SECONDS,
            'expires_in_minutes' => OtpService::EXPIRY_MINUTES,
        ];

        if ($phoneHash === null) {
            return response()->json($generic);
        }

        $wait = $this->otp->secondsUntilResendAllowed($phoneHash, OtpCode::PURPOSE_REGISTRATION);

        if ($wait > 0) {
            // A per-number cooldown on top of the throttle. Semaphore's OTP
            // endpoint is explicitly NOT rate limited on their side, so nothing
            // upstream stops a customer mashing "resend" from burning credits and
            // spamming their own handset. This cooldown is load-bearing.
            return response()->json([
                'message' => "Please wait {$wait}s before requesting another code.",
                'resend_available_in' => $wait,
            ], 429);
        }

        $user = User::query()
            ->where('phone_number_hash', $phoneHash)
            ->where('account_status', User::STATUS_PENDING_VERIFICATION)
            ->first();

        if ($user !== null) {
            $this->otp->send($user, OtpCode::PURPOSE_REGISTRATION, $request->ip());
        }

        return response()->json($generic);
    }
}
