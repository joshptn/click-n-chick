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

  return null;
}
