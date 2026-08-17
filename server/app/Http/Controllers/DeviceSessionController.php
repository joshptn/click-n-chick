<?php

namespace App\Http\Controllers;

use App\Events\SessionRevoked;
use App\Models\KnownDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * Known devices, trust, and remote sign-out (FR-01.11 / FR-01.13 / UC-AUTH-013).
 *
 * The user sees devices; the security operation acts on the Sanctum tokens
 * bound to them. Ownership is enforced structurally - every lookup starts from
 * $request->user()->knownDevices(), so a device belonging to another account is
 * not something this controller can reach, rather than something it remembers
 * to check.
 *
 * Trust adds a second gate on top of ownership: acting on a device OTHER than
 * the one you are holding requires the device you are holding to be trusted.
 * A stolen session therefore cannot immediately lock the real owner out of
 * their own account by signing out all their other devices.
 */
class DeviceSessionController extends Controller
{
    /** GET /api/user/devices */
    public function index(Request $request)
    {
        $user = $request->user();
        $current = $this->currentDevice($request);

        $devices = $user->knownDevices()
            ->withCount('tokens as active_session_count')
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (KnownDevice $device) => $this->present($device, $current?->getKey()));

        return response()->json([
            'devices' => $devices,
            // Lets the UI say "this device" without re-deriving the fingerprint
            // client-side. Null when the caller authenticated with a token that
            // predates device tracking.
            'current_device_id' => $current?->getKey(),
            // Drives whether the UI offers the controls for other devices at
            // all. The server still enforces it; this only avoids showing a
            // button that is guaranteed to fail.
            'current_device_trusted' => (bool) $current?->is_trusted,
        ]);
    }

    /**
     * PATCH /api/user/devices/{device}/trust
     *
     * Marks a device trusted or untrusted. Trusting the device you are holding
     * is always allowed - that is the bootstrap, since a fresh account has no
     * trusted device and could otherwise never gain one. Changing trust on any
     * OTHER device requires the device you are holding to be trusted already.
     */
    public function trust(Request $request, string $device)
    {
        $validated = $request->validate([
            'trusted' => ['required', 'boolean'],
        ]);

        $target = $this->ownedDevice($request, $device);

        if ($target === null) {
            return $this->notFound();
        }

        $denied = $this->denyIfActingOnAnotherDeviceWithoutTrust($request, $target, 'change trust on');

        if ($denied !== null) {
            return $denied;
        }

        $target->is_trusted = $validated['trusted'];
        $target->save();

        return response()->json([
            'success' => true,
            'message' => $target->is_trusted
                ? 'This device is now trusted.'
                : 'This device is no longer trusted.',
            'device' => $this->present(
                $target->loadCount('tokens as active_session_count'),
                $this->currentDevice($request)?->getKey()
            ),
        ]);
    }

    /**
     * DELETE /api/user/devices/{device}
     *
     * Revokes every session held by the selected device and leaves every other
     * device's sessions untouched.
     *
     * Note that the TARGET's trust is irrelevant: a trusted device can still be
     * signed out. Trust governs who may act, not what may be acted upon -
     * otherwise marking a stolen device trusted would make it unremovable.
     */
    public function destroy(Request $request, string $device)
    {
        $target = $this->ownedDevice($request, $device);

        if ($target === null) {
            return $this->notFound();
        }

        $denied = $this->denyIfActingOnAnotherDeviceWithoutTrust($request, $target, 'sign out');

        if ($denied !== null) {
            return $denied;
        }

        $isCurrent = $this->isCurrent($request, $target);

        // Only the sessions are destroyed. The device row survives so the
        // account keeps a record of it and the list stays stable after the
        // action - the entry flips to "signed out" rather than vanishing.
        $revoked = $target->revokeSessions();

        // Tell the revoked browser to leave. Dispatched after the tokens are
        // already gone, so the event can only ever be late, never premature.
        //
        // Guarded: the sessions are ALREADY revoked at this point, so a
        // broadcast transport that is down must not turn a completed security
        // action into a 500 that tells the user it failed. The client also
        // falls back to a 401 redirect, so the worst case is a slower exit.
        if ($revoked > 0 && ! $isCurrent) {
            try {
                SessionRevoked::dispatch((int) $request->user()->getKey(), (int) $target->getKey());
            } catch (Throwable $e) {
                Log::warning('Could not broadcast a session revocation.', ['error' => $e->getMessage()]);
            }
        }

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

    // -----------------------------------------------------------------
    // Shared guards
    // -----------------------------------------------------------------

    /**
     * Scoped find, not route-model binding: another account's device is simply
     * not in this relation, so it 404s without confirming the id exists at all.
     */
    private function ownedDevice(Request $request, string $device): ?KnownDevice
    {
        return $request->user()->knownDevices()->find($device);
    }

    private function notFound()
    {
        return response()->json([
            'success' => false,
            'message' => 'Device not found.',
        ], 404);
    }

    /**
     * The trust gate. Returns a 403 response when the caller is reaching for a
     * device other than its own from an untrusted device, or null to proceed.
     */
    private function denyIfActingOnAnotherDeviceWithoutTrust(Request $request, KnownDevice $target, string $verb)
    {
        if ($this->isCurrent($request, $target)) {
            return null;
        }

        if ($this->currentDevice($request)?->is_trusted) {
            return null;
        }

        return response()->json([
            'success' => false,
            'code' => 'DEVICE_NOT_TRUSTED',
            'message' => "Only a trusted device can {$verb} another device. Mark this device as trusted first.",
        ], 403);
    }

    private function isCurrent(Request $request, KnownDevice $device): bool
    {
        $current = $this->currentDevice($request);

        return $current !== null && (int) $current->getKey() === (int) $device->getKey();
    }

    /**
     * The device backing the token this request authenticated with.
     *
     * Guards the type: Sanctum::actingAs() in tests, and any future session
     * guard, hand back a TransientToken that has no device column.
     */
    private function currentDevice(Request $request): ?KnownDevice
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || $token->known_device_id === null) {
            return null;
        }

        // Resolved through the user so a token pointing at someone else's
        // device row - which should be impossible - still cannot grant trust.
        return $request->user()->knownDevices()->find($token->known_device_id);
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
