import { StyleSheet, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";

import { AppText } from "../../components/AppText";
import { TAB_BAR_SPACE } from "../../components/TabBar";
import { CartContents } from "../../components/cart/CartContents";
import { IconShoppingCart } from "../../components/icons";
import { AppHeader } from "../../components/menu/AppHeader";
import { useCart } from "../../hooks/useCart";
import { colors } from "../../theme/colors";

/**
 * My Cart (design of 2026-11-17): today's cart only. The advance cart lives
 * inside the advance-order screens (decided 2026-11-19), so the two are never
 * mixed up.
 */
export default function CartScreen() {
  const { item_count: itemCount, line_count: lineCount } = useCart();

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right"]}>
      <AppHeader />

      <View style={styles.titleBar}>
        <IconShoppingCart size={24} color={colors.orange} strokeWidth={1.9} />
        <View style={styles.titleText}>
          <AppText family="display" weight="semibold" size={18} color={colors.authInk}>
            My Cart
          </AppText>
          <AppText family="display" size={12} color={colors.authMuted}>
            {itemCount} item{itemCount === 1 ? "" : "s"}
          </AppText>
        </View>
        {lineCount > 0 && (
          <View style={styles.countBadge}>
            <AppText family="display" weight="bold" size={12} color={colors.white}>
              {lineCount}
            </AppText>
          </View>
        )}
      </View>

      <CartContents mode="immediate" bottomSpace={TAB_BAR_SPACE + 16} />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.white },
  titleBar: { flexDirection: "row", alignItems: "center", gap: 12, paddingHorizontal: 20, paddingVertical: 12 },
  titleText: { flex: 1 },
  countBadge: {
    minWidth: 24,
    height: 24,
    borderRadius: 12,
    paddingHorizontal: 6,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.orange,
  },
});
