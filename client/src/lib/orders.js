import { api } from "./api";
import { formatPeso } from "./menu";

export const ORDERS_KEY = ["orders"];

export const orderKey = (id) => ["orders", String(id)];

export function fetchOrders({ filter = "all", signal } = {}) {
  return api.get("/api/orders", { params: { filter }, signal });
}

export function fetchOrder(id, { signal } = {}) {
  return api.get(`/api/orders/${id}`, { signal });
}

export function cancelOrder(id) {
  return api.post(`/api/order/${id}/cancel`);
}

export function amendOrder(id, changes) {
  return api.patch(`/api/orders/${id}`, changes);
}

export function confirmDetails(id) {
  return api.post(`/api/orders/${id}/confirm-details`);
}

export function confirmReceipt(id) {
  return api.post(`/api/orders/${id}/received`);
}

/**
 * Tracking without an account 
 *
 * The token comes from the link rather than from storage, so it is passed per
 * call. There is no id in any of these paths - the token names the order, which
 * is why none of them can be probed by guessing a number.
 */
export const guestOrderKey = (token) => ["guest-order", token];

function holding(token) {
  return { headers: { "X-Order-Token": token } };
}

export function fetchGuestOrder(token, { signal } = {}) {
  return api.get("/api/guest/order", { ...holding(token), signal });
}

export function cancelGuestOrder(token) {
  return api.post("/api/guest/order/cancel", undefined, holding(token));
}

/** A guest changes the contact name or number from their tracking link (BR-21b). */
export function amendGuestOrder(token, changes) {
  return api.patch("/api/guest/order", changes, holding(token));
}

export function confirmGuestReceipt(token) {
  return api.post("/api/guest/order/received", undefined, holding(token));
}

/**
 * The resend card's details with the server's wait turned into a moment on
 * this clock, taken when the answer arrives, so the countdown needs no state.
 */
export function withLinkDeadline(link) {
  return link ? { ...link, resend_until: Date.now() + (link.resend_in ?? 0) * 1000 } : null;
}

/** Email the tracking link again (FR-02.13b): once per 30 seconds, 10 per order. */
export function resendGuestLink(token) {
  return api.post("/api/guest/order/link", undefined, holding(token));
}

/** Correct the email the link is sent to; sends nothing by itself (FR-02.13b). */
export function changeGuestEmail(token, email) {
  return api.patch("/api/guest/order", { contact_email: email }, holding(token));
}

export const ORDER_FILTERS = [
  { id: "active", label: "Active" },
  { id: "past", label: "Past" },
  { id: "all", label: "All" },
];

export function formatTime(iso) {
  if (!iso) return "";

  return new Date(iso).toLocaleTimeString("en-PH", { hour: "numeric", minute: "2-digit" });
}

export function formatDay(iso, { relative = true } = {}) {
  if (!iso) return "";

  const date = new Date(iso);
  const full = date.toLocaleDateString("en-PH", { month: "long", day: "2-digit", year: "numeric" });

  if (!relative) return full;

  const startOfToday = new Date();
  startOfToday.setHours(0, 0, 0, 0);

  const days = Math.floor((startOfToday - new Date(date).setHours(0, 0, 0, 0)) / 86400000);

  if (days === 0) return "Today";
  if (days === 1) return "Yesterday";

  return full;
}

export const ADVANCE_STATUS = {
  SUBMITTED: "submitted",
  ACCEPTED: "accepted",
  AWAITING_PAYMENT: "awaiting_payment",
  SCHEDULED: "scheduled",
  REJECTED: "rejected",
};

export function isRefused(order) {
  return Boolean(order?.is_cancelled) || order?.status === ADVANCE_STATUS.REJECTED;
}

export function isUnanswered(order) {
  return order?.status === ADVANCE_STATUS.SUBMITTED;
}

export function statusTone(order) {
  if (isRefused(order)) return "cancelled";
  if (order?.is_terminal) return "done";

  return "live";
}

const TONES = {
  live: "bg-[#fff4e8] text-brand-700",
  done: "bg-[#e9f8ee] text-[#2f9e44]",
  cancelled: "bg-[#fdecec] text-[#c92a2a]",
};

export function statusBadgeClass(order) {
  return TONES[statusTone(order)];
}

export function itemsSummary(items = []) {
  if (items.length === 0) return "No items";

  const [first, ...rest] = items;
  const head = first.quantity > 1 ? `${first.quantity}× ${first.food_name}` : first.food_name;

  return rest.length === 0 ? head : `${head} + ${rest.length} more`;
}

export function cancelPrompt(order) {
  const terms = order?.cancellation ?? null;
  const withdrawing = isUnanswered(order);
  const advance = Boolean(order?.is_advance);

  const outcome = (() => {
    if (terms?.refundable) {
      return `You will be refunded ${formatPeso(terms.refund_amount)} in full.`;
    }

    // The only other answer a customer can cancel with (decided 2026-11-17).
    return "You have not paid for this, so there is nothing to refund.";
  })();

  const consequence = withdrawing
    ? "The store will not see this request any more."
    : advance
      ? "Your collection date is released and the kitchen will not prepare it."
      : "The kitchen will be told straight away.";

  return {
    action: withdrawing ? "Withdraw request" : "Cancel order",
    title: withdrawing ? "Withdraw this request?" : "Cancel this order?",
    body: `${outcome} ${consequence}`,
    confirm: withdrawing ? "Yes, withdraw it" : "Yes, cancel it",
    keep: withdrawing ? "Keep my request" : "Keep my order",
    refundable: Boolean(terms?.refundable),
  };
}

export function feedbackMailto(order, address = "clicknchick.feedback@gmail.com") {
  // "Order 007", or "Order for Sunday, November 22 at 3:00 AM" before acceptance.
  const title = order?.title ?? `Order ${order?.reference ?? ""}`.trim();
  const subject = `Feedback on ${title.replace(/^Order/, "order")}`;
  const body = `${title}\n\nTell us what went well or what we can do better:\n\n`;

  return `mailto:${address}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
}
