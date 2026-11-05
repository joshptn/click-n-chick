import { api } from "./api";

/**
 * The Store Agent's order board.
 *
 * Keyed under "orders" on purpose: RealtimeContext invalidates that prefix on
 * every admin.orders broadcast, so the board refreshes the moment any order
 * moves without subscribing to anything itself.
 */
export const AGENT_QUEUE_KEY = ["orders", "agent-queue"];

export const ADVANCE_REQUESTS_KEY = ["orders", "advance-requests"];

/**
 * Everything discount-review is keyed under "discount-claims", which
 * RealtimeContext invalidates on every admin.discounts broadcast.
 */
export const DISCOUNT_CLAIMS_KEY = ["discount-claims", "pending-count"];

export const discountQueueKey = (status) => ["discount-claims", "queue", status];

export const CLAIM_TABS = [
  { key: "pending", label: "Pending" },
  { key: "approved", label: "Approved" },
  { key: "rejected", label: "Rejected" },
];

export const CLAIM_TYPE = {
  senior: { short: "Senior", card: "Senior Citizen ID" },
  pwd: { short: "PWD", card: "Persons With Disabilities ID" },
};

/** Presets for the reason the customer reads. The server requires one. */
export const CLAIM_REJECTION_REASONS = [
  "The ID photo is too blurry to read.",
  "The name on the ID does not match the account.",
  "The ID has expired.",
  "This is not a Senior Citizen or PWD ID.",
];

export function fetchAgentQueue({ signal } = {}) {
  return api.get("/api/agent/queue", { signal });
}

export function advanceAgentOrder(id) {
  return api.post(`/api/agent/orders/${id}/advance`);
}

export function cancelAgentOrder(id, { reason, refund = true } = {}) {
  return api.post(`/api/agent/orders/${id}/cancel`, { reason: reason || null, refund });
}

export function updateStoreToggles(changes) {
  return api.patch("/api/admin/store/toggles", changes);
}

export function fetchAdvanceRequests({ signal } = {}) {
  return api.get("/api/agent/advance-requests", { signal });
}

export function acceptAdvanceRequest(id) {
  return api.post(`/api/agent/advance-requests/${id}/accept`);
}

export function rejectAdvanceRequest(id, reason) {
  return api.post(`/api/agent/advance-requests/${id}/reject`, { reason });
}

export function remindAdvanceRequest(id) {
  return api.post(`/api/agent/advance-requests/${id}/remind`);
}

/**
 * The advance list's columns, in the order a request moves through them. Not a
 * queue (BR-22c): each column is ordered by collection date, and nothing here
 * carries a queue number.
 */
export const ADVANCE_COLUMNS = [
  { key: "requests", label: "Requests", tone: "orange", statuses: ["submitted"] },
  { key: "payment", label: "Awaiting Payment", tone: "amber", statuses: ["accepted", "awaiting_payment"] },
  { key: "scheduled", label: "Scheduled", tone: "blue", statuses: ["confirmed", "scheduled"] },
  { key: "kitchen", label: "In Kitchen", tone: "green", statuses: ["preparing", "ready_for_pickup"] },
  { key: "closed", label: "Closed", tone: "slate", statuses: ["completed", "rejected", "cancelled"] },
];

export function advanceColumnFor(status) {
  return ADVANCE_COLUMNS.find((column) => column.statuses.includes(status))?.key ?? null;
}

/** What the page offers on a request, by where it is in its lifecycle. */
export function advanceStage(order) {
  switch (order?.status) {
    case "submitted":
      return "decide";
    case "accepted":
    case "awaiting_payment":
      return "payment";
    case "confirmed":
    case "scheduled":
      return "scheduled";
    case "preparing":
    case "ready_for_pickup":
      return "kitchen";
    default:
      return "closed";
  }
}

/** Every request on the page once. `due_today` repeats part of `scheduled`. */
export function advanceOrders(data) {
  if (!data) return [];

  return [
    ...(data.awaiting_decision ?? []),
    ...(data.awaiting_payment ?? []),
    ...(data.scheduled ?? []),
    ...(data.in_kitchen ?? []),
    ...(data.closed ?? []),
  ];
}

/** Presets for a declined request; the reason is the customer's notification. */
export const ADVANCE_REJECTION_REASONS = [
  "We are fully booked for that date.",
  "The kitchen cannot make this quantity on that date.",
  "The store is closed on that date.",
  "Some items are not available on that date.",
];

