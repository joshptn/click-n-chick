import { queryClient } from "./queryClient";
import { STORAGE_KEYS, readSecure, removeSecure, writeSecure } from "./secureStorage";

/**
 * The signed-in session: one bearer token, held in secure storage and mirrored
 * here so every request can read it synchronously.
 *
 * Carries the web app's two session lessons (fixed there on 2026-11-13):
 * - the query cache is cleared when a session ends or another replaces it, so
 *   one account's data is never shown to the next;
 * - a response to a request sent under an ended session is discarded
 *   (api/client.ts compares the token it sent with the current one).
 */

type SessionState = {
  /** False until the stored token has been read at launch. */
  ready: boolean;
  token: string | null;
};

let state: SessionState = { ready: false, token: null };
const listeners = new Set<() => void>();

function set(next: Partial<SessionState>) {
  state = { ...state, ...next };
  listeners.forEach((listener) => listener());
}

export function subscribeSession(listener: () => void) {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

export function sessionSnapshot(): SessionState {
  return state;
}

export function currentToken(): string | null {
  return state.token;
}

/** Read the stored token once, at launch. */
export async function loadSession(): Promise<void> {
  if (state.ready) return;

  set({ ready: true, token: await readSecure(STORAGE_KEYS.token) });
}

/** Adopt a new session (sign-in, phase 5). A different account starts clean. */
export async function startSession(token: string): Promise<void> {
  if (state.token !== token) queryClient.clear();

  await writeSecure(STORAGE_KEYS.token, token);
  set({ token });
}

/**
 * End the session: on sign-out, or when the API says the token stopped working
 * (signed out from another device, password changed, expired). The protected
 * routes then send the app back to the public screens by themselves.
 */
export async function endSession(): Promise<void> {
  // The in-memory token goes first, so anything still in flight is discarded
  // when it answers; then the cache, then the stored copy.
  set({ token: null });
  queryClient.clear();
  await removeSecure(STORAGE_KEYS.token);
}
