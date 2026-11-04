import { motion, useReducedMotion } from "framer-motion";
import { IconArrowRight, IconCircleCheck, IconCircleCheckFilled, IconClock } from "@tabler/icons-react";

import { advanceLabel, handoverLabel, kitchenSummary, paymentLabel } from "../../lib/agent";
import { formatPeso } from "../../lib/menu";
import { formatTime } from "../../lib/orders";

const MotionArticle = motion.article;

/** A wait worth flagging: the line is first-come, first-served. */
const LONG_WAIT_MINUTES = 15;

function TypePill({ type }) {
  const delivery = type === "delivery";

  return (
    <span
      className={`shrink-0 rounded-full border px-1.5 py-[1px] font-display text-[9px] font-bold uppercase tracking-[0.4px] ${
        delivery
          ? "border-[#ffd6b3] bg-[#fff6ec] text-brand-600"
          : "border-[#c9dafd] bg-[#eef4ff] text-[#2563eb]"
      }`}
    >
      {delivery ? "Delivery" : "Pickup"}
    </span>
  );
}

function stop(handler) {
  return (event) => {
    event.stopPropagation();
    handler?.();
  };
}

/**
 * One order on the board.
 *
 * The whole card opens the detail drawer; the buttons do their own thing and
 * stop the click there. Every forward action is `advance` - the server walks
 * the order's own chain - so the card only decides what the button says.
 */
