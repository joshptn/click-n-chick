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

/**
 * Staff notifications open the order on the board rather than the customer
 * tracker, and a new discount application opens the review queue.
 */
export function staffNotificationTarget(notification) {
  if (notification?.notification_type === "discount_review") return "/admin/verification";

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
