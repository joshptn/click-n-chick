import { useEffect } from "react";
import { Pressable, StyleSheet, View } from "react-native";
import { router } from "expo-router";
import { useQuery } from "@tanstack/react-query";

import { ApiError } from "../../api/client";
import { guestOrderKey, guestOrdersApi, type Order } from "../../api/orders";
import { useSession } from "../../hooks/useSession";
import { forgetGuestOrder, useGuestOrders } from "../../lib/guestOrders";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { IconChevronRight, IconShoppingBag } from "../icons";

/** The Home design's banner sentence for each status. */
function line(order: Order) {
  return `${order.status_label}. Track it live.`;
}

/**
 * "Order CNC-… Track it live" on Home (design of 2026-11-17), for a guest:
 * the newest order tracked on this phone. Hidden when nothing is in progress,
 * and for a signed-in customer, who has the Orders tab (decided 2026-11-26,
 * as on the web).
 */
export function ActiveOrderCard() {
  const { signedIn } = useSession();
  const guestOrders = useGuestOrders();
  const guestToken = !signedIn ? (guestOrders[0]?.token ?? null) : null;

  const guest = useQuery({
    queryKey: guestOrderKey(guestToken ?? ""),
    queryFn: ({ signal }) => guestOrdersApi.show(guestToken ?? "", signal),
    enabled: guestToken !== null,
    retry: false,
    staleTime: 15 * 1000,
    refetchOnWindowFocus: true,
  });

  const order = guestToken !== null ? guest.data?.order : undefined;

  // A guest's order is kept only while it is open (decided 2026-11-22), so the
  // next one tracked on this phone takes its place.
  const guestClosed = Boolean(guest.data?.order.is_terminal);
  const guestRefused = guest.error instanceof ApiError && [403, 404, 410].includes(guest.error.status);

  useEffect(() => {
    if (guestToken && (guestClosed || guestRefused)) void forgetGuestOrder(guestToken);
  }, [guestToken, guestClosed, guestRefused]);

  if (!order || order.is_terminal) return null;

  const open = () => router.push(`/track/${guestToken}`);

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${order.title}. ${line(order)}`}
      onPress={open}
      style={({ pressed }) => [styles.card, pressed && styles.pressed]}
      testID="active-order"
    >
      <View style={styles.icon}>
        <IconShoppingBag size={20} color={colors.white} strokeWidth={2} />
      </View>
      <View style={styles.text}>
        <AppText family="display" weight="semibold" size={14} color={colors.white}>
          {order.title}
        </AppText>
        <AppText family="display" size={11.5} color="rgba(255,255,255,0.9)" numberOfLines={1}>
          {line(order)}
        </AppText>
      </View>
      <View style={styles.track}>
        <AppText family="display" weight="semibold" size={12} color={colors.white}>
          Track
        </AppText>
        <IconChevronRight size={14} color={colors.white} strokeWidth={2.4} />
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
    marginHorizontal: 16,
    marginTop: 18,
    paddingHorizontal: 14,
    paddingVertical: 14,
    borderRadius: 16,
    backgroundColor: colors.orange,
    elevation: 4,
    shadowColor: colors.orange,
    shadowOpacity: 0.3,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 4 },
  },
  pressed: { opacity: 0.9 },
  icon: {
    width: 40,
    height: 40,
    borderRadius: 12,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "rgba(255,255,255,0.22)",
  },
  text: { flex: 1 },
  track: {
    flexDirection: "row",
    alignItems: "center",
    gap: 2,
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 10,
    backgroundColor: "rgba(255,255,255,0.22)",
  },
});

export default ActiveOrderCard;
