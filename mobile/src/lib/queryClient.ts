import { AppState, type AppStateStatus, Platform } from "react-native";
import NetInfo from "@react-native-community/netinfo";
import { QueryClient, focusManager, onlineManager } from "@tanstack/react-query";

/** Same defaults as the web app (client/src/lib/queryClient.js). */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
      staleTime: 60 * 1000,
      retry: 1,
    },
    mutations: {
      retry: 0,
    },
  },
});

/**
 * React Native has no window focus or online events, so TanStack Query is told
 * by NetInfo when the connection drops (queries pause instead of failing) and
 * by AppState when the app comes back to the foreground.
 */
onlineManager.setEventListener((setOnline) =>
  NetInfo.addEventListener((state) => {
    setOnline(state.isConnected !== false);
  })
);

function onAppStateChange(status: AppStateStatus) {
  if (Platform.OS !== "web") focusManager.setFocused(status === "active");
}

AppState.addEventListener("change", onAppStateChange);

export default queryClient;
