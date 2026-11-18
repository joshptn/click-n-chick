import { useEffect, useMemo, useRef, useState } from "react";
import { ActivityIndicator, AppState, StyleSheet, View } from "react-native";
import { Redirect, router, useLocalSearchParams } from "expo-router";
import * as WebBrowser from "expo-web-browser";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { SafeAreaView } from "react-native-safe-area-context";

import { paymentApi, type PaymentTarget } from "../api/checkout";
import { ORDERS_KEY } from "../api/orders";
import { AppText } from "../components/AppText";
import { ScreenHeader } from "../components/account/ScreenHeader";
import { CheckoutButton } from "../components/checkout/CheckoutButton";
import { IconClock, IconClockHour4, IconCreditCard, IconLock, IconToolsKitchen2 } from "../components/icons";
import { formatPeso } from "../lib/money";
import { toast } from "../lib/toast";
import { colors } from "../theme/colors";

const POLL_MS = 5 * 1000;

/** "9:41" left, or null once the deadline has passed. */
function timeLeft(dueIso: string | null | undefined, now: number) {
  if (!dueIso) return null;

  const ms = new Date(dueIso).getTime() - now;
  if (!Number.isFinite(ms) || ms <= 0) return null;

  return `${Math.floor(ms / 60000)}:${String(Math.floor((ms % 60000) / 1000)).padStart(2, "0")}`;
}

/** Xendit's page in the in-app browser (a Chrome Custom Tab on Android). */
async function openPaymentPage(url: string) {
  await WebBrowser.openBrowserAsync(url, { showTitle: true, toolbarColor: colors.white, enableBarCollapsing: true });
}

/**
 * Paying for a checkout (phase 11; the web's PaymentReturn). Opens Xendit's
 * page and checks the payment with the API each time the customer comes back
 * to the app ("Verifying your payment..."), and every few seconds while this
 * screen is open. Xendit returns only to an HTTPS address, so an app payment
 * returns to the web's /payment/return?source=app, whose "Open BES App"
 * button brings the customer back here (clicknchick://payment-return,
 * decided 2026-11-19). The API confirms the payment, never the app.
 *
 * Paid: on to the order's tracking. Not yet: "Finish paying", with the time
 * left and the way back to Xendit's page; an unpaid checkout is not an order,
 * so nothing else is shown. Time up: nothing was charged.
 */
