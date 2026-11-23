import { Fragment, type ReactNode } from "react";
import { StyleSheet, View } from "react-native";

import type { Quote } from "../../api/checkout";
import { formatPeso } from "../../lib/money";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { IconAlertTriangle, IconCheck, IconClock, IconInfoCircle, IconTruckDelivery } from "../icons";

const DONE = colors.success;

/** ①—②—③ and "Step 2 of 3": done steps green with a tick, the current one orange. */
export function Stepper({ count, current }: { count: number; current: number }) {
  return (
    <View style={styles.stepper} accessibilityLabel={`Step ${current} of ${count}`}>
      <View style={styles.dots}>
        {Array.from({ length: count }, (_, index) => {
          const number = index + 1;
          const done = number < current;
          const active = number === current;

          return (
            <Fragment key={number}>
              {index > 0 && <View style={[styles.line, number <= current && styles.lineDone]} />}
              <View style={[styles.dot, done && styles.dotDone, active && styles.dotActive]}>
                {done ? (
                  <IconCheck size={15} color={colors.white} strokeWidth={3} />
                ) : (
                  <AppText family="display" weight="semibold" size={13} color={active ? colors.white : colors.ink500}>
                    {number}
                  </AppText>
                )}
              </View>
            </Fragment>
          );
        })}
      </View>
      <AppText family="display" size={12.5} color={colors.authMuted}>
        Step {current} of {count}
      </AppText>
    </View>
  );
}

/** The current step: an orange-edged card with its number, title and a control on the right. */
export function StepCard({
  number,
  title,
  right,
  children,
}: {
  number: number;
  title: string;
  right?: ReactNode;
  children: ReactNode;
}) {
  return (
    <View style={styles.stepCard}>
      <View style={styles.stepHead}>
        <View style={styles.stepNumber}>
          <AppText family="display" weight="semibold" size={13} color={colors.white}>
            {number}
          </AppText>
        </View>
        <AppText family="display" weight="semibold" size={15} color={colors.orange} style={styles.flex} numberOfLines={1}>
          {title}
        </AppText>
        {right}
      </View>
      <View style={styles.stepBody}>{children}</View>
    </View>
  );
}

const TONES = {
  warning: { border: "#ffe6a8", fill: "#fff9e8", text: "#8a6206" },
  error: { border: "#ffd7d5", fill: colors.dangerSurface, text: "#c92a2a" },
  info: { border: "#f0e9df", fill: "#f8f5f1", text: colors.ink500 },
} as const;

/** Something the customer can act on (or should know), in place. */
export function Notice({ tone = "warning", children }: { tone?: keyof typeof TONES; children: ReactNode }) {
  const palette = TONES[tone];
  const Icon = tone === "info" ? IconInfoCircle : IconAlertTriangle;

  return (
    <View style={[styles.notice, { borderColor: palette.border, backgroundColor: palette.fill }]}>
      <Icon size={15} color={palette.text} strokeWidth={2.1} style={styles.noticeIcon} />
      <AppText family="display" size={12.5} color={palette.text} style={styles.flex}>
        {children}
      </AppText>
    </View>
  );
}

export function SummaryRow({ label, value, strong = false }: { label: ReactNode; value: string; strong?: boolean }) {
  return (
    <View style={styles.row}>
      {typeof label === "string" ? (
        <AppText family="display" size={13} color={colors.authMuted}>
          {label}
        </AppText>
      ) : (
        label
      )}
      <AppText family="display" weight={strong ? "semibold" : "medium"} size={13} color={colors.authInk} style={styles.value}>
        {value}
      </AppText>
    </View>
  );
}

/**
 * What is being bought and what it costs, from the quote (the API's prices,
 * never the app's). `children` goes between the totals and the amount due:
 * step 3 puts the contact and timing there.
 */
