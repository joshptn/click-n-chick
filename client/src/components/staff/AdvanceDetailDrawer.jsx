import { Drawer } from "@mantine/core";
import {
  IconArrowRight,
  IconBellRinging,
  IconCalendarEvent,
  IconCircleCheck,
  IconClock,
  IconHourglassHigh,
  IconPhone,
  IconUser,
} from "@tabler/icons-react";

import { Line, Money, Section } from "./DetailParts";
import {
  advanceLabel,
  advanceStage,
  closedOutcome,
  collectionCountdown,
  collectionLabel,
  formatStamp,
  reminderCoolingDown,
} from "../../lib/agent";
import { formatPeso } from "../../lib/menu";
import { formatTime } from "../../lib/orders";

const OUTCOME_TONES = {
  green: "bg-[#ecf9f0] text-[#2f9e44]",
  red: "bg-[#fdecec] text-[#e5322d]",
  amber: "bg-[#fff8e1] text-[#c98500]",
  slate: "bg-[#f4f1ec] text-[#5f5b58]",
};

/**
 * Everything about one advance order: who, when they collect, what, the money,
 * and what can be done next.
 *
 * Read from the page's own data by id, so a broadcast that moves the order
 * updates the drawer in place.
 */
function AdvanceDetailDrawer({ order, opened, onClose, busy, canDecide, onAccept, onReject, onRemind, onAdvance, onCancel }) {
  const stage = order ? advanceStage(order) : null;
  const outcome = stage === "closed" ? closedOutcome(order) : null;
  const forward = stage === "kitchen" ? advanceLabel(order) : null;
  const coolingDown = reminderCoolingDown(order);

  return (
    <Drawer
      opened={opened}
      onClose={onClose}
      position="right"
      size={390}
      radius={0}
      padding={0}
      title={
        order ? (
          <span className="flex items-center gap-2.5">
            <span className="font-display text-[15px] font-bold text-brand-600">{order.reference ?? `#${order.id}`}</span>
            <span className="rounded-full bg-[#f4f1ec] px-2 py-0.5 font-display text-[10.5px] font-semibold text-[#5f5b58]">
              {order.status_label}
            </span>
          </span>
        ) : (
          <span className="font-display text-[15px] font-semibold">Advance order</span>
        )
      }
      styles={{ header: { padding: "14px 20px", borderBottom: "1px solid #f3ede4" }, body: { padding: 0 } }}
    >
      {!order ? (
        <p className="m-0 px-5 py-10 text-center font-display text-[13px] text-[#8d8884]">
          This advance order is no longer on the list.
        </p>
      ) : (
        <div className="flex min-h-[calc(100dvh-70px)] flex-col" data-testid="advance-drawer" data-status={order.status}>
          <div className="flex-1">
            {outcome && (
              <div className={`mx-5 mt-4 rounded-[10px] px-3.5 py-2.5 font-display text-[12.5px] ${OUTCOME_TONES[outcome.tone]}`}>
                <p className="m-0 font-semibold">{outcome.label}</p>
                {order.cancellation_reason && <p className="m-0 mt-0.5 opacity-90">{order.cancellation_reason}</p>}
                {Number(order.refund_owed) > 0 && (
                  <p className="m-0 mt-0.5 opacity-90">{formatPeso(order.refund_owed)} marked for refund.</p>
                )}
                {order.closed_at && <p className="m-0 mt-0.5 text-[11px] opacity-75">{formatStamp(order.closed_at)}</p>}
              </div>
            )}

            <Section title="Customer">
              <div className="flex flex-col gap-2">
                <Line icon={IconUser}>{order.customer?.name || "Customer"}</Line>
                {order.customer?.phone && (
                  <Line icon={IconPhone}>
                    <a href={`tel:${order.customer.phone}`} className="text-brand-600 no-underline hover:underline">
                      {order.customer.phone}
                    </a>
                  </Line>
                )}
              </div>
            </Section>

            <Section title="Pickup">
              <div className="flex flex-col gap-2">
                <Line icon={IconCalendarEvent}>
                  <span className="font-semibold">{collectionLabel(order)}</span>
                  {/* "Today" and "Tomorrow" already say it. */}
                  {((order.days_until_collection ?? 0) > 1 || (order.days_until_collection ?? 0) < 0) && (
                    <span className={order.collection_passed ? "text-[#e5322d]" : "text-[#8d8884]"}>
                      {" "}
                      · {collectionCountdown(order)}
                    </span>
                  )}
                </Line>
                <Line icon={IconClock}>
                  <span className="text-[#6f6b68]">
                    Requested {formatStamp(order.created_at)}
                    {order.accepted_at && ` · accepted ${formatStamp(order.accepted_at)}`}
                  </span>
                </Line>
              </div>
            </Section>

            <Section title="Items">
              <ul className="m-0 flex list-none flex-col gap-2.5 p-0">
                {(order.items ?? []).map((item) => (
                  <li key={item.id} className="flex gap-3">
                    <span className="grid h-[22px] min-w-[22px] place-items-center rounded-md bg-brand-50 px-1 font-display text-[11px] font-bold text-brand-700">
                      {item.quantity}×
                    </span>
                    <span className="min-w-0">
                      <span className="block font-display text-[12.5px] font-semibold text-ink">{item.food_name}</span>
                      {item.addons?.length > 0 && (
                        <span className="block font-display text-[11px] text-[#8d8884]">+ {item.addons.join(", ")}</span>
                      )}
                    </span>
                  </li>
                ))}
              </ul>
            </Section>

            <Section title="Payment">
              <div className="flex flex-col gap-1.5">
                {order.subtotal != null && <Money label="Subtotal" value={order.subtotal} />}
                {Number(order.discount_amount) > 0 && <Money label="SC/PWD discount" value={order.discount_amount} negative />}
                <div className="mt-1 border-t border-dashed border-[#ece7e0] pt-2">
                  <Money label="Total" value={order.total_amount} strong />
                </div>

                <div className="mt-1.5 flex flex-col gap-1.5">
                  {order.is_paid ? (
                    <Line icon={IconCircleCheck}>
                      <span className="text-[#2f9e44]">Paid</span>
                    </Line>
                  ) : stage === "decide" ? (
                    <Line icon={IconHourglassHigh}>
                      <span className="text-[#8d8884]">Nothing is charged until the request is accepted.</span>
                    </Line>
                  ) : stage === "payment" ? (
                    <Line icon={IconHourglassHigh}>
                      <span className={order.payment_overdue ? "font-semibold text-[#e5322d]" : "text-[#c98500]"}>
                        {order.payment_overdue ? "Payment overdue" : `Pay by ${formatStamp(order.payment_due_at)}`}
                      </span>
                      <span className="text-[#8d8884]"> · unpaid after 24 hours, it cancels itself</span>
                    </Line>
                  ) : (
                    <Line icon={IconHourglassHigh}>
                      <span className="text-[#8d8884]">Unpaid</span>
                    </Line>
                  )}
                  {stage === "payment" && order.last_reminded_at && (
                    <Line icon={IconBellRinging}>
                      <span className="text-[#6f6b68]">Reminded {formatStamp(order.last_reminded_at)}</span>
                    </Line>
                  )}
                </div>
              </div>
            </Section>
          </div>

          {stage !== "closed" && (
            <div className="sticky bottom-0 flex flex-col gap-2 border-t border-[#f3ede4] bg-white px-5 py-3 text-[13px]">
              {stage === "decide" ? (
                canDecide ? (
                  <div className={`grid gap-2 ${order.collection_passed ? "grid-cols-1" : "grid-cols-2"}`}>
                    {order.collection_passed ? (
                      <p className="m-0 rounded-[10px] bg-[#fdecec] px-3 py-2 text-center font-display text-[12px] text-[#e5322d]">
                        This date has passed. Decline it so the customer can choose another.
                      </p>
                    ) : (
                      <button
                        type="button"
                        disabled={busy}
                        onClick={() => onAccept(order)}
                        className="h-[36px] rounded-[9px] bg-[#2f9e44] font-display font-semibold text-white transition-colors hover:bg-[#278a3a] disabled:opacity-55"
                      >
                        Accept request
                      </button>
                    )}
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() => onReject(order)}
                      className="h-[36px] rounded-[9px] bg-[#e5322d] font-display font-semibold text-white transition-colors hover:bg-[#cc2a25] disabled:opacity-55"
                    >
                      Decline
                    </button>
                  </div>
                ) : (
                  <p className="m-0 rounded-[10px] bg-[#faf6f0] px-3 py-2.5 text-center font-display text-[12.5px] text-[#8d8884]">
                    A Store Agent accepts or declines requests.
                  </p>
                )
              ) : (
                <>
                  {stage === "payment" && canDecide && (
                    <button
                      type="button"
                      disabled={busy || coolingDown}
                      onClick={() => onRemind(order)}
                      className="inline-flex h-[36px] items-center justify-center gap-1.5 rounded-[10px] border border-brand-500 bg-white font-display font-semibold text-brand-600 transition-colors hover:bg-brand-50 disabled:cursor-not-allowed disabled:border-[#ece7e0] disabled:text-[#a39f9b]"
                    >
                      <IconBellRinging size={15} stroke={2.1} />
                      {coolingDown ? `Remind again at ${formatTime(order.can_remind_at)}` : "Remind the customer to pay"}
                    </button>
                  )}
                  {stage === "scheduled" && order.can_start_preparing && (
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() => onAdvance(order)}
                      className="inline-flex h-[36px] items-center justify-center gap-1.5 rounded-[10px] bg-brand-500 font-display font-semibold text-white transition-colors hover:bg-brand-600 disabled:opacity-55"
                    >
                      Start Preparing
                      <IconArrowRight size={14} stroke={2.4} />
                    </button>
                  )}
                  {forward && (
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() => onAdvance(order)}
                      className="inline-flex h-[36px] items-center justify-center gap-1.5 rounded-[10px] bg-brand-500 font-display font-semibold text-white transition-colors hover:bg-brand-600 disabled:opacity-55"
                    >
                      {forward}
                      <IconArrowRight size={14} stroke={2.4} />
                    </button>
                  )}
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => onCancel(order)}
                    data-testid="advance-cancel"
                    className="h-[34px] rounded-[9px] border border-[#ffd7d5] bg-white font-display font-semibold text-[#e5322d] transition-colors hover:bg-[#fff1f1] disabled:opacity-55"
                  >
                    {stage === "payment" ? "Cancel request" : "Cancel order"}
                  </button>
                </>
              )}
            </div>
          )}
        </div>
      )}
    </Drawer>
  );
}

export default AdvanceDetailDrawer;
