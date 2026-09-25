import { useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Modal } from "@mantine/core";
import { IconArrowLeft, IconInfoCircle, IconMail, IconToolsKitchen2 } from "@tabler/icons-react";

import AppHeader from "../../components/app/AppHeader";
import Button from "../../components/ui/Button";
import OrderProgress from "../../components/orders/OrderProgress";
import OrderReceipt from "../../components/orders/OrderReceipt";
import EditOrderModal from "../../components/orders/EditOrderModal";
import OrderStatusPanel from "../../components/orders/OrderStatusPanel";
import toast from "../../components/app/Toast";
import {
  ORDERS_KEY,
  amendOrder,
  cancelOrder,
  cancelPrompt,
  confirmDetails,
  confirmReceipt,
  fetchOrder,
  feedbackMailto,
  orderKey,
  statusBadgeClass,
} from "../../lib/orders";
import { useOrderChannel } from "../../context/useRealtime";

const IN_LINE_POLL_MS = 30 * 1000;

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

function OrderTracking() {
  const { orderId } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const [confirmingCancel, setConfirmingCancel] = useState(false);
  const [editing, setEditing] = useState(false);
  const [editError, setEditError] = useState(null);

  const { data, isLoading, isError, error } = useQuery({
    queryKey: orderKey(orderId),
    queryFn: ({ signal }) => fetchOrder(orderId, { signal }),
    enabled: Boolean(orderId),
    retry: false,
  });

  const order = data?.order ?? null;
  const prompt = cancelPrompt(order);

  const inLine = Boolean(order?.queue?.in_line);

  useEffect(() => {
    if (!inLine) return undefined;

    const timer = window.setInterval(
      () => queryClient.invalidateQueries({ queryKey: orderKey(orderId) }),
      IN_LINE_POLL_MS
    );

    return () => window.clearInterval(timer);
  }, [inLine, orderId, queryClient]);

  const lastEvent = useOrderChannel(order ? orderId : null);

  useEffect(() => {
    if (!lastEvent) return;

    queryClient.invalidateQueries({ queryKey: orderKey(orderId) });
    queryClient.invalidateQueries({ queryKey: ORDERS_KEY });
  }, [lastEvent, orderId, queryClient]);

  const settle = (payload) => {
    if (payload?.order) {
      queryClient.setQueryData(orderKey(orderId), { order: payload.order });
    }

    queryClient.invalidateQueries({ queryKey: ORDERS_KEY });
  };

  const cancelling = useMutation({
    mutationFn: () => cancelOrder(orderId),
    onSuccess: (payload) => {
      settle(payload);
      setConfirmingCancel(false);
      toast.success(payload?.message ?? "Your order has been cancelled.", "Cancelled");
    },
    onError: (err) => {
      setConfirmingCancel(false);
      toast.error(err?.message ?? "That order could not be cancelled.", "Cancellation failed");
      queryClient.invalidateQueries({ queryKey: orderKey(orderId) });
    },
  });

  const amending = useMutation({
    mutationFn: (changes) => amendOrder(orderId, changes),
    onSuccess: (payload) => {
      settle(payload);
      setEditing(false);
      setEditError(null);
      toast.success("Your order has been updated.", "Saved");
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
    mutationFn: () => confirmReceipt(orderId),
    onSuccess: (payload) => {
      settle(payload);
      toast.success("Thanks for confirming. Enjoy your meal!", "Order received");
    },
    onError: (err) => {
      toast.error(err?.message ?? "That could not be confirmed.", "Something went wrong");
      queryClient.invalidateQueries({ queryKey: orderKey(orderId) });
    },
  });

  return (
    <div className="min-h-dvh bg-[#fdfaf6] font-display text-ink">
      <AppHeader />

      <main className="mx-auto w-full max-w-[1180px] px-4 py-5 sm:px-6 lg:px-8">
        <div className="mb-5 flex items-center gap-3">
          <button
            type="button"
            onClick={() => navigate("/orders")}
            aria-label="Back to my orders"
            className="grid h-10 w-10 shrink-0 place-items-center rounded-full border border-[#f0e9df] bg-white text-ink transition-colors hover:bg-[#f7f4f0]"
          >
            <IconArrowLeft size={18} stroke={2.2} />
          </button>

          <div className="min-w-0 flex-1">
            <h1 className="m-0 font-display text-[20px] font-extrabold leading-tight text-ink sm:text-[22px]">
              Track your order
            </h1>
            {order && (
              <p className="m-0 font-display text-[12.5px] text-[#8d8884]">Order {order.reference}</p>
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
            <h2 className="m-0 font-display text-[17px] font-extrabold text-ink">
              {error?.status === 403 || error?.status === 404
                ? "We can't find that order"
                : "That order could not be loaded"}
            </h2>
            <p className="m-0 mx-auto mt-1.5 max-w-[380px] font-display text-[13px] leading-relaxed text-[#6f6b68]">
              {error?.status === 403 || error?.status === 404
                ? "It may belong to another account, or the link may be out of date."
                : "Please check your connection and try again."}
            </p>

            <Button size="md" className="mt-5" onClick={() => navigate("/orders")}>
              Back to my orders
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

            <div className="lg:sticky lg:top-[84px]">
              <OrderReceipt order={order} />
            </div>
          </div>
        )}
      </main>

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
