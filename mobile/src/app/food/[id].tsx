import { useMemo, useState } from "react";
import { Pressable, ScrollView, StyleSheet, View, useWindowDimensions } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useSafeAreaInsets } from "react-native-safe-area-context";

import { FOODS_KEY, fetchFood, stockColor, stockLabel, type Food } from "../../api/menu";
import { AppText } from "../../components/AppText";
import { IconArrowLeft, IconClock, IconMinus, IconPlus, IconShoppingBagPlus, IconTrash } from "../../components/icons";
import { DishImage } from "../../components/menu/DishImage";
import { useCart } from "../../hooks/useCart";
import { formatPeso } from "../../lib/money";
import { colors } from "../../theme/colors";

/** Without a stock count, the most the API takes in one line. */
const MAX_QUANTITY = 99;

/** The menu listing's copy of a dish, which already carries its add-ons. */
function fromListing(queryClient: ReturnType<typeof useQueryClient>, id: number): Food | undefined {
  for (const [, data] of queryClient.getQueriesData<{ data: Food[] }>({ queryKey: [FOODS_KEY] })) {
    const found = data?.data?.find((food) => food.id === id);
    if (found) return found;
  }

  return undefined;
}

/**
 * A dish (the web's FoodDetailModal as a screen): photo, price, description,
 * stock, optional add-ons, and the quantity to add, capped at the stock left.
 */
