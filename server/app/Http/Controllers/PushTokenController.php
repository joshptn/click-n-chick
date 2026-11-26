<?php

namespace App\Http\Controllers;

use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Where the installed PWA and the native app say "send pushes here" (PRD §9).
 * Any signed-in account: customers, and staff on the web portal since
 * 2026-11-26.
 */
class PushTokenController extends Controller
{
    /**
     * Register this device's FCM token for the signed-in account, tied to the
     * current sign-in so it is deleted when that sign-in ends.
     *
     * Safe to call on every app open: the same token just moves to the current
     * account and sign-in. That is also what happens when a second account signs
     * in on the same phone, so a device only ever receives one account's pushes.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:1024'],
            'platform' => ['required', 'string', Rule::in(PushToken::PLATFORMS)],
        ]);

        $session = $request->user()->currentAccessToken();

        if (! $session instanceof PersonalAccessToken) {
            return response()->json([
                'message' => 'Notifications can only be turned on from a signed-in app.',
                'error_code' => 'NO_SESSION',
            ], 422);
        }

        PushToken::query()->updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $request->user()->getKey(),
                'personal_access_token_id' => $session->getKey(),
                'platform' => $validated['platform'],
            ],
        );

        return response()->json(['message' => 'Notifications are on for this device.']);
    }

    /**
     * Stop pushes to this device ("turn off notifications"). Signing out needs
     * no call: the token goes with the sign-in.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:1024'],
        ]);

        PushToken::query()
            ->where('user_id', $request->user()->getKey())
            ->where('token', $validated['token'])
            ->delete();

        return response()->json(['message' => 'Notifications are off for this device.']);
    }
}
