import { api } from "./api";
import { isInstalledApp } from "./installedApp";

/**
 * Push notifications through Firebase Cloud Messaging (PRD §9).
 *
 * A customer turns them on from the bell menu; the browser's permission prompt
 * only ever follows that tap. It works in an installed app and in any browser
 * tab that supports web push; an iPhone only allows it once the site is added
 * to the Home Screen.
 *
 * Turning it on is remembered per account on this browser, so the same
 * customer is registered again on each visit (the server deletes a token when
 * its sign-in ends, and Firebase rotates tokens now and then), while another
 * account signing in here has to turn it on for itself.
 *
 * The Firebase SDK is loaded only when a token is needed, never on page load.
 * Messages are shown by our own service worker (public/push-sw.js).
 */

const FIREBASE = {
  apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
  projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
  messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID,
  appId: import.meta.env.VITE_FIREBASE_APP_ID,
};

const VAPID_KEY = import.meta.env.VITE_FIREBASE_VAPID_KEY;

const CONFIGURED = Boolean(
  FIREBASE.apiKey && FIREBASE.projectId && FIREBASE.messagingSenderId && FIREBASE.appId && VAPID_KEY
);

export const PUSH_STATUS = {
  /** No Firebase keys in this build: nothing about push is shown. */
  UNCONFIGURED: "unconfigured",
  /** The browser cannot receive push. */
  UNSUPPORTED: "unsupported",
  /** An iPhone or iPad in a browser tab: push needs the Home Screen app. */
  NEEDS_INSTALL: "needs-install",
  /** The customer blocked notifications for this site. */
  DENIED: "denied",
  /** Can be turned on (or already is). */
  AVAILABLE: "available",
};

function isAppleMobile() {
  if (typeof navigator === "undefined") return false;

  // iPadOS reports itself as a Mac; the touch points give it away.
  return /iPhone|iPad|iPod/.test(navigator.userAgent)
    || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
}

export function pushStatus() {
  if (!CONFIGURED || typeof window === "undefined") return PUSH_STATUS.UNCONFIGURED;

  if (isAppleMobile() && !isInstalledApp()) return PUSH_STATUS.NEEDS_INSTALL;

  if (!("Notification" in window) || !("serviceWorker" in navigator) || !("PushManager" in window)) {
    return PUSH_STATUS.UNSUPPORTED;
  }

  return Notification.permission === "denied" ? PUSH_STATUS.DENIED : PUSH_STATUS.AVAILABLE;
}

// --- "Turned on for this account on this browser" ---------------------------

const flagKey = (userId) => `cnc.push.${userId}`;

export function isPushOn(userId) {
  try {
    return Boolean(userId) && window.localStorage.getItem(flagKey(userId)) === "1";
  } catch {
    return false;
  }
}

function rememberPushOn(userId, on) {
  try {
    if (on) window.localStorage.setItem(flagKey(userId), "1");
    else window.localStorage.removeItem(flagKey(userId));
  } catch {
    // Private mode or blocked storage: it just will not be remembered.
  }
}

// --- Firebase -----------------------------------------------------------------

export class PushError extends Error {}

async function deviceToken() {
  // Only the production build registers the service worker; `vite` dev has none.
  const registration = await navigator.serviceWorker.getRegistration("/");

  if (!registration) {
    throw new PushError("Notifications need the app to finish loading. Reload the page and try again.");
  }

  const [{ initializeApp, getApps }, { getMessaging, getToken }] = await Promise.all([
    import("firebase/app"),
    import("firebase/messaging"),
  ]);

  const app = getApps()[0] ?? initializeApp(FIREBASE);

  return getToken(getMessaging(app), { vapidKey: VAPID_KEY, serviceWorkerRegistration: registration });
}

async function register() {
  const token = await deviceToken();

  if (!token) throw new PushError("This browser did not give a notification address.");

  await api.post("/api/push-tokens", { token, platform: "web" });

  return token;
}

/**
 * Ask for permission and register. Must be called straight from a tap: Safari
 * only shows the prompt for a user gesture, so nothing is awaited before it.
 *
 * @returns {Promise<"on"|"denied"|"dismissed">}
 */
export async function turnPushOn(userId) {
  const permission = await Notification.requestPermission();

  if (permission !== "granted") return permission === "denied" ? "denied" : "dismissed";

  try {
    await register();
  } catch (error) {
    if (error instanceof PushError) throw error;

    // Browser and Firebase errors are technical; the reason stays in the console.
    console.warn("Push registration failed:", error);
    throw new PushError("Notifications could not be turned on for this device. Please try again later.");
  }

  rememberPushOn(userId, true);

  return "on";
}

/** Stop pushes to this browser for this account. */
export async function turnPushOff(userId) {
  rememberPushOn(userId, false);

  if (Notification.permission !== "granted") return;

  const token = await deviceToken();

  if (token) await api.delete("/api/push-tokens", { body: { token } });
}

/**
 * Register again for a customer who turned push on here before. Quiet: no
 * prompt, no toast. If the permission was withdrawn in the browser settings,
 * the remembered choice is dropped so the bell offers it again.
 */
export async function resumePush(userId) {
  if (!isPushOn(userId) || pushStatus() !== PUSH_STATUS.AVAILABLE) return;

  if (Notification.permission !== "granted") {
    rememberPushOn(userId, false);
    return;
  }

  await register();
}

/** Message the service worker posts when a notification is tapped (public/push-sw.js). */
export const OPEN_MESSAGE = "cnc:open";
