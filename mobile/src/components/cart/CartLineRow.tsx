import { memo } from "react";
import { Pressable, StyleSheet, View } from "react-native";

import type { CartLine } from "../../api/cart";
import { formatPeso } from "../../lib/money";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { IconCheck, IconMinus, IconPlus, IconTrash } from "../icons";
import { DishImage } from "../menu/DishImage";

/**
 * One line of the cart (Cart design of 2026-11-17, behaviour from the web's
 * CartPanel): tick it in or out of checkout, change the quantity (the minus
 * becomes a bin at 1). The plus stops at the stock left, as on the web; the
 * API clamps too.
 *
 * A line that sold out (or was switched off, or deleted) is greyed and
 * unticked with a SOLD OUT tag on its photo, like Shopee's, and offers only
 * Delete (decided 2026-11-26).
 */
export const CartLineRow = memo(function CartLineRow({
  line,
  advance = false,
  selected,
  busy,
  showNoAddons,
  onToggle,
  onQuantity,
  onRemove,
}: {
  line: CartLine;
  /** An advance-order line: today's stock does not apply (the API's 9999 still does). */
  advance?: boolean;
  selected: boolean;
  busy: boolean;
  /** "No add-ons" when the same dish is in the cart twice, to tell the lines apart. */
  showNoAddons: boolean;
  onToggle: (id: number) => void;
  onQuantity: (id: number, quantity: number) => void;
  onRemove: (id: number) => void;
}) {
  const orderable = line.is_orderable;
  const soldOut = line.stock_status === "out_of_stock";
  const atStock = advance
    ? line.quantity >= 9999
    : line.stock_quantity !== null && line.quantity >= line.stock_quantity;
  const canAdd = !busy && orderable && !atStock;

  return (
    <View
      style={[styles.row, !orderable && styles.out, busy && styles.busy]}
      testID={`cart-line-${line.id}`}
    >
      <Pressable
        accessibilityRole="checkbox"
        accessibilityState={{ checked: selected, disabled: !orderable }}
        accessibilityLabel={
          orderable
            ? `Include ${line.food_name} in checkout`
            : `${line.food_name} is sold out`
        }
        disabled={!orderable}
        onPress={() => onToggle(line.id)}
        hitSlop={10}
        style={[
          styles.box,
          selected && styles.boxChecked,
          !orderable && styles.boxOut,
        ]}
      >
        {selected && (
          <IconCheck size={13} color={colors.white} strokeWidth={3} />
        )}
      </Pressable>

      <View style={styles.thumbWrap}>
        <DishImage
          url={line.thumbnail}
          width={44}
          dimmed={!orderable}
          iconSize={15}
          style={styles.thumb}
        />
        {!orderable && (
          <View style={styles.tag}>
            <AppText
              family="display"
              weight="bold"
              size={7}
              color={colors.white}
              style={styles.tagText}
            >
              {soldOut ? "SOLD OUT" : "UNAVAILABLE"}
            </AppText>
          </View>
        )}
      </View>

      <View style={styles.text}>
        <AppText
          family="display"
          weight="medium"
          size={13}
          color={orderable ? colors.authInk : colors.ink300}
          numberOfLines={1}
        >
          {line.food_name}
        </AppText>

        {line.addons.length > 0 ? (
          <AppText
            family="display"
            size={10.5}
            color={colors.authMuted}
            numberOfLines={1}
          >
            + {line.addons.map((addon) => addon.addon_name).join(", ")}
          </AppText>
        ) : showNoAddons ? (
          <AppText
            family="display"
            size={10.5}
            color={colors.authHint}
            numberOfLines={1}
            style={styles.italic}
          >
            No add-ons
          </AppText>
        ) : null}

        <AppText
          family="display"
          weight="bold"
          size={13.5}
          color={orderable ? colors.orange : "#c0bab2"}
        >
          {formatPeso(line.subtotal)}
        </AppText>

        {!orderable ? (
          <AppText
            family="display"
            weight="semibold"
            size={10.5}
            color={colors.danger}
          >
            {soldOut
              ? "Sold out. It can't be checked out."
              : "Not available right now."}
          </AppText>
        ) : line.unavailable_addons && line.unavailable_addons.length > 0 ? (
          // Switched off since it went in the cart (decided 2026-11-24).
          <AppText
            family="display"
            weight="semibold"
            size={10.5}
            color={colors.danger}
            numberOfLines={2}
          >
            {line.unavailable_addons.join(", ")} not available now
          </AppText>
        ) : null}
      </View>

      {!orderable ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={`Delete ${line.food_name}`}
          disabled={busy}
          onPress={() => onRemove(line.id)}
          hitSlop={6}
          style={styles.delete}
          testID={`delete-${line.id}`}
        >
          <IconTrash size={13} color={colors.danger} strokeWidth={2.2} />
          <AppText
            family="display"
            weight="semibold"
            size={11.5}
            color={colors.danger}
          >
            Delete
          </AppText>
        </Pressable>
      ) : (
        <View style={styles.stepper}>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={
              line.quantity <= 1
                ? `Remove ${line.food_name}`
                : `Decrease ${line.food_name}`
            }
            disabled={busy}
            onPress={() =>
              line.quantity <= 1
                ? onRemove(line.id)
                : onQuantity(line.id, line.quantity - 1)
            }
            hitSlop={4}
            style={[styles.round, styles.minus]}
            testID={`decrease-${line.id}`}
          >
            {line.quantity <= 1 ? (
              // The trash glyph reads small beside + and -, so it is drawn a size up.
              <IconTrash size={15} color={colors.orange} strokeWidth={2.2} />
            ) : (
              <IconMinus size={13} color={colors.orange} strokeWidth={3} />
            )}
          </Pressable>

          <View style={styles.count}>
            <AppText
              family="display"
              weight="semibold"
              size={13}
              color={colors.authInk}
              testID={`quantity-${line.id}`}
            >
              {line.quantity}
            </AppText>
          </View>

          <Pressable
            accessibilityRole="button"
            accessibilityLabel={`Increase ${line.food_name}`}
            accessibilityState={{ disabled: !canAdd }}
            disabled={!canAdd}
            onPress={() => onQuantity(line.id, line.quantity + 1)}
            hitSlop={4}
            style={[styles.round, styles.plus, !canAdd && styles.plusDisabled]}
            testID={`increase-${line.id}`}
          >
            <IconPlus size={13} color={colors.white} strokeWidth={3} />
          </Pressable>
        </View>
      )}
    </View>
  );
});

