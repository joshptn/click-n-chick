import { StyleSheet } from "react-native";
import { SafeAreaView, useSafeAreaInsets } from "react-native-safe-area-context";

import { ScreenHeader } from "../../components/account/ScreenHeader";
import { CartContents } from "../../components/cart/CartContents";
import { colors } from "../../theme/colors";

/**
 * The advance cart (decided 2026-11-19): its own screen inside the advance
 * flow, opened from the advance menu, never beside today's cart. Schedule
 * Order goes straight to scheduling.
 */
export default function AdvanceCart() {
  const insets = useSafeAreaInsets();

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right"]}>
      <ScreenHeader title="Advance Cart" />
      <CartContents mode="advance" bottomSpace={24 + insets.bottom} />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
});
