/**
 * The guest's cart key.
 *
 * Minted by the server and replayed as X-Guest-Token. It is NOT a credential and
 * proves nothing: it names a bucket of food ids, and the server fences the lookup
 * to carts with no owner, so it can never reach an account's cart. The order
 * token that comes later is a different value, handled separately, because that
 * one really does grant something.
 *
 * Survives signing in just long enough for the account to absorb the cart it
 * names (CartProvider), and is forgotten once that merge succeeds.
 */

const STORAGE_KEY = "guest_token";

export function guestToken() {
  try {
    return localStorage.getItem(STORAGE_KEY) || null;
  } catch {
    // Private mode or storage disabled. The cart will not survive a reload,
    // which is a better outcome than refusing to add to it.
    return null;
  }
}

export function rememberGuestToken(token) {
  if (typeof token !== "string" || token === "") return;

  try {
    localStorage.setItem(STORAGE_KEY, token);
  } catch {
    // As above.
  }
}

/** Once the cart it names has been folded into an account, the key is dead weight. */
export function forgetGuestToken() {
  try {
    localStorage.removeItem(STORAGE_KEY);
  } catch {
    // Nothing stored, so nothing to forget.
  }
}

/** Header pair to spread into a fetch init, empty until the server has minted one. */
export function guestHeader() {
  const token = guestToken();

  return token ? { "X-Guest-Token": token } : {};
}

export default guestToken;
