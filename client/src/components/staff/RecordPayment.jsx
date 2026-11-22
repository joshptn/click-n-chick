import { useContext, useState } from "react";
import { Modal } from "@mantine/core";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { IconCheck, IconCopy } from "@tabler/icons-react";

import Button from "../ui/Button";
import Input from "../ui/Input";
import toast from "../app/Toast";
import AuthContext from "../../context/AuthContext";
import { paymentLabel } from "../../lib/agent";
import { formatPeso } from "../../lib/menu";
import { OUTCOME_PILL, REFUND_STATES, fetchPayer, formatRecordStamp, markRefunded } from "../../lib/records";
import { canOperate } from "../../lib/roles";

/** Copy Xendit's payment id, which is what its dashboard searches by. */
function XenditId({ id }) {
  const [copied, setCopied] = useState(false);

  if (!id) return null;

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(id);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1500);
    } catch {
      // Clipboard refused (an insecure page, a denied permission): the id stays selectable.
    }
  };

  return (
    <p className="m-0 flex min-w-0 items-center gap-1.5 font-display text-[11px] text-[#8d8884]">
      <span className="shrink-0">Xendit</span>
      <span className="min-w-0 select-all truncate font-mono text-[10.5px] text-[#6f6b68]" title={id}>
        {id}
      </span>
      <button
        type="button"
        onClick={copy}
        aria-label="Copy Xendit payment id"
        title={copied ? "Copied" : "Copy"}
        className="grid h-5 w-5 shrink-0 place-items-center rounded text-[#a39f9b] transition-colors hover:text-ink"
      >
        {copied ? <IconCheck size={13} stroke={2.2} /> : <IconCopy size={13} stroke={2} />}
      </button>
    </p>
  );
}

/**
 * Who paid, as Xendit reports it (decided 2026-11-23): read when the panel
 * opens, never stored. Xendit fills these only when the provider gives them.
 */
function Payer({ paymentId }) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ["orders", "payer", paymentId],
    queryFn: ({ signal }) => fetchPayer(paymentId, { signal }),
    enabled: Boolean(paymentId),
    staleTime: 5 * 60 * 1000,
    retry: false,
  });

  if (!paymentId) return null;

  if (isLoading) return <p className="m-0 font-display text-[11px] text-[#a39f9b]">Asking Xendit who paid…</p>;

  if (isError) return <p className="m-0 font-display text-[11px] text-[#a39f9b]">Xendit could not be reached for the payer.</p>;

  const payer = data?.payer;
  const parts = [payer?.name, payer?.issuer, payer?.account].filter(Boolean);

  if (parts.length === 0 && !payer?.receipt) {
    return <p className="m-0 font-display text-[11px] text-[#a39f9b]">Xendit has no payer details for this payment.</p>;
  }

  return (
    <p className="m-0 font-display text-[11px] leading-snug text-[#6f6b68]" data-testid="payer">
      {parts.join(" · ")}
      {payer?.receipt && <span className="block text-[#8d8884]">Receipt {payer.receipt}</span>}
    </p>
  );
}

function RefundRow({ refund, label, canAct, onTick }) {
  const state = REFUND_STATES[refund.state];

  if (refund.state === "none") {
    return <p className="m-0 font-display text-[11px] text-[#8d8884]">Cancelled without a refund (₱0.00).</p>;
  }

  if (!state) return null;

  return (
    <div className="flex flex-wrap items-center justify-between gap-2 rounded-[9px] bg-[#faf6f0] px-2.5 py-2" data-testid="refund-row">
      <div className="min-w-0">
        <p className="m-0 font-display text-[11.5px] text-ink">
          {label}: <span className="font-semibold">{formatPeso(refund.amount)}</span>{" "}
          <span
            className={`ml-1 inline-flex rounded-full px-2 py-[1px] align-middle text-[10px] font-semibold ring-1 ring-inset ${OUTCOME_PILL[state.tone]}`}
          >
            {state.label}
          </span>
        </p>
        {refund.state === "refunded" && (
          <p className="m-0 mt-0.5 font-display text-[10.5px] text-[#8d8884]">
            {refund.refunded_at ? formatRecordStamp(refund.refunded_at) : ""}
            {refund.reference ? ` · Ref ${refund.reference}` : ""}
          </p>
        )}
        {refund.state === "by_hand" && (
          <p className="m-0 mt-0.5 font-display text-[10.5px] text-[#8d8884]">
            {refund.method === "GCASH" ? "Xendit could not refund it. Send it by hand." : "QR Ph: send it by hand."}
          </p>
        )}
      </div>

      {refund.state === "by_hand" && canAct && (
        <Button size="sm" onClick={() => onTick(refund)} data-testid="mark-refunded">
          <IconCheck size={14} stroke={2.4} aria-hidden="true" />
          Mark as refunded
        </Button>
      )}
    </div>
  );
}

