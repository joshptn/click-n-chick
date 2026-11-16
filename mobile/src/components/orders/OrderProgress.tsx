import type { ComponentType } from "react";
import { StyleSheet, View } from "react-native";

import type { Order } from "../../api/orders";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import {
  IconCalendarEvent,
  IconCheck,
  IconCircleCheck,
  IconCreditCard,
  IconHome,
  IconReceipt,
  IconScooter,
  IconSend,
  IconShoppingBag,
  IconToolsKitchen2,
  IconX,
} from "../icons";

type IconProps = { size?: number; color?: string; strokeWidth?: number };

/** One icon per step the API names (immediate and advance chains). */
const ICONS: Record<string, ComponentType<IconProps>> = {
  placed: IconReceipt,
  submitted: IconSend,
  accepted: IconCircleCheck,
  awaiting_payment: IconCreditCard,
  scheduled: IconCalendarEvent,
  confirmed: IconCircleCheck,
  preparing: IconToolsKitchen2,
  ready_for_pickup: IconShoppingBag,
  on_the_way: IconScooter,
  completed: IconHome,
  delivered: IconHome,
};

export const GREEN = "#1fa64a";

/**
 * "Progress of Your Order" (tracking design of 2026-11-17): the API's steps,
 * done in green, the current one in orange, the rest grey. A cancelled order
 * shows every step crossed out.
 */
export function OrderProgress({ order }: { order: Order }) {
  const { steps, is_cancelled: cancelled } = order;

  if (steps.length === 0) return null;

  const done = steps.filter((step) => step.state === "done").length;
  const filled = cancelled ? 0 : Math.min(1, done / Math.max(1, steps.length - 1));

  return (
    <View style={styles.card} accessibilityLabel="Order progress">
      <View style={styles.head}>
        <AppText family="display" weight="semibold" size={16} color={colors.authInk}>
          Progress of Your Order
        </AppText>
        <View style={[styles.pill, cancelled && styles.pillCancelled]}>
          <AppText family="display" weight="medium" size={11.5} color={cancelled ? colors.danger : "#d4500e"}>
            {cancelled ? "Cancelled" : `${order.step_index ?? 0} of ${order.step_count} steps`}
          </AppText>
        </View>
      </View>

      <View>
        {/* The track runs between the first and last circle centres. */}
        <View style={[styles.track, { left: `${50 / steps.length}%`, right: `${50 / steps.length}%` }]}>
          <View style={[styles.fill, { width: `${filled * 100}%` }]} />
        </View>

        <View style={styles.steps}>
          {steps.map((step) => {
            const Icon = cancelled ? IconX : (ICONS[step.key] ?? IconCheck);
            const isDone = !cancelled && step.state === "done";
            const isCurrent = !cancelled && step.state === "current";

            return (
              <View key={step.key} style={styles.step}>
                <View style={[styles.circle, isDone && styles.done, isCurrent && styles.current]}>
                  <Icon size={18} color={isDone || isCurrent ? colors.white : "#b8b2aa"} strokeWidth={2} />
                </View>
                <AppText
                  family="display"
                  weight={isCurrent || isDone ? "medium" : "regular"}
                  size={10.5}
                  color={isCurrent ? colors.orange : isDone ? GREEN : colors.ink300}
                  style={styles.label}
                  numberOfLines={2}
                >
                  {step.label}
                </AppText>
              </View>
            );
          })}
        </View>
      </View>
    </View>
  );
}

const CIRCLE = 38;

const styles = StyleSheet.create({
  card: {
    borderRadius: 16,
    backgroundColor: colors.white,
    paddingHorizontal: 16,
    paddingTop: 16,
    paddingBottom: 14,
    gap: 14,
    elevation: 2,
    shadowColor: "#412111",
    shadowOpacity: 0.08,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 2 },
  },
  head: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: 8 },
  pill: { paddingHorizontal: 10, paddingVertical: 3, borderRadius: 999, backgroundColor: "#fff1e6" },
  pillCancelled: { backgroundColor: colors.dangerSurface },
  track: {
    position: "absolute",
    top: CIRCLE / 2 - 2,
    height: 4,
    borderRadius: 2,
    backgroundColor: "#ece7e0",
    overflow: "hidden",
  },
  fill: { height: "100%", backgroundColor: GREEN },
  steps: { flexDirection: "row", justifyContent: "space-between" },
  step: { flex: 1, alignItems: "center", gap: 6 },
  circle: {
    width: CIRCLE,
    height: CIRCLE,
    borderRadius: CIRCLE / 2,
    borderWidth: 1.5,
    borderColor: "#e3ddd5",
    backgroundColor: colors.white,
    alignItems: "center",
    justifyContent: "center",
  },
  done: { backgroundColor: GREEN, borderColor: GREEN },
  current: {
    backgroundColor: colors.orange,
    borderColor: "#ffd2a8",
    borderWidth: 3,
  },
  label: { textAlign: "center" },
});

/** The thin bar under a live order card or notification: "Placed … Delivery". */
export function MiniProgress({ order }: { order: Order }) {
  const steps = order.steps;
  const at = order.step_index ?? 0;
  const filled = steps.length > 1 ? Math.min(1, Math.max(0, (at - 1) / (steps.length - 1))) : 0;

  return (
    <View style={mini.wrap}>
      <View style={mini.labels}>
        {steps.map((step) => (
          <AppText key={step.key} family="display" weight="medium" size={9.5} color={colors.authLabel} numberOfLines={1} style={mini.label}>
            {step.label}
          </AppText>
        ))}
      </View>
      <View style={mini.track}>
        <View style={[mini.fill, { width: `${Math.max(8, filled * 100)}%` }]} />
      </View>
    </View>
  );
}

const mini = StyleSheet.create({
  wrap: { gap: 4 },
  labels: { flexDirection: "row", justifyContent: "space-between", gap: 4 },
  label: { flexShrink: 1 },
  track: { height: 4, borderRadius: 2, backgroundColor: "#f3e3d3", overflow: "hidden" },
  fill: { height: "100%", borderRadius: 2, backgroundColor: colors.orange },
});

export default OrderProgress;