export function OrderSummary({
  quote,
  loading,
  children,
  bare = false,
}: {
  quote: Quote | null;
  loading: boolean;
  children?: ReactNode;
  /** Inside step 3's card: a lighter frame. */
  bare?: boolean;
}) {
  if (!quote) {
    return (
      <View style={[styles.summary, bare && styles.summaryBare]}>
        <View style={styles.skeletonTitle} />
        {loading && [0, 1].map((key) => <View key={key} style={styles.skeleton} />)}
      </View>
    );
  }

  const isDelivery = quote.fulfilment_type === "delivery";
  const discount = quote.discount;

  return (
    <View style={[styles.summary, bare && styles.summaryBare]} testID="order-summary">
      <AppText family="display" weight="semibold" size={14.5} color={colors.orange} style={styles.summaryTitle}>
        Order Summary
      </AppText>

      <View style={styles.lines}>
        {quote.items.map((line) => (
          <View key={line.id} style={styles.row}>
            <View style={styles.flex}>
              <AppText family="display" weight="semibold" size={13.5} color={colors.authInk}>
                {line.food_name}
              </AppText>
              <AppText family="display" size={11.5} color={colors.authHint}>
                {line.quantity}×{line.addons.length > 0 ? ` (${line.addons.map((addon) => addon.addon_name).join(", ")})` : ""}
              </AppText>
            </View>
            <AppText family="display" weight="semibold" size={13.5} color={colors.authInk}>
              {formatPeso(line.subtotal)}
            </AppText>
          </View>
        ))}
        {quote.items.length === 0 && (
          <AppText family="display" size={12.5} color={colors.authHint} style={styles.center}>
            Nothing selected for checkout.
          </AppText>
        )}
      </View>

      <View style={styles.totals}>
        <SummaryRow label="Subtotal" value={formatPeso(quote.subtotal)} />
        {isDelivery && (
          <SummaryRow
            label={
              <View style={styles.feeLabel}>
                <IconTruckDelivery size={15} color={colors.authMuted} strokeWidth={1.9} />
                <AppText family="display" size={13} color={colors.authMuted}>
                  Delivery Fee
                </AppText>
              </View>
            }
            value={quote.delivery?.fee != null ? formatPeso(quote.delivery_fee) : "—"}
          />
        )}
        {/* Decided 2026-11-23: the VAT comes off first, then the rate. */}
        {discount.applied && Number(discount.vat_exempt_amount) > 0 && (
          <View style={styles.row}>
            <AppText family="display" weight="medium" size={13} color={colors.brand600}>
              VAT exemption
            </AppText>
            <AppText family="display" weight="semibold" size={13} color={colors.brand600}>
              −{formatPeso(discount.vat_exempt_amount)}
            </AppText>
          </View>
        )}
        {discount.applied && (
          <View style={styles.row}>
            <AppText family="display" weight="medium" size={13} color={colors.brand600}>
              {discount.type_label} Discount ({Number(discount.percentage)}%)
            </AppText>
            <AppText family="display" weight="semibold" size={13} color={colors.brand600}>
              −{formatPeso(discount.percentage_amount ?? discount.amount)}
            </AppText>
          </View>
        )}
        {children}
      </View>

      <View style={styles.due}>
        <AppText family="display" weight="semibold" size={14} color={colors.brand600}>
          Amount Due
        </AppText>
        <AppText family="display" weight="bold" size={21} color={colors.orange} testID="amount-due">
          {formatPeso(quote.total)}
        </AppText>
      </View>

      <View style={styles.eta}>
        <IconClock size={13} color={colors.authHint} strokeWidth={2} />
        <AppText family="display" size={11.5} color={colors.authHint}>
          Est. {isDelivery ? "delivery" : "time of completion"}: 25–35 min
        </AppText>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  center: { textAlign: "center" },
  stepper: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", paddingHorizontal: 20, paddingBottom: 12 },
  dots: { flexDirection: "row", alignItems: "center" },
  dot: { width: 32, height: 32, borderRadius: 16, alignItems: "center", justifyContent: "center", backgroundColor: "#ece7e0" },
  dotActive: { backgroundColor: colors.orange },
  dotDone: { backgroundColor: DONE },
  line: { width: 34, height: 2, marginHorizontal: 6, borderRadius: 1, backgroundColor: "#e4ded6" },
  lineDone: { backgroundColor: DONE },
  stepCard: { borderRadius: 18, borderWidth: 1.5, borderColor: colors.orange, backgroundColor: colors.white, overflow: "hidden" },
  stepHead: {
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: "#f4efe8",
  },
  stepNumber: { width: 30, height: 30, borderRadius: 15, alignItems: "center", justifyContent: "center", backgroundColor: colors.orange },
  stepBody: { padding: 16, gap: 14 },
  notice: { flexDirection: "row", alignItems: "flex-start", gap: 8, padding: 12, borderRadius: 12, borderWidth: 1 },
  noticeIcon: { marginTop: 2 },
  row: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: 12 },
  value: { flexShrink: 1, textAlign: "right" },
  feeLabel: { flexDirection: "row", alignItems: "center", gap: 6 },
  summary: { borderRadius: 18, borderWidth: 1, borderColor: "#ece7e0", backgroundColor: colors.white, overflow: "hidden" },
  summaryBare: { borderColor: "#f0e9df" },
  summaryTitle: { paddingHorizontal: 16, paddingTop: 14, paddingBottom: 6 },
  lines: { paddingHorizontal: 16, paddingBottom: 12, gap: 8, borderBottomWidth: 1, borderBottomColor: "#f1ece6" },
  totals: { paddingHorizontal: 16, paddingVertical: 12, gap: 8 },
  due: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    marginHorizontal: 12,
    paddingHorizontal: 14,
    paddingVertical: 12,
    borderRadius: 12,
    backgroundColor: "#fff1e6",
  },
  eta: { flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 5, paddingVertical: 10 },
  skeletonTitle: { height: 14, width: 120, margin: 16, borderRadius: 6, backgroundColor: "#f4f1ec" },
  skeleton: { height: 34, marginHorizontal: 16, marginBottom: 12, borderRadius: 8, backgroundColor: "#f7f4f0" },
});