/** Ticking a refund by hand (decided 2026-11-23): a confirmation, with an optional reference. */
function TickModal({ refund, onClose }) {
  const queryClient = useQueryClient();
  const [reference, setReference] = useState("");

  const tick = useMutation({
    mutationFn: () => markRefunded(refund.payment_id, reference.trim()),
    onSuccess: (payload) => {
      toast.success(payload?.message ?? "Marked as refunded.", "Refunded");
      queryClient.invalidateQueries({ queryKey: ["orders"] });
      onClose();
    },
    onError: (error) => toast.error(error?.message ?? "That could not be saved. Please try again.", "Not saved"),
  });

  return (
    <Modal opened={Boolean(refund)} onClose={tick.isPending ? () => {} : onClose} title="Mark as refunded?" centered radius="md">
      {refund && (
        <>
          <p className="m-0 font-display text-[13px] leading-relaxed text-[#6f6b68]">
            Mark {formatPeso(refund.amount)} as refunded? Do this only once you have sent the money to the customer.
          </p>

          <label className="mt-4 block font-display text-[12px] font-semibold text-ink" htmlFor="refund-reference">
            Reference number (optional)
          </label>
          <Input
            id="refund-reference"
            className="mt-1.5"
            value={reference}
            maxLength={100}
            onChange={(event) => setReference(event.target.value)}
            placeholder="e.g. the GCash or bank reference"
          />

          <div className="mt-5 flex justify-end gap-2">
            <Button variant="ghost" size="sm" onClick={onClose} disabled={tick.isPending}>
              Not yet
            </Button>
            <Button size="sm" loading={tick.isPending} loadingLabel="Saving&hellip;" onClick={() => tick.mutate()}>
              Mark as refunded
            </Button>
          </div>
        </>
      )}
    </Modal>
  );
}

/**
 * The record's payment (decided 2026-11-23): the payment that paid for the
 * order, its refund, and any extra payment with its own. Only a Store Agent
 * ticks a refund by hand; the Store Manager reads.
 */
function RecordPayment({ record }) {
  const { user } = useContext(AuthContext);
  const canAct = canOperate(user);
  const [ticking, setTicking] = useState(null);

  const payment = record.payment ?? {};
  const paid = payment.status === "paid";
  const extras = payment.extra_payments ?? [];

  return (
    <div className="flex flex-col gap-1.5">
      <p className="m-0 mt-1 font-display text-[11px] text-[#8d8884]">
        {paid ? "Paid" : "Not paid"}
        {payment.method && ` · ${paymentLabel({ payment_method: payment.method })}`}
        {payment.paid_at && ` · ${formatRecordStamp(payment.paid_at)}`}
      </p>

      {paid && <XenditId id={payment.xendit_payment_id} />}
      {paid && <Payer paymentId={payment.id} />}

      {payment.refund && (
        <RefundRow refund={payment.refund} label="Refund" canAct={canAct} onTick={setTicking} />
      )}

      {!payment.refund && record.status === "cancelled" && paid && (
        <p className="m-0 font-display text-[11px] text-[#8d8884]">No refund recorded for this cancellation.</p>
      )}

      {extras.map((extra) => (
        <div key={extra.id} className="mt-1 flex flex-col gap-1 border-t border-dashed border-[#ece7e0] pt-2" data-testid="extra-payment">
          <p className="m-0 font-display text-[11px] text-[#8d8884]">
            Paid again
            {extra.method && ` · ${paymentLabel({ payment_method: extra.method })}`}
            {extra.paid_at && ` · ${formatRecordStamp(extra.paid_at)}`}
          </p>
          <XenditId id={extra.xendit_payment_id} />
          {extra.refund && <RefundRow refund={extra.refund} label="Extra payment refund" canAct={canAct} onTick={setTicking} />}
        </div>
      ))}

      <TickModal refund={ticking} onClose={() => setTicking(null)} />
    </div>
  );
}

export default RecordPayment;
