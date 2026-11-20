import { useEffect, useState } from "react";
import { Modal, Textarea } from "@mantine/core";
import { IconCashBanknote, IconCashBanknoteOff } from "@tabler/icons-react";

import Button from "../ui/Button";
import { formatPeso } from "../../lib/menu";

const REASONS = ["Out of stock", "Kitchen is at capacity", "Outside our delivery area", "Could not reach the customer"];

const MAX_REASON = 255;

function RefundOption({ selected, onSelect, icon, title, detail }) {
  const Icon = icon;

  return (
    <button
      type="button"
      role="radio"
      aria-checked={selected}
      onClick={onSelect}
      className={`flex w-full items-start gap-3 rounded-[12px] border px-4 py-3 text-left transition-colors ${
        selected ? "border-brand-500 bg-brand-50" : "border-[#ece7e0] bg-white hover:border-brand-200"
      }`}
    >
      <Icon size={20} stroke={1.9} aria-hidden="true" className={`mt-0.5 shrink-0 ${selected ? "text-brand-600" : "text-[#8d8884]"}`} />
      <span className="min-w-0">
        <span className="block font-display text-[13.5px] font-bold text-ink">{title}</span>
        <span className="block font-display text-[12px] leading-snug text-[#6f6b68]">{detail}</span>
      </span>
    </button>
  );
}

/**
 * Reject a new order, or cancel one already in progress (BR-21a, decided
 * 2026-11-17).
 *
 * First the reason, which is required: it is the customer's only explanation.
 * Then, for a paid order, a second modal asks what happens to the money:
 * refund in full, or no refund. Nothing is preselected, so the agent always
 * chooses. An unpaid order has nothing to refund and skips that step.
 */
function CancelOrderModal({ order, mode = "reject", opened, onClose, onConfirm, loading, reasons = REASONS, description }) {
  const [reason, setReason] = useState("");
  const [step, setStep] = useState("reason");
  const [refund, setRefund] = useState(null);

  useEffect(() => {
    if (opened) {
      setReason("");
      setStep("reason");
      setRefund(null);
    }
  }, [opened, order?.id]);

  if (!order) return null;

  const isReject = mode === "reject";
  const paid = order.payment_status === "paid";
  const amount = formatPeso(order.total ?? order.total_amount ?? order.subtotal);
  // A request not accepted yet has no number: it goes by its customer.
  const label = order.reference ?? order.customer?.name ?? `#${order.id}`;
  const action = isReject ? "Reject order" : "Cancel order";
  const hasReason = reason.trim() !== "";
  const close = loading ? () => {} : onClose;

  const confirm = (refunds) => onConfirm({ reason: reason.trim(), refund: refunds });

  return (
    <>
      <Modal
        opened={opened && step === "reason"}
        onClose={close}
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
          description="Required. It is the only explanation they get."
          withAsterisk
          value={reason}
          onChange={(event) => setReason(event.currentTarget.value.slice(0, MAX_REASON))}
          autosize
          minRows={2}
          maxRows={4}
          radius="md"
        />

        {!paid && (
          <p className="m-0 mt-4 rounded-[10px] bg-[#faf6f0] px-3 py-2.5 font-display text-[12.5px] text-[#6f6b68]">
            This order has not been paid for, so there is nothing to refund.
          </p>
        )}

        <div className="mt-5 flex justify-end gap-2 text-[13px]">
          <Button variant="ghost" size="sm" onClick={onClose} disabled={loading}>
            Keep order
          </Button>
          {paid ? (
            <Button size="sm" disabled={!hasReason} onClick={() => setStep("refund")}>
              Continue
            </Button>
          ) : (
            <button
              type="button"
              disabled={loading || !hasReason}
              onClick={() => confirm(false)}
              className="inline-flex h-[38px] items-center justify-center rounded-[9px] bg-[#e5322d] px-4 font-display text-[13px] font-semibold text-white transition-colors hover:bg-[#cc2a25] disabled:cursor-not-allowed disabled:opacity-55"
            >
              {loading ? "Working…" : action}
            </button>
          )}
        </div>
      </Modal>

      <Modal opened={opened && step === "refund"} onClose={close} title="Refund this order?" centered radius="md">
        <p className="m-0 font-display text-[13px] leading-relaxed text-[#6f6b68]">
          Order {label} was paid {amount}. Choose what happens to the money before it is cancelled.
        </p>

        <div role="radiogroup" aria-label="Refund choice" className="mt-4 flex flex-col gap-2">
          <RefundOption
            selected={refund === true}
            onSelect={() => setRefund(true)}
            icon={IconCashBanknote}
            title="Refund in full"
            detail={`${amount} is marked to go back to the customer.`}
          />
          <RefundOption
            selected={refund === false}
            onSelect={() => setRefund(false)}
            icon={IconCashBanknoteOff}
            title="No refund"
            detail="The order is cancelled and the payment is kept."
          />
        </div>

        <div className="mt-5 flex justify-end gap-2 text-[13px]">
          <Button variant="ghost" size="sm" onClick={() => setStep("reason")} disabled={loading}>
            Back
          </Button>
          <button
            type="button"
            disabled={loading || refund === null}
            onClick={() => confirm(refund)}
            className="inline-flex h-[38px] items-center justify-center rounded-[9px] bg-[#e5322d] px-4 font-display text-[13px] font-semibold text-white transition-colors hover:bg-[#cc2a25] disabled:cursor-not-allowed disabled:opacity-55"
          >
            {loading ? "Working…" : action}
          </button>
        </div>
      </Modal>
    </>
  );
}

export default CancelOrderModal;
