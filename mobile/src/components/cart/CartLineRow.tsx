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
 * becomes a bin at 1), and see when it sold out. The plus stops at the stock
 * left, as on the web; the API clamps too.
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
  /** An advance-order line: today's stock does not apply (the API's 99 still does). */
  advance?: boolean;
  selected: boolean;
  busy: boolean;
  /** "No add-ons" when the same dish is in the cart twice, to tell the lines apart. */
  showNoAddons: boolean;
  onToggle: (id: number) => void;
  onQuantity: (id: number, quantity: number) => void;
  onRemove: (id: number) => void;
}) {
  const orderable = advance || line.is_orderable;
  const atStock = advance ? line.quantity >= 99 : line.stock_quantity !== null && line.quantity >= line.stock_quantity;
  const canAdd = !busy && orderable && !atStock;

  return (
    <View style={[styles.row, busy && styles.busy]} testID={`cart-line-${line.id}`}>
      <Pressable
        accessibilityRole="checkbox"
        accessibilityState={{ checked: selected }}
        accessibilityLabel={`Include ${line.food_name} in checkout`}
        onPress={() => onToggle(line.id)}
        hitSlop={10}
        style={[styles.box, selected && styles.boxChecked]}
      >
        {selected && <IconCheck size={13} color={colors.white} strokeWidth={3} />}
      </Pressable>

      <DishImage url={line.thumbnail} width={44} dimmed={!orderable} iconSize={15} style={styles.thumb} />

      <View style={styles.text}>
        <AppText family="display" weight="medium" size={13} color={colors.authInk} numberOfLines={1}>
          {line.food_name}
        </AppText>

        {line.addons.length > 0 ? (
          <AppText family="display" size={10.5} color={colors.authMuted} numberOfLines={1}>
            + {line.addons.map((addon) => addon.addon_name).join(", ")}
          </AppText>
        ) : showNoAddons ? (
          <AppText family="display" size={10.5} color={colors.authHint} numberOfLines={1} style={styles.italic}>
            No add-ons
          </AppText>
        ) : null}

        <AppText family="display" weight="bold" size={13.5} color={colors.orange}>
          {formatPeso(line.subtotal)}
        </AppText>

        {!orderable && (
          <AppText family="display" weight="semibold" size={10.5} color={colors.danger}>
            Sold out
          </AppText>
        )}
      </View>

      <View style={styles.stepper}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={line.quantity <= 1 ? `Remove ${line.food_name}` : `Decrease ${line.food_name}`}
          disabled={busy}
          onPress={() => (line.quantity <= 1 ? onRemove(line.id) : onQuantity(line.id, line.quantity - 1))}
          hitSlop={4}
          style={[styles.round, styles.minus]}
          testID={`decrease-${line.id}`}
        >
          {line.quantity <= 1 ? (
            <IconTrash size={13} color={colors.orange} strokeWidth={2.2} />
          ) : (
            <IconMinus size={13} color={colors.orange} strokeWidth={3} />
          )}
        </Pressable>

        <View style={styles.count}>
          <AppText family="display" weight="semibold" size={13} color={colors.authInk} testID={`quantity-${line.id}`}>
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
    </View>
  );
});

const styles = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", gap: 10, paddingVertical: 12, paddingHorizontal: 14 },
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
  thumb: { width: 44, height: 44, borderRadius: 9 },
  text: { flex: 1, minWidth: 0 },
  italic: { fontStyle: "italic" },
  stepper: { flexDirection: "row", alignItems: "center", gap: 6 },
  round: { width: 26, height: 26, borderRadius: 13, alignItems: "center", justifyContent: "center" },
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
