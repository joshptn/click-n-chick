import { useEffect, useMemo, useState } from "react";
import { BackHandler, Pressable, ScrollView, StyleSheet, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { SafeAreaView } from "react-native-safe-area-context";

import {
  CONTACT_BLOCKERS,
  SCHEDULE_BLOCKERS,
  advanceApi,
  formatClock,
  formatCollectionDay,
  lastCollection,
  toScheduleIso,
  type AdvanceInput,
} from "../../api/advance";
import { DISCOUNT_BLOCKERS } from "../../api/checkout";
import { ORDERS_KEY } from "../../api/orders";
import { AppText } from "../../components/AppText";
import { CalendarPicker } from "../../components/pickers/CalendarPicker";
import { TimeDialPicker } from "../../components/pickers/TimeDialPicker";
import { ScreenHeader } from "../../components/account/ScreenHeader";
import { CheckoutButton } from "../../components/checkout/CheckoutButton";
import { DiscountStep, type DiscountChoice } from "../../components/checkout/DiscountStep";
import { Notice, StepCard, Stepper, SummaryRow } from "../../components/checkout/parts";
import { IconCalendarEvent, IconCheck, IconChevronRight, IconClock, IconSend, IconUser } from "../../components/icons";
import { cartKey } from "../../hooks/useCart";
import { formatPeso } from "../../lib/money";
import { formatCalendarDate, timeWindow } from "../../lib/timeWindow";
import { toast } from "../../lib/toast";
import { colors } from "../../theme/colors";

type StepId = "schedule" | "discount" | "review";
const STEPS: StepId[] = ["schedule", "discount", "review"];

const QUOTE_DEBOUNCE_MS = 400;
const PENDING_DISCOUNT_POLL_MS = 15 * 1000;

/**
 * Scheduling an advance order (phase 9; the web's AdvanceCheckout): 1 when
 * to collect, 2 the discount, 3 the request reviewed and sent. Collection
 * only, from tomorrow up to 30 days ahead; the window comes from the API's
 * quote. As in checkout, every Continue and Send Request stays grey until
 * its step is valid (decided 2026-11-19). Nothing is paid here: the store
 * accepts the request first.
 */
export default function AdvanceSchedule() {
  const queryClient = useQueryClient();
  const { ids } = useLocalSearchParams<{ ids?: string }>();
  const cartItemIds = useMemo(
    () =>
      String(ids ?? "")
        .split(",")
        .map(Number)
        .filter((id) => Number.isInteger(id) && id > 0),
    [ids]
  );

  const [stepIndex, setStepIndex] = useState(0);
  const step = STEPS[stepIndex];

  const [date, setDate] = useState<string | null>(null);
  const [time, setTime] = useState<string | null>(null);
  const [timeOpen, setTimeOpen] = useState(false);
  const [dateOpen, setDateOpen] = useState(false);
  const [discountChoice, setDiscountChoice] = useState<DiscountChoice | null>(null);
  const [acknowledged, setAcknowledged] = useState(false);

  const input = useMemo<AdvanceInput>(
    () => ({ cart_item_ids: cartItemIds, scheduled_for: toScheduleIso(date, time), apply_discount: discountChoice === "use" }),
    [cartItemIds, date, time, discountChoice]
  );

  const [debounced, setDebounced] = useState(input);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(input), QUOTE_DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [input]);

  const quoteQuery = useQuery({
    queryKey: ["advance", "quote", debounced],
    queryFn: ({ signal }) => advanceApi.quote(debounced, signal),
    placeholderData: (previous) => previous,
    retry: false,
    refetchInterval: (query) =>
      step === "discount" && query.state.data?.discount.status === "pending" ? PENDING_DISCOUNT_POLL_MS : false,
  });

  const quote = quoteQuery.data ?? null;
  const settled = debounced === input && quote !== null && !quoteQuery.isPlaceholderData;
  const schedule = quote?.schedule ?? null;

  // The earliest bookable day is chosen for the customer, as on the web; the
  // time is theirs to choose.
  if (date === null && schedule?.earliest_date) setDate(schedule.earliest_date);

  // Decided 2026-11-20: a calendar and a clock dial that offer only what the
  // window allows (5-minute steps), instead of date chips and a slot list.
  const timeRange = timeWindow(schedule?.opens_at, lastCollection(schedule));
  const day = formatCollectionDay(date);

  const blockers = quote?.blockers ?? [];
  const itemBlocker = blockers.find((blocker) => blocker.code === "EMPTY_SELECTION") ?? null;
  const scheduleBlocker = time ? (blockers.find((blocker) => SCHEDULE_BLOCKERS.includes(blocker.code)) ?? null) : null;
  const contactBlocker = blockers.find((blocker) => CONTACT_BLOCKERS.includes(blocker.code)) ?? null;
  const stepOneBlockers = blockers.filter((blocker) => !DISCOUNT_BLOCKERS.includes(blocker.code));

  const scheduleReady = Boolean(date && time) && settled && stepOneBlockers.length === 0;
  const discountReady = discountChoice !== null && settled && Boolean(quote?.can_submit);

  const submit = useMutation({
    mutationFn: () => advanceApi.submit(input, quote?.total),
    onSuccess: (payload) => {
      void queryClient.invalidateQueries({ queryKey: cartKey("account", "advance") });
      void queryClient.invalidateQueries({ queryKey: ORDERS_KEY });

      router.replace({
        pathname: "/advance/sent/[id]",
        params: {
          id: String(payload.order.id),
          scheduled_for: payload.order.scheduled_for ?? "",
          total: String(payload.order.total_amount),
          items: String(payload.order.item_count),
        },
      });
    },
    onError: (error) => {
      void queryClient.invalidateQueries({ queryKey: ["advance", "quote"] });
      toast.error("Could not send your request", error instanceof Error ? error.message : "Please try again.");
    },
  });

  const sending = submit.isPending || submit.isSuccess;
  const sendReady = acknowledged && settled && Boolean(quote?.can_submit) && !sending;

  const back = () => {
    if (sending) return;
    if (stepIndex > 0) setStepIndex(stepIndex - 1);
    else if (router.canGoBack()) router.back();
    else router.navigate("/cart");
  };

  useEffect(() => {
    const subscription = BackHandler.addEventListener("hardwareBackPress", () => {
      if (stepIndex === 0) return false;
      if (!submit.isPending && !submit.isSuccess) setStepIndex(stepIndex - 1);
      return true;
    });

    return () => subscription.remove();
  }, [stepIndex, submit.isPending, submit.isSuccess]);

  const next = () => setStepIndex((index) => Math.min(index + 1, STEPS.length - 1));

  const footer =
    step === "schedule"
      ? {
          label: "Continue to Discount",
          ready: scheduleReady,
          hint: !date
            ? "Choose a collection date"
            : !time
              ? "Choose a collection time"
              : (stepOneBlockers[0]?.message ?? (quoteQuery.isError ? "We couldn't check your request. Try again." : settled ? null : "Checking...")),
        }
      : step === "discount"
        ? {
            label: "Continue to Review",
            ready: discountReady,
            hint: discountChoice === null ? "Choose Use Discount or Later to continue" : (blockers[0]?.message ?? (settled ? null : "Checking...")),
          }
        : null;

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right", "bottom"]}>
      <ScreenHeader title="Advance Order" onBack={back} />
      <Stepper count={STEPS.length} current={stepIndex + 1} />

      <ScrollView contentContainerStyle={styles.content}>
        {itemBlocker && <Notice tone="error">{itemBlocker.message}</Notice>}

        {quoteQuery.isError && (
          <Pressable accessibilityRole="button" onPress={() => void quoteQuery.refetch()}>
            <Notice tone="error">
              {quoteQuery.error instanceof Error ? quoteQuery.error.message : "We couldn't check your request."} Tap to try again.
            </Notice>
          </Pressable>
        )}

        {step === "schedule" && (
          <StepCard number={1} title="When will you collect?">
            <Notice tone="info">
              Your request goes to the store first. Once a Store Agent accepts it you will be asked to pay; nothing is charged before
              then.
            </Notice>

            <View style={styles.block}>
              <AppText family="display" weight="medium" size={13} color={colors.authLabel}>
                Collection date
              </AppText>
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={date ? `Collection date ${formatCalendarDate(date)}. Change` : "Choose a collection date"}
                disabled={!schedule}
                onPress={() => setDateOpen(true)}
                style={styles.timeField}
                testID="collection-date"
              >
                <AppText family="display" size={14.5} color={date ? colors.authInk : colors.authHint} style={styles.flex}>
                  {date ? formatCalendarDate(date) : "Choose a date"}
                </AppText>
                <IconCalendarEvent size={18} color={colors.authHint} strokeWidth={2} />
              </Pressable>
              {schedule && (
                <AppText family="display" size={12} color={colors.authHint}>
                  {day ? `${day} · ` : ""}up to {schedule.max_days_ahead} days ahead
                </AppText>
              )}
            </View>

            {schedule?.tomorrow_closed && (
              <Notice>Ordering for tomorrow closed when the store did, so the earliest we can take is the day after.</Notice>
            )}

            <View style={styles.block}>
              <AppText family="display" weight="medium" size={13} color={colors.authLabel}>
                Collection time
              </AppText>
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={time ? `Collection time ${formatClock(time)}. Change` : "Choose a collection time"}
                disabled={!timeRange}
                onPress={() => setTimeOpen(true)}
                style={[styles.timeField, scheduleBlocker && styles.timeFieldError]}
                testID="collection-time"
              >
                <AppText family="display" size={14.5} color={time ? colors.authInk : colors.authHint} style={styles.flex}>
                  {time ? formatClock(time) : "Choose a time"}
                </AppText>
                <IconClock size={18} color={colors.authHint} strokeWidth={2} />
              </Pressable>
              {scheduleBlocker ? (
                <AppText family="display" size={12} color={colors.danger}>
                  {scheduleBlocker.message}
                </AppText>
              ) : schedule ? (
                <AppText family="display" size={12} color={colors.authHint}>
                  Collection from {formatClock(schedule.opens_at)} to {formatClock(lastCollection(schedule) ?? schedule.closes_at)}
                </AppText>
              ) : null}
            </View>

            <View style={styles.contact}>
              <AppText family="display" weight="semibold" size={11} color={colors.authHint} style={styles.caps}>
                COLLECTED BY
              </AppText>
              <View style={styles.contactRow}>
                <IconUser size={15} color={colors.orange} strokeWidth={2} />
                <AppText family="display" size={13} color={colors.authInk} style={styles.flex} numberOfLines={1}>
                  {quote?.contact.name || "—"}
                  {quote?.contact.phone ? ` · ${quote.contact.phone}` : ""}
                </AppText>
              </View>
              {contactBlocker && (
                <>
                  <AppText family="display" weight="medium" size={12} color={colors.danger}>
                    {contactBlocker.message}
                  </AppText>
                  <Pressable accessibilityRole="button" onPress={() => router.push("/account/edit")} hitSlop={6}>
                    <AppText family="display" weight="semibold" size={12.5} color={colors.orange}>
                      Open Edit Profile
                    </AppText>
                  </Pressable>
                </>
              )}
            </View>

            <CalendarPicker
              visible={dateOpen}
              onClose={() => setDateOpen(false)}
              value={date}
              min={schedule?.earliest_date}
              max={schedule?.latest_date}
              earlyMessage={(first) =>
                schedule?.tomorrow_closed
                  ? `Orders for tomorrow closed when the store did today, so the earliest date is ${first}.`
                  : `Advance orders start from tomorrow. The earliest date is ${first}.`
              }
              lateMessage={(last) => `You can book up to ${schedule?.max_days_ahead ?? 30} days ahead, until ${last}.`}
              onConfirm={(value) => {
                setDate(value);
                setDateOpen(false);
              }}
            />

            <TimeDialPicker
              visible={timeOpen}
              onClose={() => setTimeOpen(false)}
              value={time}
              min={schedule?.opens_at}
              max={lastCollection(schedule)}
              title="Select collection time"
              earlyMessage={(first) => `The store opens at ${first}, so that is the earliest collection.`}
              lateMessage={(last) => `The last collection is ${last}, shortly before the store closes.`}
              onConfirm={(value) => {
                setTime(value);
                setTimeOpen(false);
              }}
            />
          </StepCard>
        )}

        {step === "discount" && (
          <StepCard number={2} title="Senior / PWD Discount">
            <DiscountStep
              discount={quote?.discount ?? null}
              choice={discountChoice}
              onChoose={setDiscountChoice}
              onSubmitted={() => void quoteQuery.refetch()}
              forDay={day || null}
            />
          </StepCard>
        )}

        {step === "review" && (
          <StepCard number={3} title="3. Review & Send">
            <View style={styles.collection}>
              <IconCalendarEvent size={18} color={colors.brand600} strokeWidth={2.2} />
              <View style={styles.flex}>
                <AppText family="display" weight="semibold" size={10.5} color={colors.brand800} style={styles.caps}>
                  COLLECTION
                </AppText>
                <AppText family="display" weight="semibold" size={13.5} color={colors.authInk}>
                  {day && time ? `${day}, ${formatClock(time)}` : "Not chosen yet"}
                </AppText>
              </View>
            </View>

            <View style={styles.lines}>
              {(quote?.items ?? []).map((line) => (
                <View key={line.id} style={styles.line}>
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
            </View>

            <View style={styles.totals}>
              <SummaryRow label="Subtotal" value={formatPeso(quote?.subtotal)} />
              {quote?.discount.applied && Number(quote.discount.vat_exempt_amount) > 0 && (
                <View style={styles.line}>
                  <AppText family="display" weight="medium" size={13} color={colors.brand600}>
                    VAT exemption
                  </AppText>
                  <AppText family="display" weight="semibold" size={13} color={colors.brand600}>
                    −{formatPeso(quote.discount.vat_exempt_amount)}
                  </AppText>
                </View>
              )}
              {quote?.discount.applied && (
                <View style={styles.line}>
                  <AppText family="display" weight="medium" size={13} color={colors.brand600}>
                    {quote.discount.type_label} Discount ({Number(quote.discount.percentage)}%)
                  </AppText>
                  <AppText family="display" weight="semibold" size={13} color={colors.brand600}>
                    −{formatPeso(quote.discount.percentage_amount ?? quote.discount.amount)}
                  </AppText>
                </View>
              )}
              <SummaryRow label="Collected by" value={quote?.contact.name ?? ""} strong />
            </View>

            <View style={styles.due}>
              <AppText family="display" weight="semibold" size={14} color={colors.brand600}>
                Estimated Total
              </AppText>
              <AppText family="display" weight="bold" size={21} color={colors.orange} testID="advance-total">
                {formatPeso(quote?.total)}
              </AppText>
            </View>
            <AppText family="display" size={11.5} color={colors.authHint} style={styles.center}>
              Payable only after the store accepts, by the time it gives you. Unpaid by then, the store cancels it.
            </AppText>

            <Pressable
              accessibilityRole="checkbox"
              accessibilityState={{ checked: acknowledged, disabled: sending }}
              disabled={sending}
              onPress={() => setAcknowledged((value) => !value)}
              style={styles.confirm}
              hitSlop={6}
              testID="advance-acknowledge"
            >
              <View style={[styles.radio, acknowledged && styles.radioOn]}>
                {acknowledged && <IconCheck size={13} color={colors.white} strokeWidth={3} />}
              </View>
              <AppText family="display" size={12.5} color={colors.authMuted} style={styles.flex}>
                I understand this is a{" "}
                <AppText family="display" weight="semibold" size={12.5} color={colors.orange}>
                  request
                </AppText>
                , that the store may decline it, and that prices may change before I pay
              </AppText>
            </Pressable>

            <View style={styles.buttons}>
              <CheckoutButton label="Back" variant="outline" onPress={back} disabled={sending} flex={1} testID="review-back" />
              <CheckoutButton
                label={sending ? "Sending..." : "Send Request"}
                icon={IconSend}
                disabled={!sendReady && !sending}
                loading={sending}
                onPress={() => submit.mutate()}
                flex={1.7}
                testID="send-request"
              />
            </View>
          </StepCard>
        )}
      </ScrollView>

      {footer && (
        <View style={styles.footer}>
          <CheckoutButton
            label={footer.label}
            trailingIcon={IconChevronRight}
            disabled={!footer.ready}
            onPress={next}
            testID={step === "schedule" ? "continue-schedule" : "continue-discount"}
          />
          {!footer.ready && footer.hint ? (
            <AppText family="display" size={11.5} color={colors.authHint} style={styles.hint} testID="continue-hint">
              {footer.hint}
            </AppText>
          ) : null}
        </View>
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  flex: { flex: 1 },
  center: { textAlign: "center" },
  caps: { letterSpacing: 0.7 },
  content: { paddingHorizontal: 16, paddingBottom: 24, gap: 14 },
  block: { gap: 8 },
  timeField: {
    minHeight: 48,
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
    paddingHorizontal: 16,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: colors.fieldBorder,
    backgroundColor: colors.white,
  },
  timeFieldError: { borderColor: "#f5b4b2" },
  contact: { gap: 6, padding: 14, borderRadius: 14, borderWidth: 1, borderColor: "#f0e9df", backgroundColor: "#faf7f3" },
  contactRow: { flexDirection: "row", alignItems: "center", gap: 8 },
  collection: { flexDirection: "row", alignItems: "flex-start", gap: 10, padding: 12, borderRadius: 12, backgroundColor: "#fff1e6" },
  lines: { gap: 8, paddingBottom: 10, borderBottomWidth: 1, borderBottomColor: "#f1ece6" },
  line: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: 12 },
  totals: { gap: 8 },
  due: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    paddingHorizontal: 14,
    paddingVertical: 12,
    borderRadius: 12,
    backgroundColor: "#fff1e6",
  },
  confirm: { flexDirection: "row", alignItems: "flex-start", gap: 10 },
  radio: {
    width: 22,
    height: 22,
    borderRadius: 11,
    borderWidth: 2,
    borderColor: "#ddd6cd",
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
    marginTop: 1,
  },
  radioOn: { backgroundColor: colors.orange, borderColor: colors.orange },
  buttons: { flexDirection: "row", gap: 10 },
  footer: {
    paddingHorizontal: 16,
    paddingTop: 10,
    paddingBottom: 10,
    borderTopWidth: 1,
    borderTopColor: "#f1ebe3",
    backgroundColor: colors.landing,
  },
  hint: { textAlign: "center", marginTop: 6 },
});
