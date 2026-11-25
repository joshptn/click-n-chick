import { useState } from "react";
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
  ORDERS_KEY,
  ORDER_TABS,
  ordersApi,
  type OrderTab,
} from "../../api/orders";
import { AppText } from "../../components/AppText";
import { TAB_BAR_SPACE } from "../../components/TabBar";
import { IconReceipt } from "../../components/icons";
import { OrderCard } from "../../components/orders/OrderCard";
import { colors } from "../../theme/colors";

const EMPTY: Record<
  OrderTab,
  {
    title: string;
    body: string;
    action?: { label: string; to: "/home" | "/advance" };
  }
> = {
  today: {
    title: "No orders today",
    body: "What you order today stays here until the day ends, even once it is done.",
    action: { label: "Explore Menu", to: "/home" },
  },
  advance: {
    title: "No advance orders",
    body: "Orders booked ahead stay here until their collection day has passed.",
    action: { label: "Order in advance", to: "/advance" },
  },
  past: {
    title: "No past orders yet",
    body: "Orders you have collected or received move here once their day has passed.",
  },
  cancelled: {
    title: "Nothing cancelled",
    body: "Orders that are cancelled, declined or rejected are kept here.",
  },
};

/**
 * Orders (decided 2026-11-26: its own tab, the web's My Orders): Today,
 * Advance, Past and Cancelled. Today and Advance keep an order until its day
 * has passed, whatever became of it; a cancelled one shows in Cancelled at
 * once as well. Dishes are open on Today and Advance and folded on Past and
 * Cancelled.
 */
