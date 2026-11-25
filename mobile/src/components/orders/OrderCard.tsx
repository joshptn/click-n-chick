import { memo, useState } from "react";
import { Pressable, StyleSheet, View } from "react-native";
import { router } from "expo-router";

import {
  cardMoneyNote,
  cardStatusLabel,
  itemCount,
  statusTone,
  type Order,
} from "../../api/orders";
import { formatPeso } from "../../lib/money";
import { formatTime } from "../../lib/time";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import {
  IconBike,
  IconCalendarEvent,
  IconChevronDown,
  IconShoppingBag,
  IconUser,
} from "../icons";
import { DishImage } from "../menu/DishImage";
import { TONES } from "./OrderTrackingView";

const SHORT_MONTHS = [
  "Jan",
  "Feb",
  "Mar",
  "Apr",
  "May",
  "Jun",
  "Jul",
  "Aug",
  "Sep",
  "Oct",
  "Nov",
  "Dec",
];
const SHORT_DAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

function sameDay(a: Date, b: Date) {
  return (
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  );
}

/** "Placed · 11:45 AM" today, "Placed · Nov 25, 2026 · 11:45 AM" on another day. */
function stamp(label: string, iso: string | null) {
  if (!iso) return label;

  const date = new Date(iso);

  return sameDay(date, new Date())
    ? `${label} · ${formatTime(iso)}`
    : `${label} · ${SHORT_MONTHS[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()} · ${formatTime(iso)}`;
}

/** "Sat, Nov 28 · 5:00 PM" */
function collection(iso: string) {
  const date = new Date(iso);

  return `${SHORT_DAYS[date.getDay()]}, ${SHORT_MONTHS[date.getMonth()]} ${date.getDate()} · ${formatTime(iso)}`;
}

function advanceStanding(order: Order) {
  if (order.status === "submitted") return "Not accepted yet";
  if (order.status === "rejected") return "Declined";
  if (order.is_cancelled) return "Cancelled";

  return "Accepted";
}

/**
 * An order on the Orders tab (the web's My Orders card, decided 2026-11-26):
 * what kind it is, where or when, its status and total; the dishes, which
 * fold away with the chevron; who receives it; and Track order.
 */
