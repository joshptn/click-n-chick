import { useSyncExternalStore } from "react";

/**
 * What is typed in the header's search box. The header shows on every tab, so
 * the text lives here rather than in one screen: typing on the Cart tab moves
 * to Home and keeps what was typed.
 */
let text = "";
const listeners = new Set<() => void>();

function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

const snapshot = () => text;

export function setMenuSearch(next: string) {
  if (next === text) return;

  text = next;
  listeners.forEach((listener) => listener());
}

export function useMenuSearch(): string {
  return useSyncExternalStore(subscribe, snapshot, snapshot);
}
