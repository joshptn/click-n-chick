import {
  IconClock,
  IconMapPin,
  IconPhone,
  IconShoppingBag,
  IconTicket,
} from "@tabler/icons-react";

import { formatDay, formatTime } from "../../lib/orders";
import { formatPeso } from "../../lib/menu";

function OrderReceipt({ order }) {
  const isDelivery = order.order_type === "delivery";
  const initials =
    (order.contact?.name ?? "")
      .split(" ")
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part.charAt(0).toUpperCase())
      .join("") || "?";

  return (
    <div className="flex flex-col gap-3.5">
      <aside className="overflow-hidden rounded-[16px] border border-[#f0e9df] bg-white">
        <div className="flex items-start gap-3 border-b border-[#f0e9df] px-5 py-4">
          <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-brand-500 font-display text-[13px] font-bold text-white">
            {initials}
          </span>

          <div className="min-w-0 flex-1">
            <p className="m-0 truncate font-display text-[14.5px] font-bold text-ink">
              {order.contact?.name || "You"}
            </p>
            <p className="m-0 font-display text-[12px] font-semibold text-brand-600">
              {isDelivery ? "Delivery destination" : "Collecting at the store"}
            </p>

            {isDelivery ? (
              <p className="m-0 mt-1 flex items-start gap-1.5 font-display text-[12px] leading-snug text-[#6f6b68]">
                <IconMapPin size={13} stroke={2} aria-hidden="true" className="mt-0.5 shrink-0" />
                <span className="min-w-0">{order.delivery?.full_address || order.delivery?.location || "—"}</span>
              </p>
            ) : (
              order.pickup?.at && (
                <p className="m-0 mt-1 flex items-center gap-1.5 font-display text-[12px] text-[#6f6b68]">
                  <IconClock size={13} stroke={2} aria-hidden="true" className="shrink-0" />
                  Pick up at {formatTime(order.pickup.at)}
                </p>
              )
            )}

            {order.contact?.phone && (
              <p className="m-0 mt-0.5 flex items-center gap-1.5 font-display text-[12px] text-[#6f6b68]">
                <IconPhone size={13} stroke={2} aria-hidden="true" className="shrink-0" />
                {order.contact.phone}
              </p>
            )}
          </div>
        </div>

        {isDelivery && order.delivery?.note && (
          <p className="m-0 border-b border-[#f0e9df] bg-[#faf7f3] px-5 py-2.5 font-display text-[12px] leading-snug text-[#6f6b68]">
            <span className="font-bold text-ink">Note:</span> {order.delivery.note}
          </p>
        )}

        <div className="px-5 py-4">
          <p className="m-0 mb-2.5 font-display text-[11px] font-bold uppercase tracking-[0.7px] text-[#a39f9b]">
            Order items
          </p>

          <ul className="m-0 list-none p-0">
            {order.items?.map((line) => (
              <li
                key={line.id}
                className="flex items-start justify-between gap-3 border-b border-[#f7f4f0] py-2.5 last:border-b-0"
              >
                <span className="min-w-0 flex-1">
                  <span className="block font-display text-[13.5px] font-semibold leading-snug text-ink">
                    {line.food_name}
                  </span>
                  <span className="block font-display text-[11.5px] text-[#8d8884]">
                    {line.quantity}&times;
                    {line.addons?.length > 0 &&
                      ` (${line.addons.map((addon) => addon.addon_name).join(", ")})`}
                  </span>
                </span>

                <span className="shrink-0 font-display text-[13.5px] font-semibold text-brand-600">
                  {formatPeso(line.subtotal)}
                </span>
              </li>
            ))}
          </ul>
        </div>

        <div className="space-y-2 border-t border-[#f0e9df] px-5 py-3.5">
          <div className="flex items-center justify-between gap-3 font-display text-[12.5px] text-[#6f6b68]">
            <span>Subtotal</span>
            <span className="font-semibold text-ink">{formatPeso(order.subtotal)}</span>
          </div>

          {isDelivery && (
            <div className="flex items-center justify-between gap-3 font-display text-[12.5px] text-[#6f6b68]">
              <span>Delivery fee</span>
              <span className="font-semibold text-ink">{formatPeso(order.delivery_fee)}</span>
            </div>
          )}

          {order.discount_amount > 0 && (
            <div className="flex items-center justify-between gap-3 font-display text-[12.5px] font-semibold text-brand-600">
              <span>Discount</span>
              <span>&minus;{formatPeso(order.discount_amount)}</span>
            </div>
          )}
        </div>

        <div className="px-5 pb-5">
          <div className="flex items-center justify-between gap-3 rounded-[12px] bg-[#fff4e8] px-4 py-3">
            <span className="font-display text-[13.5px] font-bold text-ink">Total amount</span>
            <span className="font-display text-[19px] font-extrabold leading-none text-brand-600">
              {formatPeso(order.total)}
            </span>
          </div>
        </div>
      </aside>

      {!isDelivery && order.queue?.label && (
        <aside className="flex items-center gap-3 rounded-[16px] border border-[#f0e9df] bg-white px-5 py-4">
          <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-[#fff4e8] text-brand-600">
            <IconTicket size={19} stroke={2} aria-hidden="true" />
          </span>

          <div className="min-w-0">
            <p className="m-0 font-display text-[11px] font-bold uppercase tracking-[0.7px] text-[#a39f9b]">
              Your number at the counter
            </p>
            <p className="m-0 font-display text-[18px] font-extrabold leading-tight text-ink">
              {order.queue.label}
            </p>
          </div>
        </aside>
      )}

      {order.closed_at ? (
        <aside className="flex items-center justify-between gap-3 rounded-[16px] border border-[#f0e9df] bg-white px-5 py-4">
          <span className="flex min-w-0 items-center gap-3">
            <span
              className={`grid h-9 w-9 shrink-0 place-items-center rounded-full ${
                order.is_cancelled ? "bg-[#fdecec] text-[#c92a2a]" : "bg-[#e9f8ee] text-[#2f9e44]"
              }`}
            >
              <IconShoppingBag size={17} stroke={2} aria-hidden="true" />
            </span>

            <span className="min-w-0">
              <span className="block font-display text-[12.5px] text-[#6f6b68]">{order.status_label}</span>
              <span className="block font-display text-[15px] font-bold leading-tight text-ink">
                {formatTime(order.closed_at)}
              </span>
            </span>
          </span>

          <span className="shrink-0 text-right">
            <span className="block font-display text-[12px] text-[#a39f9b]">
              {formatDay(order.closed_at)}
            </span>
            <span className="block font-display text-[12px] font-semibold text-[#6f6b68]">
              {formatDay(order.closed_at, { relative: false })}
            </span>
          </span>
        </aside>
      ) : (
        order.placed_at && (
          <p className="m-0 text-center font-display text-[11.5px] text-[#a39f9b]">
            Placed {formatTime(order.placed_at)} &middot; {formatDay(order.placed_at)}
          </p>
        )
      )}
    </div>
  );
}

export default OrderReceipt;
