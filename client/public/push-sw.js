/*
 * Push handling, pulled into the generated service worker through Workbox's
 * importScripts (vite.config.js). Plain JavaScript: it is not bundled.
 *
 * Messages come from Firebase Cloud Messaging; the API sends a `notification`
 * (title, body) and `data` (notification_id, type, order_id). The Firebase SDK
 * is not needed here: the payload is read straight from the push event.
 */

const APPLE = /iPhone|iPad|iPod|Macintosh/.test(self.navigator.userAgent)
  && !/Chrome|CriOS|Edg|Firefox|FxiOS/.test(self.navigator.userAgent);

/** Where a tapped notification opens; matches notificationTarget() in the app. */
function targetFor(data) {
  if (data.order_id) return `/orders/${data.order_id}`;
  if (data.type === "security") return "/account/security";

  return "/home";
}

self.addEventListener("push", (event) => {
  let payload = {};

  try {
    payload = event.data ? event.data.json() : {};
  } catch {
    payload = {};
  }

  const notification = payload.notification || {};
  const data = payload.data || {};

  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: "window", includeUncontrolled: true });

    // The app is open and on screen: its own toast already shows this (the
    // same notification arrives over the live connection). Safari requires
    // every push to show a notification, so it always gets one.
    if (!APPLE && windows.some((client) => client.visibilityState === "visible")) return;

    await self.registration.showNotification(notification.title || "Click n Chick", {
      body: notification.body || "",
      icon: "/pwa-192x192.png",
      // One entry per notification, even if it arrives twice.
      tag: data.notification_id ? `notification-${data.notification_id}` : undefined,
      data: { url: targetFor(data) },
    });
  })());
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();

  const url = (event.notification.data && event.notification.data.url) || "/home";

  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: "window", includeUncontrolled: true });
    const open = windows.find((client) => new URL(client.url).origin === self.location.origin);

    if (open) {
      // The app navigates itself, keeping the page and the sign-in.
      open.postMessage({ type: "cnc:open", url });
      return open.focus();
    }

    return self.clients.openWindow(url);
  })());
});
