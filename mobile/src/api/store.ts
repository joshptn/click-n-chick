import { api } from "./client";

/** GET /api/store/status (StoreAvailability::snapshot on the API). */
export type StoreStatus = {
  timezone: string;
  server_time: string;
  /** "07:00" */
  opens_at: string;
  /** "20:00" */
  closes_at: string;
  within_hours: boolean;
  accepting_orders: boolean;
  delivery_enabled: boolean;
  accepting_delivery: boolean;
  accepting_pickup: boolean;
  next_opens_at: string | null;
};

export const STORE_STATUS_KEY = ["store", "status"] as const;

/** Public: no account needed. */
export function fetchStoreStatus({ signal }: { signal?: AbortSignal } = {}) {
  return api.get<StoreStatus>("/api/store/status", { auth: false, signal });
}

/** "7:00 AM" from the "07:00" the API returns. */
export function formatStoreTime(value: string | null | undefined): string {
  if (typeof value !== "string" || !value.includes(":")) return "";

  const [rawHour, rawMinute] = value.split(":");
  const hour = Number(rawHour);
  const suffix = hour >= 12 ? "PM" : "AM";
  const display = hour % 12 === 0 ? 12 : hour % 12;

  return `${display}:${rawMinute} ${suffix}`;
}
