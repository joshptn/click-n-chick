import { IconAlertTriangle } from "@tabler/icons-react";

/**
 * The cancellation terms, shown before payment and agreed with a tick (BR-21c).
 * Decided 2026-11-17: cancel only before the store confirms, for a full
 * refund; only the contact name and number can change afterwards. Reworded
 * 2026-11-23: the store has 15 minutes first, and a QR Ph refund is the
 * store's to send.
 */
function CancellationNotice({ quote }) {
  if (!quote) return null;

  return (
    <aside className="rounded-[16px] border border-[#ffe6a8] bg-[#fff9e8] px-5 py-4">
      <p className="m-0 flex items-center gap-2 font-display text-[13px] font-bold text-[#8a6206]">
        <IconAlertTriangle size={15} stroke={2.2} aria-hidden="true" className="shrink-0" />
        Before you pay
      </p>

      <ul className="m-0 mt-2 list-disc space-y-1.5 pl-4 font-display text-[12px] leading-snug text-[#8a6206]">
        <li>
          If the store hasn&rsquo;t confirmed your order within <strong>15 minutes</strong> of payment, you can cancel
          it for a <strong>full refund</strong>.
        </li>
        <li>
          Once the store confirms your order, <strong>it can no longer be cancelled</strong>.
        </li>
        <li>
          Paid with GCash? A refund goes back to your GCash automatically. Paid by scanning the QR code (QR Ph), even
          from the GCash app? The store refunds you directly, so please contact the store.
        </li>
        <li>
          Only the contact name and mobile number can be changed after you pay, until your order is completed.
        </li>
      </ul>
    </aside>
  );
}

export default CancellationNotice;
