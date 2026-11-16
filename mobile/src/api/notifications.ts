import { api } from "./client";

/** A row of the API's notifications table. */
export type AppNotification = {
  id: number;
  order_id: number | null;
  title: string;
  body: string;
  /** order, advance_*, security, a discount decision, or null for a store announcement. */
  notification_type: string | null;
  is_read: boolean;
  created_at: string;
};

export const NOTIFICATIONS_KEY = ["notifications"] as const;

export const notificationsApi = {
  list: (signal?: AbortSignal) => api.get<{ data: AppNotification[] }>("/api/notifications", { signal }),
  markRead: (id: number) => api.put(`/api/notifications/${id}/read`),
  markAllRead: () => api.post("/api/notifications/read-all"),
};

export type NotificationKind = "orders" | "updates" | "security";

/**
 * The chip a notification falls under (decided 2026-11-17): Orders for
 * anything about an order, Security for sign-in alerts, and Updates for the
 * store's announcements and discount decisions.
 */
export function notificationKind(notification: AppNotification): NotificationKind {
  if (notification.notification_type === "security") return "security";
  if (notification.order_id) return "orders";

  const type = notification.notification_type ?? "";

  if (type === "order" || type.startsWith("advance")) return "orders";

  return "updates";
}
