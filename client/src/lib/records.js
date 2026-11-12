import { api } from "./api";

/**
 * Order Records: closed orders, read-only (UC-OPS-007).
 *
 * Keyed under "orders" so the admin.orders broadcast refreshes the list the
 * moment an order on the board closes, without subscribing to anything here.
 */
export const recordsKey = (params) => ["orders", "records", params];

export const recordKey = (id) => ["orders", "record", String(id)];

export const RECORDS_PER_PAGE = 20;

export function fetchRecords(params, { signal } = {}) {
  return api.get("/api/agent/records", { signal, params });
}

export function fetchRecord(id, { signal } = {}) {
  return api.get(`/api/agent/records/${id}`, { signal });
}

/** The store's calendar, which is what the server files records under. */
export const STORE_TIMEZONE = "Asia/Manila";

/** "2026-11-05" in the store's timezone, `offset` days from today. */
export function storeDate(offset = 0, now = new Date()) {
  const shifted = new Date(now.getTime() + offset * 86400000);

  return new Intl.DateTimeFormat("en-CA", { timeZone: STORE_TIMEZONE }).format(shifted);
}

function startOfStoreMonth(now = new Date()) {
  return `${storeDate(0, now).slice(0, 8)}01`;
}

/** Date presets, resolved to the from/to the endpoint takes. */
export const DATE_RANGES = [
  { key: "all", label: "All time", resolve: () => ({}) },
  { key: "today", label: "Today", resolve: () => ({ from: storeDate(), to: storeDate() }) },
  { key: "yesterday", label: "Yesterday", resolve: () => ({ from: storeDate(-1), to: storeDate(-1) }) },
  { key: "7d", label: "Last 7 days", resolve: () => ({ from: storeDate(-6), to: storeDate() }) },
  { key: "30d", label: "Last 30 days", resolve: () => ({ from: storeDate(-29), to: storeDate() }) },
  { key: "month", label: "This month", resolve: () => ({ from: startOfStoreMonth(), to: storeDate() }) },
  { key: "custom", label: "Custom range…", resolve: () => ({}) },
];

export const OUTCOMES = [
  { key: "all", label: "ALL" },
  { key: "completed", label: "Completed" },
  { key: "cancelled", label: "Cancelled" },
  { key: "rejected", label: "Declined" },
];

export const ORDER_TYPES = [
  { key: "all", label: "Pickup & delivery" },
  { key: "pickup", label: "Pickup" },
  { key: "delivery", label: "Delivery" },
];

export const ORDER_KINDS = [
  { key: "all", label: "Regular & advance" },
  { key: "regular", label: "Regular" },
  { key: "advance", label: "Advance" },
];

export const PAYMENT_FILTERS = [
  { key: "all", label: "Any payment" },
  { key: "paid", label: "Paid" },
  { key: "unpaid", label: "Not paid" },
  { key: "refund_owed", label: "Refund owed" },
];

export const RECORD_SORTS = [
  { key: "newest", label: "Newest first" },
  { key: "oldest", label: "Oldest first" },
  { key: "total_high", label: "Total: high to low" },
  { key: "total_low", label: "Total: low to high" },
];

export const RECORD_DEFAULTS = {
  q: "",
  outcome: "all",
  range: "all",
  from: "",
  to: "",
  type: "all",
  kind: "all",
  payment: "all",
  sort: "newest",
  page: "1",
};

/** The page's URL state, read with defaults and unknown values dropped. */
export function readRecordFilters(searchParams) {
  const pick = (key, options) => {
    const value = searchParams.get(key);

    return options.some((option) => option.key === value) ? value : RECORD_DEFAULTS[key];
  };
  const date = (key) => (/^\d{4}-\d{2}-\d{2}$/.test(searchParams.get(key) ?? "") ? searchParams.get(key) : "");
  const page = Number.parseInt(searchParams.get("page") ?? "1", 10);

  return {
    q: searchParams.get("q") ?? "",
    outcome: pick("outcome", OUTCOMES),
    range: pick("range", DATE_RANGES),
    from: date("from"),
    to: date("to"),
    type: pick("type", ORDER_TYPES),
    kind: pick("kind", ORDER_KINDS),
    payment: pick("payment", PAYMENT_FILTERS),
    sort: pick("sort", RECORD_SORTS),
    page: String(Number.isFinite(page) && page > 0 ? page : 1),
  };
}

