import { useSyncExternalStore } from "react";
import { Linking, Platform } from "react-native";
import Constants, { ExecutionEnvironment } from "expo-constants";
import * as Notifications from "expo-notifications";
import { AndroidImportance } from "expo-notifications";

import { api } from "../api/client";
import { STORAGE_KEYS, readSecure, writeSecure } from "./secureStorage";

/**
 * Push notifications on the phone (phase 10, decided 2026-11-19), through
 * Firebase Cloud Messaging sent directly by the API (no Expo push service):
 *
 * - After sign-in the app asks once for Android's permission; Profile has a
 *   switch to turn pushes off and on again for this phone.
 * - The choice is remembered per account on this phone. While it is on, the
 *   phone's FCM token is registered at every start (POST /api/push-tokens);
 *   the API ties it to this sign-in and drops it when the sign-in ends.
 * - While the app is on screen no system notification is shown: the live
 *   connection already shows the app's toast (as on the PWA).
 *
 * Expo Go cannot receive remote push, so there push is "unavailable"; a
 * development or release build has it.
 */

/** Android's channel for every push (app.json's defaultChannel). */
export const PUSH_CHANNEL = "orders";

export type PushStatus =
  /** Expo Go, or not Android: no remote push here. */
  | "unavailable"
  /** Not worked out yet for this account. */
  | "unknown"
  | "on"
  | "off"
  /** Turned off in Android's settings for the app: only there can it come back. */
  | "blocked";

type PushState = { status: PushStatus; busy: boolean; userId: number | null };

const SUPPORTED = Platform.OS === "android" && Constants.executionEnvironment !== ExecutionEnvironment.StoreClient;

let state: PushState = { status: SUPPORTED ? "unknown" : "unavailable", busy: false, userId: null };
const listeners = new Set<() => void>();

function set(patch: Partial<PushState>) {
  state = { ...state, ...patch };
  listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

const snapshot = () => state;

export function usePush(): PushState {
  return useSyncExternalStore(subscribe, snapshot, snapshot);
}

// The app's own toast shows a notification that arrives while it is open.
Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowBanner: false,
    shouldShowList: false,
    shouldPlaySound: false,
    shouldSetBadge: false,
  }),
});

const preferenceKey = (userId: number) => `${STORAGE_KEYS.pushPreference}${userId}`;

/** Android 13+ asks for the permission only once a channel exists. */
async function ensureChannel() {
  await Notifications.setNotificationChannelAsync(PUSH_CHANNEL, {
    name: "Orders and updates",
    description: "Your orders, store updates and new sign-ins to your account.",
    importance: AndroidImportance.HIGH,
    vibrationPattern: [0, 250, 150, 250],
    lightColor: "#ff8904",
  });
}

let currentToken: string | null = null;

async function register(): Promise<void> {
  const token = (await Notifications.getDevicePushTokenAsync()).data;

  if (typeof token !== "string" || token === "") throw new Error("No push token was given.");

  currentToken = token;
  await api.post("/api/push-tokens", { token, platform: "android" });
}

/**
 * Worked out once per signed-in account, at start or sign-in: asks the first
 * time, registers the token when on. Never throws: push is a nicety.
 */
export async function syncPush(userId: number): Promise<void> {
  if (!SUPPORTED) return;
  if (state.userId === userId && state.status !== "unknown") return;

  set({ userId, status: "unknown" });

  try {
    await ensureChannel();
    const preference = await readSecure(preferenceKey(userId));

    if (preference === "off") {
      set({ status: "off" });
      return;
    }

    let permission = await Notifications.getPermissionsAsync();

    // The first sign-in on this phone: Android's prompt, once.
    if (preference === null && !permission.granted && permission.canAskAgain) {
      permission = await Notifications.requestPermissionsAsync();
    }

    if (!permission.granted) {
      await writeSecure(preferenceKey(userId), "off");
      set({ status: permission.canAskAgain ? "off" : "blocked" });
      return;
    }

    await writeSecure(preferenceKey(userId), "on");
    await register();
    set({ status: "on" });
  } catch {
    // Firebase unreachable or the API down: try again next start.
    set({ status: "off" });
  }
}

/** Profile's switch, on. A permission blocked in Android's settings opens them. */
export async function turnPushOn(): Promise<void> {
  const userId = state.userId;
  if (!SUPPORTED || userId === null || state.busy) return;

  set({ busy: true });

  try {
    await ensureChannel();
    let permission = await Notifications.getPermissionsAsync();

    if (!permission.granted && permission.canAskAgain) permission = await Notifications.requestPermissionsAsync();

    if (!permission.granted) {
      set({ status: "blocked" });
      await Linking.openSettings();
      return;
    }

    await register();
    await writeSecure(preferenceKey(userId), "on");
    set({ status: "on" });
  } finally {
    set({ busy: false });
  }
}

/** Profile's switch, off: this phone stops receiving this account's pushes. */
export async function turnPushOff(): Promise<void> {
  const userId = state.userId;
  if (!SUPPORTED || userId === null || state.busy) return;

  set({ busy: true });

  try {
    const token = currentToken ?? (await Notifications.getDevicePushTokenAsync()).data;
    await api.delete("/api/push-tokens", { body: { token } });
    await writeSecure(preferenceKey(userId), "off");
    set({ status: "off" });
  } finally {
    set({ busy: false });
  }
}

/** A new token from Firebase while on (rare): the API is told the new one. */
export function watchPushToken(): () => void {
  if (!SUPPORTED) return () => {};

  const subscription = Notifications.addPushTokenListener((token) => {
    if (state.status !== "on" || typeof token.data !== "string") return;

    currentToken = token.data;
    api.post("/api/push-tokens", { token: token.data, platform: "android" }).catch(() => {});
  });

  return () => subscription.remove();
}

/** Signed out: the API already dropped the token with the sign-in. */
export function forgetPushAccount() {
  if (!SUPPORTED) return;
  set({ userId: null, status: "unknown", busy: false });
}

/** Back from Android's settings: the permission may have changed there. */
export async function recheckPushPermission(): Promise<void> {
  if (!SUPPORTED || state.userId === null || state.status !== "blocked") return;

  const permission = await Notifications.getPermissionsAsync();

  if (permission.granted) {
    const userId = state.userId;
    set({ status: "unknown", userId: null });
    await writeSecure(preferenceKey(userId), "on");
    await syncPush(userId);
  }
}

/** Where a push's tap opens, from its data (notification_id, type, order_id). */
export function pushTarget(data: Record<string, unknown>): { path: string; notificationId: number | null } {
  const orderId = Number(data.order_id);
  const notificationId = Number(data.notification_id);
  const id = Number.isInteger(notificationId) && notificationId > 0 ? notificationId : null;

  if (Number.isInteger(orderId) && orderId > 0) return { path: `/orders/${orderId}`, notificationId: id };
  // A new-device sign-in alert: where it can be signed out (as the Notif tab).
  if (data.type === "security") return { path: "/account/devices", notificationId: id };

  return { path: "/notifications", notificationId: id };
}

/** The data of a tapped push: in the content, or in Firebase's message when the system showed it. */
export function responseData(response: Notifications.NotificationResponse): Record<string, unknown> {
  const content = response.notification.request.content.data;
  if (content && Object.keys(content).length > 0) return content as Record<string, unknown>;

  const trigger = response.notification.request.trigger as { type?: string; remoteMessage?: { data?: Record<string, string> } } | null;
  return trigger?.remoteMessage?.data ?? {};
}