export const ADVANCE_CANCEL_REASONS = [
  "The customer asked to cancel.",
  "Payment did not arrive in time.",
  "We could not reach the customer.",
  "The kitchen cannot fulfil it on the day.",
];

/** "Tomorrow · 12:00 PM", "Fri, Nov 7 · 3:30 PM" - when the customer collects. */
export function collectionLabel(order) {
  if (!order?.scheduled_for) return "";

  const at = new Date(order.scheduled_for);
  const time = at.toLocaleTimeString("en-PH", { hour: "numeric", minute: "2-digit" });
  const days = order.days_until_collection;

  if (days === 0) return `Today · ${time}`;
  if (days === 1) return `Tomorrow · ${time}`;

  const date = at.toLocaleDateString("en-PH", { weekday: "short", month: "short", day: "numeric" });

  return `${date} · ${time}`;
}

/** "in 3 days", "today", "2 days ago". */
export function collectionCountdown(order) {
  const days = order?.days_until_collection;

  if (days == null) return "";
  if (days === 0) return "today";
  if (days === 1) return "tomorrow";
  if (days > 1) return `in ${days} days`;

  return days === -1 ? "yesterday" : `${-days} days ago`;
}

/** "5 h left", "40 min left", "overdue" - BR-16f's 24-hour payment window. */
export function paymentWindowLabel(order, now = Date.now()) {
  if (!order?.payment_due_at) return null;
  if (order.payment_overdue) return "Payment overdue";

  const minutes = Math.max(0, Math.floor((new Date(order.payment_due_at).getTime() - now) / 60000));

  if (minutes < 60) return `${minutes} min left to pay`;

  return `${Math.floor(minutes / 60)} h left to pay`;
}

/** True while remind() would still answer REMINDER_TOO_SOON. */
export function reminderCoolingDown(order, now = Date.now()) {
  return Boolean(order?.can_remind_at) && new Date(order.can_remind_at).getTime() > now;
}

/** How a closed request ended, as the agent reads it. */
export function closedOutcome(order) {
  if (order?.status === "completed") return { tone: "green", label: "Picked up" };
  if (order?.status === "rejected") return { tone: "red", label: "Declined" };

  if (order?.cancelled_by_store) return { tone: "red", label: "Cancelled by the store" };

  // The deadline job and the customer both leave cancelled_by empty; the
  // deadline's reason is the one the job writes.
  if (/not received within 24 hours/i.test(order?.cancellation_reason ?? "")) {
    return { tone: "amber", label: "Expired unpaid" };
  }

  return { tone: "slate", label: "Cancelled by the customer" };
}

export function fetchPendingDiscountClaims({ signal } = {}) {
  return api.get("/api/admin/discount-claims", { signal, params: { per_page: 1 } });
}

export function fetchDiscountClaims({ status = "pending", page = 1, perPage = 30, signal } = {}) {
  return api.get("/api/admin/discount-claims", { signal, params: { status, page, per_page: perPage } });
}

export function approveDiscountClaim(id) {
  return api.post(`/api/admin/discount-claims/${id}/approve`);
}

export function rejectDiscountClaim(id, reason) {
  return api.post(`/api/admin/discount-claims/${id}/reject`, { rejection_reason: reason });
}

const ADVANCE_NOTICE_TYPES = ["advance_request", "advance_due", "advance_order"];

/**
 * Staff notifications open the order on the board rather than the customer
 * tracker, and a new discount application opens the review queue.
 */
export function staffNotificationTarget(notification) {
  if (notification?.notification_type === "discount_review") return "/admin/verification";

  // A new request, a due-today alert, a customer calling one off: advance orders
  // hold no queue number, so they live on their own page, not the day's board.
  if (ADVANCE_NOTICE_TYPES.includes(notification?.notification_type) && notification?.order_id) {
    return `/admin/advance-orders?order=${notification.order_id}`;
  }

  return notification?.order_id ? `/admin?order=${notification.order_id}` : null;
}

/** "003": the application's number as the queue shows it. */
export function claimLabel(claim) {
  return String(claim?.id ?? "").padStart(3, "0");
}

export function claimantName(claim) {
  const name = [claim?.user?.first_name, claim?.user?.last_name].filter(Boolean).join(" ").trim();

  return name || "Customer";
}

