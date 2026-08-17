/**
 * Known devices / active sessions (FR-01.13).
 *
 * Both calls go through api.js, so the bearer token and the X-Device-Id hint
 * are attached the same way as everywhere else. The server decides ownership;
 * nothing here is trusted to scope the request.
 */

import api from "./api";

export function fetchDevices({ signal } = {}) {
  return api.get("/api/user/devices", { signal });
}

/**
 * Sign a device out.
 *
 * Resolves to { revoked_sessions, current_device_revoked }. When
 * current_device_revoked is true the caller's own token has just been
 * destroyed and the local session must be cleared.
 */
export function revokeDevice(deviceId) {
  return api.delete(`/api/user/devices/${deviceId}`);
}

/**
 * Mark a device trusted or untrusted.
 *
 * Only a trusted device may act on another device, so the first call a user
 * makes is always against their own device - the server permits that
 * unconditionally, which is what makes the first trust obtainable.
 */
export function setDeviceTrust(deviceId, trusted) {
  return api.patch(`/api/user/devices/${deviceId}/trust`, { trusted });
}
