import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  View,
  useWindowDimensions,
  type NativeScrollEvent,
  type NativeSyntheticEvent,
  type ScrollViewInstance,
} from "react-native";
import { router } from "expo-router";
import { useQuery } from "@tanstack/react-query";
import { SafeAreaView, useSafeAreaInsets } from "react-native-safe-area-context";

import { ALL_CATEGORY, FOODS_KEY, fetchFoods, type Food } from "../../api/menu";
import { AppText } from "../../components/AppText";
import { ScreenHeader } from "../../components/account/ScreenHeader";
import { TextField } from "../../components/auth/TextField";
import { DiscountBanner } from "../../components/advance/DiscountBanner";
import { IconMoodEmpty, IconSearchOff, IconShoppingCart } from "../../components/icons";
import { FoodCard } from "../../components/menu/FoodCard";
import { MENU_BAR_HEIGHT, MenuCategoryBar } from "../../components/menu/MenuCategoryBar";
import { useCart } from "../../hooks/useCart";
import { dishCount, menuSections, type MenuSection } from "../../lib/menuSections";
import { colors } from "../../theme/colors";

const SEARCH_DEBOUNCE_MS = 300;
const GUTTER = 16;
const GAP = 12;

/** Room left between the sticky bar and a section's heading. */
const SECTION_GAP = 6;

/**
 * The advance menu (phase 9; the web's AdvanceOrder page): the same dishes,
 * added to the advance cart instead of today's, with today's stock set aside
 * (the store checks the date when it accepts). The floating cart button at
 * the bottom right opens the advance cart, its own screen in this flow.
 *
 * Redesigned 2026-11-26 to match Home: the Senior/PWD banner to apply from
 * at the top (the "Book the kitchen ahead of time" banner was removed),
 * then every dish in sections (BES Seller, then each category by ID) under a
 * category row that sticks below the header and follows the section being
 * read. A search shows one list of results instead.
 */
export default function AdvanceMenu() {
  const { width } = useWindowDimensions();
  const insets = useSafeAreaInsets();
  const [search, setSearch] = useState("");
  const [debounced, setDebounced] = useState("");
  const cart = useCart("advance");
  const { addItem, addingFoodId } = cart;

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(search.trim()), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [search]);

  const { data, isLoading, isError, refetch, isRefetching } = useQuery({
    // Its own key: Home's list is the same request, kept apart so neither
    // screen's search shows on the other.
    queryKey: [FOODS_KEY, "advance-menu", debounced],
    queryFn: ({ signal }) => fetchFoods({ category: ALL_CATEGORY, search: debounced, signal }),
    staleTime: 60 * 1000,
  });

  const foods = useMemo(() => data?.data ?? [], [data]);

  const scroller = useRef<ScrollViewInstance>(null);
  const menuTop = useRef(0);
  const sectionTops = useRef<Record<string, number>>({});
  // A tab's section, held as active while the menu scrolls there, until the
  // customer drags again.
  const picked = useRef<string | null>(null);
  const [active, setActive] = useState<string | null>(null);

  // Sections while browsing; a search is one list across the whole menu.
  const browsing = debounced === "";
  const sections = useMemo(() => (browsing ? menuSections(foods) : []), [foods, browsing]);
  const showBar = browsing && !isLoading && !isError && sections.length > 0;
  const current = active && sections.some((section) => section.key === active) ? active : (sections[0]?.key ?? null);
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

  const grid = (list: typeof foods) => (
    <View style={styles.grid}>
      {list.map((food) => (
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
  );

  const heading = (section: MenuSection) =>
    section.featured ? (
      <View style={styles.featured}>
        <AppText family="display" weight="bold" size={10.5} color={colors.orange} style={styles.caps}>
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
      <ScreenHeader title="Advance Order" />

      <ScrollView
        ref={scroller}
        contentContainerStyle={[styles.content, { paddingBottom: 110 + insets.bottom }]}
        keyboardShouldPersistTaps="handled"
        keyboardDismissMode="on-drag"
        // The category row is the third child: it sticks under the header once it reaches it.
        stickyHeaderIndices={showBar ? [2] : undefined}
        onScroll={onScroll}
        scrollEventThrottle={32}
        onScrollBeginDrag={() => {
          picked.current = null;
        }}
        refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={() => void refetch()} colors={[colors.orange]} />}
      >
        <DiscountBanner />

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
  caps: { letterSpacing: 1.4 },
  content: { paddingTop: 4 },
  search: { paddingHorizontal: GUTTER, marginTop: 16 },
  barSpace: { marginTop: 16 },
  featured: {
    marginHorizontal: GUTTER,
    marginTop: 18,
    marginBottom: 12,
    paddingBottom: 12,
    gap: 3,
    borderBottomWidth: 1,
    borderBottomColor: "#f0e9df",
  },
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