export default function Orders() {
  const [tab, setTab] = useState<OrderTab>("today");

  const query = useInfiniteQuery({
    queryKey: [...ORDERS_KEY, "tab", tab],
    queryFn: ({ pageParam, signal }) => ordersApi.tab(tab, pageParam, signal),
    initialPageParam: 1,
    getNextPageParam: (last) =>
      last.meta.current_page < last.meta.last_page
        ? last.meta.current_page + 1
        : undefined,
    staleTime: 15 * 1000,
    refetchOnWindowFocus: true,
  });

  const orders = query.data?.pages.flatMap((page) => page.data) ?? [];
  const first = query.data?.pages[0];
  const counts = first?.counts ?? { active: 0 };
  const total = first?.meta.total ?? 0;
  const folded = tab === "past" || tab === "cancelled";
  const empty = !query.isLoading && !query.isError && orders.length === 0;

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right"]}>
      {/* The Notification and Profile tabs' bar: the title centred on the whole
          bar, the same height, so moving between tabs does not jump. */}
      <View style={styles.header}>
        <AppText
          family="display"
          weight="semibold"
          size={19}
          color={colors.authInk}
          style={styles.title}
          pointerEvents="none"
          accessibilityRole="header"
        >
          My Orders
        </AppText>
        {!query.isLoading && !query.isError && (
          <AppText
            family="display"
            size={12}
            color={colors.ink300}
            testID="orders-total"
          >
            {total} order{total === 1 ? "" : "s"}
          </AppText>
        )}
      </View>

      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        style={styles.tabBar}
        contentContainerStyle={styles.tabs}
      >
        {ORDER_TABS.map((entry) => {
          const selected = entry.key === tab;
          const count =
            entry.key === "today"
              ? counts.today
              : entry.key === "advance"
                ? counts.advance
                : undefined;

          return (
            <Pressable
              key={entry.key}
              accessibilityRole="tab"
              accessibilityState={{ selected }}
              onPress={() => setTab(entry.key)}
              style={[styles.tab, selected && styles.tabOn]}
              testID={`orders-tab-${entry.key}`}
            >
              <AppText
                family="display"
                weight="medium"
                size={12.5}
                color={selected ? colors.white : colors.authLabel}
              >
                {entry.label}
              </AppText>
              {count ? (
                <View style={[styles.count, selected && styles.countOn]}>
                  <AppText
                    family="display"
                    weight="bold"
                    size={10}
                    color={selected ? colors.white : colors.brand600}
                  >
                    {count}
                  </AppText>
                </View>
              ) : null}
            </Pressable>
          );
        })}
      </ScrollView>

      <ScrollView
        contentContainerStyle={[styles.content, empty && styles.contentEmpty]}
        refreshControl={
          <RefreshControl
            refreshing={query.isRefetching}
            onRefresh={() => void query.refetch()}
            colors={[colors.orange]}
          />
        }
      >
        {query.isLoading &&
          Array.from({ length: 2 }, (_, index) => (
            <View key={index} style={styles.skeleton} />
          ))}

        {query.isError && !query.isLoading && (
          <View style={styles.empty}>
            <AppText
              family="display"
              weight="semibold"
              size={15}
              color={colors.danger}
            >
              Your orders could not be loaded
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
              <IconReceipt size={44} color={colors.orange} strokeWidth={1.6} />
            </View>
            <AppText
              family="display"
              weight="semibold"
              size={18}
              color={colors.authInk}
              style={styles.center}
            >
              {EMPTY[tab].title}
            </AppText>
            <AppText
              family="display"
              size={13.5}
              color={colors.ink500}
              style={styles.center}
            >
              {EMPTY[tab].body}
            </AppText>
            {EMPTY[tab].action ? (
              <Pressable
                accessibilityRole="button"
                onPress={() => router.navigate(EMPTY[tab].action!.to)}
                style={styles.explore}
              >
                <AppText
                  family="display"
                  weight="semibold"
                  size={14}
                  color={colors.white}
                >
                  {EMPTY[tab].action!.label}
                </AppText>
              </Pressable>
            ) : null}
          </View>
        )}

        {orders.map((order) => (
          <OrderCard
            key={`${tab}-${order.id}`}
            order={order}
            defaultOpen={!folded}
          />
        ))}

        {query.hasNextPage && (
          <Pressable
            accessibilityRole="button"
            disabled={query.isFetchingNextPage}
            onPress={() => void query.fetchNextPage()}
            style={styles.more}
          >
            <AppText
              family="display"
              weight="semibold"
              size={13}
              color={colors.orange}
            >
              {query.isFetchingNextPage ? "Loading..." : "Show more"}
            </AppText>
          </Pressable>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  // The same bar and chips as the Notification tab.
  header: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "flex-end",
    minHeight: 58,
    paddingHorizontal: 16,
    paddingVertical: 10,
  },
  title: { position: "absolute", left: 0, right: 0, textAlign: "center" },
  tabBar: { flexGrow: 0 },
  tabs: {
    flexDirection: "row",
    gap: 8,
    paddingHorizontal: 16,
    paddingVertical: 8,
  },
  tab: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 6,
    height: 30,
    paddingHorizontal: 16,
    borderRadius: 15,
    borderWidth: 1,
    borderColor: "#e5e7eb",
    backgroundColor: colors.white,
  },
  tabOn: { backgroundColor: colors.orange, borderColor: colors.orange },
  count: {
    minWidth: 17,
    height: 17,
    paddingHorizontal: 4,
    borderRadius: 9,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "#fff1e3",
  },
  countOn: { backgroundColor: "rgba(255,255,255,0.3)" },
  content: {
    paddingHorizontal: 16,
    paddingTop: 6,
    paddingBottom: TAB_BAR_SPACE + 16,
    gap: 14,
  },
  contentEmpty: { flexGrow: 1, justifyContent: "center" },
  skeleton: { height: 240, borderRadius: 18, backgroundColor: "#f0e9df" },
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
    paddingHorizontal: 28,
    paddingVertical: 12,
    borderRadius: 999,
    backgroundColor: colors.orange,
  },
  more: { alignItems: "center", paddingVertical: 12 },
});