export function initialsOf(name) {
  const parts = String(name ?? "").trim().split(/\s+/).filter(Boolean);

  if (parts.length === 0) return "?";

  return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : "")).toUpperCase();
}

export function matchesClaim(claim, search) {
  const needle = search.trim().toLowerCase();

  if (!needle) return true;

  return [claimLabel(claim), String(claim.id), claimantName(claim), claim.user?.email, claim.user?.phone_number]
    .filter(Boolean)
    .some((value) => String(value).toLowerCase().includes(needle.replace(/^#/, "")));
}

/** "12 min ago" for the queue, where how long someone has waited is the point. */
export function timeAgo(iso, now = Date.now()) {
  if (!iso) return "";

  const minutes = Math.floor((now - new Date(iso).getTime()) / 60000);

  if (minutes < 1) return "just now";
  if (minutes < 60) return `${minutes} min ago`;

  const hours = Math.floor(minutes / 60);

  if (hours < 24) return `${hours} h ago`;

  const days = Math.floor(hours / 24);

  return days === 1 ? "yesterday" : `${days} days ago`;
}

export function formatStamp(iso) {
  if (!iso) return "";

  return new Date(iso).toLocaleString("en-PH", {
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}

/** The board's columns, in the order an order moves through them. */
export const BOARD_COLUMNS = [
  { key: "new", label: "New", tone: "slate", statuses: ["placed"] },
  { key: "confirmed", label: "Confirmed", tone: "orange", statuses: ["confirmed"] },
  { key: "preparing", label: "Preparing", tone: "amber", statuses: ["preparing"] },
  { key: "ready", label: "Ready", tone: "blue", statuses: ["ready_for_pickup", "on_the_way"] },
  { key: "completed", label: "Completed", tone: "green", statuses: ["completed", "delivered"] },
];

export function columnFor(status) {
  return BOARD_COLUMNS.find((column) => column.statuses.includes(status))?.key ?? null;
}

/** Every order on today's board, from the three lists the endpoint returns. */
export function boardOrders(queue) {
  if (!queue) return [];

  return [...(queue.line ?? []), ...(queue.handover ?? []), ...(queue.completed ?? [])];
}

/**
 * What the one forward button says.
 *
 * The agent never names a target status - `advance` moves one step along the
 * order's own chain - so the label is read from `next_status`, which the server
 * computed from the same chain.
 */
export function advanceLabel(order) {
  switch (order?.next_status) {
    case "confirmed":
      return "Accept";
    case "preparing":
      return "Start Preparing";
    case "ready_for_pickup":
      return "Mark as Ready";
    case "on_the_way":
      return "Send Out for Delivery";
    case "completed":
      return "Mark as Picked Up";
    case "delivered":
      return "Mark as Delivered";
    default:
      return null;
  }
}

/** Ready holds two different waits, so the card says which one. */
export function handoverLabel(order) {
  if (order?.status === "on_the_way") return "Out for delivery";
  if (order?.status === "ready_for_pickup") return "Awaiting pickup";

  return null;
}

const PAYMENT_METHODS = {
  gcash: "GCash",
  maya: "Maya",
  paymaya: "Maya",
  qrph: "QR Ph",
  card: "Card",
  grab_pay: "GrabPay",
  cash: "Cash",
};

export function paymentLabel(order) {
  const method = order?.payment_method;

  if (method) return PAYMENT_METHODS[String(method).toLowerCase()] ?? method;

  return order?.payment_status === "paid" ? "Paid" : "Unpaid";
}

/** The line as the kitchen reads it: "Herb Roasted Chicken ×1 · Fries ×1". */
export function kitchenSummary(items = []) {
  return items.map((item) => `${item.food_name} ×${item.quantity}`).join(" · ");
}

/** Case-insensitive match on what an agent would actually type. */
export function matchesSearch(order, term) {
  const needle = term.trim().toLowerCase();

  if (!needle) return true;

  const haystack = [
    order.queue_label,
    order.reference,
    order.order_number,
    order.customer?.name,
    order.customer?.phone,
    ...(order.items ?? []).flatMap((item) => [item.food_name, ...(item.addons ?? [])]),
  ];

  return haystack.some((value) => value && String(value).toLowerCase().includes(needle));
}

/** Optimistic view of an order one step further along. */
export function advancedLocally(order) {
  if (!order?.next_status) return order;

  return { ...order, status: order.next_status, next_status: null };
}
