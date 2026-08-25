import { api } from "./api";

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

export function confirmReceipt(id) {
  return api.post(`/api/orders/${id}/received`);
}

export const ORDER_FILTERS = [
  { id: "active", label: "Active" },
  { id: "past", label: "Past" },
  { id: "all", label: "All" },
];

export function queueMessage(queue) {
  if (!queue?.in_line) return null;

  const ahead = queue.ahead ?? 0;

  if (ahead <= 0) return { headline: "We're starting your order now", detail: "Nobody is ahead of you." };
  if (ahead === 1) return { headline: "1 order ahead of you", detail: "You're next after this one." };

  return { headline: `${ahead} orders ahead of you`, detail: "We're working through them in order." };
}

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

export function statusTone(order) {
  if (order?.is_cancelled) return "cancelled";
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

export function feedbackMailto(order, address = "clicknchick.feedback@gmail.com") {
  const subject = `Feedback on order ${order?.reference ?? ""}`.trim();
  const body = `Order: ${order?.reference ?? ""}\n\nTell us what went well or what we can do better:\n\n`;

  return `mailto:${address}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
}
