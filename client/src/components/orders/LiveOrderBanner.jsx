import { Link } from "react-router-dom";
import { IconChevronRight, IconShoppingBag } from "@tabler/icons-react";

/** "Order 007 · Preparing. Track it live." The orange card on Home, the app's design. */
function LiveOrderBanner({ order, to, className = "" }) {
  return (
    <Link
      to={to}
      data-testid="active-order"
      className={`flex items-center gap-3 rounded-[16px] bg-brand-500 px-4 py-3.5 text-white no-underline shadow-[0_6px_16px_-4px_rgba(255,139,43,0.5)] transition-colors hover:bg-brand-600 ${className}`}
    >
      <span className="grid h-10 w-10 shrink-0 place-items-center rounded-[12px] bg-white/20">
        <IconShoppingBag size={20} stroke={2} aria-hidden="true" />
      </span>

      <span className="min-w-0 flex-1">
        <span className="block font-display text-[14px] font-semibold">{order.title}</span>
        <span className="block truncate font-display text-[12px] text-white/90">
          {order.status_label}. Track it live.
        </span>
      </span>

      <span className="flex shrink-0 items-center gap-0.5 rounded-[10px] bg-white/20 px-3 py-1.5 font-display text-[12.5px] font-semibold">
        Track
        <IconChevronRight size={14} stroke={2.4} aria-hidden="true" />
      </span>
    </Link>
  );
}

export default LiveOrderBanner;
