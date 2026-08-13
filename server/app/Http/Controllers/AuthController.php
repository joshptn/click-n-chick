<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const TOKEN_NAME = 'auth_token';

    public function register(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',

            'phone_number' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^[0-9+\-\s()]+$/'
            ],

        ]);

        $phoneHash = User::hashPhoneNumber($validated['phone_number'] ?? null);

        if ($phoneHash !== null && User::where('phone_number_hash', $phoneHash)->exists()) {
            throw ValidationException::withMessages([
                'phone_number' => 'This phone number is already registered.',
            ]);
        }

        $user = new User();
        $user->email             = $validated['email'];
        $user->password          = Hash::make($validated['password']);
        $user->first_name        = $validated['first_name'];
        $user->last_name         = $validated['last_name'];
        $user->phone_number      = $validated['phone_number'] ?? null;
        $user->phone_number_hash = $phoneHash;
        $user->save();

        $user->refresh();

        $token = $user->createToken(self::TOKEN_NAME);

        return response()->json([
            'user' => $user,
            'token' => $token->plainTextToken
        ]);
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
                'password' => 'sometimes|required|string|min:8|confirmed',
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
