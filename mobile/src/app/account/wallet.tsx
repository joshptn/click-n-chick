import { StyleSheet, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";

import { AppText } from "../../components/AppText";
import { ScreenHeader } from "../../components/account/ScreenHeader";
import { IconGift, IconStarFilled } from "../../components/icons";
import { useProfile } from "../../hooks/useProfile";
import { colors } from "../../theme/colors";

/**
 * BES Wallet: a placeholder until the loyalty module (decided 2026-11-18). It
 * shows the stored balance, as the web's "My points" does, and offers nothing
 * to redeem yet.
 */
export default function Wallet() {
  const { data } = useProfile();
  const points = data?.loyalty_points ?? 0;

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right", "bottom"]}>
      <ScreenHeader title="BES Wallet" />

      <View style={styles.content}>
        <View style={styles.balance} testID="wallet-balance">
          <AppText family="display" weight="medium" size={12} color="rgba(255,255,255,0.85)" style={styles.caps}>
            MY POINTS
          </AppText>
          <View style={styles.amount}>
            <IconStarFilled size={26} color="#ffe08a" />
            <AppText family="display" weight="bold" size={34} color={colors.white}>
              {points.toLocaleString("en-PH")}
            </AppText>
          </View>
        </View>

        <View style={styles.soon}>
          <View style={styles.soonIcon}>
            <IconGift size={40} color={colors.orange} strokeWidth={1.6} />
          </View>
          <AppText family="display" weight="semibold" size={18} color={colors.authInk} style={styles.center}>
            Rewards are coming soon
          </AppText>
          <AppText family="display" size={13.5} color={colors.ink500} style={styles.center}>
            You&apos;ll be able to redeem your loyalty points here in a later update.
          </AppText>
        </View>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  content: { flex: 1, paddingHorizontal: 20, paddingTop: 8, gap: 28 },
  balance: {
    gap: 4,
    padding: 22,
    borderRadius: 20,
    backgroundColor: colors.orange,
    elevation: 4,
    shadowColor: colors.orange,
    shadowOpacity: 0.3,
    shadowRadius: 10,
    shadowOffset: { width: 0, height: 4 },
  },
  caps: { letterSpacing: 1 },
  amount: { flexDirection: "row", alignItems: "center", gap: 10 },
  soon: { alignItems: "center", gap: 8, paddingHorizontal: 12, paddingTop: 20 },
  soonIcon: {
    width: 96,
    height: 96,
    borderRadius: 48,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "#fff1e0",
    marginBottom: 10,
  },
  center: { textAlign: "center" },
});
