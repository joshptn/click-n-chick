import { memo } from "react";
import { Pressable, StyleSheet, View } from "react-native";

import { stockColor, stockLabel, type Food } from "../../api/menu";
import { formatPeso } from "../../lib/money";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { IconPlus } from "../icons";
import { DishImage } from "./DishImage";

/**
 * A dish in the menu grid (Home design of 2026-11-17, behaviour from the
 * web's FoodCard): the photo opens the dish, "Add" adds one. A dish that
 * cannot be ordered is faded with the reason under its name.
 */
export const FoodCard = memo(function FoodCard({
  food,
  width,
  adding,
  advance = false,
  onOpen,
  onAdd,
}: {
  food: Food;
  width: number;
  adding: boolean;
  /** On the advance menu: today's stock does not apply, as on the web. */
  advance?: boolean;
  onOpen: (food: Food) => void;
  onAdd: (food: Food) => void;
}) {
  const orderable = advance || food.is_orderable;
  const label = advance ? null : stockLabel(food);
  // The design's grey line under the name, from the dish's description.
  const summary = food.description?.split("\n")[0]?.trim();

  return (
    <View style={[styles.card, { width }]} testID={`food-${food.id}`}>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={`View ${food.food_name}`}
        onPress={() => onOpen(food)}
      >
        <DishImage url={food.thumbnail} width={width} dimmed={!orderable} style={styles.photo} />

        {food.is_best_seller && (
          <View style={styles.ribbon}>
            <AppText family="display" weight="extrabold" size={8.5} color={colors.white} style={styles.ribbonText}>
              BEST SELLER
            </AppText>
          </View>
        )}
      </Pressable>

      <View style={styles.body}>
        <AppText
          family="display"
          weight="semibold"
          size={13}
          color={orderable ? colors.authInk : colors.ink300}
          numberOfLines={2}
        >
          {food.food_name}
        </AppText>

        {summary ? (
          <AppText family="display" size={10.5} color={colors.authHint} numberOfLines={1}>
            {summary}
          </AppText>
        ) : null}

        {label ? (
          <AppText family="display" weight="medium" size={10.5} color={stockColor(food)} numberOfLines={1}>
            {label}
          </AppText>
        ) : null}

        <AppText
          family="display"
          weight="bold"
          size={15}
          color={orderable ? colors.orange : "#c0bab2"}
          style={styles.price}
        >
          {formatPeso(food.price)}
        </AppText>

        <Pressable
          accessibilityRole="button"
          accessibilityLabel={orderable ? `Add ${food.food_name} to your order` : `${food.food_name} is unavailable`}
          accessibilityState={{ disabled: !orderable || adding }}
          disabled={!orderable || adding}
          onPress={() => onAdd(food)}
          style={({ pressed }) => [styles.add, (!orderable || adding) && styles.addDisabled, pressed && styles.pressed]}
          testID={`add-${food.id}`}
        >
          <IconPlus size={13} color={colors.white} strokeWidth={2.8} />
          <AppText family="display" weight="semibold" size={12} color={colors.white}>
            {adding ? "Adding..." : "Add"}
          </AppText>
        </Pressable>
      </View>
    </View>
  );
});

const styles = StyleSheet.create({
  card: {
    overflow: "hidden",
    borderRadius: 14,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: "#f0e9df",
    elevation: 1,
    shadowColor: "#412111",
    shadowOpacity: 0.06,
    shadowRadius: 6,
    shadowOffset: { width: 0, height: 2 },
  },
  photo: { aspectRatio: 1.42 },
  ribbon: {
    position: "absolute",
    top: 8,
    left: 8,
    paddingHorizontal: 7,
    paddingVertical: 3,
    borderRadius: 4,
    backgroundColor: colors.orange,
  },
  ribbonText: { letterSpacing: 0.5, lineHeight: 12 },
  body: { flex: 1, paddingHorizontal: 10, paddingTop: 9, paddingBottom: 10, gap: 2 },
  price: { marginTop: "auto", paddingTop: 6, paddingBottom: 6 },
  add: {
    height: 34,
    borderRadius: 8,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 4,
    backgroundColor: colors.orange,
  },
  addDisabled: { backgroundColor: colors.orangeSoft },
  pressed: { opacity: 0.85 },
});

export default FoodCard;