export default function Payment() {
  const params = useLocalSearchParams<{ order?: string; token?: string; url?: string }>();
  const queryClient = useQueryClient();
  const target = useMemo<PaymentTarget | null>(
    () => (params.token ? { token: params.token } : params.order ? { orderId: params.order } : null),
    [params.token, params.order]
  );

  const [now, setNow] = useState(() => Date.now());

  const status = useQuery({
    queryKey: ["payment", target?.orderId ?? target?.token ?? null],
    queryFn: ({ signal }) => paymentApi.status(target as PaymentTarget, signal),
    enabled: target !== null,
    retry: 1,
    refetchInterval: (query) => (query.state.data?.order.awaiting_payment ? POLL_MS : false),
  });

  // Back from Xendit's page (or from anywhere): ask straight away, and say so.
  const refetch = status.refetch;
  const [verifying, setVerifying] = useState(false);

  useEffect(() => {
    const subscription = AppState.addEventListener("change", (state) => {
      if (state !== "active") return;

      setVerifying(true);
      void refetch().finally(() => setVerifying(false));
    });

    return () => subscription.remove();
  }, [refetch]);

  // The page from checkout opens once, when this screen first appears.
  const opened = useRef(false);
  useEffect(() => {
    if (opened.current || !params.url) return;
    opened.current = true;
    void openPaymentPage(params.url).catch(() => toast.error("Payment page did not open", "Tap Return to payment to try again."));
  }, [params.url]);

  const order = status.data?.order ?? null;
  const paid = Boolean(status.data?.paid || order?.payment_status === "paid");
  const waiting = Boolean(order?.awaiting_payment) && !paid;
  const remaining = waiting ? timeLeft(order?.payment_due_at, now) : null;

  useEffect(() => {
    if (!waiting) return;
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, [waiting]);

  const announced = useRef(false);
  useEffect(() => {
    if (!paid || announced.current) return;
    announced.current = true;
    toast.success("Order placed", "Your payment went through and your order is with the store.");
    void queryClient.invalidateQueries({ queryKey: ORDERS_KEY });
    void queryClient.invalidateQueries({ queryKey: ["cart"] });
  }, [paid, queryClient]);

  const reopen = useMutation({
    mutationFn: () => paymentApi.pay(target as PaymentTarget),
    onSuccess: async (payload) => {
      if (payload.payment?.url) await openPaymentPage(payload.payment.url);
      else void refetch();
    },
    onError: (error) =>
      toast.error("Payment unavailable", error instanceof Error ? error.message : "Payment could not be reopened. Please try again."),
  });

  if (!target) return <Redirect href="/home" />;

  if (paid) {
    return <Redirect href={target.token ? `/track/${target.token}` : `/orders/${target.orderId}`} />;
  }

  const home = () => router.navigate("/home");

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right", "bottom"]}>
      <ScreenHeader title="Payment" onBack={home} />

      <View style={styles.body}>
        {status.isLoading || verifying ? (
          <View style={styles.card} testID="verifying-payment">
            <IconClock size={34} color={colors.ink500} strokeWidth={1.8} />
            <AppText family="display" weight="semibold" size={17} color={colors.authInk} style={styles.center}>
              Verifying your payment...
            </AppText>
            <AppText family="display" size={13.5} color={colors.authMuted} style={styles.center}>
              We&apos;re waiting for payment confirmation. This may take a moment.
            </AppText>
            <ActivityIndicator color={colors.orange} style={styles.spinner} />
          </View>
        ) : waiting && remaining ? (
          <View style={styles.card} testID="finish-paying">
            <View style={[styles.ring, styles.ringBrand]}>
              <IconCreditCard size={27} color={colors.white} strokeWidth={2} />
            </View>
            <AppText family="display" weight="bold" size={21} color={colors.authInk} style={styles.center}>
              Finish paying
            </AppText>
            <AppText family="display" size={13.5} color={colors.authMuted} style={styles.center}>
              Your payment has not gone through yet. Complete it on the payment page and your order goes to the store straight away.
            </AppText>
            <AppText family="display" weight="bold" size={28} color={colors.authInk} testID="payment-amount">
              {formatPeso(order?.total)}
            </AppText>
            <View style={styles.timer}>
              <IconClockHour4 size={14} color={colors.brand800} strokeWidth={2.2} />
              <AppText family="display" weight="semibold" size={12.5} color={colors.brand800}>
                {remaining} left to pay
              </AppText>
            </View>
            <View style={styles.action}>
              <CheckoutButton
                label="Return to payment"
                icon={IconLock}
                loading={reopen.isPending}
                onPress={() => reopen.mutate()}
                testID="return-to-payment"
              />
            </View>
            <AppText family="display" size={11.5} color={colors.authHint} style={styles.center}>
              Already paid? This screen moves on by itself in a few seconds.
            </AppText>
          </View>
        ) : (
          <View style={styles.card} testID="payment-ended">
            <View style={[styles.ring, styles.ringMuted]}>
              <IconClockHour4 size={27} color={colors.ink500} strokeWidth={2} />
            </View>
            <AppText family="display" weight="bold" size={21} color={colors.authInk} style={styles.center}>
              {status.isError ? "We couldn't check your payment" : "Payment time ran out"}
            </AppText>
            <AppText family="display" size={13.5} color={colors.authMuted} style={styles.center}>
              {status.isError
                ? "Please check your connection. If you paid, your order will show under your orders."
                : "The 10 minutes to pay have passed, so nothing was charged and no order was placed."}
            </AppText>
            <View style={styles.action}>
              {status.isError ? (
                <CheckoutButton label="Try again" loading={status.isFetching} onPress={() => void refetch()} testID="payment-retry" />
              ) : (
                <CheckoutButton label="Back to the menu" icon={IconToolsKitchen2} onPress={home} testID="payment-home" />
              )}
            </View>
          </View>
        )}
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  body: { flex: 1, justifyContent: "center", paddingHorizontal: 16, paddingBottom: 40 },
  card: {
    alignItems: "center",
    gap: 10,
    paddingHorizontal: 22,
    paddingVertical: 28,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: "#f0e9df",
    backgroundColor: colors.white,
  },
  center: { textAlign: "center" },
  spinner: { marginTop: 6 },
  ring: { width: 58, height: 58, borderRadius: 29, alignItems: "center", justifyContent: "center", marginBottom: 4 },
  ringBrand: { backgroundColor: colors.orange },
  ringMuted: { backgroundColor: "#f4f1ec" },
  timer: { flexDirection: "row", alignItems: "center", gap: 6, paddingHorizontal: 12, paddingVertical: 5, borderRadius: 999, backgroundColor: "#fff1e6" },
  action: { alignSelf: "stretch", marginTop: 8 },
});
