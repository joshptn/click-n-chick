import { IconClockHour4, IconCreditCard, IconLock } from "@tabler/icons-react";

import Button from "../ui/Button";
import { formatPeso } from "../../lib/menu";

function payBy(iso) {
  if (!iso) return null;

  return new Date(iso).toLocaleString("en-PH", {
    weekday: "short",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}

/**
 * An accepted advance order waiting on its payment (BR-16f). The button opens
 * Xendit's page, the same one if it is still open.
 *
 * Immediate orders never show this: an unpaid immediate checkout is not an
 * order, and its customer finishes paying on /payment/return instead.
 */
function PaymentPanel({ order, onPay, isOpening, isChecking }) {
  return (
    <section className="rounded-[16px] border border-[#ffd8a8] bg-[#fff8ef] px-5 py-5 sm:px-6">
      <div className="flex items-start gap-3">
        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-brand-500 text-white">
          <IconCreditCard size={19} stroke={2} aria-hidden="true" />
        </span>

        <div className="min-w-0 flex-1">
          <p className="m-0 font-display text-[15px] font-extrabold text-ink">
            Pay {formatPeso(order.total)} to lock in your date
          </p>

          <p className="m-0 mt-1 font-display text-[12.5px] leading-snug text-[#6f6b68]">
            Your collection date is booked once payment goes through.
          </p>

          <p className="m-0 mt-2.5 inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 font-display text-[12.5px] font-bold text-brand-700">
            <IconClockHour4 size={14} stroke={2.2} aria-hidden="true" />
            Pay by {payBy(order.payment_due_at) ?? "the deadline"}
          </p>

          <p className="m-0 mt-2 font-display text-[12px] leading-snug text-[#8d8884]">
            If it is not paid by then, the store will cancel it.
          </p>
        </div>
      </div>

      <Button
        size="md"
        className="mt-4 w-full"
        loading={isOpening}
        loadingLabel="Opening payment&hellip;"
        disabled={isChecking}
        onClick={onPay}
      >
        <IconLock size={16} stroke={2.2} aria-hidden="true" />
        Pay now with QR Ph or GCash
      </Button>
    </section>
  );
}

export default PaymentPanel;
