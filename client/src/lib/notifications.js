import { api } from "./api";

export const NOTIFICATIONS_KEY = ["notifications"];

export function fetchNotifications({ signal } = {}) {
  return api.get("/api/notifications", { signal });
}

export function markNotificationRead(id) {
  return api.put(`/api/notifications/${id}/read`);
}

export function markAllNotificationsRead() {
  return api.post("/api/notifications/read-all");
}

/** Where a notification opens. A tapped push opens the same (public/push-sw.js). */
export function notificationTarget(notification) {
  if (notification?.order_id) return `/orders/${notification.order_id}`;
  if (notification?.notification_type === "security") return "/account/security";
  if (notification?.notification_type === "discount_decision") return "/account/profile";

  return null;
}

/**
 * The tab a notification falls under, the same as the app's (mobile
 * src/api/notifications.ts): Security for sign-in alerts, Orders for anything
 * about an order, and Updates for the rest (store news, discount decisions;
 * for staff, applications to review and poster reminders). Orders has no tab
 * of its own: order notices show under All.
 */
export function notificationKind(notification) {
  const type = notification?.notification_type ?? "";

  if (type === "security") return "security";
  if (notification?.order_id || type === "order" || type.startsWith("advance")) return "orders";

  return "updates";
}

export const NOTIFICATION_TABS = [
  { key: "all", label: "All" },
  { key: "updates", label: "Updates" },
  { key: "security", label: "Security" },
];

export function inTab(notification, tab) {
  return tab === "all" || notificationKind(notification) === tab;
}

const MINUTE = 60 * 1000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

function plural(count, unit) {
  return `${count} ${unit}${count === 1 ? "" : "s"} ago`;
}

/** "Just now", "8 min ago", "2 hours ago", "1 day ago", "3 weeks ago", "2 months ago". */
export function notificationAgo(iso, now = Date.now()) {
  if (!iso) return "";

  const elapsed = Math.max(0, now - new Date(iso).getTime());

  if (elapsed < MINUTE) return "Just now";
  if (elapsed < HOUR) return `${Math.floor(elapsed / MINUTE)} min ago`;
  if (elapsed < DAY) return plural(Math.floor(elapsed / HOUR), "hour");
  if (elapsed < 7 * DAY) return plural(Math.floor(elapsed / DAY), "day");
  if (elapsed < 30 * DAY) return plural(Math.floor(elapsed / (7 * DAY)), "week");
  if (elapsed < 365 * DAY) return plural(Math.floor(elapsed / (30 * DAY)), "month");

  return plural(Math.floor(elapsed / (365 * DAY)), "year");
}

/** "Today, 9:14 AM", "Yesterday, 6:40 PM", "Jul 5, 4:30 PM", or "Jul 5, 2025, 4:30 PM" from another year. */
export function notificationWhen(iso, now = new Date()) {
  if (!iso) return "";

  const date = new Date(iso);
  const time = date.toLocaleTimeString("en-PH", { hour: "numeric", minute: "2-digit" });
  const startOf = (value) => new Date(value).setHours(0, 0, 0, 0);
  const days = Math.round((startOf(now) - startOf(date)) / DAY);

  if (days === 0) return `Today, ${time}`;
  if (days === 1) return `Yesterday, ${time}`;

  const day = date.toLocaleDateString("en-PH", {
    month: "short",
    day: "numeric",
    ...(date.getFullYear() === now.getFullYear() ? {} : { year: "numeric" }),
  });

  return `${day}, ${time}`;
}