const styles = StyleSheet.create({
  row: {
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
    paddingVertical: 12,
    paddingHorizontal: 14,
  },
  out: { backgroundColor: "#f6f4f1" },
  busy: { opacity: 0.55 },
  box: {
    width: 18,
    height: 18,
    borderRadius: 4,
    borderWidth: 1.5,
    borderColor: "#c8ccd2",
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
  },
  boxChecked: { backgroundColor: colors.orange, borderColor: colors.orange },
  boxOut: { backgroundColor: "#eeebe7", borderColor: "#dcd7d0" },
  thumbWrap: { width: 44, height: 44, borderRadius: 9, overflow: "hidden" },
  thumb: { width: 44, height: 44, borderRadius: 9 },
  tag: {
    position: "absolute",
    left: 0,
    right: 0,
    bottom: 0,
    paddingVertical: 2,
    backgroundColor: "rgba(111,107,104,0.88)",
  },
  tagText: { textAlign: "center", letterSpacing: 0.3, lineHeight: 9 },
  delete: {
    flexDirection: "row",
    alignItems: "center",
    gap: 4,
    paddingHorizontal: 10,
    paddingVertical: 5,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: "#f3d6d4",
    backgroundColor: colors.white,
  },
  text: { flex: 1, minWidth: 0 },
  italic: { fontStyle: "italic" },
  stepper: { flexDirection: "row", alignItems: "center", gap: 6 },
  round: {
    width: 26,
    height: 26,
    borderRadius: 13,
    alignItems: "center",
    justifyContent: "center",
  },
  minus: { backgroundColor: colors.brand100 },
  plus: { backgroundColor: colors.orange },
  plusDisabled: { backgroundColor: colors.orangeSoft },
  count: {
    minWidth: 34,
    height: 26,
    paddingHorizontal: 6,
    borderRadius: 7,
    borderWidth: 1,
    borderColor: "#f3e3d3",
    backgroundColor: "#fffaf5",
    alignItems: "center",
    justifyContent: "center",
  },
});

export default CartLineRow;