function AgentOrderCard({ order, busy, canConfirmDelivery, onAdvance, onReject, onOpen }) {
  const reduceMotion = useReducedMotion();

  const isNew = order.status === "placed";
  const isFinished = order.status === "completed" || order.status === "delivered";
  const handover = handoverLabel(order);
  const forward = advanceLabel(order);
  const blockedDelivery = order.next_status === "delivered" && !canConfirmDelivery;
  const longWait =
    ["placed", "confirmed", "preparing"].includes(order.status) && (order.waiting_minutes ?? 0) >= LONG_WAIT_MINUTES;

  const time = isFinished ? order.closed_at ?? order.created_at : order.queued_at ?? order.created_at;

  return (
    <MotionArticle
      layout={!reduceMotion}
      layoutId={reduceMotion ? undefined : `order-${order.id}`}
      initial={reduceMotion ? false : { opacity: 0, y: 8 }}
      animate={{ opacity: 1, y: 0 }}
      exit={reduceMotion ? undefined : { opacity: 0, scale: 0.97 }}
      transition={{ type: "spring", stiffness: 420, damping: 36 }}
      role="button"
      tabIndex={0}
      onClick={() => onOpen(order)}
      onKeyDown={(event) => {
        if (event.key === "Enter" || event.key === " ") {
          event.preventDefault();
          onOpen(order);
        }
      }}
      data-testid="agent-order-card"
      data-status={order.status}
      aria-label={`Order ${order.queue_label ?? order.id}, ${order.status_label}`}
      className={`cursor-pointer rounded-[12px] border bg-white p-3 text-left shadow-[0_2px_10px_-6px_rgba(65,33,17,0.18)] outline-none transition-[border-color,box-shadow] hover:border-brand-200 hover:shadow-[0_8px_22px_-12px_rgba(65,33,17,0.3)] focus-visible:ring-2 focus-visible:ring-brand-300 ${
        isNew ? "border-[#ffd6b3]" : "border-[#f0e9df]"
      }`}
    >
      <div className="flex items-center gap-1.5">
        <span className="whitespace-nowrap font-display text-[12px] font-bold text-brand-600">
          {order.queue_label ?? `#${order.id}`}
        </span>
        <TypePill type={order.order_type} />
        <span className="ml-auto whitespace-nowrap font-display text-[10px] text-[#a39f9b]">{formatTime(time)}</span>
      </div>

      <p className="m-0 mt-1.5 font-display text-[12.5px] font-semibold leading-snug text-ink">
        {kitchenSummary(order.items)}
      </p>

      {order.items?.some((item) => item.addons?.length) && (
        <p className="m-0 mt-0.5 font-display text-[10.5px] leading-snug text-[#8d8884]">
          +{" "}
          {order.items
            .flatMap((item) => item.addons ?? [])
            .join(", ")}
        </p>
      )}

      <p className="m-0 mt-0.5 flex items-center gap-1.5 font-display text-[10.5px] text-[#a39f9b]">
        <span className="truncate">{order.customer?.name || "Customer"}</span>
        {order.customer?.is_guest && (
          <span className="rounded bg-[#f4f1ec] px-1.5 text-[9px] font-semibold text-[#8d8884]">Guest</span>
        )}
      </p>

      <div className="mt-2 flex items-end justify-between gap-2">
        <span className="font-display text-[10.5px] text-[#a39f9b]">{paymentLabel(order)}</span>
        <span className="font-display text-[13.5px] font-bold text-ink">
          {formatPeso(order.total_amount ?? order.subtotal)}
        </span>
      </div>

      {(handover || longWait || (order.details_confirmed_at && !isFinished)) && (
        <div className="mt-1.5 flex flex-wrap items-center gap-1">
          {handover && (
            <span className="rounded-full bg-[#eef4ff] px-2 py-0.5 font-display text-[9.5px] font-semibold text-[#2563eb]">
              {handover}
            </span>
          )}
          {order.details_confirmed_at && !isFinished && !handover && (
            <span className="inline-flex items-center gap-1 rounded-full bg-[#ecf9f0] px-2 py-0.5 font-display text-[9.5px] font-semibold text-[#2f9e44]">
              <IconCircleCheck size={11} stroke={2.4} />
              Details confirmed
            </span>
          )}
          {longWait && (
            <span className="inline-flex items-center gap-1 rounded-full bg-[#fff8e1] px-2 py-0.5 font-display text-[9.5px] font-semibold text-[#c98500]">
              <IconClock size={11} stroke={2.4} />
              Waiting {order.waiting_minutes} min
            </span>
          )}
        </div>
      )}

      {/* Sized here: Mantine's global `button { font-size: inherit }` is unlayered
          and beats a text utility on the button itself. */}
      <div className="mt-2.5 text-[12px]">
        {isFinished ? (
          <p className="m-0 flex items-center justify-center gap-1.5 py-1 font-display text-[11.5px] font-semibold text-[#2f9e44]">
            <IconCircleCheckFilled size={14} />
            {order.status === "delivered" ? "Delivered" : "Order Completed"}
          </p>
        ) : isNew ? (
          <div className="grid grid-cols-2 gap-1.5">
            <button
              type="button"
              disabled={busy}
              onClick={stop(() => onAdvance(order))}
              className="h-[30px] rounded-[8px] bg-[#2f9e44] font-display font-semibold text-white transition-colors hover:bg-[#278a3a] disabled:cursor-not-allowed disabled:opacity-55"
            >
              Accept
            </button>
            <button
              type="button"
              disabled={busy}
              onClick={stop(() => onReject(order))}
              className="h-[30px] rounded-[8px] bg-[#e5322d] font-display font-semibold text-white transition-colors hover:bg-[#cc2a25] disabled:cursor-not-allowed disabled:opacity-55"
            >
              Reject
            </button>
          </div>
        ) : blockedDelivery ? (
          <p className="m-0 rounded-[8px] bg-[#faf6f0] px-3 py-1.5 text-center font-display text-[10.5px] text-[#8d8884]">
            The Store Agent confirms delivery.
          </p>
        ) : forward ? (
          <button
            type="button"
            disabled={busy}
            onClick={stop(() => onAdvance(order))}
            className="inline-flex h-[30px] w-full items-center justify-center gap-1 rounded-[8px] bg-brand-500 font-display font-semibold text-white shadow-[0_6px_14px_-6px_rgba(255,139,43,0.6)] transition-colors hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-55"
          >
            {forward}
            <IconArrowRight size={13} stroke={2.4} />
          </button>
        ) : null}
      </div>
    </MotionArticle>
  );
}

export default AgentOrderCard;
