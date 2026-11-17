import { useSyncExternalStore } from "react";

/**
 * Short messages at the top of the screen ("Added to your order"), as the web
 * app's toasts. One at a time: a new one replaces the last.
 */
export type Toast = {
  id: number;
  tone: "success" | "error" | "info";
  title: string;
  message?: string;
};

const SHOW_MS = 3200;

let current: Toast | null = null;
let nextId = 1;
let timer: ReturnType<typeof setTimeout> | null = null;
const listeners = new Set<() => void>();

function emit() {
  listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

const snapshot = () => current;

export function dismissToast() {
  if (timer) clearTimeout(timer);
  timer = null;
  current = null;
  emit();
}

function show(tone: Toast["tone"], title: string, message?: string) {
  if (timer) clearTimeout(timer);

  current = { id: nextId++, tone, title, message };
  timer = setTimeout(dismissToast, SHOW_MS);
  emit();
}

export const toast = {
  success: (title: string, message?: string) => show("success", title, message),
  error: (title: string, message?: string) => show("error", title, message),
  info: (title: string, message?: string) => show("info", title, message),
};

export function useToast(): Toast | null {
  return useSyncExternalStore(subscribe, snapshot, snapshot);
}
