import { useCallback, useEffect, useMemo, useState } from "react";
import {
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  View,
} from "react-native";
import { router } from "expo-router";
import { useInfiniteQuery } from "@tanstack/react-query";
import { SafeAreaView } from "react-native-safe-area-context";

import {
  notificationKind,
  type AppNotification,
  type NotificationKind,
} from "../../api/notifications";
import { ORDERS_KEY, ordersApi } from "../../api/orders";
import { AppText } from "../../components/AppText";
import { TAB_BAR_SPACE } from "../../components/TabBar";
import { IconBellOff } from "../../components/icons";
import { NotificationCard } from "../../components/orders/NotificationCard";
import { useNotifications } from "../../hooks/useNotifications";
import { formatDay } from "../../lib/time";
import { colors } from "../../theme/colors";

// Orders moved to their own tab (2026-11-26); order notices stay under All.
type Chip = "all" | Exclude<NotificationKind, "orders">;

const CHIPS: { key: Chip; label: string }[] = [
  { key: "all", label: "All" },
  { key: "updates", label: "Updates" },
  { key: "security", label: "Security" },
];

const EMPTY: Record<Chip, { title: string; body: string }> = {
  all: {
    title: "No Notifications Yet",
    body: "You're all caught up! When you have new orders or updates, they'll appear here.",
  },
  updates: {
    title: "No updates yet",
    body: "News from the store, like price changes and delivery rates, will show here.",
  },
  security: {
    title: "No security alerts",
    body: "We'll tell you here when your account signs in on a new device.",
  },
};

/**
 * Notifications: All, Updates and Security; an order notice opens its order.
 * The orders themselves have their own tab since 2026-11-26 (they were an
 * Orders chip here); the order list is still read here so the newest notice
 * of a live order can show its progress.
 */
