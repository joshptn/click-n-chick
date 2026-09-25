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

export function notificationTarget(notification) {
  return notification?.order_id ? `/orders/${notification.order_id}` : null;
}
