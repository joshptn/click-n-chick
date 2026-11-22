import { useMemo } from "react";
import { Pressable, RefreshControl, ScrollView, StyleSheet, View } from "react-native";
import { router } from "expo-router";

import type { CartMode } from "../../api/cart";
import { useCart } from "../../hooks/useCart";
import { formatPeso } from "../../lib/money";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { IconAlertTriangle, IconCalendarEvent, IconCheck, IconShoppingCart, IconTrash } from "../icons";
import { CartLineRow } from "./CartLineRow";

/**
 * A cart's lines, totals and button, with the web CartPanel's behaviour:
 * lines ticked for checkout by default (BR-25), the totals counting only
 * ticked lines, and checkout blocked while a ticked line has sold out.
 *
 * Today's cart lives on the Cart tab and opens checkout (phase 11). The
 * advance cart lives only inside the advance-order screens (decided
 * 2026-11-19, so the two are never confused) and opens scheduling.
 */
export function CartContents({ mode, bottomSpace }: { mode: CartMode; bottomSpace: number }) {
  const advance = mode === "advance";
  const cart = useCart(mode);
  const {
    cart: lines,
    isLoading,
    isError,
    isRefetching,
    refetch,
    isSelected,
    toggleSelected,
    selectAll,
    deselectAll,
    allSelected,
    selectedCount,
    selectedSubtotal,
    selectedItemCount,
    hasUnavailableSelected,
    removeSelected,
    setQuantity,
    removeItem,
    pendingLineId,
    isMutating,
  } = cart;

  const repeated = useMemo(() => {
    const counts = new Map<number, number>();
    lines.forEach((line) => counts.set(line.food_id, (counts.get(line.food_id) ?? 0) + 1));
    return new Set([...counts].filter(([, count]) => count > 1).map(([foodId]) => foodId));
  }, [lines]);

  const empty = !isLoading && lines.length === 0;
  const canCheckout = selectedCount > 0 && !hasUnavailableSelected && !isMutating;

  // Only the ticked lines go on (BR-25), as on the web.
  const checkout = () =>
    router.push({ pathname: advance ? "/advance/schedule" : "/checkout", params: { ids: cart.selectedIds.join(",") } });

  return (
    <>
      {lines.length > 0 && (
        <View style={styles.selectBar}>
          <Pressable
            accessibilityRole="checkbox"
            accessibilityState={{ checked: allSelected }}
            onPress={() => (allSelected ? deselectAll() : selectAll())}
            style={styles.selectAll}
            hitSlop={8}
            testID="select-all"
          >
            <View style={[styles.box, allSelected && styles.boxChecked]}>
              {allSelected && <IconCheck size={13} color={colors.white} strokeWidth={3} />}
            </View>
            <AppText family="display" size={13} color={colors.authLabel}>
              {allSelected ? "Deselect All" : "Select All"}
            </AppText>
          </Pressable>

          <Pressable
            accessibilityRole="button"
            disabled={selectedCount === 0 || isMutating}
            onPress={removeSelected}
            style={[styles.delete, (selectedCount === 0 || isMutating) && styles.faded]}
            hitSlop={8}
            testID="delete-selected"
          >
            <IconTrash size={14} color={colors.danger} strokeWidth={2.2} />
            <AppText family="display" weight="medium" size={13} color={colors.danger}>
              Delete ({selectedCount})
            </AppText>
          </Pressable>
        </View>
      )}

      <ScrollView
        style={styles.body}
        contentContainerStyle={[styles.content, { paddingBottom: bottomSpace }]}
        refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={refetch} colors={[colors.orange]} />}
      >
        {isLoading && (
          <View style={styles.card}>
            {Array.from({ length: 2 }, (_, index) => (
              <View key={index} style={styles.skeleton} />
            ))}
          </View>
        )}

        {isError && !isLoading && lines.length === 0 && (
          <View style={styles.empty}>
            <AppText family="display" weight="semibold" size={14} color={colors.danger}>
              We couldn&apos;t load your cart.
            </AppText>
            <Pressable accessibilityRole="button" onPress={refetch} style={styles.browse}>
              <AppText family="display" weight="semibold" size={13} color={colors.white}>
                Try again
              </AppText>
            </Pressable>
          </View>
        )}

        {empty && !isError && (
          <View style={styles.empty}>
            {advance ? (
              <IconCalendarEvent size={44} color="#d9d3cb" strokeWidth={1.4} />
            ) : (
              <IconShoppingCart size={44} color="#d9d3cb" strokeWidth={1.4} />
            )}
            <AppText family="display" weight="semibold" size={15} color={colors.ink500}>
              {advance ? "Nothing booked ahead yet" : "Your cart is empty"}
            </AppText>
            <AppText family="display" size={13} color={colors.ink300}>
              {advance ? "Add dishes from the advance menu" : "Add items to get started"}
            </AppText>
            <Pressable
              accessibilityRole="button"
              onPress={() => (advance ? (router.canGoBack() ? router.back() : router.navigate("/advance")) : router.navigate("/home"))}
              style={styles.browse}
              testID="cart-browse"
            >
              <AppText family="display" weight="semibold" size={13} color={colors.white}>
                {advance ? "Browse the advance menu" : "Browse the menu"}
              </AppText>
            </Pressable>
          </View>
        )}

        {lines.length > 0 && (
          <>
            {hasUnavailableSelected && (
              <View style={styles.warning}>
                <IconAlertTriangle size={15} color="#8a6206" strokeWidth={2.2} />
                <AppText family="display" size={12.5} color="#8a6206" style={styles.warningText}>
                  Something you selected sold out. Untick or remove it before checking out.
                </AppText>
              </View>
            )}

            <View style={styles.card}>
              {lines.map((line, index) => (
                <View key={line.id} style={index > 0 && styles.divider}>
                  <CartLineRow
                    line={line}
                    advance={advance}
                    selected={isSelected(line.id)}
                    busy={pendingLineId === line.id}
                    showNoAddons={repeated.has(line.food_id)}
                    onToggle={toggleSelected}
                    onQuantity={setQuantity}
                    onRemove={removeItem}
                  />
                </View>
              ))}
            </View>

            <View style={[styles.card, styles.summary]}>
              <View style={styles.summaryRow}>
                <AppText family="display" size={13} color={colors.authMuted}>
                  Subtotal ({selectedItemCount} item{selectedItemCount === 1 ? "" : "s"} selected)
                </AppText>
                <AppText family="display" size={13} color={colors.authLabel}>
                  {formatPeso(selectedSubtotal)}
                </AppText>
              </View>
              <View style={[styles.summaryRow, styles.totalRow]}>
                <AppText family="display" weight="semibold" size={16} color={colors.authInk}>
                  {advance ? "Estimated Total" : "Total"}
                </AppText>
                <AppText family="display" weight="bold" size={21} color={colors.orange} testID="cart-total">
                  {formatPeso(selectedSubtotal)}
                </AppText>
              </View>
            </View>

            <Pressable
              accessibilityRole="button"
              accessibilityState={{ disabled: !canCheckout }}
              disabled={!canCheckout}
              onPress={checkout}
              style={({ pressed }) => [styles.checkout, !canCheckout && styles.checkoutDisabled, pressed && styles.pressed]}
              testID="checkout"
            >
              {advance ? (
                <IconCalendarEvent size={20} color={colors.white} strokeWidth={2} />
              ) : (
                <IconShoppingCart size={20} color={colors.white} strokeWidth={2} />
              )}
              <AppText family="display" weight="semibold" size={15} color={colors.white}>
                {advance ? "Schedule Order" : "Proceed to Checkout"}
              </AppText>
            </Pressable>

            <AppText family="display" size={10.5} color={colors.ink300} style={styles.note}>
              {selectedCount === 0
                ? `Tick at least one item to ${advance ? "schedule" : "check out"}`
                : advance
                  ? "Pickup only · you pay once the store accepts your request"
                  : "🔒 Secure checkout · cancel for a full refund if the store hasn't confirmed within 15 minutes"}
            </AppText>
          </>
        )}
      </ScrollView>
    </>
  );
}

