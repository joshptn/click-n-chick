import { useContext, useEffect, useState } from "react";
import { Link, Navigate } from "react-router-dom";
import { useMutation, useQuery } from "@tanstack/react-query";
import { IconClockHour4, IconCreditCard, IconLock, IconToolsKitchen2 } from "@tabler/icons-react";

import AppHeader from "../../components/app/AppHeader";
import Button from "../../components/ui/Button";
import AuthContext from "../../context/AuthContext";
import toast from "../../components/app/Toast";
import { formatPeso } from "../../lib/menu";
import { checkPayment, goToPayment, payOrder, paymentReturnTarget, timeLeft, trackingPath } from "../../lib/payments";

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
  const target = paymentReturnTarget();
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
  const remaining = waiting ? timeLeft(order.payment_due_at, now) : null;

  useEffect(() => {
    if (!waiting) return undefined;

    const timer = window.setInterval(() => setNow(Date.now()), 1000);

    return () => window.clearInterval(timer);
  }, [waiting]);

  useEffect(() => {
    if (data?.paid) toast.success("Your payment went through and your order is with the store.", "Order placed");
  }, [data?.paid]);

  const reopening = useMutation({
    mutationFn: () => payOrder(target),
    onSuccess: (payload) => {
      if (payload?.payment?.url) goToPayment(payload.payment.url, target);
    },
    onError: (err) => toast.error(err?.message ?? "Payment could not be reopened. Please try again.", "Payment unavailable"),
  });

  if (!target) {
    return <Navigate to={token ? "/orders" : "/home"} replace />;
  }

  // A real order: paid (even one owed back), or an advance order, which is an
  // order before it is paid.
  if (data?.paid || order?.is_advance || order?.payment_status === "paid") {
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
            ? "Please check your connection. If you paid, your order will show under your orders."
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
