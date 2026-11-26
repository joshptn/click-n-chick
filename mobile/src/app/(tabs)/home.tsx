import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  View,
  useWindowDimensions,
  type NativeScrollEvent,
  type ScrollViewInstance,
  type NativeSyntheticEvent,
} from "react-native";
import { router } from "expo-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { SafeAreaView } from "react-native-safe-area-context";

import { ALL_CATEGORY, FOODS_KEY, fetchFoods, type Food } from "../../api/menu";
import { AppText } from "../../components/AppText";
import { TAB_BAR_SPACE } from "../../components/TabBar";
import { IconMoodEmpty, IconSearchOff } from "../../components/icons";
import { AppHeader } from "../../components/menu/AppHeader";
import { FoodCard } from "../../components/menu/FoodCard";
import { MENU_BAR_HEIGHT, MenuCategoryBar } from "../../components/menu/MenuCategoryBar";
import { PosterBanner } from "../../components/menu/PosterBanner";
import { ActiveOrderCard } from "../../components/orders/ActiveOrderCard";
import { useCart } from "../../hooks/useCart";
import { dishCount, menuSections, type MenuSection } from "../../lib/menuSections";
import { useMenuSearch } from "../../lib/menuSearch";
import { colors } from "../../theme/colors";

/** Long enough that typing does not send a request per letter. */
const SEARCH_DEBOUNCE_MS = 300;

const GUTTER = 16;
const GAP = 12;

/** Room left between the sticky bar and a section's heading. */
const SECTION_GAP = 6;

/**
 * Home (redesigned 2026-11-26, with the web): posters, then every dish in
 * sections, BES Seller first and then each category in the order it was
 * added, under a category bar that sticks below the header and follows the
 * section being read. A search shows one list of results instead.
 */
