import { useEffect, useState } from "react";
import { Link, useLocation, useNavigate, useParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Modal } from "@mantine/core";
import { IconArrowLeft, IconInfoCircle, IconMail, IconToolsKitchen2 } from "@tabler/icons-react";

import AppHeader from "../../components/app/AppHeader";
import Button from "../../components/ui/Button";
import OrderProgress from "../../components/orders/OrderProgress";
import OrderReceipt from "../../components/orders/OrderReceipt";
import EditOrderModal from "../../components/orders/EditOrderModal";
import OrderStatusPanel from "../../components/orders/OrderStatusPanel";
import PaymentPanel from "../../components/orders/PaymentPanel";
import ReviewAdvanceModal from "../../components/orders/ReviewAdvanceModal";
import TrackingLinkCard from "../../components/orders/TrackingLinkCard";
import toast from "../../components/app/Toast";
import { forgetGuestOrder, rememberGuestOrder } from "../../lib/guestOrders";
import { checkPayment, goToPayment, payOrder } from "../../lib/payments";
import {
  ORDERS_KEY,
  amendGuestOrder,
  amendOrder,
  cancelGuestOrder,
  cancelOrder,
  cancelPrompt,
  confirmDetails,
  confirmGuestReceipt,
  confirmReceipt,
  fetchGuestOrder,
  fetchOrder,
  feedbackMailto,
  guestOrderKey,
  orderKey,
  statusBadgeClass,
  withLinkDeadline,
} from "../../lib/orders";
import { useOrderChannel, usePublicOrderChannel } from "../../context/useRealtime";



function TrackingSkeleton() {
  return (
    <div className="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
      <div className="flex flex-col gap-3.5">
        <div className="h-[150px] animate-pulse rounded-[16px] bg-[#f4f1ec]" />
        <div className="h-[260px] animate-pulse rounded-[16px] bg-[#f7f4f0]" />
      </div>
      <div className="h-[420px] animate-pulse rounded-[16px] bg-[#f7f4f0]" />
    </div>
  );
}

/** An expired link says how the order ended (FR-02.13); the server's message is the body. */
const LAPSED_TITLES = {
  ORDER_COMPLETED: "Order completed",
  ORDER_CANCELLED: "Order cancelled",
  TRACKING_LINK_EXPIRED: "This link has expired",
};

function errorTitle(error) {
  const lapsed = LAPSED_TITLES[error?.payload?.error_code];

  if (lapsed) return lapsed;
  if (error?.status === 410) return "This link has expired";
  if (error?.status === 403 || error?.status === 404) return "We can't find that order";

  return "That order could not be loaded";
}

/**
 * One tracking screen for both kinds of customer.
 *
 * A guest arrives by link, so the order is named by the token after "#" in the
 * address (decided 2026-11-22: never sent to the web host) rather than by an
 * id, and the live feed comes over a public channel the response names
 * instead of a private one. Everything the screen renders - the step chain, the
 * status copy, the cancel prompt, the receipt - is the same resource either way,
 * which is what stops the two experiences drifting apart.
 */
