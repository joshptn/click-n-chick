<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\Auth\DeviceRegistrar;
use App\Services\Otp\OtpService;
use App\Services\Verification\Channel;
use App\Services\Verification\ChannelRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    private const TOKEN_NAME = 'auth_token';

    /**
     * Step 1 of the blocking registration flow.
     *
     * Creates the account as pending_verification and texts an OTP. No token is
     * issued and no authenticated access is granted here - the caller must clear
     * OtpController::verify first. There is no skip path.
     */
    public function register(Request $request, OtpService $otp)
    {
        // Uniqueness is resolved by hand below rather than with a `unique` rule:
        // an unverified row for the same email/phone must be reusable as a resend
        // instead of being rejected as a duplicate.
        $validated = $request->validate([
            'email' => 'required|string|email|max:255',
            'password' => ['required', 'string', 'confirmed', new StrongPassword()],
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone_number' => [
                'required',
                'string',
                'max:20',
                'regex:/^[0-9+\-\s()]+$/',
            ],
            // PRD §6.2: the user picks which channel blocks registration. Both
            // identifiers are always collected (FR-01.1); only the chosen one is
            // verified now. Defaults to sms when a caller omits it.
            'verification_channel' => ['sometimes', 'string', Rule::in(Channel::values())],
        ]);

        $channel = Channel::tryFromValue($validated['verification_channel'] ?? null) ?? Channel::Sms;

        $phoneHash = User::hashPhoneNumber($validated['phone_number']);

        if ($phoneHash === null) {
            throw ValidationException::withMessages([
                'phone_number' => 'Enter a valid Philippine mobile number.',
            ]);
        }

        $user = DB::transaction(function () use ($validated, $phoneHash, $channel) {
            $pending = User::query()
                ->where('account_status', User::STATUS_PENDING_VERIFICATION)
                ->where(function ($query) use ($validated, $phoneHash) {
                    $query->where('email', $validated['email'])
                        ->orWhere('phone_number_hash', $phoneHash);
                })
                ->lockForUpdate()
                ->first();

            if ($pending !== null) {
                if ($pending->created_at->gt(now()->subHours(User::PENDING_VERIFICATION_HOURS))) {
                    // Live pending signup. Refresh the details from this submission
                    // and fall through to a resend rather than erroring.
                    return $this->fillRegistration($pending, $validated, $phoneHash, $channel);
                }

                // Past the abandonment window: the row is forfeit and this attempt
                // may take the email/phone over.
                $pending->delete();
            }

            // Only accounts that got past verification block a signup.
            if (User::where('email', $validated['email'])->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'This email address is already registered.',
                ]);
            }

            if (User::where('phone_number_hash', $phoneHash)->exists()) {
                throw ValidationException::withMessages([
                    'phone_number' => 'This phone number is already registered.',
                ]);
            }

            return $this->fillRegistration(new User(), $validated, $phoneHash, $channel);
        });

        $otp->send($user, OtpCode::PURPOSE_REGISTRATION, $request->ip(), $channel);

        $transport = app(ChannelRegistry::class)->for($channel);
        $identifier = $transport->identifierFor($user);

        return response()->json([
            'status' => User::STATUS_PENDING_VERIFICATION,
            'message' => $channel === Channel::Email
                ? 'We sent a verification code to your email address.'
                : 'We sent a verification code to your phone.',
            'verification_channel' => $channel->value,
            // Whichever identifier the code went to, masked. `phone_number` is
            // kept alongside it so existing callers keep reading what they expect.
            'identifier' => $transport->mask($identifier),
            'phone_number' => $this->maskPhoneNumber($user->phone_number),
            'expires_in_minutes' => OtpService::EXPIRY_MINUTES,
            'resend_available_in' => OtpService::RESEND_COOLDOWN_SECONDS,
        ], 201);
    }

    /** Apply a registration submission to a new or reused pending row. */
    private function fillRegistration(User $user, array $validated, string $phoneHash, Channel $channel): User
    {
        $user->email                = $validated['email'];
        $user->password             = Hash::make($validated['password']);
        $user->first_name           = $validated['first_name'];
        $user->last_name            = $validated['last_name'];
        $user->phone_number         = $validated['phone_number'];
        $user->phone_number_hash    = $phoneHash;
        $user->verification_channel = $channel->value;
        $user->account_status       = User::STATUS_PENDING_VERIFICATION;
        // Both cleared: a re-used pending row must not carry a stale
        // verification from an earlier attempt on the other channel.
        $user->phone_verified_at    = null;
        $user->email_verified_at    = null;
        $user->save();

        return $user->refresh();
    }

    /** '+639171234567' -> '+639*****567', for echoing back to the UI. */
    private function maskPhoneNumber(?string $phone): ?string
    {
        if ($phone === null || strlen($phone) < 7) {
            return $phone;
        }

        return substr($phone, 0, 4).str_repeat('*', strlen($phone) - 7).substr($phone, -3);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'login' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'email' => ['required_without:login', 'nullable', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $identifier = trim((string) ($validated['login'] ?? $validated['email'] ?? ''));

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', $identifier)->first();
        } else {
            $phoneHash = User::hashPhoneNumber($identifier);

            $user = $phoneHash === null
                ? null
                : User::where('phone_number_hash', $phoneHash)->first();
        }

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'The credentials are wrong'], 401);
        }

        $registry = app(ChannelRegistry::class);

        // Gate on the per-channel timestamp, NOT on account_status. That column
        // is mass-assignable and any path that flipped it to 'active' would
        // otherwise let an unverified account straight in.
        if (! $user->hasVerifiedChosenChannel()) {
            $transport = $registry->forUser($user);

            return response()->json([
                'status' => User::STATUS_PENDING_VERIFICATION,
                // Always names the specific channel - never "verify your account".
                'message' => $transport->channel() === Channel::Email
                    ? 'Verify your email address to finish setting up your account.'
                    : 'Verify your phone number to finish setting up your account.',
                'verification_channel' => $transport->channel()->value,
                'identifier' => $transport->mask($transport->identifierFor($user)),
                'phone_number' => $this->maskPhoneNumber($user->phone_number),
            ], 403);
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

    public function userDetails(Request $request){
        return $request->user();
    }

    public function logout(Request $request){

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
            'message' => 'You are logged out'
        ];
    }

    public function updateUser(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access. Please log in again.'
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
            if (!$changed) {
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

    public function updateUserRole(Request $request, User $user)
    {
        $actor = $request->user();

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(['super_admin', 'admin', 'customer'])],
        ]);

        if ($actor->id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot change your own role.',
            ], 422);
        }

        $isDemotingASuperAdmin = $user->role === 'super_admin'
            && $validated['role'] !== 'super_admin';

        if ($isDemotingASuperAdmin) {
            $remaining = User::where('role', 'super_admin')
                ->whereKeyNot($user->getKey())
                ->count();

            if ($remaining < 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot remove the last super admin.',
                ], 422);
            }
        }

        $user->role = $validated['role'];
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully.',
            'user' => $user->fresh(),
        ], 200);
    }
}
