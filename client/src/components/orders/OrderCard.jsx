import { Link } from "react-router-dom";
import {
  IconBike,
  IconChevronRight,
  IconShoppingBag,
  IconUsers,
} from "@tabler/icons-react";

import { formatDay, formatTime, itemsSummary, statusBadgeClass } from "../../lib/orders";
import { formatPeso } from "../../lib/menu";

function OrderCard({ order }) {
  const isDelivery = order.order_type === "delivery";
  const ahead = order.queue?.in_line ? order.queue.ahead ?? 0 : null;
  const when = order.closed_at ?? order.placed_at;

  return (
    <Link
      to={`/orders/${order.id}`}
      className="group block rounded-[16px] border border-[#f0e9df] bg-white px-5 py-4 no-underline transition-all hover:border-brand-200 hover:shadow-[0_6px_20px_-12px_rgba(65,33,17,0.25)]"
    >
      <div className="flex items-start justify-between gap-3">
        <div className="flex min-w-0 items-start gap-3">
          <span
            className={`grid h-10 w-10 shrink-0 place-items-center rounded-full ${
              order.is_terminal ? "bg-[#f4f1ec] text-[#8d8884]" : "bg-[#fff4e8] text-brand-600"
            }`}
          >
            {isDelivery ? <IconBike size={19} stroke={2} /> : <IconShoppingBag size={19} stroke={2} />}
          </span>

          <div className="min-w-0">
            <p className="m-0 font-display text-[14.5px] font-bold leading-tight text-ink">
              {order.reference}
            </p>
            <p className="m-0 mt-0.5 truncate font-display text-[12.5px] text-[#6f6b68]">
              {itemsSummary(order.items)}
            </p>
            <p className="m-0 mt-0.5 font-display text-[11.5px] text-[#a39f9b]">
              {isDelivery ? "Delivery" : "Pickup"} &middot; {formatDay(when)} &middot; {formatTime(when)}
            </p>
          </div>
        </div>

        <div className="flex shrink-0 items-center gap-2">
          <div className="text-right">
            <span
              className={`inline-block rounded-full px-3 py-1 font-display text-[11.5px] font-bold ${statusBadgeClass(order)}`}
            >
              {order.status_label}
            </span>
            <p className="m-0 mt-1.5 font-display text-[14px] font-extrabold text-ink">
              {formatPeso(order.total)}
            </p>
          </div>

          <IconChevronRight
            size={17}
            stroke={2.4}
            aria-hidden="true"
            className="mt-1 shrink-0 text-[#d3cec7] transition-colors group-hover:text-brand-500"
          />
        </div>
      </div>

      {ahead !== null && (
        <p className="m-0 mt-3 flex items-center gap-2 rounded-[10px] bg-[#faf7f3] px-3 py-2 font-display text-[12.5px] font-semibold text-ink">
          <IconUsers size={14} stroke={2.2} aria-hidden="true" className="shrink-0 text-brand-500" />
          {ahead === 0
            ? "We're starting your order now"
            : `${ahead} order${ahead === 1 ? "" : "s"} ahead of you`}
        </p>
      )}
    </Link>
  );
}

export default OrderCard;
