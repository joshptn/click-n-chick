import { useCallback, useEffect, useMemo, useState } from "react";
import { Pressable, RefreshControl, ScrollView, StyleSheet, View, useWindowDimensions } from "react-native";
import { router } from "expo-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { SafeAreaView } from "react-native-safe-area-context";

import { ALL_CATEGORY, FOODS_KEY, fetchFoods, type Food } from "../../api/menu";
import { AppText } from "../../components/AppText";
import { TAB_BAR_SPACE } from "../../components/TabBar";
import { IconMoodEmpty, IconSearchOff } from "../../components/icons";
import { AppHeader } from "../../components/menu/AppHeader";
import { CategoryChips } from "../../components/menu/CategoryChips";
import { FoodCard } from "../../components/menu/FoodCard";
import { PosterBanner } from "../../components/menu/PosterBanner";
import { ActiveOrderCard } from "../../components/orders/ActiveOrderCard";
import { useCart } from "../../hooks/useCart";
import { useMenuSearch } from "../../lib/menuSearch";
import { colors } from "../../theme/colors";

/** Long enough that typing does not send a request per letter. */
const SEARCH_DEBOUNCE_MS = 300;

const GUTTER = 16;
const GAP = 12;

/**
 * Home (design of 2026-11-17), with the web Home's behaviour: posters,
 * categories, then the menu split into Popular Dishes (best sellers) and the
 * rest while browsing, or one list of results while searching.
 */
export default function Home() {
  const { width } = useWindowDimensions();
  const search = useMenuSearch();
  const [category, setCategory] = useState(ALL_CATEGORY);
  const [debounced, setDebounced] = useState(search.trim());
  const { addItem, addingFoodId } = useCart();
  const queryClient = useQueryClient();

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(search.trim()), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [search]);

  const { data, isLoading, isError, refetch, isRefetching } = useQuery({
    // Both filters are in the key, so going back to a category serves the cache.
    queryKey: [FOODS_KEY, category, debounced],
    queryFn: ({ signal }) => fetchFoods({ category, search: debounced, signal }),
    staleTime: 60 * 1000,
  });

  const foods = useMemo(() => data?.data ?? [], [data]);

  // A search covers the whole menu, so "Popular Dishes" only splits browsing.
  const browsing = debounced === "";
  const popular = useMemo(() => (browsing ? foods.filter((food) => food.is_best_seller) : []), [foods, browsing]);
  const rest = useMemo(() => (browsing ? foods.filter((food) => !food.is_best_seller) : foods), [foods, browsing]);

  const cardWidth = Math.floor((width - GUTTER * 2 - GAP) / 2);

  const openFood = useCallback((food: Food) => router.push(`/food/${food.id}`), []);

  const quickAdd = useCallback(
    (food: Food) => {
      // A dish with add-ons opens instead: adding it blind would choose "no
      // add-ons" for the customer.
      if ((food.addon_groups?.length ?? 0) > 0) {
        openFood(food);
        return;
      }

      addItem({ foodId: food.id, quantity: 1, addonIds: [] }).catch(() => {});
    },
    [addItem, openFood]
  );

  const onRefresh = () => {
    void refetch();
    void queryClient.invalidateQueries({ queryKey: ["posters"] });
    void queryClient.invalidateQueries({ queryKey: ["categories"] });
    void queryClient.invalidateQueries({ queryKey: ["store", "status"] });
    void queryClient.invalidateQueries({ queryKey: ["orders", "active"] });
  };

  const grid = (list: Food[]) => (
    <View style={styles.grid}>
      {list.map((food) => (
        <FoodCard
          key={food.id}
          food={food}
          width={cardWidth}
          adding={addingFoodId === food.id}
          onOpen={openFood}
          onAdd={quickAdd}
        />
      ))}
    </View>
  );

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right"]}>
      <AppHeader />

      <ScrollView
        contentContainerStyle={styles.content}
        keyboardShouldPersistTaps="handled"
        keyboardDismissMode="on-drag"
        refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={onRefresh} colors={[colors.orange]} />}
      >
        <PosterBanner width={width - GUTTER * 2} />

        <AppText family="display" weight="semibold" size={11} color={colors.ink300} style={styles.eyebrow}>
          CATEGORY
        </AppText>

        <CategoryChips value={category} onChange={setCategory} />

        <ActiveOrderCard />

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
            <AppText family="display" size={13} color={colors.ink300} style={styles.center}>
              {debounced ? "Try a different word, or browse the categories above." : "Pick another category to keep looking."}
            </AppText>
          </View>
        )}

        {!isLoading && !isError && popular.length > 0 && (
          <View>
            <AppText family="display" weight="semibold" size={11} color={colors.ink300} style={styles.eyebrow}>
              OUR MENU
            </AppText>
            <AppText family="display" weight="semibold" size={20} color={colors.authInk} style={styles.title}>
              Popular Dishes
            </AppText>
            {grid(popular)}
          </View>
        )}

        {!isLoading && !isError && rest.length > 0 && (
          <View>
            <AppText family="display" weight="semibold" size={20} color={colors.authInk} style={styles.title}>
              {browsing ? "More on the Menu" : `Results for “${debounced}”`}
            </AppText>
            {grid(rest)}
          </View>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  content: { paddingTop: 16, paddingBottom: TAB_BAR_SPACE + 16 },
  eyebrow: { letterSpacing: 1.2, paddingHorizontal: GUTTER, marginTop: 18, marginBottom: 8 },
  title: { paddingHorizontal: GUTTER, marginTop: 4, marginBottom: 12 },
  grid: { flexDirection: "row", flexWrap: "wrap", gap: GAP, paddingHorizontal: GUTTER, marginBottom: 8 },
  skeleton: { height: 236, borderRadius: 14, backgroundColor: "#f0e9df", marginTop: 16 },
  message: { alignItems: "center", gap: 6, marginTop: 28, marginHorizontal: GUTTER, paddingVertical: 24 },
  error: { borderRadius: 14, borderWidth: 1, borderColor: "#ffd7d5", backgroundColor: colors.dangerSurface },
  retry: { marginTop: 8, paddingHorizontal: 18, paddingVertical: 8, borderRadius: 10, backgroundColor: colors.orange },
  center: { textAlign: "center" },
});