/** What the endpoint is asked for. Defaults are left out so cache keys stay short. */
export function recordQueryParams(filters) {
  const range = DATE_RANGES.find((option) => option.key === filters.range) ?? DATE_RANGES[0];
  let dates = range.resolve();

  if (filters.range === "custom") {
    // A half-typed or backwards range asks for nothing rather than for an error.
    const { from, to } = filters;
    dates = from && to && from > to ? { from: to, to: from } : { from: from || undefined, to: to || undefined };
  }

  const params = {
    search: filters.q.trim() || undefined,
    outcome: filters.outcome !== "all" ? filters.outcome : undefined,
    order_type: filters.type !== "all" ? filters.type : undefined,
    kind: filters.kind !== "all" ? filters.kind : undefined,
    payment: filters.payment !== "all" ? filters.payment : undefined,
    sort: filters.sort !== "newest" ? filters.sort : undefined,
    from: dates.from,
    to: dates.to,
    page: filters.page !== "1" ? filters.page : undefined,
    per_page: RECORDS_PER_PAGE,
  };

  return Object.fromEntries(Object.entries(params).filter(([, value]) => value !== undefined));
}

/**
 * How a record ended, in the agent's words, with the tone its pill takes.
 *
 * Who cancelled is read from the order: a staff member is the store, and the
 * server marks the requests its expiry job cancelled; anything else was the
 * customer.
 */
export function recordOutcome(record) {
  switch (record?.status) {
    case "completed":
      // Delivery ends at `delivered`, so a completed order was collected.
      return { tone: "green", label: "Completed", detail: "Picked up" };
    case "delivered":
      return { tone: "green", label: "Delivered", detail: "Delivered" };
    case "rejected":
      return { tone: "slate", label: "Declined", detail: "Declined by the store" };
    default:
      if (record?.cancelled_by_store) return { tone: "red", label: "Cancelled", detail: "Cancelled by the store" };
      if (record?.expired_unpaid) {
        return { tone: "amber", label: "Cancelled", detail: "Expired unpaid" };
      }

      return { tone: "red", label: "Cancelled", detail: "Cancelled by the customer" };
  }
}

export const OUTCOME_PILL = {
  green: "bg-[#ecf9f0] text-[#2f9e44] ring-[#bfe8cb]",
  red: "bg-[#fdecec] text-[#e5322d] ring-[#f8c9c7]",
  amber: "bg-[#fff8e1] text-[#c98500] ring-[#ffe59a]",
  slate: "bg-[#f1eee9] text-[#5f5b58] ring-[#dcd6ce]",
};

export function typeLabel(record) {
  return record?.order_type === "delivery" ? "Delivery" : "Pickup";
}

/** "Herb Roasted Chicken ×1 · +2 more" - enough to recognise an order by. */
export function itemsPreview(record) {
  const shown = (record?.items_preview ?? []).map((item) => `${item.food_name} ×${item.quantity}`);
  const more = Math.max(0, (record?.line_count ?? shown.length) - shown.length);

  if (shown.length === 0) return "No items";

  return more > 0 ? `${shown.join(" · ")} · +${more} more` : shown.join(" · ");
}

/** "+63965*****32": enough to recognise a number without reading it out. */
export function maskPhone(phone) {
  const value = String(phone ?? "").trim();

  if (value.length <= 7) return value;

  return `${value.slice(0, value.startsWith("+") ? 6 : 5)}${"*".repeat(Math.max(3, value.length - 8))}${value.slice(-2)}`;
}

export function formatRecordDate(iso) {
  if (!iso) return "";

  return new Date(iso).toLocaleDateString("en-PH", {
    timeZone: STORE_TIMEZONE,
    month: "short",
    day: "numeric",
    year: "numeric",
  });
}

export function formatRecordTime(iso) {
  if (!iso) return "";

  return new Date(iso).toLocaleTimeString("en-PH", { timeZone: STORE_TIMEZONE, hour: "numeric", minute: "2-digit" });
}

export function formatRecordStamp(iso) {
  if (!iso) return "";

  return `${formatRecordDate(iso)}, ${formatRecordTime(iso)}`;
}

/** "42 min", "3 h 5 min", "2 days" - placed to closed. */
export function durationLabel(fromIso, toIso) {
  if (!fromIso || !toIso) return "";

  const minutes = Math.max(0, Math.round((new Date(toIso) - new Date(fromIso)) / 60000));

  if (minutes < 60) return `${minutes} min`;

  const hours = Math.floor(minutes / 60);

  if (hours < 48) return minutes % 60 ? `${hours} h ${minutes % 60} min` : `${hours} h`;

  return `${Math.round(hours / 24)} days`;
}
