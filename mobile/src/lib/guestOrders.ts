import { useSyncExternalStore } from "react";

import { STORAGE_KEYS, readSecure, removeSecure, writeSecure } from "./secureStorage";

/**
 * The orders a guest has tracked on this phone (decided 2026-11-17), so Home
 * can show "Track it live" without the link. Kept only while the order is
 * open, and never past the link's 7 days from placement, matching the
 * browser (FR-02.13c, decided 2026-11-22). The API is the authority: a link
 * it refuses (expired, unknown) is dropped here too.
 *
 * The token grants access to its order, so it lives in secure storage, like
 * the session token.
 */
export type TrackedOrder = {
  token: string;
  reference: string | null;
  /** When the order was placed (ISO): the link works 7 days from then. */
  placedAt: string | null;
  /** When it closed (ISO); a closed order is not kept. */
  closedAt: string | null;
};

const LIFETIME_MS = 7 * 24 * 60 * 60 * 1000;
const MAX_KEPT = 10;

let orders: TrackedOrder[] = [];
const listeners = new Set<() => void>();

function stillValid(order: TrackedOrder, now = Date.now()): boolean {
  if (order.closedAt) return false;

  const placed = order.placedAt ? Date.parse(order.placedAt) : now;

  return Number.isFinite(placed) && now - placed < LIFETIME_MS;
}

async function save(next: TrackedOrder[]) {
  orders = next;
  listeners.forEach((listener) => listener());

  if (next.length === 0) await removeSecure(STORAGE_KEYS.guestOrders);
  else await writeSecure(STORAGE_KEYS.guestOrders, JSON.stringify(next)).catch(() => {});
}

/** Read the list once, at launch, dropping links that have run out. */
export async function loadGuestOrders(): Promise<void> {
  const raw = await readSecure(STORAGE_KEYS.guestOrders);

  let stored: TrackedOrder[] = [];

  try {
    const parsed: unknown = raw ? JSON.parse(raw) : [];
    if (Array.isArray(parsed)) stored = parsed.filter((item) => typeof item?.token === "string");
  } catch {
    // Unreadable: start again.
  }

  const valid = stored.filter((order) => stillValid(order));

  orders = valid;
  if (valid.length !== stored.length) await save(valid);
}

/** Remember (or refresh) an order whose link was opened. Newest first. */
export async function rememberGuestOrder(order: TrackedOrder): Promise<void> {
  const rest = orders.filter((item) => item.token !== order.token);
  const existing = orders.find((item) => item.token === order.token);

  const same =
    existing &&
    existing.reference === order.reference &&
    existing.placedAt === order.placedAt &&
    existing.closedAt === order.closedAt &&
    orders[0]?.token === order.token;

  if (same) return;

  // A handful at most: secure storage is meant for small values.
  await save([order, ...rest].filter((item) => stillValid(item)).slice(0, MAX_KEPT));
}

/** The API refused the link (expired or unknown). */
export async function forgetGuestOrder(token: string): Promise<void> {
  if (!orders.some((item) => item.token === token)) return;

  await save(orders.filter((item) => item.token !== token));
}

function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

const snapshot = () => orders;

export function useGuestOrders(): TrackedOrder[] {
  return useSyncExternalStore(subscribe, snapshot, snapshot);
}

/**
 * The checkout being paid for, a guest's or a signed-in customer's (decided
 * 2026-11-22 and 2026-11-23). Android may close the app while the customer is
 * in GCash or a bank app; started cold, the return link then has no screen to
 * step back to, so payment-return resumes the payment screen from this.
 * Remembered when the order is placed, cleared once it is paid or its time
 * to pay has run out.
 */
export type PendingCheckout = { token?: string; orderId?: string };

/** Ten minutes to pay, and a slow return: an hour, no more. */
const PENDING_TTL_MS = 60 * 60 * 1000;

export async function rememberPendingCheckout(target: PendingCheckout): Promise<void> {
  const saved = { token: target.token ?? null, orderId: target.orderId ?? null, at: Date.now() };

  await writeSecure(STORAGE_KEYS.pendingCheckout, JSON.stringify(saved)).catch(() => {});
}

export async function readPendingCheckout(): Promise<PendingCheckout | null> {
  const raw = await readSecure(STORAGE_KEYS.pendingCheckout).catch(() => null);

  try {
    const saved: unknown = raw ? JSON.parse(raw) : null;

    if (!saved || typeof saved !== "object") return null;

    const { token, orderId, at } = saved as { token?: unknown; orderId?: unknown; at?: unknown };

    if (typeof at !== "number" || Date.now() - at > PENDING_TTL_MS) return null;
    if (typeof token === "string" && token !== "") return { token };
    if (typeof orderId === "string" && orderId !== "") return { orderId };
  } catch {
    // Unreadable: nothing to resume.
  }

  return null;
}

export async function clearPendingCheckout(): Promise<void> {
  await removeSecure(STORAGE_KEYS.pendingCheckout).catch(() => {});
}