const styles = StyleSheet.create({
  selectBar: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    paddingHorizontal: 20,
    paddingBottom: 12,
  },
  selectAll: { flexDirection: "row", alignItems: "center", gap: 10 },
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
  delete: { flexDirection: "row", alignItems: "center", gap: 5 },
  faded: { opacity: 0.4 },
  body: { flex: 1, backgroundColor: "#f8f5f1" },
  content: { padding: 16, gap: 14 },
  card: {
    borderRadius: 16,
    borderWidth: 1,
    borderColor: "#ece7e0",
    backgroundColor: colors.white,
    overflow: "hidden",
  },
  divider: { borderTopWidth: 1, borderTopColor: "#f1ece6" },
  skeleton: { height: 62, margin: 12, borderRadius: 12, backgroundColor: "#f4f1ec" },
  summary: { paddingHorizontal: 18, paddingVertical: 14 },
  summaryRow: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: 12 },
  totalRow: { marginTop: 10, paddingTop: 12, borderTopWidth: 1, borderTopColor: "#f1ece6" },
  checkout: {
    height: 52,
    borderRadius: 14,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
    backgroundColor: colors.orange,
    elevation: 4,
    shadowColor: colors.orange,
    shadowOpacity: 0.35,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 4 },
  },
  checkoutDisabled: { backgroundColor: colors.orangeSoft, elevation: 0, shadowOpacity: 0 },
  pressed: { opacity: 0.9 },
  note: { textAlign: "center", marginTop: -4 },
  warning: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: 8,
    padding: 12,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: "#ffe6a8",
    backgroundColor: "#fff9e8",
  },
  warningText: { flex: 1 },
  empty: { alignItems: "center", gap: 6, paddingTop: 56 },
  browse: { marginTop: 12, paddingHorizontal: 20, paddingVertical: 10, borderRadius: 12, backgroundColor: colors.orange },
});

export default CartContents;