export default function Notifications() {
  const [chip, setChip] = useState<Chip>("all");

  // "8 min ago" moves on by itself.
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 60 * 1000);
    return () => clearInterval(timer);
  }, []);
  const {
    notifications,
    unreadCount,
    isLoading,
    isError,
    isRefetching,
    refetch,
    markRead,
    markAllRead,
  } = useNotifications();

  const ordersQuery = useInfiniteQuery({
    queryKey: [...ORDERS_KEY, "list"],
    queryFn: ({ pageParam, signal }) => ordersApi.list(pageParam, signal),
    initialPageParam: 1,
    getNextPageParam: (last) =>
      last.meta.current_page < last.meta.last_page
        ? last.meta.current_page + 1
        : undefined,
    staleTime: 15 * 1000,
    refetchOnWindowFocus: true,
  });

  const orders = useMemo(
    () => ordersQuery.data?.pages.flatMap((page) => page.data) ?? [],
    [ordersQuery.data],
  );
  const byId = useMemo(
    () => new Map(orders.map((order) => [order.id, order])),
    [orders],
  );

  const shown = useMemo(
    () =>
      chip === "all"
        ? notifications
        : notifications.filter((item) => notificationKind(item) === chip),
    [chip, notifications],
  );

  // Only the newest notice of a live order carries its progress.
  const newestPerOrder = useMemo(() => {
    const seen = new Set<number>();
    const newest = new Set<number>();

    notifications.forEach((item) => {
      if (item.order_id && !seen.has(item.order_id)) {
        seen.add(item.order_id);
        newest.add(item.id);
      }
    });

    return newest;
  }, [notifications]);

  const groups = useMemo(() => {
    const result: { day: string; items: AppNotification[] }[] = [];

    shown.forEach((item) => {
      const day = formatDay(item.created_at);
      const last = result[result.length - 1];
      if (last && last.day === day) last.items.push(item);
      else result.push({ day, items: [item] });
    });

    return result;
  }, [shown]);

  const open = useCallback(
    (notification: AppNotification) => {
      if (!notification.is_read) markRead(notification.id);
      if (notification.order_id)
        router.push(`/orders/${notification.order_id}`);
      // A new-device sign-in alert opens the device list, where it can be signed out.
      else if (notificationKind(notification) === "security")
        router.push("/account/devices");
      // A Senior/PWD decision opens the discount card on Profile.
      else if (notification.notification_type === "discount_decision")
        router.push("/profile");
    },
    [markRead],
  );

  const refresh = () => {
    void refetch();
    void ordersQuery.refetch();
  };

  const loading = isLoading;
  const failed = isError;
  const empty = !loading && !failed && shown.length === 0;

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right"]}>
      <View style={styles.header}>
        {/* Centred on the whole bar; the tab bar moves between tabs, so no back button. */}
        <AppText
          family="display"
          weight="semibold"
          size={19}
          color={colors.authInk}
          style={styles.title}
          pointerEvents="none"
          accessibilityRole="header"
        >
          Notification
        </AppText>
        <Pressable
          accessibilityRole="button"
          disabled={unreadCount === 0}
          onPress={() => markAllRead()}
          style={[styles.markAll, unreadCount === 0 && styles.faded]}
          hitSlop={8}
          testID="mark-all-read"
        >
          <AppText
            family="display"
            weight="medium"
            size={12}
            color={colors.authInk}
          >
            Mark all as read
          </AppText>
        </Pressable>
      </View>

      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        contentContainerStyle={styles.chips}
        style={styles.chipBar}
      >
        {CHIPS.map((item) => {
          const selected = item.key === chip;

          return (
            <Pressable
              key={item.key}
              accessibilityRole="tab"
              accessibilityState={{ selected }}
              onPress={() => setChip(item.key)}
              style={[styles.chip, selected && styles.chipSelected]}
              testID={`chip-${item.key}`}
            >
              <AppText
                family="display"
                weight="medium"
                size={12.5}
                color={selected ? colors.white : colors.authLabel}
              >
                {item.label}
              </AppText>
            </Pressable>
          );
        })}
      </ScrollView>

      <ScrollView
        contentContainerStyle={[styles.content, empty && styles.contentEmpty]}
        refreshControl={
          <RefreshControl
            refreshing={isRefetching || ordersQuery.isRefetching}
            onRefresh={refresh}
            colors={[colors.orange]}
          />
        }
      >
        {loading &&
          Array.from({ length: 3 }, (_, index) => (
            <View key={index} style={styles.skeleton} />
          ))}

        {failed && !loading && (
          <View style={styles.empty}>
            <AppText
              family="display"
              weight="semibold"
              size={15}
              color={colors.danger}
            >
              Your notifications could not be loaded
            </AppText>
            <AppText
              family="display"
              size={13}
              color={colors.ink500}
              style={styles.center}
            >
              Please check your connection and pull down to try again.
            </AppText>
          </View>
        )}

        {empty && (
          <View style={styles.empty}>
            <View style={styles.emptyIcon}>
              <IconBellOff size={44} color={colors.orange} strokeWidth={1.6} />
            </View>
            <AppText
              family="display"
              weight="semibold"
              size={18}
              color={colors.authInk}
              style={styles.center}
            >
              {EMPTY[chip].title}
            </AppText>
            <AppText
              family="display"
              size={13.5}
              color={colors.ink500}
              style={styles.center}
            >
              {EMPTY[chip].body}
            </AppText>
            <Pressable
              accessibilityRole="button"
              onPress={() => router.navigate("/home")}
              style={styles.explore}
            >
              <AppText
                family="display"
                weight="semibold"
                size={14}
                color={colors.authInk}
              >
                Explore Menu
              </AppText>
            </Pressable>
          </View>
        )}

        {groups.map((group) => (
          <View key={group.day} style={styles.group}>
            <AppText
              family="display"
              weight="semibold"
              size={11}
              color={colors.ink300}
              style={styles.day}
            >
              {group.day.toUpperCase()}
            </AppText>
            {group.items.map((item) => {
              const order = item.order_id ? byId.get(item.order_id) : undefined;

              return (
                <NotificationCard
                  key={item.id}
                  notification={item}
                  order={order}
                  showTracking={Boolean(
                    order && !order.is_terminal && newestPerOrder.has(item.id),
                  )}
                  now={now}
                  onPress={open}
                />
              );
            })}
          </View>
        ))}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  header: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "flex-end",
    minHeight: 58,
    paddingHorizontal: 16,
    paddingVertical: 10,
  },
  title: { position: "absolute", left: 0, right: 0, textAlign: "center" },
  markAll: { paddingVertical: 4 },
  faded: { opacity: 0.4 },
  chipBar: { flexGrow: 0 },
  chips: {
    flexDirection: "row",
    gap: 8,
    paddingHorizontal: 16,
    paddingVertical: 8,
  },
  chip: {
    height: 30,
    paddingHorizontal: 16,
    borderRadius: 15,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: "#e5e7eb",
  },
  chipSelected: { backgroundColor: colors.orange, borderColor: colors.orange },
  content: {
    paddingHorizontal: 16,
    paddingTop: 6,
    paddingBottom: TAB_BAR_SPACE + 16,
    gap: 12,
  },
  contentEmpty: { flexGrow: 1, justifyContent: "center" },
  skeleton: { height: 96, borderRadius: 16, backgroundColor: "#f0e9df" },
  group: { gap: 12 },
  day: { letterSpacing: 1, marginTop: 4 },
  empty: {
    alignItems: "center",
    gap: 8,
    paddingHorizontal: 24,
    paddingBottom: 60,
  },
  emptyIcon: {
    width: 120,
    height: 120,
    borderRadius: 60,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "#fff1e0",
    marginBottom: 14,
  },
  center: { textAlign: "center" },
  explore: {
    marginTop: 14,
    paddingHorizontal: 32,
    paddingVertical: 12,
    borderRadius: 999,
    backgroundColor: colors.orange,
    elevation: 4,
    shadowColor: colors.orange,
    shadowOpacity: 0.35,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 4 },
  },
});
