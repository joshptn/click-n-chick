import { useSyncExternalStore } from "react";

import { sessionSnapshot, subscribeSession } from "../lib/session";

/** The session as React state: `ready` once the stored token was read, `signedIn` while one is held. */
export function useSession() {
  const state = useSyncExternalStore(subscribeSession, sessionSnapshot, sessionSnapshot);

  return { ready: state.ready, signedIn: Boolean(state.token) };
}

export default useSession;
