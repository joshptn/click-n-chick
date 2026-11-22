import { useEffect, useMemo, useRef, useState } from "react";
import { BackHandler, KeyboardAvoidingView, Pressable, ScrollView, StyleSheet, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { SafeAreaView } from "react-native-safe-area-context";

import { PROFILE_KEY, accountApi } from "../api/account";
import { ApiError } from "../api/client";
import {
  DISCOUNT_BLOCKERS,
  ITEM_BLOCKERS,
  STORE_BLOCKERS,
  checkoutApi,
  toLocalMobile,
  type QuoteInput,
} from "../api/checkout";
import { AppText } from "../components/AppText";
import { ScreenHeader } from "../components/account/ScreenHeader";
import { CheckoutButton } from "../components/checkout/CheckoutButton";
import { DiscountStep, type DiscountChoice } from "../components/checkout/DiscountStep";
import { DispatchStep, FulfilmentToggle, dispatchProblems, type DispatchValues } from "../components/checkout/DispatchStep";
import { Notice, OrderSummary, StepCard, Stepper, SummaryRow } from "../components/checkout/parts";
import { IconCheck, IconChevronRight, IconLock, IconQrcode, IconShoppingCart, IconWallet } from "../components/icons";
import { useSession } from "../hooks/useSession";
import { useStoreStatus } from "../hooks/useStoreStatus";
import { rememberPendingCheckout } from "../lib/guestOrders";
import { formatTime } from "../lib/time";
import { toast } from "../lib/toast";
import { colors } from "../theme/colors";

type StepId = "dispatch" | "discount" | "payment";

const QUOTE_DEBOUNCE_MS = 400;
const PIN_DEBOUNCE_MS = 900;
const PENDING_DISCOUNT_POLL_MS = 15 * 1000;

/**
 * Checkout (phase 11, designs of 2026-11-19 used as layout only), the web's
 * Checkout page as three steps: Order Details, Discount (accounts only, as on
 * the web) and Secure Payment.
 *
 * Every Continue and Place Order button stays grey until its step is valid
 * (decided 2026-11-19): the fields the app can check itself, then the API's
 * quote for exactly what is on screen. Place Order creates the order waiting
 * for payment and goes to Xendit; the API, never the app, marks it paid.
 */
export default function Checkout() {
  const { signedIn } = useSession();
  const scope = signedIn ? "account" : "guest";
  const guest = !signedIn;
  const queryClient = useQueryClient();
  const { status: store } = useStoreStatus();

  const { ids } = useLocalSearchParams<{ ids?: string }>();
  const cartItemIds = useMemo(
    () =>
      String(ids ?? "")
        .split(",")
        .map(Number)
        .filter((id) => Number.isInteger(id) && id > 0),
    [ids]
  );

  const steps: StepId[] = guest ? ["dispatch", "payment"] : ["dispatch", "discount", "payment"];
  const [stepIndex, setStepIndex] = useState(0);
  const step = steps[Math.min(stepIndex, steps.length - 1)];

  const deliveryDisabled = store ? !store.delivery_enabled : false;

  const [values, setValues] = useState<DispatchValues>(() => ({
    fulfilment: store && !store.delivery_enabled ? "pickup" : "delivery",
    name: "",
    phone: "",
    email: "",
    pickupAt: null,
    destination: null,
    note: "",
  }));
  const [discountChoice, setDiscountChoice] = useState<DiscountChoice | null>(null);
  const [acknowledged, setAcknowledged] = useState(false);
  const [scrollLocked, setScrollLocked] = useState(false);

  const change = (patch: Partial<DispatchValues>) => setValues((prev) => ({ ...prev, ...patch }));

  // The account's name and number fill the form once, as on the web.
  const profile = useQuery({
    queryKey: PROFILE_KEY,
    queryFn: ({ signal }) => accountApi.profile(signal),
    enabled: signedIn,
    staleTime: 60 * 1000,
  });
  const [prefilled, setPrefilled] = useState(false);

  if (!prefilled && profile.data) {
    const user = profile.data.user;
    setPrefilled(true);
    setValues((prev) => ({
      ...prev,
      name: prev.name || [user.first_name, user.last_name].filter(Boolean).join(" "),
      phone: prev.phone || toLocalMobile(user.phone_number),
    }));
  }

  const delivery = values.fulfilment === "delivery";
  const destination = values.destination;

  const input = useMemo<QuoteInput>(
    () => ({
      fulfilment_type: values.fulfilment,
      cart_item_ids: cartItemIds,
      latitude: delivery ? (destination?.latitude ?? null) : null,
      longitude: delivery ? (destination?.longitude ?? null) : null,
      full_address: delivery ? destination?.full_address.trim() || null : null,
      location: delivery ? (destination?.locality ?? null) : null,
      delivery_note: delivery ? values.note.trim() || null : null,
      pickup_at: delivery ? null : values.pickupAt,
      contact_name: values.name.trim(),
      contact_phone: values.phone.trim(),
      contact_email: guest ? values.email.trim() : "",
      apply_discount: !guest && discountChoice === "use",
    }),
    [values, cartItemIds, delivery, destination, guest, discountChoice]
  );

  // Asked of the API once typing settles; a moved pin waits a little longer,
  // since each new spot costs a route lookup.
  const [debounced, setDebounced] = useState(input);
  const settledPin = useRef({ latitude: input.latitude, longitude: input.longitude });

  useEffect(() => {
    const moved = input.latitude !== settledPin.current.latitude || input.longitude !== settledPin.current.longitude;
    const timer = setTimeout(
      () => {
        settledPin.current = { latitude: input.latitude, longitude: input.longitude };
        setDebounced(input);
      },
      moved ? PIN_DEBOUNCE_MS : QUOTE_DEBOUNCE_MS
    );

    return () => clearTimeout(timer);
  }, [input]);

  const quoteQuery = useQuery({
    queryKey: ["checkout", "quote", scope, debounced],
    queryFn: ({ signal }) => checkoutApi.quote(scope, debounced, signal),
    staleTime: 30 * 1000,
    placeholderData: (previous) => previous,
    retry: false,
    // An application under review may be approved while the customer waits.
    refetchInterval: (query) =>
      step === "discount" && query.state.data?.discount.status === "pending" ? PENDING_DISCOUNT_POLL_MS : false,
  });

  const quote = quoteQuery.data ?? null;
  // The quote answers exactly what is on screen now.
  const settled = debounced === input && quote !== null && !quoteQuery.isPlaceholderData;
  const quoting = !settled && !quoteQuery.isError;

  const blockers = quote?.blockers ?? [];
  const pageBlockers = blockers.filter((blocker) => [...STORE_BLOCKERS, ...ITEM_BLOCKERS].includes(blocker.code));
  const dispatchBlockers = blockers.filter((blocker) => !DISCOUNT_BLOCKERS.includes(blocker.code));
  const problems = dispatchProblems(values, guest);

  const dispatchReady = problems.length === 0 && settled && dispatchBlockers.length === 0;
  const discountReady = discountChoice !== null && settled && Boolean(quote?.can_place);

  const place = useMutation({
    mutationFn: () => checkoutApi.place(scope, input),
    onSuccess: async (result) => {
      // The ordered lines have left the cart.
      void queryClient.invalidateQueries({ queryKey: ["cart"] });

      // Saved before Xendit opens, so a cold start can come back to it.
      await rememberPendingCheckout(
        result.tracking_token ? { token: result.tracking_token } : { orderId: String(result.order.id) }
      );

      router.replace({
        pathname: "/payment",
        params: result.tracking_token
          ? { token: result.tracking_token, url: result.payment.url }
          : { order: String(result.order.id), url: result.payment.url },
      });
    },
    onError: (error) => {
      void queryClient.invalidateQueries({ queryKey: ["checkout", "quote"] });
      const status = error instanceof ApiError ? error.status : 0;
      toast.error(
        status === 502 ? "Payment unavailable" : "Order not placed",
        error instanceof Error ? error.message : "Your order could not be placed. Please try again."
      );
    },
  });

  // Sent once: the button stays busy from the tap until the payment screen opens.
  const placing = place.isPending || place.isSuccess;
  const placeReady = acknowledged && settled && Boolean(quote?.can_place) && !placing;

  const back = () => {
    if (placing) return;
    if (stepIndex > 0) setStepIndex(stepIndex - 1);
    else if (router.canGoBack()) router.back();
    else router.navigate("/cart");
  };

  // Android's back button walks back through the steps first.
  useEffect(() => {
    const subscription = BackHandler.addEventListener("hardwareBackPress", () => {
      if (stepIndex === 0) return false;
      if (!place.isPending && !place.isSuccess) setStepIndex(stepIndex - 1);
      return true;
    });

    return () => subscription.remove();
  }, [stepIndex, place.isPending, place.isSuccess]);

  const next = () => setStepIndex((index) => Math.min(index + 1, steps.length - 1));

  const footer =
    step === "dispatch"
      ? {
          label: guest ? "Continue to Payment" : "Continue to Discount",
          ready: dispatchReady,
          hint: problems[0] ?? dispatchBlockers[0]?.message ?? (quoteQuery.isError ? "We couldn't check your order. Pull down or try again." : settled ? null : "Checking your order..."),
          testID: "continue-dispatch",
        }
      : step === "discount"
        ? {
            label: "Continue to Payment",
            ready: discountReady,
            hint:
              discountChoice === null
                ? "Choose Use Discount or Later to continue"
                : (blockers[0]?.message ?? (settled ? null : "Checking your order...")),
            testID: "continue-discount",
          }
        : null;

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right", "bottom"]}>
      <ScreenHeader title="Checkout" onBack={back} />
      <Stepper count={steps.length} current={stepIndex + 1} />

      <KeyboardAvoidingView behavior="padding" style={styles.flex}>
        <ScrollView
          contentContainerStyle={styles.content}
          keyboardShouldPersistTaps="handled"
          scrollEnabled={!scrollLocked}
          testID="checkout-scroll"
        >
          {pageBlockers.map((blocker) => (
            <Notice key={blocker.code} tone="error">
              {blocker.message}
            </Notice>
          ))}

          {quoteQuery.isError && (
            <Pressable accessibilityRole="button" onPress={() => void quoteQuery.refetch()}>
              <Notice tone="error">
                {quoteQuery.error instanceof Error ? quoteQuery.error.message : "We couldn't check your order."} Tap to try again.
              </Notice>
            </Pressable>
          )}

          {step === "dispatch" && (
            <StepCard
              number={1}
              title=""
              right={
                <FulfilmentToggle
                  value={values.fulfilment}
                  onChange={(fulfilment) => change({ fulfilment })}
                  deliveryDisabled={deliveryDisabled}
                />
              }
            >
              <DispatchStep
                values={values}
                onChange={change}
                quote={quote}
                quoting={quoting}
                scope={scope}
                onMapTouch={setScrollLocked}
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
              />
            </StepCard>
          )}

          {step === "payment" && (
            <StepCard number={steps.length} title={`${steps.length}. Secure Payment`}>
              <OrderSummary quote={quote} loading={quoting} bare>
                <View style={styles.details}>
                  <SummaryRow label={delivery ? "Recipient" : "Pick up Person"} value={values.name.trim()} strong />
                  <SummaryRow label="Contact Number" value={toLocalMobile(values.phone)} strong />
                  {guest && <SummaryRow label="Email" value={values.email.trim()} strong />}
                  {delivery ? (
                    <SummaryRow label="Deliver to" value={destination?.full_address || destination?.locality || "Pinned location"} strong />
                  ) : (
                    <SummaryRow label="Pick-up Time" value={formatTime(values.pickupAt)} strong />
                  )}
                </View>
              </OrderSummary>

              <View style={styles.methods}>
                {[
                  { Icon: IconQrcode, title: "QR Ph", body: "Any bank or e-wallet app" },
                  { Icon: IconWallet, title: "GCash", body: "From your GCash account" },
                ].map(({ Icon, title, body }) => (
                  <View key={title} style={styles.method}>
                    <Icon size={20} color={colors.brand600} strokeWidth={1.8} />
                    <View style={styles.flex}>
                      <AppText family="display" weight="semibold" size={13} color={colors.authInk}>
                        {title}
                      </AppText>
                      <AppText family="display" size={11} color={colors.authMuted}>
                        {body}
                      </AppText>
                    </View>
                  </View>
                ))}
              </View>

              <Notice>
                You can cancel while your order is placed, before the store confirms it, for a full refund. Once the store confirms it, it
                can no longer be cancelled. Only the contact name and number can be changed after you pay.
              </Notice>

              <Pressable
                accessibilityRole="checkbox"
                accessibilityState={{ checked: acknowledged, disabled: placing }}
                disabled={placing}
                onPress={() => setAcknowledged((value) => !value)}
                style={styles.confirm}
                hitSlop={6}
                testID="confirm-details"
              >
                <View style={[styles.radio, acknowledged && styles.radioOn]}>
                  {acknowledged && <IconCheck size={13} color={colors.white} strokeWidth={3} />}
                </View>
                <AppText family="display" size={12.5} color={colors.authMuted} style={styles.flex}>
                  I confirm that my{" "}
                  <AppText family="display" weight="semibold" size={12.5} color={colors.orange}>
                    Order Details
                  </AppText>{" "}
                  are correct and have read the{" "}
                  <AppText family="display" weight="semibold" size={12.5} color={colors.orange}>
                    Refund Policy
                  </AppText>
                </AppText>
              </Pressable>

              <View style={styles.buttons}>
                <CheckoutButton label="Back" variant="outline" onPress={back} disabled={placing} flex={1} testID="payment-back" />
                <CheckoutButton
                  label={placing ? "Opening payment..." : "Place Order"}
                  icon={IconShoppingCart}
                  disabled={!placeReady && !placing}
                  loading={placing}
                  onPress={() => place.mutate()}
                  flex={1.7}
                  testID="place-order"
                />
              </View>

              <View style={styles.secure}>
                <IconLock size={13} color={colors.authHint} strokeWidth={2} />
                <AppText family="display" size={11} color={colors.authHint} style={styles.secureText}>
                  You pay on Xendit&apos;s secure page. Your order is held for 10 minutes while you pay and reaches the store once payment
                  goes through.
                </AppText>
              </View>
            </StepCard>
          )}

          {step !== "payment" && <OrderSummary quote={quote} loading={quoting} />}
        </ScrollView>

        {footer && (
          <View style={styles.footer}>
            <CheckoutButton
              label={footer.label}
              trailingIcon={IconChevronRight}
              disabled={!footer.ready}
              onPress={next}
              testID={footer.testID}
            />
            {!footer.ready && footer.hint ? (
              <AppText family="display" size={11.5} color={colors.authHint} style={styles.hint} testID="continue-hint">
                {footer.hint}
              </AppText>
            ) : null}
          </View>
        )}
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  flex: { flex: 1 },
  content: { paddingHorizontal: 16, paddingBottom: 24, gap: 14 },
  details: { gap: 8, marginTop: 4, paddingTop: 10, borderTopWidth: 1, borderTopColor: "#f1ece6" },
  methods: { flexDirection: "row", gap: 8 },
  method: {
    flex: 1,
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    padding: 10,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: "#f0e9df",
    backgroundColor: "#faf7f3",
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
  secure: { flexDirection: "row", alignItems: "flex-start", justifyContent: "center", gap: 5 },
  secureText: { flexShrink: 1, textAlign: "center" },
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