export default function Home() {
  const { width } = useWindowDimensions();
  const search = useMenuSearch();
  const [debounced, setDebounced] = useState(search.trim());
  const { addItem, addingFoodId } = useCart();
  const queryClient = useQueryClient();

  const scroller = useRef<ScrollViewInstance>(null);
  const menuTop = useRef(0);
  const sectionTops = useRef<Record<string, number>>({});
  // A tab's section, held as active while the menu scrolls there, until the
  // customer drags again.
  const picked = useRef<string | null>(null);
  const [active, setActive] = useState<string | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(search.trim()), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [search]);

  const { data, isLoading, isError, refetch, isRefetching } = useQuery({
    // The search is in the key, so clearing it serves the whole menu from cache.
    queryKey: [FOODS_KEY, "menu", debounced],
    queryFn: ({ signal }) => fetchFoods({ category: ALL_CATEGORY, search: debounced, signal }),
    staleTime: 60 * 1000,
  });

  const foods = useMemo(() => data?.data ?? [], [data]);

  // Sections while browsing; a search is one list across the whole menu.
  const browsing = debounced === "";
  const sections = useMemo(() => (browsing ? menuSections(foods) : []), [foods, browsing]);
  const showBar = browsing && !isLoading && !isError && sections.length > 0;
  const current = active && sections.some((section) => section.key === active) ? active : (sections[0]?.key ?? null);

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
    void queryClient.invalidateQueries({ queryKey: ["store", "status"] });
  };

  /** The section whose heading has passed under the bar; the last one at the very bottom. */
  const onScroll = (event: NativeSyntheticEvent<NativeScrollEvent>) => {
    if (!showBar || picked.current) return;

    const { contentOffset, layoutMeasurement, contentSize } = event.nativeEvent;
    const line = contentOffset.y + MENU_BAR_HEIGHT + SECTION_GAP + 12;
    let reading = sections[0]?.key ?? null;

    for (const section of sections) {
      const top = sectionTops.current[section.key];

      if (top !== undefined && menuTop.current + top <= line) reading = section.key;
    }

    if (contentOffset.y > 0 && contentOffset.y + layoutMeasurement.height >= contentSize.height - 2) {
      reading = sections[sections.length - 1]?.key ?? reading;
    }

    if (reading !== active) setActive(reading);
  };

  const pick = (key: string) => {
    const top = sectionTops.current[key];

    if (top === undefined) return;

    picked.current = key;
    setActive(key);
    scroller.current?.scrollTo({ y: Math.max(0, menuTop.current + top - MENU_BAR_HEIGHT - SECTION_GAP), animated: true });
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

  const heading = (section: MenuSection) =>
    section.featured ? (
      <View style={styles.featured}>
        <AppText family="display" weight="bold" size={10.5} color={colors.orange} style={styles.eyebrow}>
          FROM THE MENU
        </AppText>
        <AppText family="display" weight="extrabold" size={21} color={colors.authInk}>
          {section.label}
        </AppText>
        <AppText family="display" size={12.5} color={colors.ink500}>
          From crispy wings to comforting classics, find your next BES favorite.
        </AppText>
      </View>
    ) : (
      <View style={styles.heading}>
        <AppText family="display" weight="extrabold" size={17} color={colors.authInk} style={styles.headingTitle} numberOfLines={1}>
          {section.label}
        </AppText>
        <AppText family="display" size={11} color={colors.ink300}>
          {dishCount(section.foods.length)}
        </AppText>
      </View>
    );

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right"]}>
      <AppHeader />

      <ScrollView
        ref={scroller}
        contentContainerStyle={styles.content}
        keyboardShouldPersistTaps="handled"
        keyboardDismissMode="on-drag"
        // The bar is the third child: it sticks under the header once it reaches it.
        stickyHeaderIndices={showBar ? [2] : undefined}
        onScroll={onScroll}
        scrollEventThrottle={32}
        onScrollBeginDrag={() => {
          picked.current = null;
        }}
        refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={onRefresh} colors={[colors.orange]} />}
      >
        <PosterBanner width={width - GUTTER * 2} />

        <ActiveOrderCard />

        <View style={showBar ? styles.barSpace : undefined}>
          {showBar && <MenuCategoryBar sections={sections} active={current} onPick={pick} />}
        </View>

        <View
          onLayout={(event) => {
            menuTop.current = event.nativeEvent.layout.y;
          }}
        >
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
                {debounced ? `Nothing matches “${debounced}”` : "Nothing on the menu yet"}
              </AppText>
              <AppText family="display" size={13} color={colors.ink300} style={styles.center}>
                {debounced ? "Try a different word, or clear the search to see the whole menu." : "Dishes added by the store appear here."}
              </AppText>
            </View>
          )}

          {!isLoading &&
            !isError &&
            browsing &&
            sections.map((section) => (
              <View
                key={section.key}
                testID={`menu-section-${section.key}`}
                onLayout={(event) => {
                  sectionTops.current[section.key] = event.nativeEvent.layout.y;
                }}
              >
                {heading(section)}
                {grid(section.foods)}
              </View>
            ))}

          {!isLoading && !isError && !browsing && foods.length > 0 && (
            <View>
              <View style={styles.heading}>
                <AppText family="display" weight="extrabold" size={17} color={colors.authInk} style={styles.headingTitle} numberOfLines={1}>
                  Results for “{debounced}”
                </AppText>
                <AppText family="display" size={11} color={colors.ink300}>
                  {dishCount(foods.length)}
                </AppText>
              </View>
              {grid(foods)}
            </View>
          )}
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  content: { paddingTop: 16, paddingBottom: TAB_BAR_SPACE + 16 },
  barSpace: { marginTop: 16 },
  featured: {
    marginHorizontal: GUTTER,
    marginTop: 13,
    marginBottom: 12,
    paddingBottom: 12,
    gap: 3,
    borderBottomWidth: 1,
    borderBottomColor: "#f0e9df",
  },
  eyebrow: { letterSpacing: 1.4 },
  heading: {
    flexDirection: "row",
    alignItems: "baseline",
    justifyContent: "space-between",
    gap: 12,
    paddingHorizontal: GUTTER,
    marginTop: 18,
    marginBottom: 10,
  },
  headingTitle: { flexShrink: 1 },
  grid: { flexDirection: "row", flexWrap: "wrap", gap: GAP, paddingHorizontal: GUTTER, marginBottom: 8 },
  skeleton: { height: 236, borderRadius: 14, backgroundColor: "#f0e9df", marginTop: 16 },
  message: { alignItems: "center", gap: 6, marginTop: 28, marginHorizontal: GUTTER, paddingVertical: 24 },
  error: { borderRadius: 14, borderWidth: 1, borderColor: "#ffd7d5", backgroundColor: colors.dangerSurface },
  retry: { marginTop: 8, paddingHorizontal: 18, paddingVertical: 8, borderRadius: 10, backgroundColor: colors.orange },
  center: { textAlign: "center" },
});