function OrderTracking({ guest = false }) {
  const { orderId } = useParams();
  const { hash } = useLocation();
  const token = guest ? hash.replace(/^#/, "") : null;
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const queryKey = guest ? guestOrderKey(token) : orderKey(orderId);
  const backTo = guest ? "/home" : "/orders";
  const backLabel = guest ? "Back to the menu" : "Back to my orders";

  const [confirmingCancel, setConfirmingCancel] = useState(false);
  const [reviewing, setReviewing] = useState(false);
  const [editing, setEditing] = useState(false);
  const [editError, setEditError] = useState(null);

  const { data, isLoading, isError, error } = useQuery({
    queryKey,
    queryFn: async ({ signal }) => {
      if (!guest) return fetchOrder(orderId, { signal });

      const payload = await fetchGuestOrder(token, { signal });

      return { ...payload, link: withLinkDeadline(payload?.link) };
    },
    enabled: Boolean(guest ? token : orderId),
    retry: false,
  });

  const order = data?.order ?? null;
  const prompt = cancelPrompt(order);

  // Kept in this browser while the order is open, so a closed tab does not
  // lose the link (FR-02.13c); forgotten once it closes or the link is refused.
  useEffect(() => {
    if (!guest || !token || !order) return;

    if (order.is_terminal) forgetGuestOrder(token);
    else rememberGuestOrder({ token, reference: order.reference ?? null, placedAt: order.placed_at });
  }, [guest, token, order]);

  useEffect(() => {
    if (guest && token && (error?.status === 404 || error?.status === 410)) forgetGuestOrder(token);
  }, [guest, token, error]);


  const payTarget = guest ? { token } : { orderId };
  // Only an advance order is waiting for payment here: an unpaid immediate
  // checkout is not an order, and the server does not show it on this page.
  const awaitingPayment = Boolean(order?.awaiting_payment) && Boolean(order?.is_advance);

  const paymentCheck = useQuery({
    queryKey: [...queryKey, "payment"],
    queryFn: async ({ signal }) => {
      const payload = await checkPayment(payTarget, { signal });

      if (payload?.order) {
        queryClient.setQueryData(queryKey, (prev) => ({ ...prev, order: payload.order }));

        if (payload.paid) queryClient.invalidateQueries({ queryKey: ORDERS_KEY });
      }

      return payload;
    },
    // A day to pay: asked once when the page opens, which is also when the
    // customer comes back from Xendit.
    enabled: awaitingPayment,
    retry: false,
  });

  const paying = useMutation({
    mutationFn: ({ reviewed = false } = {}) => payOrder({ ...payTarget, reviewed }),
    onSuccess: (payload) => {
      if (payload?.payment?.url) {
        goToPayment(payload.payment.url, payTarget);

        return;
      }

      setReviewing(false);
      settle(payload);
      toast.success("Your payment has already gone through.", "Paid");
    },
    onError: (err) => {
      setReviewing(false);
      toast.error(err?.message ?? "Payment could not be started. Please try again.", "Payment unavailable");
      queryClient.invalidateQueries({ queryKey });
    },
  });

  // Both hooks always run, one of them with a null subject.
  const privateEvent = useOrderChannel(!guest && order ? orderId : null);
  const publicEvent = usePublicOrderChannel(guest ? (data?.channel ?? null) : null);
  const lastEvent = guest ? publicEvent : privateEvent;

  useEffect(() => {
    if (!lastEvent) return;

    queryClient.invalidateQueries({ queryKey });
    queryClient.invalidateQueries({ queryKey: ORDERS_KEY });
  }, [lastEvent, queryKey, queryClient]);

  // Decided 2026-11-23: a placed order can be cancelled once the store has had
  // its 15 minutes. Asked again then, so the Cancel button appears by itself.
  const cancelFrom = order?.cancellation?.cancel_from ?? null;

  useEffect(() => {
    if (!cancelFrom) return undefined;

    const wait = Math.max(0, new Date(cancelFrom).getTime() - Date.now()) + 1000;
    // setTimeout holds at most ~24.8 days; this wait is minutes.
    const timer = window.setTimeout(() => queryClient.invalidateQueries({ queryKey }), wait);

    return () => window.clearTimeout(timer);
  }, [cancelFrom, queryKey, queryClient]);

  // Merged rather than replaced: a guest's cached entry also holds the channel
  // name, and dropping it would silently unsubscribe them from their own order.
  const settle = (payload) => {
    if (payload?.order) {
      queryClient.setQueryData(queryKey, (prev) => ({ ...prev, order: payload.order }));
    }

    queryClient.invalidateQueries({ queryKey: ORDERS_KEY });
  };

  const cancelling = useMutation({
    mutationFn: () => (guest ? cancelGuestOrder(token) : cancelOrder(orderId)),
    onSuccess: (payload) => {
      settle(payload);
      setConfirmingCancel(false);
      toast.success(payload?.message ?? "Your order has been cancelled.", "Cancelled");
    },
    onError: (err) => {
      setConfirmingCancel(false);
      toast.error(err?.message ?? "That order could not be cancelled.", "Cancellation failed");
      queryClient.invalidateQueries({ queryKey });
    },
  });

  const amending = useMutation({
    mutationFn: (changes) => (guest ? amendGuestOrder(token, changes) : amendOrder(orderId, changes)),
    onSuccess: (payload) => {
      settle(payload);
      setEditing(false);
      setEditError(null);
      toast.success("The contact details for this order were updated.", "Saved");
    },

    onError: (err) => setEditError(err?.message ?? "That change could not be saved."),
  });

  const confirmingDetails = useMutation({
    mutationFn: () => confirmDetails(orderId),
    onSuccess: (payload) => {
      settle(payload);
      toast.success("Thanks - we'll get started.", "Details confirmed");
    },
    onError: (err) => toast.error(err?.message ?? "That could not be sent.", "Something went wrong"),
  });

  const confirming = useMutation({
    mutationFn: () => (guest ? confirmGuestReceipt(token) : confirmReceipt(orderId)),
    onSuccess: (payload) => {
      settle(payload);
      toast.success("Thanks for confirming. Enjoy your meal!", "Order received");
    },
    onError: (err) => {
      toast.error(err?.message ?? "That could not be confirmed.", "Something went wrong");
      queryClient.invalidateQueries({ queryKey });
    },
  });

  return (
    <div className="min-h-dvh bg-[#fdfaf6] font-display text-ink">
      <AppHeader />

      <main className="mx-auto w-full max-w-[1180px] px-4 py-5 sm:px-6 lg:px-8">
        <div className="mb-5 flex items-center gap-3">
          <button
            type="button"
            onClick={() => navigate(backTo)}
            aria-label={backLabel}
            className="grid h-10 w-10 shrink-0 place-items-center rounded-full border border-[#f0e9df] bg-white text-ink transition-colors hover:bg-[#f7f4f0]"
          >
            <IconArrowLeft size={18} stroke={2.2} />
          </button>

          <div className="min-w-0 flex-1">
            <h1 className="m-0 font-display text-[20px] font-extrabold leading-tight text-ink sm:text-[22px]">
              Track your order
            </h1>
            {order && (
              <p className="m-0 font-display text-[12.5px] text-[#8d8884]">{order.title ?? `Order ${order.reference}`}</p>
            )}
          </div>

          {order && (
            <span
              className={`shrink-0 rounded-full px-3.5 py-1.5 font-display text-[12px] font-bold ${statusBadgeClass(order)}`}
            >
              {order.status_label}
            </span>
          )}
        </div>

        {isLoading && <TrackingSkeleton />}

        {isError && (
          <section className="rounded-[16px] border border-[#f0e9df] bg-white px-6 py-12 text-center">
            <h2 className="m-0 font-display text-[17px] font-extrabold text-ink">{errorTitle(error)}</h2>
            <p className="m-0 mx-auto mt-1.5 max-w-[380px] font-display text-[13px] leading-relaxed text-[#6f6b68]">
              {/* A guest has no account it could "belong to", and the server's
                  wording for an expired or unknown link is already the right one. */}
              {guest && error?.payload?.message
                ? error.payload.message
                : error?.status === 403 || error?.status === 404
                  ? "It may belong to another account, or the link may be out of date."
                  : "Please check your connection and try again."}
            </p>

            <Button size="md" className="mt-5" onClick={() => navigate(backTo)}>
              {backLabel}
            </Button>
          </section>
        )}

        {order && (
          <div className="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
            <div className="flex min-w-0 flex-col gap-3.5">
              <OrderProgress
                steps={order.steps}
                stepIndex={order.step_index}
                stepCount={order.step_count}
                isCancelled={order.is_cancelled}
              />

              {awaitingPayment && (
                <PaymentPanel
                  order={order}
                  onPay={() => setReviewing(true)}
                  isOpening={paying.isPending || paying.isSuccess}
                  isChecking={paymentCheck.isFetching && !paymentCheck.data}
                />
              )}

              <OrderStatusPanel
                order={order}
                onEdit={() => {
                  setEditError(null);
                  setEditing(true);
                }}
                onCancel={() => setConfirmingCancel(true)}
                onConfirmReceipt={() => confirming.mutate()}
                onConfirmDetails={() => confirmingDetails.mutate()}
                isCancelling={cancelling.isPending}
                isConfirming={confirming.isPending}
                isConfirmingDetails={confirmingDetails.isPending}
              />
              <section className="rounded-[16px] border border-[#f0e9df] bg-white px-5 py-4 sm:px-6">
                <div className="flex items-start gap-3">
                  <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-[#fff4e8] text-brand-600">
                    <IconMail size={19} stroke={2} aria-hidden="true" />
                  </span>

                  <div className="min-w-0 flex-1">
                    <p className="m-0 font-display text-[14px] font-bold text-ink">
                      Need help, or want to tell us how it went?
                    </p>
                    <p className="m-0 mt-1 font-display text-[12.5px] leading-snug text-[#6f6b68]">
                      Concerns, complaints, suggestions, or good news &mdash; we read all of it. Your order
                      number is filled in for you.
                    </p>
                  </div>
                </div>

                <a
                  href={feedbackMailto(order)}
                  className="mt-3 flex h-[46px] w-full items-center justify-center gap-2 rounded-[10px] border border-[#ece7e0] bg-[#faf7f3] font-display text-[13.5px] font-semibold text-[#6f6b68] no-underline transition-colors hover:bg-[#f4f1ec] hover:text-ink"
                >
                  <IconMail size={16} stroke={2} aria-hidden="true" />
                  Send feedback by email
                </a>
              </section>

              {order.is_terminal && (
                <Link
                  to="/home"
                  className="flex h-[52px] w-full items-center justify-center gap-2 rounded-[12px] border border-brand-500 bg-transparent font-display text-[15px] font-semibold text-brand-600 no-underline transition-colors hover:bg-brand-50"
                >
                  <IconToolsKitchen2 size={17} stroke={2.2} aria-hidden="true" />
                  Browse the menu
                </Link>
              )}
            </div>

            <div className="flex flex-col gap-3.5 lg:sticky lg:top-[84px]">
              <OrderReceipt order={order} />

              {guest && data?.link && !order.is_terminal && (
                <TrackingLinkCard token={token} link={data.link} queryKey={queryKey} />
              )}
            </div>
          </div>
        )}
      </main>

      <ReviewAdvanceModal
        order={order}
        opened={reviewing && Boolean(order)}
        onClose={() => setReviewing(false)}
        onContinue={() => paying.mutate({ reviewed: true })}
        isOpening={paying.isPending || paying.isSuccess}
      />

      <EditOrderModal
        order={order}
        opened={editing}
        onClose={() => setEditing(false)}
        onSave={(changes) => amending.mutate(changes)}
        isSaving={amending.isPending}
        error={editError}
      />

      <Modal
        opened={confirmingCancel}
        onClose={() => setConfirmingCancel(false)}
        title={prompt.title}
        centered
        radius="md"
      >
        <p className="m-0 font-display text-[13.5px] leading-relaxed text-[#6f6b68]">{prompt.body}</p>

        {!prompt.refundable && (
          <p className="m-0 mt-2.5 flex items-start gap-2 rounded-[10px] bg-[#fff9e8] px-3 py-2.5 font-display text-[12.5px] leading-snug text-[#8a6206]">
            <IconInfoCircle size={15} stroke={2} aria-hidden="true" className="mt-px shrink-0" />
            This cannot be undone.
          </p>
        )}

        <div className="mt-5 flex justify-end gap-2">
          <Button variant="ghost" size="sm" onClick={() => setConfirmingCancel(false)} disabled={cancelling.isPending}>
            {prompt.keep}
          </Button>
          <Button
            variant="secondary"
            size="sm"
            loading={cancelling.isPending}
            loadingLabel="Working&hellip;"
            onClick={() => cancelling.mutate()}
          >
            {prompt.confirm}
          </Button>
        </div>
      </Modal>
    </div>
  );
}

export default OrderTracking;
