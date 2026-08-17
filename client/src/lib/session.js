/**
 * Ending a session from outside React.
 *
 * A session can die while the app is idle - another device signs this one out
 * (FR-01.13), or the token is revoked server-side. When that happens the tab
 * must not keep rendering a signed-in UI whose every request will 401.
 *
 * Deliberately does NOT import echo.js or AuthContext: this runs from the
 * fetch layer, and a full page navigation tears the socket and all React state
 * down anyway. Keeping it dependency-free also avoids an import cycle with
 * api.js, which echo.js already depends on.
 */

const LOGIN_PATH = "/login";

/**
 * Drop the stored credentials.
 *
 * device_id is deliberately KEPT: it identifies the browser, not the session,
 * and clearing it would make this machine look like a brand new device on the
 * next login - which would then fire a new-device security alert at the user
 * for simply signing back in.
 */
export function clearStoredSession() {
  try {
    localStorage.removeItem("token");
    localStorage.removeItem("user");
    localStorage.removeItem("device_session_id");
  } catch {
    // Storage unavailable; the redirect below still gets them out.
  }
}

/** True when there is no signed-in session left to end. */
function alreadyOnLogin() {
  return window.location.pathname.startsWith(LOGIN_PATH);
}

/**
 * Clear the session and send the browser to the login screen.
 *
 * Uses a real navigation rather than the router so no stale component keeps
 * firing authenticated requests on the way out. `replace` so the dead page
 * does not sit in history behind the login screen.
 */
export function endSession({ reason } = {}) {
  clearStoredSession();

  if (alreadyOnLogin()) return;

  const target = reason
    ? `${LOGIN_PATH}?reason=${encodeURIComponent(reason)}`
    : LOGIN_PATH;

  window.location.replace(target);
}

/** The device id issued with this session, or null. */
export function sessionDeviceId() {
  try {
    const raw = localStorage.getItem("device_session_id");

    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}
