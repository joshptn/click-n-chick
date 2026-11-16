import { randomUUID } from "expo-crypto";

import { STORAGE_KEYS, readSecure, writeSecure } from "./secureStorage";

/**
 * A stable, opaque id for this install, sent as X-Device-Id (as the web app
 * does). It lets the server tell this phone's sessions apart in the account's
 * device list. Not a credential: the server scopes everything to the signed-in
 * account.
 *
 * Kept across sign-out on purpose: a new id on every sign-in would make the
 * same phone look new each time and set off a new-device alert.
 */
let cached: string | null = null;

export async function getDeviceId(): Promise<string | null> {
  if (cached) return cached;

  const stored = await readSecure(STORAGE_KEYS.deviceId);

  if (stored) {
    cached = stored;
    return cached;
  }

  const id = randomUUID();

  try {
    await writeSecure(STORAGE_KEYS.deviceId, id);
  } catch {
    // Not persisted: still usable for this run.
  }

  cached = id;

  return cached;
}
