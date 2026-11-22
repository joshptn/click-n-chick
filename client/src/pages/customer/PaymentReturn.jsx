import { useContext, useEffect, useState } from "react";
import { Link, Navigate, useSearchParams } from "react-router-dom";
import { useMutation, useQuery } from "@tanstack/react-query";
import {
  IconCircleCheck,
  IconClockHour4,
  IconCreditCard,
  IconDeviceMobile,
  IconLock,
  IconReceiptRefund,
  IconToolsKitchen2,
} from "@tabler/icons-react";

import AppHeader from "../../components/app/AppHeader";
import Button from "../../components/ui/Button";
import AuthContext from "../../context/AuthContext";
import toast from "../../components/app/Toast";
import { formatPeso } from "../../lib/menu";
import {
  checkPayment,
  clearPaymentReturn,
  goToPayment,
  payOrder,
  paymentReturnTarget,
  timeLeft,
  trackingPath,
} from "../../lib/payments";

const POLL_MS = 5 * 1000;

function Shell({ children }) {
  return (
    <div className="min-h-dvh bg-[#fdfaf6] font-display text-ink">
      <AppHeader />
      <main className="mx-auto w-full max-w-[520px] px-4 py-10 sm:px-6">{children}</main>
    </div>
  );
}

function Card({ icon, tone = "brand", title, children }) {
  const Icon = icon;
  const ring = tone === "muted" ? "bg-[#f4f1ec] text-[#8d8884]" : "bg-brand-500 text-white";

  return (
    <section className="rounded-[16px] border border-[#f0e9df] bg-white px-6 py-8 text-center">
      <span className={`mx-auto grid h-[58px] w-[58px] place-items-center rounded-full ${ring}`}>
        <Icon size={27} stroke={2} aria-hidden="true" />
      </span>
      <h1 className="m-0 mt-4 font-display text-[22px] font-extrabold leading-tight text-ink">{title}</h1>
      {children}
    </section>
  );
}

/** The app's own address (app.json "scheme"); it opens on its payment screen. */
const APP_RETURN_LINK = "clicknchick://payment-return";

/**
 * Back from Xendit to the native app (decided 2026-11-19). The tab knows no
 * order, so it only hands over to the app, which checks the payment itself.
 */
function AppReturn({ cancelled }) {
  return (
    <main className="mx-auto flex min-h-dvh w-full max-w-[520px] items-center bg-[#fdfaf6] px-4 py-10 font-display text-ink">
      <section className="w-full rounded-[16px] border border-[#f0e9df] bg-white px-6 py-8 text-center">
        <span
          className={`mx-auto grid h-[58px] w-[58px] place-items-center rounded-full ${
            cancelled ? "bg-[#f4f1ec] text-[#8d8884]" : "bg-[#e9f8ee] text-[#2f9e44]"
          }`}
        >
          {cancelled ? (
            <IconClockHour4 size={28} stroke={2} aria-hidden="true" />
          ) : (
            <IconCircleCheck size={30} stroke={2} aria-hidden="true" />
          )}
        </span>

        <h1 className="m-0 mt-4 font-display text-[22px] font-extrabold leading-tight text-ink">
          {cancelled ? "Payment not completed" : "Return to BES Click n Chick"}
        </h1>

        <p className="m-0 mx-auto mt-2 max-w-[360px] font-display text-[13.5px] leading-relaxed text-[#6f6b68]">
          {cancelled
            ? "Nothing was charged. Return to the app to try again before your time to pay runs out."
            : "Your payment has been submitted. Return to the app to check your order status."}
        </p>

        <a
          href={APP_RETURN_LINK}
          className="mt-6 flex h-[48px] w-full items-center justify-center gap-2 rounded-[12px] bg-brand-500 font-display text-[15px] font-semibold text-white no-underline transition-colors hover:bg-brand-600"
        >
          <IconDeviceMobile size={18} stroke={2.2} aria-hidden="true" />
          Open BES App
        </a>

        <p className="m-0 mt-3 font-display text-[11.5px] text-[#a39f9b]">
          Or close this page to go back to the app.
        </p>
      </section>
    </main>
  );
}

/**
 * Where Xendit sends the customer back, paid or not (decided 2026-11-17).
 *
 * Paid: on to the order. Not paid yet: a "finish paying" screen that is not
 * an order - no number, no status, no tracking, because an unpaid checkout is
 * not one - only the amount, the time left and the way back to Xendit's page.
 * The address names no order (a guest's token is never given to Xendit); the
 * tab remembers which checkout it left from.
 */
