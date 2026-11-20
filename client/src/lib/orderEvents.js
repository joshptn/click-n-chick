import { useEffect, useRef } from "react";

/**
 * Staff order events, handed to the boards one by one (decided 2026-11-20):
 * Today's Orders, Pending Orders and Advance Orders fetch just the changed
 * order and update its card, instead of reloading their whole list.
 */
const listeners = new Set();

export function emitOrderEvent(payload) {
  listeners.forEach((listener) => listener(payload));
}

/** Call `handler(payload)` for every staff order event while mounted. */
export function useOrderEvents(handler) {
  const latest = useRef(handler);

  useEffect(() => {
    latest.current = handler;
  });

  useEffect(() => {
    const listener = (payload) => latest.current(payload);

    listeners.add(listener);

    return () => listeners.delete(listener);
  }, []);
}
