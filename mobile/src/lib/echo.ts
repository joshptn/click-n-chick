import Echo from "laravel-echo";
import * as PusherModule from "pusher-js";
import type PusherType from "pusher-js";

import { api } from "../api/client";
import { currentToken } from "./session";

/**
 * The Reverb connection, as the web's (client/src/lib/echo.js):
 *
 * - private channels are authorised with the bearer token through
 *   /api/broadcasting/auth (the API has no session cookie to send);
 * - the connection is dropped when a session ends, because its private
 *   subscriptions were authorised as whoever was signed in.
 *
 * pusher-js resolves to its React Native build here, which follows the
 * phone's connection through NetInfo and reconnects by itself.
 */

const KEY = process.env.EXPO_PUBLIC_REVERB_APP_KEY ?? "";
const HOST = process.env.EXPO_PUBLIC_REVERB_HOST ?? "";
const PORT = Number(process.env.EXPO_PUBLIC_REVERB_PORT ?? 8080);
const TLS = (process.env.EXPO_PUBLIC_REVERB_SCHEME ?? "http") === "https";

type AppEcho = Echo<"reverb">;

/**
 * The Pusher class. The React Native build exports `{ Pusher }` (itself an
 * ES module with a default export) rather than a default class, so the
 * class is picked out of whichever shape arrives.
 */
const Pusher = ((): typeof PusherType => {
  const found = [PusherModule as unknown]
    .flatMap((value) => [value, (value as { default?: unknown })?.default])
    .flatMap((value) => [value, (value as { Pusher?: unknown })?.Pusher])
    .flatMap((value) => [value, (value as { default?: unknown })?.default])
    .find((value) => typeof value === "function");

  return found as typeof PusherType;
})();

/** pusher-js's channel authorisation answer (its own type is not exported). */
type ChannelAuthorization = { auth: string; channel_data?: string; shared_secret?: string };

let echo: AppEcho | null = null;

/** False when the build has no Reverb settings: screens then refresh on pull and on return. */
export function isRealtimeConfigured(): boolean {
  return KEY !== "" && HOST !== "";
}

function createEcho(): AppEcho {
  return new Echo({
    broadcaster: "reverb",
    Pusher,
    key: KEY,
    wsHost: HOST,
    wsPort: PORT,
    wssPort: PORT,
    forceTLS: TLS,
    enabledTransports: TLS ? ["ws", "wss"] : ["ws"],
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: Error | null, data: ChannelAuthorization | null) => void) => {
        // No token, no private channel: a genuine authorisation failure.
        if (!currentToken()) {
          callback(new Error("Not signed in."), null);
          return;
        }

        api
          .post<ChannelAuthorization>("/api/broadcasting/auth", { socket_id: socketId, channel_name: channel.name })
          .then((data) => callback(null, data))
          .catch((error: Error) => callback(error, null));
      },
    }),
  });
}

/** The live connection, made on first use; null without Reverb settings. */
export function getEcho(): AppEcho | null {
  if (!isRealtimeConfigured()) return null;

  if (!echo) echo = createEcho();

  return echo;
}

/** Drop the connection and forget it (sign-out). */
export function disconnectEcho() {
  if (!echo) return;

  try {
    echo.disconnect();
  } catch {
    // Already gone.
  }

  echo = null;
}
