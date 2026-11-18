import { useCallback, useEffect, useMemo, useState } from "react";
import { Pressable, RefreshControl, ScrollView, StyleSheet, View, useWindowDimensions } from "react-native";
import { router } from "expo-router";
import { useQuery } from "@tanstack/react-query";
import { SafeAreaView, useSafeAreaInsets } from "react-native-safe-area-context";

import { ALL_CATEGORY, FOODS_KEY, fetchFoods, type Food } from "../../api/menu";
import { AppText } from "../../components/AppText";
import { ScreenHeader } from "../../components/account/ScreenHeader";
import { TextField } from "../../components/auth/TextField";
import { IconCalendarEvent, IconCheck, IconMoodEmpty, IconSearchOff, IconShoppingCart } from "../../components/icons";
import { CategoryChips } from "../../components/menu/CategoryChips";
import { FoodCard } from "../../components/menu/FoodCard";
import { useCart } from "../../hooks/useCart";
import { colors } from "../../theme/colors";

const SEARCH_DEBOUNCE_MS = 300;
const GUTTER = 16;
const GAP = 12;

const RULES = [
  "Collection only: advance orders cannot be delivered",
  "From tomorrow up to 30 days ahead",
  "A Store Agent accepts your request before you pay",
];

/**
 * The advance menu (phase 9; the web's AdvanceOrder page): the same dishes,
 * added to the advance cart instead of today's, with today's stock set aside
 * (the store checks the date when it accepts). The floating cart button at
 * the bottom right opens the advance cart, its own screen in this flow.
 */
