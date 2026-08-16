import Echo from "laravel-echo";
import Pusher from "pusher-js";

import { authToken } from "./api";

/**
 * The Reverb connection.
 *
 * Two things here are specific to this application and are the whole reason a
 * default Echo setup would not work:
 *
 * 1. A CUSTOM AUTHORIZER. This API authenticates with Sanctum bearer tokens -
 *    statefulApi() is not enabled, so there is no session cookie and no CSRF
 *    token to send. Echo's stock authorizer posts cookies, which would be
 *    rejected by auth:sanctum on every private channel. The authorizer below
 *    sends `Authorization: Bearer <token>` instead.
 *
 * 2. The auth endpoint is /api/broadcasting/auth, not /broadcasting/auth,
 *    because bootstrap/app.php registers withBroadcasting() under the 'api'
 *    prefix so the route lands in the api middleware group.
 *
 * The instance is created lazily and torn down on sign-out, because the token
 * is baked into the authorizer at construction time - a stale connection would
 * keep authorizing as the previous user.
 */

// pusher-js is the wire protocol Reverb speaks; Echo looks for it on window.
window.Pusher = Pusher;

let echo = null;

/** True when the deployment has told the client where Reverb lives. */
export function isRealtimeConfigured() {
  return Boolean(import.meta.env.VITE_REVERB_APP_KEY && import.meta.env.VITE_REVERB_HOST);
}

function createEcho() {
  const scheme = import.meta.env.VITE_REVERB_SCHEME ?? "http";
  const forceTLS = scheme === "https";
  const apiUrl = import.meta.env.VITE_API_URL;

  return new Echo({
    broadcaster: "reverb",
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
    forceTLS,
    enabledTransports: forceTLS ? ["ws", "wss"] : ["ws"],

    authorizer: (channel) => ({
      authorize: (socketId, callback) => {
        const token = authToken();

        // No token means no private channel. Reporting it as an auth failure
        // is correct: the subscription genuinely cannot be authorized.
        if (!token) {
          callback(new Error("Not signed in."), null);
          return;
        }

        fetch(`${apiUrl}/api/broadcasting/auth`, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
        })
          .then(async (response) => {
            if (!response.ok) {
              // 403 here is the channel rule doing its job, not a bug.
              throw new Error(`Channel authorization failed (${response.status}).`);
            }

            callback(null, await response.json());
          })
          .catch((error) => callback(error, null));
      },
    }),
  });
}

/** The live instance, created on first use. Null when unconfigured. */
export function getEcho() {
  if (!isRealtimeConfigured()) return null;

  if (!echo) {
    echo = createEcho();
  }

  return echo;
}

/**
 * Drop the connection and forget it.
 *
 * Called on sign-out. The authorizer closes over the token that was present
 * when the instance was built, so reusing it across users would authorize the
 * next user's subscriptions with the previous user's credentials.
 */
export function disconnectEcho() {
  if (!echo) return;

  try {
    echo.disconnect();
  } catch {
    // Already gone; nothing to do.
  }

  echo = null;
}

/** Current connection state, for the UI indicator. */
export function connectionState() {
  return getEcho()?.connector?.pusher?.connection?.state ?? "unavailable";
}

export default getEcho;
