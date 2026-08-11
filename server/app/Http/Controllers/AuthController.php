<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',

            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',

            'phone_number' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^[0-9+\-\s()]+$/'
            ],

            'location' => 'nullable|string|max:255',

            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',

            'note' => 'nullable|string|max:500',
        ]);

       
        $validated['password'] = Hash::make($validated['password']);
        

        $user = User::create($validated);

        $token = $user->createToken($request->name);

        return response()->json([
            'user' => $user,
            'token' => $token->plainTextToken
        ]);
    }

    public function login(Request $request){

         $request->validate([
            'email' => 'required|email|exists:users',
            'password' => 'required'
        ]);

        $user =  User::where('email', $request->email)->first();

        if(!$user || !Hash::check($request->password, $user->password)){
            return[ 'message' => 'The credential are wrong' ];
        }

        $token = $user->createToken($user->name);

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
                'name' => 'sometimes|required|max:255',
                'first_name' => 'nullable|string|max:255',
                'last_name' => 'nullable|string|max:255',
                'phone_number' => 'nullable|string|max:20',
                'location' => 'nullable|string|max:255',
                'latitude' => 'nullable|numeric|between:-90,90',
                'longitude' => 'nullable|numeric|between:-180,180',
                'note' => 'nullable|string|max:500',
            ]);

            if (empty(array_filter($validated, fn($v) => !is_null($v) && $v !== ''))) {
                return response()->json([
                    'success' => false,
                    'message' => 'No data provided to update.',
                    'user' => $user,
                ], 400);
            }

            // Convert latitude/longitude to floats if provided
            if (isset($validated['latitude'])) {
                $validated['latitude'] = (float) $validated['latitude'];
            }
            if (isset($validated['longitude'])) {
                $validated['longitude'] = (float) $validated['longitude'];
            }

            $user->update($validated);

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
