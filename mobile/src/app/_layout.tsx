import { useEffect } from "react";
import { Stack } from "expo-router";
import * as SplashScreen from "expo-splash-screen";
import { StatusBar } from "expo-status-bar";
import { useFonts } from "expo-font";
import { QueryClientProvider } from "@tanstack/react-query";
import { SafeAreaProvider } from "react-native-safe-area-context";

import { OfflineNotice } from "../components/OfflineNotice";
import { useSession } from "../hooks/useSession";
import { queryClient } from "../lib/queryClient";
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
 * Three route groups, guarded by the session (Expo Router protected routes):
 * - (public)  open to everyone;
 * - (auth)    sign-in screens, only while signed out;
 * - (account) only while signed in. When a session ends, the app leaves these
 *   by itself.
 * Customer module only: there are no staff routes (PRD §9).
 */
export default function RootLayout() {
  const [fontsLoaded, fontError] = useFonts(fontFiles);
  const session = useSession();

  useEffect(() => {
    loadSession();
  }, []);

  // A font that fails to load falls back to the system face rather than
  // holding the app on its splash screen.
  const ready = (fontsLoaded || Boolean(fontError)) && session.ready;

  useEffect(() => {
    if (ready) SplashScreen.hide();
  }, [ready]);

  if (!ready) return null;

  return (
    <SafeAreaProvider>
      <QueryClientProvider client={queryClient}>
        <StatusBar style="dark" />
        <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.canvas } }}>
          <Stack.Screen name="(public)" />

          <Stack.Protected guard={!session.signedIn}>
            <Stack.Screen name="(auth)" />
          </Stack.Protected>

          <Stack.Protected guard={session.signedIn}>
            <Stack.Screen name="(account)" />
          </Stack.Protected>
        </Stack>
        <OfflineNotice />
      </QueryClientProvider>
    </SafeAreaProvider>
  );
}
