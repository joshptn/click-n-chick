import { useState } from "react";
import { Link } from "react-router-dom";
import { IconBike, IconCalendarEvent, IconChevronDown, IconPhotoOff, IconShoppingBag, IconUser } from "@tabler/icons-react";

import { thumbnailImage } from "../../lib/images";
import { formatPeso } from "../../lib/menu";
import { cardMoneyNote, cardStatusLabel, formatTime, itemCount, statusBadgeClass } from "../../lib/orders";
import { STORE_TIMEZONE, storeDate } from "../../lib/records";

/** "Nov 25, 2026" in the store's timezone. */
function formatDate(iso) {
  return new Date(iso).toLocaleDateString("en-PH", { timeZone: STORE_TIMEZONE, month: "short", day: "numeric", year: "numeric" });
}

/** "Placed · 11:45 AM" today, "Placed · Nov 25, 2026 · 11:45 AM" on another day. */
function stamp(label, iso) {
  if (!iso) return label;

  const day = new Intl.DateTimeFormat("en-CA", { timeZone: STORE_TIMEZONE }).format(new Date(iso));

  return day === storeDate() ? `${label} · ${formatTime(iso)}` : `${label} · ${formatDate(iso)} · ${formatTime(iso)}`;
}

/** "Sat, Nov 28 · 5:00 PM" */
function formatCollection(iso) {
  const when = new Date(iso);

  return `${when.toLocaleDateString("en-PH", { timeZone: STORE_TIMEZONE, weekday: "short", month: "short", day: "numeric" })} · ${formatTime(iso)}`;
}

function advanceStanding(order) {
  if (order.status === "submitted") return "Not accepted yet";
  if (order.status === "rejected") return "Declined";
  if (order.is_cancelled) return "Cancelled";

  return "Accepted";
}

/**
 * One order on My Orders (the user's design, 2026-11-26): what kind it is,
 * where or when, its status and total; the dishes, which fold away with the
 * chevron; and who receives it, with Track order.
 */
