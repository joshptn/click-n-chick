import { useEffect, useState } from "react";
import { IconClockHour4, IconCreditCard, IconLock } from "@tabler/icons-react";

import Button from "../ui/Button";
import { formatPeso } from "../../lib/menu";
import { timeLeft } from "../../lib/payments";

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
 * The order is waiting on the customer's payment (FR-04.5).
 *
 * An immediate order counts down its 10 minutes; an advance order shows the
 * time its 24 hours run out. Either way the button opens Xendit's page again,
 * the same one if it is still open.
 */
function PaymentPanel({ order, onPay, isOpening, isChecking }) {
  const [now, setNow] = useState(() => Date.now());
  const advance = Boolean(order.is_advance);

  useEffect(() => {
    if (advance) return undefined;

    const timer = window.setInterval(() => setNow(Date.now()), 1000);

    return () => window.clearInterval(timer);
  }, [advance]);

  const remaining = advance ? null : timeLeft(order.payment_due_at, now);
  const lapsed = !advance && remaining === null;

  return (
    <section className="rounded-[16px] border border-[#ffd8a8] bg-[#fff8ef] px-5 py-5 sm:px-6">
      <div className="flex items-start gap-3">
        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-brand-500 text-white">
          <IconCreditCard size={19} stroke={2} aria-hidden="true" />
        </span>

        <div className="min-w-0 flex-1">
          <p className="m-0 font-display text-[15px] font-extrabold text-ink">
            {lapsed ? "Checking your payment…" : `Pay ${formatPeso(order.total)} to place your order`}
          </p>

          <p className="m-0 mt-1 font-display text-[12.5px] leading-snug text-[#6f6b68]">
            {advance
              ? "Your collection date is locked in once payment goes through."
              : "The store sees your order as soon as payment goes through. If it is not paid in time it is cancelled, and nothing is charged."}
          </p>

          {(remaining || advance) && (
            <p className="m-0 mt-2.5 inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 font-display text-[12.5px] font-bold text-brand-700">
              <IconClockHour4 size={14} stroke={2.2} aria-hidden="true" />
              {advance ? `Pay by ${payBy(order.payment_due_at) ?? "the deadline"}` : `${remaining} left to pay`}
            </p>
          )}
        </div>
      </div>

      <Button
        size="md"
        className="mt-4 w-full"
        loading={isOpening}
        loadingLabel="Opening payment&hellip;"
        disabled={lapsed || isChecking}
        onClick={onPay}
      >
        <IconLock size={16} stroke={2.2} aria-hidden="true" />
        Pay now with QR Ph or GCash
      </Button>

      <p className="m-0 mt-2 text-center font-display text-[11.5px] text-[#a39f9b]">
        Already paid? This page updates by itself in a few seconds.
      </p>
    </section>
  );
}

export default PaymentPanel;
