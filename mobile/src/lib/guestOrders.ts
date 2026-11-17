import { useSyncExternalStore } from "react";

import { STORAGE_KEYS, readSecure, removeSecure, writeSecure } from "./secureStorage";

/**
 * The orders a guest has tracked on this phone (decided 2026-11-17), so Home
 * can show "Track it live" without the link. Kept only as long as the link
 * works (FR-02.13): while the order is open, to 30 days after it was placed,
 * and 7 days after it closes. The API is the authority: a link it refuses
 * (expired, unknown) is dropped here too.
 *
 * The token grants access to its order, so it lives in secure storage, like
 * the session token.
 */
export type TrackedOrder = {
  token: string;
  reference: string | null;
  /** When the order was placed (ISO), for the 30-day ceiling. */
  placedAt: string | null;
  /** When it closed (ISO), for the 7 days after. */
  closedAt: string | null;
};

const OPEN_LIMIT_MS = 30 * 24 * 60 * 60 * 1000;
const CLOSED_LIMIT_MS = 7 * 24 * 60 * 60 * 1000;
const MAX_KEPT = 10;

let orders: TrackedOrder[] = [];
const listeners = new Set<() => void>();

function stillValid(order: TrackedOrder, now = Date.now()): boolean {
  const placed = order.placedAt ? Date.parse(order.placedAt) : now;
  const closed = order.closedAt ? Date.parse(order.closedAt) : null;

  if (closed !== null) return now - closed < CLOSED_LIMIT_MS;

  return now - placed < OPEN_LIMIT_MS;
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
