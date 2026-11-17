import { memo, type ComponentType } from "react";
import { Pressable, StyleSheet, View } from "react-native";

import { notificationKind, type AppNotification } from "../../api/notifications";
import type { Order } from "../../api/orders";
import { timeAgo } from "../../lib/time";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import {
  IconBell,
  IconChefHat,
  IconCircleCheck,
  IconCircleX,
  IconReceipt,
  IconScooter,
  IconShieldLock,
  IconShoppingBag,
  IconSpeakerphone,
} from "../icons";
import { MiniProgress } from "./OrderProgress";

type IconProps = { size?: number; color?: string; strokeWidth?: number };
type Look = { Icon: ComponentType<IconProps>; color: string; tint: string };

const LOOKS = {
  placed: { Icon: IconReceipt, color: "#e2551c", tint: "#fde8de" },
  confirmed: { Icon: IconCircleCheck, color: "#1fa64a", tint: "#dcf5e4" },
  preparing: { Icon: IconChefHat, color: "#b7791f", tint: "#fdf1cf" },
  onTheWay: { Icon: IconScooter, color: "#2563eb", tint: "#dde8fd" },
  ready: { Icon: IconShoppingBag, color: "#7c3aed", tint: "#ece4fd" },
  cancelled: { Icon: IconCircleX, color: colors.danger, tint: colors.dangerSurface },
  order: { Icon: IconBell, color: colors.orange, tint: "#fff1e6" },
  security: { Icon: IconShieldLock, color: "#2563eb", tint: "#dde8fd" },
  updates: { Icon: IconSpeakerphone, color: colors.orange, tint: "#fff1e6" },
} satisfies Record<string, Look>;

/**
 * The picture for a notice. An order notice is drawn from its words, since a
 * notification records its message, not the status it announced.
 */
function lookFor(notification: AppNotification): Look {
  const kind = notificationKind(notification);

  if (kind === "security") return LOOKS.security;
  if (kind === "updates") return LOOKS.updates;

  // The title names the status; the message is only a fallback ("Order
  // placed" says "confirming it" in its message).
  return matchLook(notification.title) ?? matchLook(notification.body) ?? LOOKS.order;
}

function matchLook(text: string | null | undefined): Look | null {
  const words = (text ?? "").toLowerCase();

  if (/cancel|declin|reject/.test(words)) return LOOKS.cancelled;
  if (/placed|request/.test(words)) return LOOKS.placed;
  if (/on the way|out for delivery|rider/.test(words)) return LOOKS.onTheWay;
  if (/ready/.test(words)) return LOOKS.ready;
  if (/prepar|cooking|kitchen/.test(words)) return LOOKS.preparing;
  if (/delivered|completed|received|confirm|accept/.test(words)) return LOOKS.confirmed;

  return null;
}

/**
 * A notification (Notification design of 2026-11-17): the order's reference
 * when it has one, the title and message, when, and an orange dot until read.
 * The newest notice of a live order also shows its progress and "Track order".
 */
export const NotificationCard = memo(function NotificationCard({
  notification,
  order,
  showTracking,
  now,
  onPress,
}: {
  notification: AppNotification;
  /** The order it is about, once the orders list has it. */
  order: Order | undefined;
  showTracking: boolean;
  now: number;
  onPress: (notification: AppNotification) => void;
}) {
  const { Icon, color, tint } = lookFor(notification);

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${notification.is_read ? "" : "Unread. "}${notification.title}. ${notification.body}`}
      onPress={() => onPress(notification)}
      style={({ pressed }) => [styles.card, pressed && styles.pressed]}
      testID={`notification-${notification.id}`}
    >
      <View style={styles.row}>
        <View style={[styles.icon, { backgroundColor: tint }]}>
          <Icon size={22} color={color} strokeWidth={2} />
        </View>

        <View style={styles.text}>
          {order ? (
            <View style={[styles.reference, { backgroundColor: tint }]}>
              <AppText family="display" weight="semibold" size={10} color={color}>
                {order.reference}
              </AppText>
            </View>
          ) : null}

          <AppText family="display" weight="semibold" size={14.5} color={colors.authInk}>
            {notification.title}
          </AppText>
          <AppText family="display" size={12.5} color={colors.ink500}>
            {notification.body}
          </AppText>
          <AppText family="display" weight="medium" size={11.5} color={colors.authLabel}>
            {timeAgo(notification.created_at, now)}
          </AppText>
        </View>

        {!notification.is_read && <View style={styles.unread} />}
      </View>

      {showTracking && order ? (
        <View style={styles.tracking}>
          <MiniProgress order={order} />
          <View style={styles.trackButton}>
            <AppText family="display" weight="semibold" size={12.5} color={colors.authInk}>
              Track order
            </AppText>
          </View>
        </View>
      ) : null}
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
  row: { flexDirection: "row", alignItems: "flex-start", gap: 12 },
  icon: { width: 44, height: 44, borderRadius: 22, alignItems: "center", justifyContent: "center" },
  text: { flex: 1, gap: 2 },
  reference: { alignSelf: "flex-start", paddingHorizontal: 8, paddingVertical: 1, borderRadius: 6, marginBottom: 2 },
  unread: { width: 8, height: 8, borderRadius: 4, backgroundColor: colors.orange, marginTop: 6 },
  tracking: { gap: 10, paddingLeft: 56 },
  trackButton: {
    alignSelf: "flex-start",
    paddingHorizontal: 16,
    paddingVertical: 7,
    borderRadius: 10,
    backgroundColor: colors.orange,
  },
});

export default NotificationCard;