export default function FoodDetail() {
  const params = useLocalSearchParams<{ id: string }>();
  const id = Number(params.id);
  const { width } = useWindowDimensions();
  const insets = useSafeAreaInsets();
  const queryClient = useQueryClient();
  const { addItem, addingFoodId } = useCart();

  // The listing is the copy kept current, so it is preferred; only a dish
  // opened without it (a link) is asked for on its own.
  const listed = useMemo(() => fromListing(queryClient, id), [queryClient, id]);

  const { data, isError, refetch } = useQuery({
    queryKey: ["food", id],
    queryFn: ({ signal }) => fetchFood(id, signal),
    enabled: !listed && Number.isFinite(id),
    staleTime: 60 * 1000,
  });

  const food = listed ?? data?.data;

  const [quantity, setQuantity] = useState(1);
  const [addons, setAddons] = useState<number[]>([]);

  const groups = useMemo(() => food?.addon_groups ?? [], [food]);
  const prices = useMemo(
    () => new Map(groups.flatMap((group) => group.addons).map((addon) => [addon.id, Number(addon.addon_price)])),
    [groups]
  );

  const back = () => (router.canGoBack() ? router.back() : router.replace("/home"));

  if (!food) {
    return (
      <View style={[styles.missing, { paddingTop: insets.top + 60 }]}>
        <AppText family="display" weight="semibold" size={15} color={isError ? colors.danger : colors.ink500}>
          {isError ? "We couldn't load this dish." : "Loading..."}
        </AppText>
        {isError && (
          <Pressable accessibilityRole="button" onPress={() => refetch()} style={styles.retry}>
            <AppText family="display" weight="semibold" size={13} color={colors.white}>
              Try again
            </AppText>
          </Pressable>
        )}
        <Pressable accessibilityRole="button" onPress={back} style={styles.textButton}>
          <AppText family="display" weight="semibold" size={13} color={colors.orange}>
            Back to Menu
          </AppText>
        </Pressable>
      </View>
    );
  }

  const orderable = food.is_orderable;
  const label = stockLabel(food);
  const maxQuantity = Math.max(1, food.stock_quantity ?? MAX_QUANTITY);
  const addonsTotal = addons.reduce((sum, addonId) => sum + (prices.get(addonId) ?? 0), 0);
  const lineTotal = (Number(food.price) + addonsTotal) * quantity;
  const adding = addingFoodId === food.id;

  const toggle = (addonId: number) =>
    setAddons((prev) => (prev.includes(addonId) ? prev.filter((x) => x !== addonId) : [...prev, addonId]));

  const add = () => {
    addItem({ foodId: food.id, quantity, addonIds: addons })
      .then(back)
      .catch(() => {});
  };

  const tileWidth = Math.floor((width - 40 - 10) / 2);

  return (
    <View style={styles.screen}>
      <ScrollView contentContainerStyle={{ paddingBottom: 120 + insets.bottom }}>
        <View>
          <DishImage
            url={food.thumbnail}
            width={width}
            dimmed={!orderable}
            iconSize={30}
            priority="high"
            style={styles.photo}
          />

          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Back to Menu"
            onPress={back}
            style={[styles.backButton, { top: insets.top + 10 }]}
            testID="food-back"
          >
            <IconArrowLeft size={20} color={colors.authInk} strokeWidth={2.2} />
          </Pressable>

          <View style={styles.photoTags}>
            {food.is_best_seller && (
              <View style={styles.bestSeller}>
                <AppText family="display" weight="extrabold" size={9.5} color={colors.white} style={styles.tagText}>
                  BEST SELLER
                </AppText>
              </View>
            )}
            {food.prep_time ? (
              <View style={styles.prep}>
                <IconClock size={13} color={colors.white} strokeWidth={2.2} />
                <AppText family="display" weight="semibold" size={11.5} color={colors.white} style={styles.tagText}>
                  {food.prep_time} mins
                </AppText>
              </View>
            ) : null}
          </View>
        </View>

        <View style={styles.body}>
          <AppText family="display" weight="bold" size={24} color={colors.authInk}>
            {food.food_name}
          </AppText>

          <View style={styles.priceRow}>
            <AppText family="display" weight="extrabold" size={24} color={colors.orange}>
              {formatPeso(food.price)}
            </AppText>
            <AppText family="display" size={13} color={colors.ink300}>
              per serving
            </AppText>
          </View>

          {label ? (
            <View style={styles.stockRow}>
              <View style={[styles.stockDot, { backgroundColor: stockColor(food) }]} />
              <AppText family="display" weight="semibold" size={12.5} color={stockColor(food)}>
                {label}
              </AppText>
            </View>
          ) : null}

          <View style={styles.rule} />

          <AppText family="display" weight="semibold" size={14} color={colors.authInk}>
            Description
          </AppText>
          <AppText family="display" size={13.5} color={colors.ink500} style={styles.description}>
            {food.description || "No description yet."}
          </AppText>

          {groups.length > 0 && (
            <View style={styles.addons}>
              <View style={styles.addonsHead}>
                <AppText family="display" weight="semibold" size={15} color={colors.authInk}>
                  Add-ons
                </AppText>
                <View style={styles.optional}>
                  <AppText family="display" weight="semibold" size={10.5} color={colors.ink300}>
                    optional
                  </AppText>
                </View>
              </View>

              {groups.map((group) => (
                <View key={group.group} style={styles.group}>
                  <AppText family="display" weight="semibold" size={13.5} color={colors.authInk}>
                    {group.group}
                  </AppText>
                  <View style={styles.tiles}>
                    {group.addons.map((addon) => {
                      const checked = addons.includes(addon.id);

                      return (
                        <Pressable
                          key={addon.id}
                          accessibilityRole="checkbox"
                          accessibilityState={{ checked }}
                          onPress={() => toggle(addon.id)}
                          style={[styles.tile, { width: tileWidth }, checked && styles.tileChecked]}
                          testID={`addon-${addon.id}`}
                        >
                          <AppText family="display" weight="semibold" size={12.5} color={colors.authInk} numberOfLines={2}>
                            {addon.addon_name}
                          </AppText>
                          <AppText family="display" weight="bold" size={12} color={colors.orange}>
                            +{formatPeso(addon.addon_price)}
                          </AppText>
                        </Pressable>
                      );
                    })}
                  </View>
                </View>
              ))}
            </View>
          )}
        </View>
      </ScrollView>

      <View style={[styles.footer, { paddingBottom: 14 + insets.bottom }]}>
        <View style={styles.stepper}>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Decrease quantity"
            disabled={!orderable || quantity <= 1}
            onPress={() => setQuantity((q) => Math.max(1, q - 1))}
            style={[styles.round, styles.minus]}
            hitSlop={4}
          >
            {quantity <= 1 ? (
              <IconTrash size={15} color={!orderable || quantity <= 1 ? "#c0bab2" : colors.authInk} />
            ) : (
              <IconMinus size={15} color={colors.authInk} strokeWidth={2.6} />
            )}
          </Pressable>

          <AppText family="display" weight="bold" size={16} color={colors.authInk} style={styles.quantity} testID="food-quantity">
            {quantity}
          </AppText>

          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Increase quantity"
            disabled={!orderable || quantity >= maxQuantity}
            onPress={() => setQuantity((q) => Math.min(maxQuantity, q + 1))}
            style={[styles.round, styles.plus, (!orderable || quantity >= maxQuantity) && styles.plusDisabled]}
            hitSlop={4}
            testID="food-increase"
          >
            <IconPlus size={15} color={colors.white} strokeWidth={2.6} />
          </Pressable>
        </View>

        <Pressable
          accessibilityRole="button"
          accessibilityState={{ disabled: !orderable || adding }}
          disabled={!orderable || adding}
          onPress={add}
          style={({ pressed }) => [styles.addButton, (!orderable || adding) && styles.addDisabled, pressed && styles.pressed]}
          testID="food-add"
        >
          <IconShoppingBagPlus size={18} color={colors.white} strokeWidth={2} />
          <AppText family="display" weight="semibold" size={14} color={colors.white} numberOfLines={1}>
            {orderable ? `${adding ? "Adding" : "Add to Cart"} — ${formatPeso(lineTotal)}` : "Unavailable"}
          </AppText>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.white },
  photo: { width: "100%", aspectRatio: 1.25 },
  backButton: {
    position: "absolute",
    left: 16,
    width: 40,
    height: 40,
    borderRadius: 20,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "rgba(255,255,255,0.92)",
    elevation: 3,
  },
  photoTags: { position: "absolute", left: 16, bottom: 14, flexDirection: "row", gap: 8 },
  bestSeller: { paddingHorizontal: 12, paddingVertical: 5, borderRadius: 999, backgroundColor: colors.orange },
  prep: {
    flexDirection: "row",
    alignItems: "center",
    gap: 5,
    paddingHorizontal: 11,
    paddingVertical: 5,
    borderRadius: 999,
    backgroundColor: "rgba(43,21,10,0.78)",
  },
  tagText: { lineHeight: 15 },
  body: { padding: 20, gap: 4 },
  priceRow: { flexDirection: "row", alignItems: "baseline", gap: 8, marginTop: 4 },
  stockRow: { flexDirection: "row", alignItems: "center", gap: 7, marginTop: 6 },
  stockDot: { width: 7, height: 7, borderRadius: 4 },
  rule: { height: 1, backgroundColor: "#f0e9df", marginVertical: 16 },
  description: { marginTop: 4 },
  addons: { marginTop: 20, gap: 4 },
  addonsHead: { flexDirection: "row", alignItems: "center", gap: 10 },
  optional: { paddingHorizontal: 10, paddingVertical: 3, borderRadius: 999, backgroundColor: "#f4f1ec" },
  group: { marginTop: 12, gap: 8 },
  tiles: { flexDirection: "row", flexWrap: "wrap", gap: 10 },
  tile: {
    paddingHorizontal: 12,
    paddingVertical: 10,
    borderRadius: 12,
    borderWidth: 2,
    borderColor: "transparent",
    backgroundColor: "#faf7f3",
  },
  tileChecked: { borderColor: colors.orange, backgroundColor: "#fff6ee" },
  footer: {
    position: "absolute",
    left: 0,
    right: 0,
    bottom: 0,
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
    paddingTop: 14,
    paddingHorizontal: 20,
    borderTopWidth: 1,
    borderTopColor: "#f0e9df",
    backgroundColor: colors.white,
  },
  stepper: { flexDirection: "row", alignItems: "center", gap: 8 },
  round: { width: 36, height: 36, borderRadius: 18, alignItems: "center", justifyContent: "center" },
  minus: { backgroundColor: "#f4f1ec" },
  plus: { backgroundColor: colors.orange },
  plusDisabled: { backgroundColor: colors.orangeSoft },
  quantity: { minWidth: 24, textAlign: "center" },
  addButton: {
    flex: 1,
    height: 50,
    borderRadius: 12,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
    paddingHorizontal: 12,
    backgroundColor: colors.orange,
  },
  addDisabled: { backgroundColor: colors.orangeSoft },
  pressed: { opacity: 0.9 },
  missing: { flex: 1, alignItems: "center", gap: 12, backgroundColor: colors.white },
  retry: { paddingHorizontal: 18, paddingVertical: 8, borderRadius: 10, backgroundColor: colors.orange },
  textButton: { padding: 8 },
});
