/**
 * A stable, opaque id for this browser install.
 *
 * Sent as X-Device-Id so the server can tell two sessions on the same machine
 * apart - a user agent alone cannot, so without it every Chrome-on-Windows
 * login for an account collapses into one "device" in the list.
 *
 * It is NOT a credential and proves nothing. The server derives its own
 * fingerprint from it, scopes every device row to the authenticated user, and
 * authorises remote logout against that user - never against this value.
 *
 * Deliberately survives sign-out: clearing it on logout would make the same
 * physical machine appear as a brand new device on every login, which is
 * exactly the noise this list exists to avoid.
 */

const STORAGE_KEY = "device_id";

function randomId() {
  // randomUUID needs a secure context; plain http on a LAN IP during dev is
  // not one, so fall back rather than throwing on the way to the login screen.
  if (globalThis.crypto?.randomUUID) {
    return globalThis.crypto.randomUUID();
  }

  return `dev-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

export function getDeviceId() {
  try {
    let id = localStorage.getItem(STORAGE_KEY);

    if (!id) {
      id = randomId();
      localStorage.setItem(STORAGE_KEY, id);
    }

    return id;
  } catch {
    // Private mode or storage disabled: degrade to user-agent-only grouping on
    // the server rather than breaking the request.
    return null;
  }
}

/** Header pair to spread into a fetch init, empty when storage is unavailable. */
export function deviceHeader() {
  const id = getDeviceId();

  return id ? { "X-Device-Id": id } : {};
}

export default getDeviceId;
