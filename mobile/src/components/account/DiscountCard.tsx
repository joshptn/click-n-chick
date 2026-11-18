import { Pressable, StyleSheet, View } from "react-native";

import type { DiscountStanding, DiscountType } from "../../api/account";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { IconAlertTriangle, IconCircleCheck, IconClockHour4, IconRosetteDiscountCheck } from "../icons";

export const DISCOUNT_LABEL: Record<DiscountType, string> = { senior: "Senior Citizen", pwd: "PWD" };

/** "20" from the API's "20.00". */
export function percentText(value: number | string | undefined): string {
  const number = Number(value ?? 20);
  return Number.isInteger(number) ? String(number) : number.toFixed(2).replace(/0+$/, "");
}

/**
 * Discount eligibility on the Profile screen, in whichever state applies (the
 * web's DiscountCard): offer to apply, waiting for an agent, approved, or not
 * approved with the agent's reason and a way to apply again.
 */
export function DiscountCard({ discount, onApply }: { discount: DiscountStanding; onApply: () => void }) {
  const claim = discount.claim;
  const status = claim?.discount_status ?? null;
  const percent = percentText(discount.percentage);
  const label = claim ? DISCOUNT_LABEL[claim.discount_type] : null;

  return (
    <View style={styles.card} testID="discount-card">
      <View style={styles.top}>
        <AppText family="display" weight="semibold" size={16} color={colors.brand700} style={styles.flex}>
          Discount Eligibility
        </AppText>
        {discount.can_apply && (
          <Pressable
            accessibilityRole="button"
            onPress={onApply}
            style={({ pressed }) => [styles.apply, pressed && styles.pressed]}
            testID="discount-apply"
          >
            <AppText family="display" weight="medium" size={14} color={colors.white}>
              {status === "rejected" ? "Apply again" : "Apply"}
            </AppText>
          </Pressable>
        )}
      </View>

      {status === null && (
        <AppText family="display" size={12} color="#c46a12">
          Senior Citizens and PWDs get {percent}% off once a day, after a Store Agent checks your ID.
        </AppText>
      )}

      {status === "pending" && (
        <>
          <Line icon={IconCircleCheck} color={colors.success} text="Submitted — pending agent approval" />
          <AppText family="display" size={11.5} color="#e8890c">
            {percent}% {label} Discount will activate once verified.
          </AppText>
        </>
      )}

      {status === "approved" && (
        <>
          <Line icon={IconRosetteDiscountCheck} color={colors.success} text={`${percent}% ${label} discount active`} />
          <AppText family="display" size={11.5} color="#e8890c">
            Use it at checkout, once per day.
          </AppText>
        </>
      )}

      {status === "rejected" && (
        <>
          <Line icon={IconAlertTriangle} color={colors.danger} text="Not approved" />
          <AppText family="display" size={11.5} color="#9c2b28">
            {claim?.rejection_reason || "Your application was not approved."} You can fix the problem and apply again.
          </AppText>
        </>
      )}
    </View>
  );
}

function Line({
  icon: Icon,
  color,
  text,
}: {
  icon: typeof IconClockHour4;
  color: string;
  text: string;
}) {
  return (
    <View style={styles.line}>
      <Icon size={15} color={color} strokeWidth={2.2} />
      <AppText family="display" weight="semibold" size={12.5} color={color} style={styles.flex}>
        {text}
      </AppText>
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    gap: 6,
    padding: 16,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: "#ffd9b0",
    backgroundColor: "#fff4e6",
  },
  top: { flexDirection: "row", alignItems: "center", gap: 12 },
  flex: { flex: 1 },
  apply: {
    paddingHorizontal: 18,
    paddingVertical: 7,
    borderRadius: 10,
    backgroundColor: colors.orange,
    elevation: 3,
    shadowColor: colors.orange,
    shadowOpacity: 0.3,
    shadowRadius: 6,
    shadowOffset: { width: 0, height: 3 },
  },
  pressed: { opacity: 0.85 },
  line: { flexDirection: "row", alignItems: "center", gap: 6 },
});

export default DiscountCard;
