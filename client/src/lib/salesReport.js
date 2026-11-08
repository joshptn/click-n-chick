import { api } from "./api";
import { RECORDS_PER_PAGE, STORE_TIMEZONE, storeDate } from "./records";

/** Keyed under "orders" so the admin.orders broadcast refreshes an open report. */
export const dailySalesKey = (params) => ["orders", "daily-sales", params];

export function fetchDailySales(params, { signal } = {}) {
  return api.get("/api/admin/reports/daily-sales", { signal, params });
}

export const REPORT_TYPES = [
  { key: "all", label: "Pickup & delivery" },
  { key: "pickup", label: "Pickup" },
  { key: "delivery", label: "Delivery" },
];

export const REPORT_KINDS = [
  { key: "all", label: "Regular & advance" },
  { key: "regular", label: "Regular" },
  { key: "advance", label: "Advance" },
];

export const REPORT_OUTCOMES = [
  { key: "completed", label: "Completed" },
  { key: "cancelled", label: "Cancelled" },
  { key: "rejected", label: "Declined" },
];

/** The page's URL state. An unusable or future date falls back to today. */
export function readReportFilters(searchParams) {
  const today = storeDate();
  const pick = (key, options) => {
    const value = searchParams.get(key);

    return options.some((option) => option.key === value) ? value : options[0].key;
  };
  const rawDate = searchParams.get("date") ?? "";
  const page = Number.parseInt(searchParams.get("page") ?? "1", 10);

  return {
    date: /^\d{4}-\d{2}-\d{2}$/.test(rawDate) && rawDate <= today ? rawDate : today,
    type: pick("type", REPORT_TYPES),
    kind: pick("kind", REPORT_KINDS),
    outcome: pick("outcome", REPORT_OUTCOMES),
    page: Number.isFinite(page) && page > 0 ? page : 1,
  };
}

export function reportParams({ date, type, kind }) {
  return {
    date,
    ...(type !== "all" && { order_type: type }),
    ...(kind !== "all" && { kind }),
  };
}

/** The day's orders, from Order Records, under the same day and filters. */
export function reportRecordParams({ date, type, kind, outcome, page }) {
  return {
    from: date,
    to: date,
    outcome,
    per_page: RECORDS_PER_PAGE,
    ...(type !== "all" && { order_type: type }),
    ...(kind !== "all" && { kind }),
    ...(page > 1 && { page }),
  };
}

/** "2026-11-09" moved by whole calendar days. */
export function shiftDate(date, days) {
  const [year, month, day] = date.split("-").map(Number);

  return new Date(Date.UTC(year, month - 1, day + days)).toISOString().slice(0, 10);
}

/** "Monday, November 9, 2026", for a store calendar day. */
export function formatReportDate(date) {
  return new Date(`${date}T12:00:00+08:00`).toLocaleDateString("en-PH", {
    timeZone: STORE_TIMEZONE,
    weekday: "long",
    month: "long",
    day: "numeric",
    year: "numeric",
  });
}

const METHODS = { gcash: "GCash", maya: "Maya", paymaya: "Maya", qrph: "QR Ph", grab_pay: "GrabPay", grabpay: "GrabPay" };

export function methodLabel(method) {
  if (!method) return "Not recorded";

  return METHODS[String(method).toLowerCase()] ?? method;
}