export const OrderCard = memo(function OrderCard({
  order,
  defaultOpen = true,
}: {
  order: Order;
  defaultOpen?: boolean;
}) {
  const [open, setOpen] = useState(defaultOpen);

  const isAdvance = order.is_advance;
  const isDelivery = order.order_type === "delivery";
  const Icon = isAdvance
    ? IconCalendarEvent
    : isDelivery
      ? IconBike
      : IconShoppingBag;
  const tone = TONES[statusTone(order)];

  const headline = isAdvance
    ? order.scheduled_for
      ? `For ${collection(order.scheduled_for)}`
      : "Collection date to follow"
    : isDelivery
      ? order.delivery?.full_address || "Delivery address"
      : order.pickup?.at
        ? `Pickup at ${formatTime(order.pickup.at)}`
        : "Pickup time not set";

  const contact = [order.contact?.name, order.contact?.phone]
    .filter(Boolean)
    .join(" · ");
  const note = cardMoneyNote(order);
  const track = () => router.push(`/orders/${order.id}`);

  return (
    <View style={styles.card} testID={`order-${order.id}`}>
      <View style={styles.head}>
        <View style={styles.kind}>
          <View style={styles.tile}>
            <Icon size={20} color={colors.brand600} strokeWidth={2} />
          </View>
          <View style={styles.flex}>
            <AppText
              family="display"
              weight="bold"
              size={9}
              color="#8d8884"
              style={styles.caps}
            >
              FULFILMENT
            </AppText>
            <AppText
              family="display"
              weight="extrabold"
              size={15}
              color={colors.authInk}
              numberOfLines={1}
            >
              {isAdvance ? "Advance Order" : isDelivery ? "Delivery" : "Pickup"}
            </AppText>
            <AppText family="display" size={11} color={colors.ink300}>
              {isAdvance ? advanceStanding(order) : itemCount(order.items)}
            </AppText>
          </View>
        </View>

        <View style={styles.money}>
          <View style={[styles.pill, { backgroundColor: tone.badge }]}>
            <AppText
              family="display"
              weight="bold"
              size={9.5}
              color={tone.text}
              style={styles.caps}
            >
              {cardStatusLabel(order).toUpperCase()}
            </AppText>
          </View>
          <AppText
            family="display"
            weight="extrabold"
            size={15}
            color={colors.authInk}
          >
            {formatPeso(order.total)}
          </AppText>
        </View>

        <Pressable
          accessibilityRole="button"
          accessibilityLabel={open ? "Hide the dishes" : "Show the dishes"}
          accessibilityState={{ expanded: open }}
          onPress={() => setOpen((prev) => !prev)}
          hitSlop={10}
          style={styles.chevron}
          testID={`order-${order.id}-toggle`}
        >
          <IconChevronDown
            size={18}
            color={colors.ink300}
            strokeWidth={2.2}
            style={open ? styles.flipped : undefined}
          />
        </Pressable>
      </View>

      <View style={styles.where}>
        <AppText
          family="display"
          weight="bold"
          size={13.5}
          color="#5f5b58"
          numberOfLines={2}
        >
          {headline}
        </AppText>
        <AppText
          family="display"
          weight="semibold"
          size={11}
          color={colors.authInk}
        >
          {stamp(isAdvance ? "Requested" : "Placed", order.placed_at)}
        </AppText>
      </View>

      {open && (
        <View style={styles.items} testID={`order-${order.id}-items`}>
          {order.items.map((item, index) => (
            <View
              key={item.id}
              style={[styles.item, index > 0 && styles.itemDivider]}
            >
              <DishImage
                url={item.thumbnail}
                width={46}
                iconSize={15}
                style={styles.thumb}
              />
              <View style={styles.flex}>
                <AppText
                  family="display"
                  weight="bold"
                  size={12.5}
                  color={colors.authInk}
                  numberOfLines={2}
                >
                  {item.food_name}
                  {item.addons.length > 0 ? (
                    <AppText
                      family="display"
                      weight="semibold"
                      size={12.5}
                      color={colors.ink500}
                    >
                      {` + ${item.addons.map((addon) => addon.addon_name).join(", ")}`}
                    </AppText>
                  ) : null}
                </AppText>
                <AppText family="display" size={11} color={colors.ink300}>
                  Qty {item.quantity}
                </AppText>
              </View>
              <AppText
                family="display"
                weight="bold"
                size={12.5}
                color={colors.authInk}
              >
                {formatPeso(item.subtotal)}
              </AppText>
            </View>
          ))}
        </View>
      )}

      <View style={styles.foot}>
        <View style={styles.footRow}>
          <View style={styles.flex}>
            {note ? (
              <AppText
                family="display"
                size={9.5}
                color="#8d8884"
                style={styles.caps}
                numberOfLines={1}
              >
                {note.toUpperCase()}
              </AppText>
            ) : null}
            <AppText
              family="display"
              weight="extrabold"
              size={13.5}
              color={colors.authInk}
            >
              Total: {formatPeso(order.total)}
            </AppText>
          </View>

          <Pressable
            accessibilityRole="button"
            onPress={track}
            style={({ pressed }) => [styles.track, pressed && styles.pressed]}
            testID={`track-${order.id}`}
          >
            <AppText
              family="display"
              weight="semibold"
              size={12.5}
              color={colors.white}
            >
              Track order
            </AppText>
          </Pressable>
        </View>

        {contact ? (
          <View style={styles.contact}>
            <IconUser size={13} color={colors.ink300} strokeWidth={2.2} />
            <AppText
              family="display"
              weight="bold"
              size={12}
              color="#5f5b58"
              numberOfLines={1}
              style={styles.flexShrink}
            >
              {contact}
            </AppText>
            <AppText
              family="display"
              weight="bold"
              size={9.5}
              color={colors.authInk}
              style={styles.caps}
            >
              · {isDelivery ? "RECIPIENT" : "PICKUP PERSON"}
            </AppText>
          </View>
        ) : null}
      </View>
    </View>
  );
});

const styles = StyleSheet.create({
  card: {
    overflow: "hidden",
    borderRadius: 18,
    borderWidth: 1,
    borderColor: "#f6d9bf",
    backgroundColor: colors.white,
    elevation: 2,
    shadowColor: "#783c0a",
    shadowOpacity: 0.08,
    shadowRadius: 10,
    shadowOffset: { width: 0, height: 4 },
  },
  head: {
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
    paddingHorizontal: 14,
    paddingTop: 14,
  },
  kind: { flex: 1, flexDirection: "row", alignItems: "center", gap: 10 },
  tile: {
    width: 42,
    height: 42,
    borderRadius: 12,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "#fff1e3",
  },
  flex: { flex: 1, minWidth: 0 },
  flexShrink: { flexShrink: 1 },
  caps: { letterSpacing: 0.8 },
  money: { alignItems: "flex-end", gap: 3 },
  pill: { paddingHorizontal: 8, paddingVertical: 2, borderRadius: 999 },
  chevron: {
    width: 28,
    height: 28,
    alignItems: "center",
    justifyContent: "center",
  },
  flipped: { transform: [{ rotate: "180deg" }] },
  where: { paddingHorizontal: 14, paddingTop: 10, paddingBottom: 12, gap: 1 },
  items: {
    borderTopWidth: 1,
    borderTopColor: "#f3ede4",
    paddingHorizontal: 14,
  },
  item: {
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
    paddingVertical: 10,
  },
  itemDivider: { borderTopWidth: 1, borderTopColor: "#f6f1ea" },
  thumb: { width: 46, height: 46, borderRadius: 10 },
  foot: {
    gap: 8,
    borderTopWidth: 1,
    borderTopColor: "#f3ede4",
    backgroundColor: "#fdfaf6",
    paddingHorizontal: 14,
    paddingVertical: 12,
  },
  footRow: { flexDirection: "row", alignItems: "center", gap: 10 },
  track: {
    height: 38,
    paddingHorizontal: 16,
    borderRadius: 10,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.orange,
  },
  contact: { flexDirection: "row", alignItems: "center", gap: 5 },
  pressed: { opacity: 0.85 },
});

export default OrderCard;
