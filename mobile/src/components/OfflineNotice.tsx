import { StyleSheet, View } from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";

import { useOnline } from "../hooks/useOnline";
import { colors } from "../theme/colors";
import { AppText } from "./AppText";

/**
 * Shown while the phone has no connection (NFR-05), as on the web. The app has
 * no offline features: this only says why nothing loads, and goes away by
 * itself when the connection returns.
 */
export function OfflineNotice() {
  const online = useOnline();
  const insets = useSafeAreaInsets();

  if (online) return null;

  return (
    <View
      accessibilityRole="alert"
      accessibilityLiveRegion="polite"
      testID="offline-notice"
      style={[styles.bar, { paddingBottom: 12 + insets.bottom }]}
    >
      <AppText size={13} color={colors.white} style={styles.text}>
        You are offline. Check your connection; Click n Chick needs it to load the menu and your orders.
      </AppText>
    </View>
  );
}

const styles = StyleSheet.create({
  bar: {
    position: "absolute",
    left: 0,
    right: 0,
    bottom: 0,
    paddingTop: 12,
    paddingHorizontal: 16,
    backgroundColor: colors.notice,
  },
  text: { textAlign: "center" },
});

export default OfflineNotice;