function PaymentReturn() {
  const { token } = useContext(AuthContext);
  const [searchParams] = useSearchParams();
  const fromApp = searchParams.get("source") === "app";
  // The app's payment is the app's to check: this tab has no order to look at.
  // Read once: it is cleared from storage once the payment has gone through.
  const [target] = useState(() => (fromApp ? null : paymentReturnTarget()));
  const [now, setNow] = useState(() => Date.now());

  const { data, isLoading, isError } = useQuery({
    queryKey: ["payment-return", target?.orderId ?? target?.token ?? null],
    queryFn: ({ signal }) => checkPayment(target, { signal }),
    enabled: Boolean(target),
    refetchInterval: (query) => (query.state.data?.order?.awaiting_payment ? POLL_MS : false),
    retry: false,
  });

  const order = data?.order ?? null;
  const waiting = Boolean(order?.awaiting_payment) && !order?.is_advance;
  const settled = Boolean(data?.paid || order?.is_advance || order?.payment_status === "paid");
  const remaining = waiting ? timeLeft(order.payment_due_at, now) : null;
  // Decided 2026-11-23: a payment can go through while the order does not, so
  // the order is checked as well as the payment. "cancelled": paid, but the
  // order could not go ahead; "extra": paid twice.
  const notice = order?.payment_notice ?? null;

  useEffect(() => {
    if (!waiting) return undefined;

    const timer = window.setInterval(() => setNow(Date.now()), 1000);

    return () => window.clearInterval(timer);
  }, [waiting]);

  useEffect(() => {
    if (!data?.paid || notice?.kind === "cancelled") return;

    if (notice?.kind === "extra") toast.info(notice.body, notice.title);
    else toast.success("Your payment went through and your order is with the store.", "Order placed");
  }, [data?.paid, notice?.kind, notice?.body, notice?.title]);

  // On to the order: nothing more to come back to here.
  useEffect(() => {
    if (settled) clearPaymentReturn();
  }, [settled]);

  const reopening = useMutation({
    mutationFn: () => payOrder(target),
    onSuccess: (payload) => {
      if (payload?.payment?.url) goToPayment(payload.payment.url, target);
    },
    onError: (err) => toast.error(err?.message ?? "Payment could not be reopened. Please try again.", "Payment unavailable"),
  });

  // A payment opened from the native app comes back here inside the app's
  // browser tab; it is sent on to the app instead (decided 2026-11-19).
  if (fromApp) {
    return <AppReturn cancelled={searchParams.get("result") === "cancelled"} />;
  }

  if (!target) {
    return <Navigate to={token ? "/orders" : "/home"} replace />;
  }

  // Paid, but the order could not go ahead: said plainly, with the refund,
  // rather than "Order placed" (decided 2026-11-23).
  if (settled && notice?.kind === "cancelled") {
    return (
      <Shell>
        <Card icon={IconReceiptRefund} tone="muted" title={notice.title.replace(/\.$/, "")}>
          <p
            className="m-0 mx-auto mt-2 max-w-[380px] font-display text-[13.5px] leading-relaxed text-[#6f6b68]"
            data-testid="payment-notice"
          >
            {notice.body}
          </p>

          <Link
            to={trackingPath(target)}
            replace
            className="mt-6 flex h-[48px] w-full items-center justify-center gap-2 rounded-[12px] bg-brand-500 font-display text-[15px] font-semibold text-white no-underline transition-colors hover:bg-brand-600"
          >
            View order details
          </Link>
        </Card>
      </Shell>
    );
  }

  // A real order: paid, or an advance order, which is an order before it is paid.
  if (settled) {
    return <Navigate to={trackingPath(target)} replace />;
  }

  if (isLoading) {
    return (
      <Shell>
        <div className="h-[300px] animate-pulse rounded-[16px] bg-[#f4f1ec]" />
      </Shell>
    );
  }

  if (waiting && remaining) {
    return (
      <Shell>
        <Card icon={IconCreditCard} title="Finish paying">
          <p className="m-0 mx-auto mt-2 max-w-[360px] font-display text-[13.5px] leading-relaxed text-[#6f6b68]">
            Your payment has not gone through yet. Complete it on the payment page and your order goes to the
            store straight away.
          </p>

          <p className="m-0 mt-5 font-display text-[28px] font-extrabold text-ink">{formatPeso(order.total)}</p>

          <p className="m-0 mt-2 inline-flex items-center gap-1.5 rounded-full bg-[#fff4e8] px-3 py-1 font-display text-[12.5px] font-bold text-brand-700">
            <IconClockHour4 size={14} stroke={2.2} aria-hidden="true" />
            {remaining} left to pay
          </p>

          <Button
            size="md"
            className="mt-6 w-full"
            loading={reopening.isPending || reopening.isSuccess}
            loadingLabel="Opening payment&hellip;"
            onClick={() => reopening.mutate()}
          >
            <IconLock size={16} stroke={2.2} aria-hidden="true" />
            Return to payment
          </Button>

          <p className="m-0 mt-3 font-display text-[11.5px] text-[#a39f9b]">
            Already paid? This page moves on by itself in a few seconds.
          </p>
        </Card>
      </Shell>
    );
  }

  return (
    <Shell>
      <Card icon={IconClockHour4} tone="muted" title={isError ? "We couldn't check your payment" : "Payment time ran out"}>
        <p className="m-0 mx-auto mt-2 max-w-[360px] font-display text-[13.5px] leading-relaxed text-[#6f6b68]">
          {isError
            ? target.token
              ? "Please check your connection. If you paid, your tracking link is on its way to your email."
              : "Please check your connection. If you paid, your order will show under your orders."
            : "The 10 minutes to pay have passed, so nothing was charged and no order was placed."}
        </p>

        <Link
          to="/home"
          className="mt-6 flex h-[48px] w-full items-center justify-center gap-2 rounded-[12px] bg-brand-500 font-display text-[15px] font-semibold text-white no-underline transition-colors hover:bg-brand-600"
        >
          <IconToolsKitchen2 size={17} stroke={2.2} aria-hidden="true" />
          Back to the menu
        </Link>
      </Card>
    </Shell>
  );
}

export default PaymentReturn;
