import { motion, useReducedMotion } from "framer-motion";
import {
  IconArrowRight,
  IconBellRinging,
  IconCalendarEvent,
  IconCircleCheckFilled,
  IconCircleX,
  IconClock,
  IconHourglassHigh,
} from "@tabler/icons-react";

import {
  advanceLabel,
  advanceStage,
  closedOutcome,
  collectionCountdown,
  collectionLabel,
  handoverLabel,
  kitchenSummary,
  paymentWindowLabel,
  reminderCoolingDown,
} from "../../lib/agent";
import { formatPeso } from "../../lib/menu";
import { formatTime } from "../../lib/orders";

const MotionArticle = motion.article;

const OUTCOME_TONES = {
  green: "text-[#2f9e44]",
  red: "text-[#e5322d]",
  amber: "text-[#c98500]",
  slate: "text-[#6f6b68]",
};

function Chip({ tone = "slate", icon, children }) {
  const Icon = icon;
  const tones = {
    slate: "bg-[#f4f1ec] text-[#6f6b68]",
    orange: "bg-[#fff1e6] text-brand-600",
    amber: "bg-[#fff8e1] text-[#c98500]",
    red: "bg-[#fdecec] text-[#e5322d]",
    blue: "bg-[#eef4ff] text-[#2563eb]",
    green: "bg-[#ecf9f0] text-[#2f9e44]",
  };

  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-display text-[9.5px] font-semibold ${tones[tone]}`}>
      {Icon && <Icon size={11} stroke={2.4} />}
      {children}
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
 * One advance order on the list.
 *
 * The whole card opens the detail drawer; the buttons stop the click there.
 * Unlike the day's board, the collection date leads, nothing carries a queue
 * number (BR-22c), and only payment moves an accepted request on (BR-16g) -
 * so the awaiting-payment card offers a reminder, never a forward step.
 */
function AdvanceOrderCard({ order, busy, canDecide, onAccept, onReject, onRemind, onAdvance, onOpen }) {
  const reduceMotion = useReducedMotion();
  const stage = advanceStage(order);
  const forward = stage === "kitchen" ? advanceLabel(order) : null;
  const outcome = stage === "closed" ? closedOutcome(order) : null;
  const coolingDown = reminderCoolingDown(order);
  const payWindow = stage === "payment" ? paymentWindowLabel(order) : null;

  return (
    <MotionArticle
      layout={!reduceMotion}
      layoutId={reduceMotion ? undefined : `advance-${order.id}`}
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
      data-testid="advance-order-card"
      data-status={order.status}
      aria-label={`Advance order ${order.reference ?? order.id}, ${order.status_label}, collecting ${collectionLabel(order)}`}
      className={`cursor-pointer rounded-[12px] border bg-white p-3 text-left shadow-[0_2px_10px_-6px_rgba(65,33,17,0.18)] outline-none transition-[border-color,box-shadow] hover:border-brand-200 hover:shadow-[0_8px_22px_-12px_rgba(65,33,17,0.3)] focus-visible:ring-2 focus-visible:ring-brand-300 ${
        stage === "decide" || (stage === "scheduled" && order.is_due_today) ? "border-[#ffd6b3]" : "border-[#f0e9df]"
      }`}
    >
      <div className="flex items-center gap-1.5">
        <span className="whitespace-nowrap font-display text-[12px] font-bold text-brand-600">
          {order.reference ?? `#${order.id}`}
        </span>
        <span className="ml-auto inline-flex items-center gap-1 whitespace-nowrap font-display text-[10px] font-medium text-[#6f6b68]">
          <IconCalendarEvent size={12} stroke={2} className="text-[#a39f9b]" />
          {collectionLabel(order)}
        </span>
      </div>

      <p className="m-0 mt-1.5 font-display text-[12.5px] font-semibold leading-snug text-ink">
        {kitchenSummary(order.items)}
      </p>

      {order.items?.some((item) => item.addons?.length) && (
        <p className="m-0 mt-0.5 font-display text-[10.5px] leading-snug text-[#8d8884]">
          + {order.items.flatMap((item) => item.addons ?? []).join(", ")}
        </p>
      )}

      <p className="m-0 mt-0.5 truncate font-display text-[10.5px] text-[#a39f9b]">{order.customer?.name || "Customer"}</p>

      <div className="mt-2 flex items-end justify-between gap-2">
        <span className="font-display text-[10.5px] text-[#a39f9b]">{order.is_paid ? "Paid" : "Unpaid"}</span>
        <span className="font-display text-[13.5px] font-bold text-ink">{formatPeso(order.total_amount)}</span>
      </div>

      <div className="mt-1.5 flex flex-wrap items-center gap-1">
        {stage === "decide" &&
          (order.collection_passed ? (
            <Chip tone="red" icon={IconCalendarEvent}>
              Date passed {collectionCountdown(order)}
            </Chip>
          ) : (
            <Chip tone="blue" icon={IconCalendarEvent}>
              Collect {collectionCountdown(order)}
            </Chip>
          ))}
        {stage === "payment" && payWindow && (
          <Chip tone={order.payment_overdue ? "red" : "amber"} icon={IconHourglassHigh}>
            {payWindow}
          </Chip>
        )}
        {stage === "payment" && order.last_reminded_at && (
          <Chip icon={IconBellRinging}>Reminded {formatTime(order.last_reminded_at)}</Chip>
        )}
        {stage === "scheduled" &&
          (order.is_due_today ? (
            <Chip tone="orange" icon={IconClock}>
              Due today
            </Chip>
          ) : order.days_until_collection < 0 ? (
            <Chip tone="red" icon={IconClock}>
              Was due {collectionCountdown(order)}
            </Chip>
          ) : (
            <Chip tone="blue" icon={IconCalendarEvent}>
              Collect {collectionCountdown(order)}
            </Chip>
          ))}
        {stage === "kitchen" && (
          <Chip tone={order.status === "ready_for_pickup" ? "blue" : "amber"}>
            {handoverLabel(order) ?? "Preparing"}
          </Chip>
        )}
        {Number(order.discount_amount) > 0 && <Chip tone="green">SC/PWD discount</Chip>}
      </div>

      {/* Sized here: Mantine's global `button { font-size: inherit }` is unlayered
          and beats a text utility on the button itself. */}
      <div className="mt-2.5 text-[12px]">
        {stage === "closed" ? (
          <p
            className={`m-0 flex items-center justify-center gap-1.5 py-1 font-display text-[11.5px] font-semibold ${OUTCOME_TONES[outcome.tone]}`}
          >
            {order.status === "completed" ? <IconCircleCheckFilled size={14} /> : <IconCircleX size={14} stroke={2.2} />}
            {outcome.label}
          </p>
        ) : stage === "decide" ? (
          canDecide ? (
            <div className={`grid gap-1.5 ${order.collection_passed ? "grid-cols-1" : "grid-cols-2"}`}>
              {/* A date that has gone can only be declined (the server refuses
                  ADVANCE_DATE_PASSED), so the customer hears back and rebooks. */}
              {!order.collection_passed && (
                <button
                  type="button"
                  disabled={busy}
                  onClick={stop(() => onAccept(order))}
                  data-testid="advance-accept"
                  className="h-[30px] rounded-[8px] bg-[#2f9e44] font-display font-semibold text-white transition-colors hover:bg-[#278a3a] disabled:cursor-not-allowed disabled:opacity-55"
                >
                  Accept
                </button>
              )}
              <button
                type="button"
                disabled={busy}
                onClick={stop(() => onReject(order))}
                data-testid="advance-decline"
                className="h-[30px] rounded-[8px] bg-[#e5322d] font-display font-semibold text-white transition-colors hover:bg-[#cc2a25] disabled:cursor-not-allowed disabled:opacity-55"
              >
                Decline
              </button>
            </div>
          ) : (
            <p className="m-0 rounded-[8px] bg-[#faf6f0] px-3 py-1.5 text-center font-display text-[10.5px] text-[#8d8884]">
              A Store Agent decides requests.
            </p>
          )
        ) : stage === "payment" ? (
          canDecide ? (
            <button
              type="button"
              disabled={busy || coolingDown}
              onClick={stop(() => onRemind(order))}
              data-testid="advance-remind"
              title={coolingDown ? `You can remind again at ${formatTime(order.can_remind_at)}` : undefined}
              className="inline-flex h-[30px] w-full items-center justify-center gap-1 rounded-[8px] border border-brand-500 bg-white font-display font-semibold text-brand-600 transition-colors hover:bg-brand-50 disabled:cursor-not-allowed disabled:border-[#ece7e0] disabled:text-[#a39f9b] disabled:hover:bg-white"
            >
              <IconBellRinging size={13} stroke={2.2} />
              {coolingDown ? `Remind again at ${formatTime(order.can_remind_at)}` : "Remind to pay"}
            </button>
          ) : (
            <p className="m-0 rounded-[8px] bg-[#faf6f0] px-3 py-1.5 text-center font-display text-[10.5px] text-[#8d8884]">
              Waiting for the customer&rsquo;s payment.
            </p>
          )
        ) : stage === "scheduled" ? (
          order.can_start_preparing ? (
            <button
              type="button"
              disabled={busy}
              onClick={stop(() => onAdvance(order))}
              data-testid="advance-start"
              className="inline-flex h-[30px] w-full items-center justify-center gap-1 rounded-[8px] bg-brand-500 font-display font-semibold text-white shadow-[0_6px_14px_-6px_rgba(255,139,43,0.6)] transition-colors hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-55"
            >
              Start Preparing
              <IconArrowRight size={13} stroke={2.4} />
            </button>
          ) : (
            <p className="m-0 rounded-[8px] bg-[#faf6f0] px-3 py-1.5 text-center font-display text-[10.5px] text-[#8d8884]">
              {order.status === "confirmed"
                ? "Payment received · being booked in"
                : `Prepared on the day · ${collectionLabel(order).split(" · ")[0]}`}
            </p>
          )
        ) : forward ? (
          <button
            type="button"
            disabled={busy}
            onClick={stop(() => onAdvance(order))}
            data-testid="advance-forward"
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

export default AdvanceOrderCard;
