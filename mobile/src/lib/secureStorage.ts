import * as SecureStore from "expo-secure-store";

/**
 * What the app keeps in the device's secure storage (Android Keystore).
 * Nothing else is persisted: no profile, no API responses (the web app's
 * cross-account cache bug of 2026-11-13 is why).
 */
export const STORAGE_KEYS = {
  /** The Sanctum bearer token of the signed-in session. */
  token: "session_token",
  /** This install's id, sent as X-Device-Id. Survives sign-out. */
  deviceId: "device_id",
  /** A guest's cart key, sent as X-Guest-Token (lib/guestCart.ts). */
  guestCart: "guest_cart_token",
  /** A guest's tracked orders and their link tokens (lib/guestOrders.ts). */
  guestOrders: "guest_tracked_orders",
  /** The checkout being paid for, so a cold start can resume it (lib/guestOrders.ts). */
  pendingCheckout: "pending_checkout",
  /** Push on or off for one account on this phone; the user id is appended (lib/push.ts). */
  pushPreference: "push_pref_",
} as const;

export async function readSecure(key: string): Promise<string | null> {
  try {
    return await SecureStore.getItemAsync(key);
  } catch {
    // Keystore unavailable or the entry unreadable after a restore: treat as
    // absent rather than crash on the way in.
    return null;
  }
}

export async function writeSecure(key: string, value: string): Promise<void> {
  await SecureStore.setItemAsync(key, value);
}

export async function removeSecure(key: string): Promise<void> {
  try {
    await SecureStore.deleteItemAsync(key);
  } catch {
    // Already gone.
  }
}
