<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\Otp\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        ]);

        $phoneHash = User::hashPhoneNumber($validated['phone_number']);

        if ($phoneHash === null) {
            throw ValidationException::withMessages([
                'phone_number' => 'Enter a valid Philippine mobile number.',
            ]);
        }

        $user = DB::transaction(function () use ($validated, $phoneHash) {
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
                    return $this->fillRegistration($pending, $validated, $phoneHash);
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

            return $this->fillRegistration(new User(), $validated, $phoneHash);
        });

        $otp->send($user, OtpCode::PURPOSE_REGISTRATION, $request->ip());

        return response()->json([
            'status' => User::STATUS_PENDING_VERIFICATION,
            'message' => 'We sent a verification code to your phone.',
            'phone_number' => $this->maskPhoneNumber($user->phone_number),
            'expires_in_minutes' => OtpService::EXPIRY_MINUTES,
            'resend_available_in' => OtpService::RESEND_COOLDOWN_SECONDS,
        ], 201);
    }

    /** Apply a registration submission to a new or reused pending row. */
    private function fillRegistration(User $user, array $validated, string $phoneHash): User
    {
        $user->email             = $validated['email'];
        $user->password          = Hash::make($validated['password']);
        $user->first_name        = $validated['first_name'];
        $user->last_name         = $validated['last_name'];
        $user->phone_number      = $validated['phone_number'];
        $user->phone_number_hash = $phoneHash;
        $user->account_status    = User::STATUS_PENDING_VERIFICATION;
        $user->phone_verified_at = null;
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

        // Blocking flow: correct credentials are not enough while the phone is
        // unverified. Answered distinctly (and only after the password checks out,
        // so it is not an enumeration signal) so the UI can route to the code screen.
        if ($user->isPendingVerification()) {
            return response()->json([
                'status' => User::STATUS_PENDING_VERIFICATION,
                'message' => 'Verify your phone number to finish setting up your account.',
                'phone_number' => $this->maskPhoneNumber($user->phone_number),
            ], 403);
        }

        $token = $user->createToken(self::TOKEN_NAME);

        return [
            'user' => $user,
            'token' => $token->plainTextToken
        ];
    }

    public function userDetails(Request $request){
        return $request->user();
    }

    public function logout(Request $request){

        $request->user()->tokens()->delete();

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

                // Password change: the current password must be supplied and correct.
                'current_password' => ['required_with:password', 'current_password'],
                'password' => ['sometimes', 'required', 'string', 'confirmed', new StrongPassword()],
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
            if (array_key_exists('password', $validated)) {
                $user->password = Hash::make($validated['password']);
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
