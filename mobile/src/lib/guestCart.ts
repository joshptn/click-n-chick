import { STORAGE_KEYS, readSecure, removeSecure, writeSecure } from "./secureStorage";

/**
 * The guest's cart key, as on the web (client/src/lib/guest.js).
 *
 * Minted by the API on a guest's first add and replayed as X-Guest-Token. It
 * is not a credential: it names a cart with no owner, and the API fences the
 * lookup to such carts, so it can never reach an account's cart.
 *
 * Kept in secure storage so a guest's cart survives closing the app, and
 * mirrored here so every request can read it synchronously. It survives
 * signing in just long enough for the account to take the cart over (the
 * merge in hooks/useCart.tsx), and is forgotten once that succeeds.
 */

let token: string | null = null;

/** Read the stored key once, at launch. */
export async function loadGuestToken(): Promise<void> {
  token = await readSecure(STORAGE_KEYS.guestCart);
}

export function guestToken(): string | null {
  return token;
}

export async function rememberGuestToken(value: unknown): Promise<void> {
  if (typeof value !== "string" || value === "" || value === token) return;

  token = value;
  await writeSecure(STORAGE_KEYS.guestCart, value).catch(() => {
    // The cart still works for this run; it just will not survive a restart.
  });
}

/** Once the cart it names has been folded into an account, the key is dead weight. */
export async function forgetGuestToken(): Promise<void> {
  token = null;
  await removeSecure(STORAGE_KEYS.guestCart);
}
