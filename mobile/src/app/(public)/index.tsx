import { StyleSheet, View } from "react-native";
import { Link } from "expo-router";

import { formatStoreTime } from "../../api/store";
import { AppText } from "../../components/AppText";
import { Button } from "../../components/Button";
import { Screen } from "../../components/Screen";
import { useSession } from "../../hooks/useSession";
import { useStoreStatus } from "../../hooks/useStoreStatus";
import { colors } from "../../theme/colors";

/**
 * The first screen. For now it shows whether the store is taking orders, read
 * from the API; the menu replaces it in phase 6.
 */
export default function Welcome() {
  const { signedIn } = useSession();

  return (
    <Screen>
      <View style={styles.hero}>
        <AppText family="display" weight="bold" size={30} color={colors.brand700}>
          Click <AppText family="display" weight="bold" size={30}>n</AppText> Chick
        </AppText>
        <AppText size={16} color={colors.ink500}>
          Fried chicken from BES House of Chicken, for pickup or delivery.
        </AppText>
      </View>

      <StoreStatusCard />

      {signedIn ? (
        <Link href="/account" asChild>
          <Button label="My account" />
        </Link>
      ) : (
        <Link href="/sign-in" asChild>
          <Button label="Sign in" />
        </Link>
      )}
    </Screen>
  );
}

function StoreStatusCard() {
  const { status, isLoading, error, refetch, isRefetching, isOpen } = useStoreStatus();

  if (isLoading) {
    return (
      <View style={styles.card} testID="store-status-loading">
        <AppText color={colors.ink500}>Checking whether the store is open…</AppText>
      </View>
    );
  }

  if (error || !status) {
    return (
      <View style={[styles.card, styles.cardError]} testID="store-status-error">
        <AppText weight="semibold" color={colors.danger}>
          {"We couldn't reach the store."}
        </AppText>
        <AppText size={13} color={colors.ink500}>
          {error instanceof Error ? error.message : "Please try again."}
        </AppText>
        <Button label="Try again" variant="outline" loading={isRefetching} onPress={() => refetch()} />
      </View>
    );
  }

  return (
    <View style={styles.card} testID="store-status">
      <View style={[styles.badge, { backgroundColor: isOpen ? colors.successSurface : colors.dangerSurface }]}>
        <AppText weight="semibold" size={13} color={isOpen ? colors.success : colors.danger}>
          {isOpen ? "Open" : "Closed"}
        </AppText>
      </View>
      <AppText weight="medium" size={16}>
        {isOpen ? "Taking orders now" : "Not taking orders right now"}
      </AppText>
      <AppText size={14} color={colors.ink500}>
        Open {formatStoreTime(status.opens_at)} to {formatStoreTime(status.closes_at)}
        {status.accepting_delivery ? " · Pickup and delivery" : isOpen ? " · Pickup only" : ""}
      </AppText>
    </View>
  );
}

const styles = StyleSheet.create({
  hero: { gap: 6, paddingTop: 12 },
  card: {
    gap: 8,
    padding: 16,
    borderRadius: 16,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.border,
  },
  cardError: { borderColor: "#ffd7d5" },
  badge: { alignSelf: "flex-start", paddingHorizontal: 10, paddingVertical: 2, borderRadius: 999 },
});
