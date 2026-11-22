import { useSyncExternalStore } from "react";

/**
 * A guest's orders kept in this browser (FR-02.13c, decided 2026-11-22), so a
 * closed tab does not lose the link and Home can show "Track it live".
 *
 * Kept only while the order is open, and never past the link's 7 days from
 * placement: the tracking page forgets an order once it closes, or once the
 * API refuses its link. localStorage, so every tab of this browser sees the
 * same list; every read and write is guarded, because storage can be blocked
 * (a private window) - the email still carries the link then.
 */
const KEY = "guest_orders";
const LIFETIME_MS = 7 * 24 * 60 * 60 * 1000;
const MAX_KEPT = 10;

const listeners = new Set();
let cache = null;

function stillValid(order, now = Date.now()) {
  const placed = Date.parse(order?.placedAt ?? "");

  return typeof order?.token === "string" && order.token !== "" && Number.isFinite(placed) && now - placed < LIFETIME_MS;
}

function read() {
  try {
    const list = JSON.parse(localStorage.getItem(KEY) ?? "[]");

    return Array.isArray(list) ? list.filter((order) => stillValid(order)) : [];
  } catch {
    return [];
  }
}

function write(list) {
  try {
    if (list.length === 0) localStorage.removeItem(KEY);
    else localStorage.setItem(KEY, JSON.stringify(list));
  } catch {
    // Storage blocked: nothing is kept, and the email still has the link.
  }

  cache = null;
  listeners.forEach((listener) => listener());
}

/** Remember (or refresh) an open order. Newest first. */
export function rememberGuestOrder({ token, reference = null, placedAt }) {
  const entry = { token, reference, placedAt };

  if (!stillValid(entry)) return;

  const list = read();
  const existing = list.find((order) => order.token === token);

  if (existing && existing.reference === reference && list[0]?.token === token) return;

  write([entry, ...list.filter((order) => order.token !== token)].slice(0, MAX_KEPT));
}

/** The order closed, or the API refused its link. */
export function forgetGuestOrder(token) {
  const list = read();

  if (list.some((order) => order.token === token)) {
    write(list.filter((order) => order.token !== token));
  }
}

function subscribe(listener) {
  listeners.add(listener);

  // Another tab changed the list.
  const onStorage = (event) => {
    if (event.key === null || event.key === KEY) {
      cache = null;
      listener();
    }
  };

  window.addEventListener("storage", onStorage);

  return () => {
    listeners.delete(listener);
    window.removeEventListener("storage", onStorage);
  };
}

function snapshot() {
  if (cache === null) cache = read();

  return cache;
}

const EMPTY = [];

export function useGuestOrders() {
  return useSyncExternalStore(subscribe, snapshot, () => EMPTY);
}