export default function AdvanceMenu() {
  const { width } = useWindowDimensions();
  const insets = useSafeAreaInsets();
  const [category, setCategory] = useState(ALL_CATEGORY);
  const [search, setSearch] = useState("");
  const [debounced, setDebounced] = useState("");
  const cart = useCart("advance");
  const { addItem, addingFoodId } = cart;

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(search.trim()), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [search]);

  const { data, isLoading, isError, refetch, isRefetching } = useQuery({
    queryKey: [FOODS_KEY, category, debounced],
    queryFn: ({ signal }) => fetchFoods({ category, search: debounced, signal }),
    staleTime: 60 * 1000,
  });

  const foods = useMemo(() => data?.data ?? [], [data]);
  const cardWidth = Math.floor((width - GUTTER * 2 - GAP) / 2);

  const openFood = useCallback((food: Food) => router.push({ pathname: "/food/[id]", params: { id: String(food.id), mode: "advance" } }), []);

  const quickAdd = useCallback(
    (food: Food) => {
      if ((food.addon_groups?.length ?? 0) > 0) {
        openFood(food);
        return;
      }

      addItem({ foodId: food.id, quantity: 1, addonIds: [] }).catch(() => {});
    },
    [addItem, openFood]
  );

  const openCart = () => router.push("/advance/cart");

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right"]}>
      <ScreenHeader title="Advance Order" />

      <ScrollView
        contentContainerStyle={[styles.content, { paddingBottom: 110 + insets.bottom }]}
        keyboardShouldPersistTaps="handled"
        keyboardDismissMode="on-drag"
        refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={() => void refetch()} colors={[colors.orange]} />}
      >
        <View style={styles.banner}>
          <View style={styles.bannerHead}>
            <IconCalendarEvent size={14} color="rgba(255,255,255,0.8)" strokeWidth={2.4} />
            <AppText family="display" weight="semibold" size={11} color="rgba(255,255,255,0.8)" style={styles.caps}>
              ADVANCE ORDER
            </AppText>
          </View>
          <AppText family="display" weight="bold" size={20} color={colors.white}>
            Book the kitchen ahead of time
          </AppText>
          {RULES.map((rule) => (
            <View key={rule} style={styles.rule}>
              <IconCheck size={13} color={colors.white} strokeWidth={3} style={styles.ruleIcon} />
              <AppText family="display" size={12} color="rgba(255,255,255,0.92)" style={styles.flex}>
                {rule}
              </AppText>
            </View>
          ))}
        </View>

        <View style={styles.search}>
          <TextField
            label="Search the menu"
            value={search}
            onChangeText={setSearch}
            placeholder="Search the menu to schedule..."
            returnKeyType="search"
            testID="advance-search"
          />
        </View>

        <AppText family="display" weight="semibold" size={11} color={colors.ink300} style={styles.eyebrow}>
          CATEGORY
        </AppText>
        <CategoryChips value={category} onChange={setCategory} />

        {isError && (
          <View style={[styles.message, styles.error]}>
            <AppText family="display" weight="semibold" size={14} color={colors.danger}>
              We couldn&apos;t load the menu.
            </AppText>
            <Pressable accessibilityRole="button" onPress={() => refetch()} style={styles.retry}>
              <AppText family="display" weight="semibold" size={13} color={colors.white}>
                Try again
              </AppText>
            </Pressable>
          </View>
        )}

        {isLoading && (
          <View style={styles.grid}>
            {Array.from({ length: 4 }, (_, index) => (
              <View key={index} style={[styles.skeleton, { width: cardWidth }]} />
            ))}
          </View>
        )}

        {!isLoading && !isError && foods.length === 0 && (
          <View style={styles.message}>
            {debounced ? <IconSearchOff size={40} color="#d9d3cb" /> : <IconMoodEmpty size={40} color="#d9d3cb" />}
            <AppText family="display" weight="semibold" size={15} color={colors.ink500}>
              {debounced ? `Nothing matches “${debounced}”` : "Nothing on the menu here yet"}
            </AppText>
          </View>
        )}

        {!isLoading && !isError && foods.length > 0 && (
          <View>
            <AppText family="display" weight="semibold" size={18} color={colors.authInk} style={styles.title}>
              {debounced ? `Results for “${debounced}”` : "What would you like us to prepare?"}
            </AppText>
            <View style={styles.grid}>
              {foods.map((food) => (
                <FoodCard
                  key={food.id}
                  food={food}
                  width={cardWidth}
                  advance
                  adding={addingFoodId === food.id}
                  onOpen={openFood}
                  onAdd={quickAdd}
                />
              ))}
            </View>
          </View>
        )}
      </ScrollView>

      {/* The advance cart, floating at the bottom right (design of 2026-11-19). */}
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={`Advance cart, ${cart.item_count} item${cart.item_count === 1 ? "" : "s"}`}
        onPress={openCart}
        style={({ pressed }) => [styles.cartButton, { bottom: 20 + insets.bottom }, pressed && styles.pressed]}
        testID="advance-cart-button"
      >
        <IconShoppingCart size={28} color={colors.authInk} strokeWidth={1.9} />
        {cart.item_count > 0 && (
          <View style={styles.cartBadge}>
            <AppText family="display" weight="bold" size={13} color={colors.white}>
              {cart.item_count > 99 ? "99+" : cart.item_count}
            </AppText>
          </View>
        )}
      </Pressable>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  flex: { flex: 1 },
  caps: { letterSpacing: 1.4 },
  content: { paddingTop: 4 },
  banner: { marginHorizontal: GUTTER, gap: 6, padding: 18, borderRadius: 20, backgroundColor: colors.brand600 },
  bannerHead: { flexDirection: "row", alignItems: "center", gap: 6 },
  rule: { flexDirection: "row", alignItems: "flex-start", gap: 6 },
  ruleIcon: { marginTop: 3 },
  search: { paddingHorizontal: GUTTER, marginTop: 16 },
  eyebrow: { letterSpacing: 1.2, paddingHorizontal: GUTTER, marginTop: 18, marginBottom: 8 },
  title: { paddingHorizontal: GUTTER, marginTop: 18, marginBottom: 12 },
  grid: { flexDirection: "row", flexWrap: "wrap", gap: GAP, paddingHorizontal: GUTTER, marginBottom: 8 },
  skeleton: { height: 236, borderRadius: 14, backgroundColor: "#f0e9df", marginTop: 16 },
  message: { alignItems: "center", gap: 6, marginTop: 28, marginHorizontal: GUTTER, paddingVertical: 24 },
  error: { borderRadius: 14, borderWidth: 1, borderColor: "#ffd7d5", backgroundColor: colors.dangerSurface },
  retry: { marginTop: 8, paddingHorizontal: 18, paddingVertical: 8, borderRadius: 10, backgroundColor: colors.orange },
  cartButton: {
    position: "absolute",
    right: 20,
    width: 64,
    height: 64,
    borderRadius: 32,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
    elevation: 6,
    shadowColor: "#412111",
    shadowOpacity: 0.16,
    shadowRadius: 10,
    shadowOffset: { width: 0, height: 4 },
  },
  cartBadge: {
    position: "absolute",
    top: -4,
    right: -4,
    minWidth: 26,
    height: 26,
    paddingHorizontal: 6,
    borderRadius: 13,
    borderWidth: 2,
    borderColor: colors.white,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.orange,
  },
  pressed: { opacity: 0.85 },
});
