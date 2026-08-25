import { IconAlertTriangle } from "@tabler/icons-react";

import { FULFILMENT } from "../../lib/checkout";

function CancellationNotice({ quote }) {
  const terms = quote?.cancellation ?? null;

  if (!terms) return null;

  const isDelivery = quote?.fulfilment_type === FULFILMENT.DELIVERY;
  const freeUntil = terms.full_refund_through_label?.toLowerCase() ?? "confirmed";

  return (
    <aside className="rounded-[16px] border border-[#ffe6a8] bg-[#fff9e8] px-5 py-4">
      <p className="m-0 flex items-center gap-2 font-display text-[13px] font-bold text-[#8a6206]">
        <IconAlertTriangle size={15} stroke={2.2} aria-hidden="true" className="shrink-0" />
        Before you pay
      </p>

      <ul className="m-0 mt-2 list-disc space-y-1.5 pl-4 font-display text-[12px] leading-snug text-[#8a6206]">
        <li>
          Cancel any time while your order is <strong>{freeUntil}</strong> and you get a full refund.
        </li>
        <li>
          Once the kitchen starts cooking you can still cancel, but{" "}
          <strong>the order cannot be refunded</strong>.
        </li>
        <li>
          You can correct your {isDelivery ? "address or delivery note" : "collection time"} without
          cancelling, right up until your order leaves the kitchen.
        </li>
      </ul>
    </aside>
  );
}

export default CancellationNotice;
