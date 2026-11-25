import { useMemo, useState } from "react";
import { Pressable, StyleSheet, View } from "react-native";
import { router } from "expo-router";

import type { DiscountType } from "../../api/account";
import { useCart } from "../../hooks/useCart";
import { useProfile } from "../../hooks/useProfile";
import { formatPeso } from "../../lib/money";
import { discountLine, discountSaving } from "../../lib/statutoryDiscount";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { IconClockHour4, IconRosetteDiscountCheck, IconTag } from "../icons";

const TYPES: { value: DiscountType; label: string }[] = [
  { value: "senior", label: "Senior" },
  { value: "pwd", label: "PWD" },
];

const TYPE_LABEL: Record<string, string> = { senior: "Senior Citizen", pwd: "PWD" };

/** "₱126" for a whole amount, "₱126.43" otherwise. */
function pesos(amount: number) {
  return Number.isInteger(amount) ? formatPeso(amount).replace(/\.00$/, "") : formatPeso(amount);
}

/**
 * The Senior Citizen and PWD banner on the advance menu, as on the web
 * (DiscountBanner.jsx, decided 2026-11-26): pick Senior or PWD and Apply opens
 * the ID upload screen on that type. What it would save comes from the ticked
 * lines of the advance cart (one unit of the highest-priced marked dish, VAT
 * out, then the rate); with none, it shows the rate. After applying it says
 * "Under review" or "Verified"; a refused application offers "Apply again".
 */
export function DiscountBanner() {
  const { data } = useProfile();
  const cart = useCart("advance");
  const [type, setType] = useState<DiscountType>("senior");

  const standing = data?.discount ?? null;
  const percentage = Number(standing?.percentage ?? 20);

  const saving = useMemo(() => {
    const line = discountLine(cart.cart.filter((entry) => cart.selectedIds.includes(entry.id)));

    return line ? discountSaving(line.base_price, percentage) : 0;
  }, [cart.cart, cart.selectedIds, percentage]);

  if (!standing) return null;

  const status = standing.claim?.discount_status ?? null;
  const claimType = TYPE_LABEL[standing.claim?.discount_type ?? ""] ?? "Senior Citizen or PWD";

  return (
    <View style={styles.banner} testID="discount-banner">
      <View style={styles.top}>
        <View style={styles.titleRow}>
          <View style={styles.tile}>
            <IconTag size={20} color={colors.white} strokeWidth={2} />
          </View>
          <View style={styles.flex}>
            <AppText family="display" weight="medium" size={11.5} color="rgba(255,255,255,0.88)">
              PWD &amp; Senior Citizens Privilege
            </AppText>
            <AppText family="display" weight="extrabold" size={17} color={colors.white}>
              Get {percentage}% Off Your Order
            </AppText>
          </View>
        </View>

        <View style={styles.badge} testID="discount-banner-badge">
          {saving > 0 ? (
            <>
              <AppText family="display" weight="semibold" size={8.5} color="rgba(255,255,255,0.88)" style={styles.center}>
                YOU SAVE
              </AppText>
              <AppText family="display" weight="extrabold" size={17} color={colors.white} style={styles.center}>
                {pesos(saving)}
              </AppText>
              <AppText family="display" size={8.5} color="rgba(255,255,255,0.88)" style={styles.center}>
                on this order
              </AppText>
            </>
          ) : (
            <>
              <AppText family="display" weight="extrabold" size={17} color={colors.white} style={styles.center}>
                {percentage}%
              </AppText>
              <AppText family="display" weight="semibold" size={8.5} color="rgba(255,255,255,0.88)" style={styles.center}>
                OFF
              </AppText>
            </>
          )}
        </View>
      </View>

      {status === "pending" || status === "approved" ? (
        <View style={styles.status} testID={`discount-banner-${status}`}>
          {status === "pending" ? (
            <IconClockHour4 size={17} color={colors.white} strokeWidth={2} />
          ) : (
            <IconRosetteDiscountCheck size={17} color={colors.white} strokeWidth={2} />
          )}
          <AppText family="display" weight="semibold" size={12.5} color={colors.white} style={styles.flex}>
            {status === "pending"
              ? `Under review: a Store Agent is checking your ${claimType} ID.`
              : `Verified: choose your ${claimType} discount at checkout.`}
          </AppText>
        </View>
      ) : (
        <View style={styles.actions}>
          <View style={styles.switch} accessibilityRole="radiogroup" accessibilityLabel="Discount type">
            {TYPES.map((option) => {
              const chosen = option.value === type;

              return (
                <Pressable
                  key={option.value}
                  accessibilityRole="radio"
                  accessibilityState={{ checked: chosen }}
                  onPress={() => setType(option.value)}
                  style={[styles.option, chosen && styles.optionOn]}
                  testID={`discount-banner-${option.value}`}
                >
                  <AppText family="display" weight={chosen ? "bold" : "semibold"} size={12.5} color={colors.white}>
                    {option.label}
                  </AppText>
                </Pressable>
              );
            })}
          </View>

          <Pressable
            accessibilityRole="button"
            onPress={() => router.push({ pathname: "/account/discount", params: { type } })}
            style={({ pressed }) => [styles.apply, pressed && styles.pressed]}
            testID="discount-banner-apply"
          >
            <AppText family="display" weight="bold" size={13.5} color={colors.brand600} numberOfLines={1}>
              {status === "rejected"
                ? "Apply again"
                : saving > 0
                  ? `Apply — Save ${formatPeso(saving)}`
                  : "Apply for the discount"}
            </AppText>
          </Pressable>
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  banner: { marginHorizontal: 16, marginTop: 12, padding: 14, gap: 12, borderRadius: 18, backgroundColor: "#ec7524" },
  top: { flexDirection: "row", alignItems: "flex-start", gap: 10 },
  titleRow: { flex: 1, flexDirection: "row", alignItems: "center", gap: 10 },
  tile: {
    width: 40,
    height: 40,
    borderRadius: 11,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "rgba(255,255,255,0.2)",
  },
  flex: { flex: 1 },
  center: { textAlign: "center" },
  badge: {
    minWidth: 74,
    paddingHorizontal: 10,
    paddingVertical: 5,
    borderRadius: 11,
    borderWidth: 1,
    borderColor: "rgba(255,255,255,0.3)",
    backgroundColor: "rgba(255,255,255,0.15)",
  },
  actions: { flexDirection: "row", gap: 8 },
  switch: {
    flexDirection: "row",
    padding: 3,
    borderRadius: 11,
    borderWidth: 1,
    borderColor: "rgba(255,255,255,0.4)",
    backgroundColor: "rgba(255,255,255,0.1)",
  },
  option: { height: 36, paddingHorizontal: 13, borderRadius: 8, alignItems: "center", justifyContent: "center" },
  optionOn: { backgroundColor: "rgba(255,255,255,0.3)" },
  apply: {
    flex: 1,
    height: 44,
    borderRadius: 11,
    paddingHorizontal: 10,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
  },
  status: {
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    minHeight: 44,
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: 11,
    borderWidth: 1,
    borderColor: "rgba(255,255,255,0.3)",
    backgroundColor: "rgba(255,255,255,0.15)",
  },
  pressed: { opacity: 0.85 },
});

export default DiscountBanner;
