<?php

namespace App\Http\Controllers;

use App\Models\KnownDevice;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Known devices and their active sessions (FR-01.13 / UC-AUTH-013).
 *
 * The user sees devices; the security operation acts on the Sanctum tokens
 * bound to them. Ownership is enforced structurally - every lookup starts from
 * $request->user()->knownDevices(), so a device belonging to another account is
 * not something this controller can reach, rather than something it remembers
 * to check.
 */
class DeviceSessionController extends Controller
{
    /** GET /api/user/devices */
    public function index(Request $request)
    {
        $user = $request->user();
        $currentDeviceId = $this->currentDeviceId($request);

        $devices = $user->knownDevices()
            ->withCount('tokens as active_session_count')
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (KnownDevice $device) => $this->present($device, $currentDeviceId));

        return response()->json([
            'devices' => $devices,
            // Lets the UI say "this device" without re-deriving the fingerprint
            // client-side. Null when the caller authenticated with a token that
            // predates device tracking.
            'current_device_id' => $currentDeviceId,
        ]);
    }

    /**
     * DELETE /api/user/devices/{device}
     *
     * Revokes every session held by the selected device and leaves every other
     * device's sessions untouched.
     */
    public function destroy(Request $request, string $device)
    {
        $user = $request->user();

        // Scoped find, not route-model binding: another account's device is
        // simply not in this relation, so it 404s without confirming that the
        // id exists at all.
        $target = $user->knownDevices()->find($device);

        if ($target === null) {
            return response()->json([
                'success' => false,
                'message' => 'Device not found.',
            ], 404);
        }

        $currentDeviceId = $this->currentDeviceId($request);
        $isCurrent = $currentDeviceId !== null && (int) $currentDeviceId === (int) $target->getKey();

        // Only the sessions are destroyed. The device row survives so the
        // account keeps a record of it and the list stays stable after the
        // action - the entry flips to "signed out" rather than vanishing.
        $revoked = $target->revokeSessions();

        return response()->json([
            'success' => true,
            'message' => $isCurrent
                ? 'This device has been signed out.'
                : 'That device has been signed out.',
            'revoked_sessions' => $revoked,
            // The caller just revoked its own credential; every later request
            // with it will 401. The SPA uses this to clear local state and send
            // the user to the login screen rather than looking signed in until
            // the next failed call.
            'current_device_revoked' => $isCurrent,
        ]);
    }

    /**
     * The device backing the token this request authenticated with.
     *
     * Guards the type: Sanctum::actingAs() in tests, and any future session
     * guard, hand back a TransientToken that has no device column.
     */
    private function currentDeviceId(Request $request): ?int
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken
            ? $token->known_device_id
            : null;
    }

    /** Display shape. Deliberately carries no token value of any kind. */
    private function present(KnownDevice $device, ?int $currentDeviceId): array
    {
        return [
            'id' => $device->getKey(),
            'name' => $device->device_name,
            'platform' => $device->platform,
            'last_ip_address' => $device->last_ip_address,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'first_seen_at' => $device->created_at?->toIso8601String(),
            'is_trusted' => (bool) $device->is_trusted,
            'active_session_count' => (int) $device->active_session_count,
            'is_active' => $device->active_session_count > 0,
            'is_current' => $currentDeviceId !== null
                && (int) $currentDeviceId === (int) $device->getKey(),
        ];
    }
}