function OrderCard({ order, defaultOpen = true }) {
  const [open, setOpen] = useState(defaultOpen);

  const isAdvance = Boolean(order.is_advance);
  const isDelivery = order.order_type === "delivery";
  const items = order.items ?? [];
  const Icon = isAdvance ? IconCalendarEvent : isDelivery ? IconBike : IconShoppingBag;

  const title = isAdvance ? "Advance Order" : isDelivery ? "Delivery" : "Pickup";
  const subtitle = isAdvance ? advanceStanding(order) : itemCount(items);

  const headline = isAdvance
    ? order.scheduled_for
      ? `For ${formatCollection(order.scheduled_for)}`
      : "Collection date to follow"
    : isDelivery
      ? order.delivery?.full_address || "Delivery address"
      : order.pickup?.at
        ? `Pickup at ${formatTime(order.pickup.at)}`
        : "Pickup time not set";

  const contact = [order.contact?.name, order.contact?.phone].filter(Boolean).join(" · ");
  const note = cardMoneyNote(order);
  const listId = `order-${order.id}-items`;

  return (
    <article
      className="overflow-hidden rounded-[18px] border border-[#f6d9bf] bg-white shadow-[0_10px_26px_-20px_rgba(120,60,10,0.45)]"
      data-testid="order-card"
    >
      <header className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-3 px-4 py-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)_minmax(0,1fr)] sm:px-5">
        <div className="col-start-1 row-start-1 flex min-w-0 items-center gap-3">
          <span className="grid h-11 w-11 shrink-0 place-items-center rounded-[12px] bg-[#fff1e3] text-brand-600">
            <Icon size={20} stroke={2} aria-hidden="true" />
          </span>
          <div className="min-w-0">
            <p className="m-0 font-display text-[9.5px] font-bold uppercase tracking-[0.12em] text-[#8d8884]">Fulfilment</p>
            <p className="m-0 font-display text-[16px] font-extrabold leading-tight text-ink">{title}</p>
            <p className="m-0 font-display text-[11.5px] text-[#a39f9b]">{subtitle}</p>
          </div>
        </div>

        <div className="col-span-2 row-start-2 min-w-0 text-left sm:col-span-1 sm:col-start-2 sm:row-start-1 sm:text-center">
          <p className="m-0 line-clamp-2 font-display text-[14px] font-bold leading-snug text-[#5f5b58]" data-testid="order-card-headline">
            {headline}
          </p>
          <p className="m-0 mt-0.5 font-display text-[11.5px] font-semibold text-ink">
            {stamp(isAdvance ? "Requested" : "Placed", order.placed_at)}
          </p>
        </div>

        {/* Both side columns are the same width, so the middle sits in the middle of the card. */}
        <div className="col-start-2 row-start-1 flex items-center gap-2 justify-self-end sm:col-start-3">
          <div className="text-right">
            <span
              className={`inline-block rounded-full px-2.5 py-0.5 font-display text-[10.5px] font-bold uppercase tracking-[0.04em] ${statusBadgeClass(order)}`}
              data-testid="order-card-status"
            >
              {cardStatusLabel(order)}
            </span>
            <p className="m-0 mt-1 font-display text-[16px] font-extrabold text-ink">{formatPeso(order.total)}</p>
          </div>

          <button
            type="button"
            onClick={() => setOpen((prev) => !prev)}
            aria-expanded={open}
            aria-controls={listId}
            aria-label={open ? "Hide the dishes" : "Show the dishes"}
            data-testid="order-card-toggle"
            className="grid h-8 w-8 shrink-0 place-items-center rounded-full text-[#a39f9b] transition-colors hover:bg-[#faf6f0] hover:text-ink"
          >
            <IconChevronDown size={18} stroke={2.2} className={`transition-transform duration-200 ${open ? "rotate-180" : ""}`} />
          </button>
        </div>
      </header>

      {open && (
        <ul id={listId} className="m-0 list-none border-t border-[#f3ede4] px-4 py-1 sm:px-5" data-testid="order-card-items">
          {items.map((item) => (
            <li key={item.id} className="flex items-center gap-3 border-b border-[#f6f1ea] py-3 last:border-b-0">
              <span className="h-12 w-12 shrink-0 overflow-hidden rounded-[10px] bg-[#f4f1ec]">
                {item.thumbnail ? (
                  <img {...thumbnailImage(item.thumbnail, 48)} alt="" className="h-full w-full object-cover" />
                ) : (
                  <span className="grid h-full w-full place-items-center text-[#c9c2b8]">
                    <IconPhotoOff size={16} stroke={1.8} aria-hidden="true" />
                  </span>
                )}
              </span>
              <div className="min-w-0 flex-1">
                <p className="m-0 font-display text-[13px] font-bold leading-snug text-ink [overflow-wrap:anywhere]">
                  {item.food_name}
                  {item.addons?.length > 0 && (
                    <span className="font-semibold text-[#6f6b68]"> + {item.addons.map((addon) => addon.addon_name).join(", ")}</span>
                  )}
                </p>
                <p className="m-0 font-display text-[11.5px] text-[#a39f9b]">Qty {item.quantity}</p>
              </div>
              <span className="shrink-0 font-display text-[13px] font-bold text-ink">{formatPeso(item.subtotal)}</span>
            </li>
          ))}
        </ul>
      )}

      <footer className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-3 border-t border-[#f3ede4] bg-[#fdfaf6] px-4 py-3.5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)_minmax(0,1fr)] sm:px-5">
        <div className="col-start-1 row-start-1 min-w-0">
          {note && <p className="m-0 truncate font-display text-[10.5px] uppercase tracking-[0.04em] text-[#8d8884]">{note}</p>}
          <p className="m-0 font-display text-[14px] font-extrabold text-ink">Total: {formatPeso(order.total)}</p>
        </div>

        <div className="col-span-2 row-start-2 min-w-0 sm:col-span-1 sm:col-start-2 sm:row-start-1 sm:text-center">
          {contact && (
            <>
              <p className="m-0 flex items-center gap-1.5 truncate font-display text-[13px] font-bold text-[#5f5b58] sm:justify-center">
                <IconUser size={14} stroke={2.2} aria-hidden="true" className="shrink-0 text-[#a39f9b]" />
                <span className="truncate">{contact}</span>
              </p>
              <p className="m-0 font-display text-[10.5px] font-bold uppercase tracking-[0.06em] text-ink">
                {isDelivery ? "Recipient" : "Pickup person"}
              </p>
            </>
          )}
        </div>

        <Link
          to={`/orders/${order.id}`}
          className="col-start-2 row-start-1 inline-flex h-[40px] justify-self-end items-center justify-center rounded-[10px] bg-brand-500 px-5 font-display text-[13px] font-semibold text-white no-underline shadow-[0_6px_14px_-6px_rgba(255,139,43,0.6)] transition-colors hover:bg-brand-600 sm:col-start-3"
          data-testid="order-card-track"
        >
          Track order
        </Link>
      </footer>
    </article>
  );
}

export default OrderCard;
