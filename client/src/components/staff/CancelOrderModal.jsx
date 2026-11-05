import { useEffect, useState } from "react";
import { Checkbox, Modal, Textarea } from "@mantine/core";

import Button from "../ui/Button";
import { formatPeso } from "../../lib/menu";

const REASONS = ["Out of stock", "Kitchen is at capacity", "Outside our delivery area", "Could not reach the customer"];

const MAX_REASON = 255;

/**
 * Reject a new order, or cancel one already in progress.
 *
 * The reason is what the customer reads in their notification. A shop-side
 * cancellation refunds whatever was paid (BR-21a); only a cancellation the
 * customer asked for, by phone, may waive it - so the waiver is offered for
 * in-progress orders, never for a rejection.
 */
function CancelOrderModal({
  order,
  mode = "reject",
  opened,
  onClose,
  onConfirm,
  loading,
  reasons = REASONS,
  description,
  allowWaiver,
}) {
  const [reason, setReason] = useState("");
  const [customerAsked, setCustomerAsked] = useState(false);

  useEffect(() => {
    if (opened) {
      setReason("");
      setCustomerAsked(false);
    }
  }, [opened, order?.id]);

  if (!order) return null;

  const isReject = mode === "reject";
  const paid = order.payment_status === "paid";
  // Whether "the customer asked" may waive the refund. A page passes this when
  // its orders follow a different threshold (an advance order resting in
  // scheduled is refundable whoever asks, BR-20).
  const offersWaiver = (allowWaiver ?? !isReject) && paid;
  const refunds = paid && !(offersWaiver && customerAsked);
  const amount = formatPeso(order.total_amount ?? order.subtotal);
  const label = order.queue_label ?? `#${order.id}`;

  return (
    <Modal
      opened={opened}
      onClose={loading ? () => {} : onClose}
      title={isReject ? `Reject order ${label}?` : `Cancel order ${label}?`}
      centered
      radius="md"
    >
      <p className="m-0 font-display text-[13px] leading-relaxed text-[#6f6b68]">
        {description ??
          (isReject
            ? "The customer is told the store could not take this order."
            : "The kitchen stops on this order and the customer is told it was cancelled.")}
      </p>

      <div className="mt-4 flex flex-wrap gap-1.5 text-[12px]">
        {reasons.map((preset) => (
          <button
            key={preset}
            type="button"
            onClick={() => setReason(preset)}
            className={`rounded-full border px-3 py-1 font-display text-[12px] transition-colors ${
              reason === preset
                ? "border-brand-500 bg-brand-50 text-brand-700"
                : "border-[#ece7e0] bg-white text-[#6f6b68] hover:border-brand-200"
            }`}
          >
            {preset}
          </button>
        ))}
      </div>

      <Textarea
        mt="sm"
        label="Reason for the customer"
        description="Optional, but it is the only explanation they get."
        value={reason}
        onChange={(event) => setReason(event.currentTarget.value.slice(0, MAX_REASON))}
        autosize
        minRows={2}
        maxRows={4}
        radius="md"
      />

      {offersWaiver && (
        <Checkbox
          mt="md"
          color="orange"
          checked={customerAsked}
          onChange={(event) => setCustomerAsked(event.currentTarget.checked)}
          label="The customer asked to cancel"
          description="Only when they phoned in to call it off. No refund is recorded."
        />
      )}

      <p
        className={`m-0 mt-4 rounded-[10px] px-3 py-2.5 font-display text-[12.5px] ${
          refunds ? "bg-[#ecf9f0] text-[#2f9e44]" : "bg-[#faf6f0] text-[#6f6b68]"
        }`}
      >
        {!paid
          ? "This order has not been paid for, so there is nothing to refund."
          : refunds
            ? `${amount} will be marked for refund.`
            : "No refund will be recorded."}
      </p>

      <div className="mt-5 flex justify-end gap-2 text-[13px]">
        <Button variant="ghost" size="sm" onClick={onClose} disabled={loading}>
          Keep order
        </Button>
        <button
          type="button"
          disabled={loading}
          onClick={() => onConfirm({ reason: reason.trim(), refund: !(offersWaiver && customerAsked) })}
          className="inline-flex h-[38px] items-center justify-center rounded-[9px] bg-[#e5322d] px-4 font-display text-[13px] font-semibold text-white transition-colors hover:bg-[#cc2a25] disabled:cursor-not-allowed disabled:opacity-55"
        >
          {loading ? "Working…" : isReject ? "Reject order" : "Cancel order"}
        </button>
      </div>
    </Modal>
  );
}

export default CancelOrderModal;
