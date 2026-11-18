import { useCallback, useEffect, useRef, useState } from "react";
import { AppState } from "react-native";
import { Stack, router, type Href } from "expo-router";
import * as Notifications from "expo-notifications";
import * as SplashScreen from "expo-splash-screen";
import { StatusBar } from "expo-status-bar";
import { useFonts } from "expo-font";
import { QueryClientProvider, useQueryClient } from "@tanstack/react-query";
import { SafeAreaProvider } from "react-native-safe-area-context";

import { NOTIFICATIONS_KEY, notificationsApi } from "../api/notifications";
import { BrandSplash } from "../components/BrandSplash";
import { OfflineNotice } from "../components/OfflineNotice";
import { ToastHost } from "../components/ToastHost";
import { CartProvider } from "../hooks/useCart";
import { useRealtimeConnection } from "../hooks/useRealtime";
import { useSession } from "../hooks/useSession";
import { useUser } from "../hooks/useUser";
import { queryClient } from "../lib/queryClient";
import { loadGuestToken } from "../lib/guestCart";
import { loadGuestOrders } from "../lib/guestOrders";
import { forgetPushAccount, pushTarget, recheckPushPermission, responseData, syncPush, watchPushToken } from "../lib/push";
import { loadSession } from "../lib/session";
import { colors } from "../theme/colors";
import { fontFiles } from "../theme/fonts";

// Keep the splash up until the fonts and the stored session are ready, so no
// screen renders in the wrong font or flashes the signed-out view.
SplashScreen.preventAutoHideAsync().catch(() => {});

/**
 * The app starts on the public screens, and that is also where a protected
 * route sends someone when its guard closes (sign-out). Without it the first
 * group alphabetically, (auth), would be the start.
 */
export const unstable_settings = {
  anchor: "(public)",
};

/**
 * The route groups, guarded by the session (Expo Router protected routes):
 * - (public)  the landing page, for signed-out visitors;
 * - (tabs)    Home, Cart, and while signed in Notif and Profile: customers
 *             and guests alike (the tabs guard their own signed-in screens);
 * - food/[id] a dish, open to everyone;
 * - track/[token] a guest's order from its tracking link, open to everyone;
 * - checkout, payment  checking out and paying, for customers and guests;
 * - payment-return  the link back from the web's payment return page;
 * - orders/[id] a customer's order, only while signed in;
 * - account/*   the account pages opened from Profile, only while signed in;
 * - advance/*   advance orders (menu, scheduling, request sent), only while
 *               signed in: the API takes them from accounts only;
 * - (auth)    sign-in screens, only while signed out.
 * Customer module only: there are no staff routes (PRD §9).
 */
export default function RootLayout() {
  const [fontsLoaded, fontError] = useFonts(fontFiles);
  const session = useSession();

  // The guest's cart key first, so the cart knows whether there is one to
  // load by the time the session is ready.
  useEffect(() => {
    Promise.all([loadGuestToken(), loadGuestOrders()]).finally(loadSession);
  }, []);

  // A font that fails to load falls back to the system face rather than
  // holding the app on its splash screen.
  const ready = (fontsLoaded || Boolean(fontError)) && session.ready;

  // The branded splash stays over the app until its bar has filled.
  const [splashDone, setSplashDone] = useState(false);
  const finishSplash = useCallback(() => setSplashDone(true), []);

  useEffect(() => {
    if (ready) SplashScreen.hide();
  }, [ready]);

  if (!ready) return null;

  return (
    <SafeAreaProvider>
      <QueryClientProvider client={queryClient}>
        <StatusBar style="dark" />
        <CartProvider>
          <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.canvas } }}>
            <Stack.Screen name="(public)" />
            <Stack.Screen name="(tabs)" />
            <Stack.Screen name="food/[id]" />
            <Stack.Screen name="track/[token]" />
            <Stack.Screen name="checkout" />
            <Stack.Screen name="payment" options={{ gestureEnabled: false }} />
            <Stack.Screen name="payment-return" options={{ animation: "none" }} />

            <Stack.Protected guard={session.signedIn}>
              <Stack.Screen name="orders/[id]" />
              <Stack.Screen name="account" />
              <Stack.Screen name="advance" />
            </Stack.Protected>

            <Stack.Protected guard={!session.signedIn}>
              <Stack.Screen name="(auth)" />
            </Stack.Protected>
          </Stack>
          <Realtime />
          <PushSetup />
          <ToastHost />
          <OfflineNotice />
          {!splashDone && <BrandSplash onDone={finishSplash} />}
        </CartProvider>
      </QueryClientProvider>
    </SafeAreaProvider>
  );
}

/** Live updates for the whole app (hooks/useRealtime.ts); draws nothing. */
function Realtime() {
  useRealtimeConnection();
  return null;
}

/**
 * Push (phase 10; lib/push.ts): works out the signed-in customer's choice
 * (asking once after sign-in), keeps the token current, and opens the right
 * screen when a push is tapped, from the app closed or in the background:
 * the order, Manage Devices for a sign-in alert, or the Notif tab. The tapped
 * notification is marked read. Draws nothing.
 */
function PushSetup() {
  const { signedIn } = useSession();
  const user = useUser();
  const queryClient = useQueryClient();
  const userId = user?.role === "customer" ? user.id : null;

  useEffect(() => {
    if (signedIn && userId !== null) void syncPush(userId);
    if (!signedIn) forgetPushAccount();
  }, [signedIn, userId]);

  useEffect(() => watchPushToken(), []);

  // Back from Android's settings, where a blocked permission may have been allowed.
  useEffect(() => {
    const subscription = AppState.addEventListener("change", (next) => {
      if (next === "active") void recheckPushPermission();
    });
    return () => subscription.remove();
  }, []);

  const response = Notifications.useLastNotificationResponse();
  const handled = useRef<string | null>(null);

  useEffect(() => {
    if (!response || response.actionIdentifier !== Notifications.DEFAULT_ACTION_IDENTIFIER) return;

    const id = response.notification.request.identifier;
    if (handled.current === id) return;
    handled.current = id;
    Notifications.clearLastNotificationResponse();

    // Pushes go to signed-in customers only; a tap after signing out opens nothing.
    if (!signedIn) return;

    const { path, notificationId } = pushTarget(responseData(response));
    router.push(path as Href);

    if (notificationId !== null) {
      notificationsApi
        .markRead(notificationId)
        .then(() => queryClient.invalidateQueries({ queryKey: NOTIFICATIONS_KEY }))
        .catch(() => {});
    }
  }, [response, signedIn, queryClient]);

  return null;
}
