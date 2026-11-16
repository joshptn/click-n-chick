import { memo } from "react";
import { Pressable, StyleSheet, View } from "react-native";
import { router } from "expo-router";

import { itemsSummary, type Order } from "../../api/orders";
import { formatPeso } from "../../lib/money";
import { formatDay, formatTime } from "../../lib/time";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { IconChevronRight } from "../icons";
import { MiniProgress } from "./OrderProgress";
import { StatusBadge } from "./OrderTrackingView";

/**
 * An order in the Notif tab's Orders list (the web's OrderCard): reference,
 * what was ordered, when, the status and total. A live order also shows its
 * progress and "Track order".
 */
export const OrderCard = memo(function OrderCard({ order }: { order: Order }) {
  const live = !order.is_terminal;
  const when = order.closed_at ?? order.placed_at;
  const open = () => router.push(`/orders/${order.id}`);

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`Order ${order.reference}, ${order.status_label}`}
      onPress={open}
      style={({ pressed }) => [styles.card, pressed && styles.pressed]}
      testID={`order-${order.id}`}
    >
      <View style={styles.row}>
        <View style={styles.flex}>
          <AppText family="display" weight="semibold" size={14.5} color={colors.authInk}>
            {order.reference}
          </AppText>
          <AppText family="display" size={12.5} color={colors.ink500} numberOfLines={1}>
            {itemsSummary(order.items)}
          </AppText>
          <AppText family="display" size={11.5} color={colors.ink300}>
            {order.is_advance ? "Advance" : order.order_type === "delivery" ? "Delivery" : "Pickup"} · {formatDay(when)} ·{" "}
            {formatTime(when)}
          </AppText>
        </View>

        <View style={styles.right}>
          <StatusBadge order={order} />
          <AppText family="display" weight="bold" size={14} color={colors.authInk}>
            {formatPeso(order.total)}
          </AppText>
        </View>

        {!live && <IconChevronRight size={17} color="#d3cec7" strokeWidth={2.4} />}
      </View>

      {live && (
        <>
          <MiniProgress order={order} />
          <Pressable accessibilityRole="button" onPress={open} style={styles.track} testID={`track-${order.id}`}>
            <AppText family="display" weight="semibold" size={12.5} color={colors.authInk}>
              Track order
            </AppText>
          </Pressable>
        </>
      )}
    </Pressable>
  );
});

const styles = StyleSheet.create({
  card: {
    borderRadius: 16,
    backgroundColor: colors.white,
    padding: 14,
    gap: 10,
    elevation: 2,
    shadowColor: "#412111",
    shadowOpacity: 0.08,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 2 },
  },
  pressed: { opacity: 0.85 },
  row: { flexDirection: "row", alignItems: "center", gap: 10 },
  flex: { flex: 1, gap: 2 },
  right: { alignItems: "flex-end", gap: 6 },
  track: {
    alignSelf: "flex-start",
    paddingHorizontal: 16,
    paddingVertical: 7,
    borderRadius: 10,
    backgroundColor: colors.orange,
  },
});

export default OrderCard;
