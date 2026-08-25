import { Modal } from "@mantine/core";
import { IconClock, IconMapPin, IconPhone, IconUser } from "@tabler/icons-react";

import Button from "../ui/Button";
import { FULFILMENT } from "../../lib/checkout";
import { formatPeso } from "../../lib/menu";

function ReviewOrderModal({ quote, opened, onClose, acknowledged, onAcknowledge, onContinue }) {
  const isDelivery = quote?.fulfilment_type === FULFILMENT.DELIVERY;

  const pickupTime = quote?.pickup?.requested_at
    ? new Date(quote.pickup.requested_at).toLocaleTimeString("en-PH", {
        hour: "numeric",
        minute: "2-digit",
      })
    : null;

  return (
    <Modal
      opened={opened}
      onClose={onClose}
      title="Is everything correct?"
      centered
      radius="md"
      size="lg"
    >
      <div className="flex flex-col gap-4 font-display">
        <label
          className={`flex cursor-pointer items-start gap-3 rounded-[12px] border-2 px-4 py-4 transition-colors ${
            acknowledged ? "border-brand-500 bg-[#fff8f1]" : "border-brand-200 bg-[#fffdfa] hover:bg-[#fff8f1]"
          }`}
        >
          <input
            type="checkbox"
            checked={acknowledged}
            onChange={(event) => onAcknowledge(event.target.checked)}
            className="mt-0.5 h-[19px] w-[19px] shrink-0 cursor-pointer accent-brand-500"
          />

          <span className="min-w-0 flex-1 text-[14px] font-semibold leading-snug text-ink">
            I have checked my order details and I understand when this order can and cannot be refunded.
          </span>
        </label>

        <div className="rounded-[12px] border border-[#f0e9df] bg-[#faf7f3] px-4 py-3.5">
          <p className="m-0 mb-2 text-[11px] font-bold uppercase tracking-[0.7px] text-[#a39f9b]">
            {isDelivery ? "Delivering to" : "Collecting at the store"}
          </p>

          <div className="flex flex-col gap-1.5">
            {isDelivery ? (
              <p className="m-0 flex items-start gap-2 text-[12.5px] leading-snug text-[#6f6b68]">
                <IconMapPin size={14} stroke={2} aria-hidden="true" className="mt-0.5 shrink-0 text-brand-500" />
                {quote?.destination?.full_address || quote?.destination?.locality || "No address set"}
              </p>
            ) : (
              pickupTime && (
                <p className="m-0 flex items-center gap-2 text-[12.5px] text-[#6f6b68]">
                  <IconClock size={14} stroke={2} aria-hidden="true" className="shrink-0 text-brand-500" />
                  Ready for {pickupTime}
                </p>
              )
            )}

            <p className="m-0 flex items-center gap-2 text-[12.5px] text-[#6f6b68]">
              <IconUser size={14} stroke={2} aria-hidden="true" className="shrink-0 text-brand-500" />
              {quote?.contact?.name || "—"}
            </p>

            <p className="m-0 flex items-center gap-2 text-[12.5px] text-[#6f6b68]">
              <IconPhone size={14} stroke={2} aria-hidden="true" className="shrink-0 text-brand-500" />
              {quote?.contact?.phone || "—"}
            </p>
          </div>
        </div>

        <div>
          <p className="m-0 mb-1 text-[11px] font-bold uppercase tracking-[0.7px] text-[#a39f9b]">
            {quote?.item_count} item{quote?.item_count === 1 ? "" : "s"}
          </p>

          <ul className="m-0 max-h-[190px] list-none overflow-y-auto p-0">
            {quote?.items?.map((line) => (
              <li
                key={line.id}
                className="flex items-start justify-between gap-3 border-b border-[#f7f4f0] py-2 last:border-b-0"
              >
                <span className="min-w-0 flex-1">
                  <span className="block text-[13.5px] font-semibold leading-snug text-ink">
                    {line.food_name}
                  </span>
                  <span className="block text-[11.5px] text-[#8d8884]">
                    {line.quantity}&times;
                    {line.addons?.length > 0 &&
                      ` (${line.addons.map((addon) => addon.addon_name).join(", ")})`}
                  </span>
                </span>

                <span className="shrink-0 text-[13.5px] font-semibold text-ink">
                  {formatPeso(line.subtotal)}
                </span>
              </li>
            ))}
          </ul>
        </div>

        <div className="flex items-center justify-between gap-3 rounded-[12px] bg-[#fff4e8] px-4 py-3">
          <span className="text-[13.5px] font-bold text-ink">Amount due</span>
          <span className="text-[19px] font-extrabold leading-none text-brand-600">
            {formatPeso(quote?.total)}
          </span>
        </div>

        <div className="mt-1 flex justify-end gap-2">
          <Button variant="ghost" size="sm" onClick={onClose}>
            Let me check
          </Button>
          <Button size="sm" disabled={!acknowledged} onClick={onContinue}>
            Continue to payment
          </Button>
        </div>
      </div>
    </Modal>
  );
}

export default ReviewOrderModal;
