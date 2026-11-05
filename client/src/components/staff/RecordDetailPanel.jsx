import { useState } from "react";
import { Skeleton } from "@mantine/core";
import { AnimatePresence, motion, useReducedMotion } from "framer-motion";
import {
  IconCalendarEvent,
  IconClock,
  IconEye,
  IconEyeOff,
  IconMail,
  IconMapPin,
  IconNote,
  IconPhone,
  IconRefresh,
  IconTicket,
} from "@tabler/icons-react";

import { Line, Money, Section } from "./DetailParts";
import { initialsOf, paymentLabel } from "../../lib/agent";
import { formatPeso } from "../../lib/menu";
import {
  OUTCOME_PILL,
  durationLabel,
  formatRecordStamp,
  maskPhone,
  recordOutcome,
  typeLabel,
} from "../../lib/records";

const MotionDiv = motion.div;

function Phone({ phone }) {
  const [shown, setShown] = useState(false);

  return (
    <span className="inline-flex items-center gap-1.5">
      <span className="tabular-nums">{shown ? phone : maskPhone(phone)}</span>
      <button
        type="button"
        onClick={() => setShown((value) => !value)}
        aria-label={shown ? "Hide phone number" : "Show phone number"}
        title={shown ? "Hide" : "Show"}
        className="grid h-5 w-5 place-items-center rounded bg-transparent text-[#a39f9b] transition-colors hover:text-ink"
      >
        {shown ? <IconEyeOff size={13} stroke={2} /> : <IconEye size={13} stroke={2} />}
      </button>
    </span>
  );
}

function PanelSkeleton() {
  return (
    <div className="flex flex-col gap-4 px-5 py-4" aria-hidden="true">
      <Skeleton height={86} radius={12} />
      <Skeleton height={14} width="40%" />
      <Skeleton height={40} />
      <Skeleton height={40} />
      <Skeleton height={60} radius={12} />
    </div>
  );
}

function TimelineRow({ label, at, children }) {
  return (
    <li className="relative flex justify-between gap-3 pl-4 font-display text-[12px] before:absolute before:left-0 before:top-[6px] before:h-[7px] before:w-[7px] before:rounded-full before:bg-[#e2dbd2]">
      <span className="text-[#6f6b68]">{label}</span>
      <span className="text-right text-ink">{children ?? formatRecordStamp(at)}</span>
    </li>
  );
}

/**
 * One closed order in full - read-only.
 *
 * The prices are the ones the order was sold at, from its own snapshot: a line's
 * unit price already includes its add-ons, and each add-on shows the price it
 * carried on the day. Nothing here acts on the order.
 */
function RecordDetailPanel({ preview, record, isLoading, isError, error, onRetry }) {
  const reduceMotion = useReducedMotion();
  const shown = record ?? preview;

  if (!shown && !isLoading && !isError) {
    return (
      <div className="grid h-full min-h-[280px] place-items-center px-6 text-center">
        <p className="m-0 font-display text-[12.5px] text-[#a39f9b]">Select an order to see its details.</p>
      </div>
    );
  }

  const outcome = shown ? recordOutcome(shown) : null;
  const delivery = shown?.order_type === "delivery";
  const name = shown?.customer?.name || "Customer";

  return (
    <div className="flex flex-col" data-testid="record-panel" data-record={shown?.id}>
      <div className="flex items-start justify-between gap-2 border-b border-[#f3ede4] px-5 py-4">
        <div className="min-w-0">
          <p className="m-0 font-display text-[11px] font-bold uppercase tracking-[0.6px] text-ink">Order details</p>
          <p className="m-0 mt-0.5 font-display text-[12.5px] font-bold text-brand-600">
            {shown?.reference ?? ""}
            {shown?.queue_label && <span className="ml-1.5 font-medium text-[#a39f9b]">· Order #{shown.id}</span>}
          </p>
        </div>
        {shown && (
          <span
            className={`shrink-0 rounded-full px-2.5 py-0.5 font-display text-[10px] font-bold ${
              shown.is_advance ? "bg-[#f3ecff] text-[#7c3aed]" : "bg-[#eef4ff] text-[#2563eb]"
            }`}
          >
            {shown.is_advance ? "Advance" : "Regular"}
          </span>
        )}
      </div>

      {isError && !record ? (
        <div className="flex flex-col items-center gap-3 px-6 py-10 text-center">
          <p className="m-0 font-display text-[13px] font-semibold text-ink">
            {error?.status === 404 ? "This order is not in the records." : "The order could not be loaded."}
          </p>
          <p className="m-0 font-display text-[12px] text-[#8d8884]">{error?.message}</p>
          {error?.status !== 404 && (
            <button
              type="button"
              onClick={onRetry}
              className="inline-flex items-center gap-1.5 bg-transparent font-display font-semibold text-brand-600 hover:text-brand-800"
            >
              <IconRefresh size={14} stroke={2.2} />
              <span className="text-[12px]">Try again</span>
            </button>
          )}
        </div>
      ) : (
        <AnimatePresence mode="wait" initial={false}>
          <MotionDiv
            key={shown?.id ?? "loading"}
            initial={reduceMotion ? false : { opacity: 0, y: 6 }}
            animate={{ opacity: 1, y: 0 }}
            exit={reduceMotion ? undefined : { opacity: 0, y: -4 }}
            transition={{ duration: 0.16 }}
          >
            {shown && (
              <div className="px-5 pt-4">
                <div className="rounded-[14px] bg-white p-3.5 ring-1 ring-[#f0e9df]">
                  <div className="flex items-start gap-3">
                    <span className="grid h-10 w-10 shrink-0 place-items-center rounded-[10px] bg-[#7c3aed] font-display text-[14px] font-bold text-white">
                      {initialsOf(name)}
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="m-0 flex flex-wrap items-center gap-1.5 font-display text-[14px] font-bold leading-snug text-ink">
                        {name}
                        {shown.customer?.is_guest && (
                          <span className="rounded bg-[#f4f1ec] px-1.5 text-[10px] font-semibold text-[#8d8884]">Guest</span>
                        )}
                      </p>
                      <p className="m-0 font-display text-[11.5px] font-semibold text-brand-600">{typeLabel(shown)}</p>
                      {record?.contact?.email && (
                        <p className="m-0 mt-1 flex items-center gap-1.5 truncate font-display text-[11.5px] text-[#6f6b68]">
                          <IconMail size={12} stroke={1.9} className="shrink-0 text-[#a39f9b]" />
                          <span className="truncate">{record.contact.email}</span>
                        </p>
                      )}
                      {record?.contact?.phone && (
                        <p className="m-0 flex items-center gap-1.5 font-display text-[11.5px] text-[#6f6b68]">
                          <IconPhone size={12} stroke={1.9} className="shrink-0 text-[#a39f9b]" />
                          <Phone key={shown.id} phone={record.contact.phone} />
                        </p>
                      )}
                    </div>
                  </div>

                  <div
                    className={`mt-3 flex items-center justify-between gap-2 rounded-[9px] px-2.5 py-1.5 ring-1 ring-inset ${OUTCOME_PILL[outcome.tone]}`}
                    data-testid="record-outcome"
                  >
                    <span className="font-display text-[11.5px] font-semibold">{outcome.detail}</span>
                    <span className="font-display text-[10.5px] opacity-80">{formatRecordStamp(shown.closed_at)}</span>
                  </div>
                </div>
              </div>
            )}

            {isLoading && !record ? (
              <PanelSkeleton />
            ) : (
              record && (
                <>
                  {(record.cancellation_reason || record.status === "cancelled") && record.status !== "completed" && (
                    <Section title={record.status === "rejected" ? "Reason given" : "Cancellation"}>
                      <p className="m-0 font-display text-[12.5px] leading-relaxed text-ink">
                        {record.cancellation_reason || "No reason was given."}
                      </p>
                      {record.cancelled_by_name && (
                        <p className="m-0 mt-1 font-display text-[11px] text-[#8d8884]">By {record.cancelled_by_name}</p>
                      )}
                    </Section>
                  )}

                  <Section title={delivery ? "Delivery" : record.is_advance ? "Collection" : "Pickup"}>
                    <div className="flex flex-col gap-2">
                      {delivery ? (
                        <>
                          <Line icon={IconMapPin}>
                            {record.delivery?.full_address || record.delivery?.location || "No address on file"}
                            {record.delivery?.distance_km != null && (
                              <span className="text-[#8d8884]"> · {Number(record.delivery.distance_km).toFixed(1)} km</span>
                            )}
                          </Line>
                          {record.delivery?.note && <Line icon={IconNote}>{record.delivery.note}</Line>}
                        </>
                      ) : record.is_advance ? (
                        <Line icon={IconCalendarEvent}>For {formatRecordStamp(record.scheduled_for)}</Line>
                      ) : (
                        <Line icon={IconClock}>
                          {record.pickup_at ? `Collection set for ${formatRecordStamp(record.pickup_at)}` : "Collected when ready"}
                        </Line>
                      )}
                      {record.queue_label && <Line icon={IconTicket}>Ticket {record.queue_label}</Line>}
                    </div>
                  </Section>

                  <Section title={`Order items · ${record.item_count}`}>
                    <ul className="m-0 flex list-none flex-col gap-3 p-0">
                      {(record.items ?? []).map((item) => (
                        <li key={item.id} className="flex items-start justify-between gap-3" data-testid="record-item">
                          <span className="min-w-0">
                            <span className="block font-display text-[13px] font-semibold leading-snug text-ink">
                              {item.food_name}
                            </span>
                            <span className="block font-display text-[11px] text-[#8d8884]">
                              {item.quantity}× {formatPeso(item.unit_price)}
                            </span>
                            {item.addons?.map((addon) => (
                              <span key={addon.id} className="block font-display text-[11px] text-[#8d8884]">
                                + {addon.addon_name}
                                {addon.unit_price != null && ` (${formatPeso(addon.unit_price)})`}
                              </span>
                            ))}
                          </span>
                          <span className="shrink-0 font-display text-[13px] font-bold text-brand-600">
                            {formatPeso(item.subtotal)}
                          </span>
                        </li>
                      ))}
                    </ul>
                  </Section>

                  <Section title="Payment">
                    <div className="flex flex-col gap-1.5">
                      <Money label="Subtotal" value={record.subtotal} />
                      {record.discount_amount > 0 && (
                        <Money label="Senior / PWD discount" value={record.discount_amount} negative />
                      )}
                      {delivery && <Money label="Delivery fee" value={record.delivery_fee} />}
                      <div className="mt-1.5 flex items-center justify-between rounded-[12px] border border-[#ffd6b3] bg-[#fff1e6] px-3.5 py-2.5">
                        <span className="font-display text-[12px] font-bold uppercase tracking-[0.3px] text-ink">Total amount</span>
                        <span className="font-display text-[18px] font-bold text-brand-600" data-testid="record-total">
                          {formatPeso(record.total)}
                        </span>
                      </div>
                      <p className="m-0 mt-1 font-display text-[11px] text-[#8d8884]">
                        {record.payment?.status === "paid" ? "Paid" : "Not paid"}
                        {record.payment?.method && ` · ${paymentLabel({ payment_method: record.payment.method })}`}
                        {record.payment?.paid_at && ` · ${formatRecordStamp(record.payment.paid_at)}`}
                      </p>
                      {record.refund_owed > 0 ? (
                        <p className="m-0 rounded-[9px] bg-[#fdecec] px-2.5 py-1.5 font-display text-[11.5px] text-[#c4302b]">
                          Refund owed: <span className="font-semibold">{formatPeso(record.refund_owed)}</span>
                          {record.payment?.refund_status && ` · ${record.payment.refund_status}`}
                        </p>
                      ) : (
                        record.status === "cancelled" &&
                        record.payment?.status === "paid" && (
                          <p className="m-0 font-display text-[11px] text-[#8d8884]">No refund recorded for this cancellation.</p>
                        )
                      )}
                    </div>
                  </Section>

                  <Section title="Timeline">
                    <ul className="m-0 flex list-none flex-col gap-1.5 p-0">
                      <TimelineRow label="Placed" at={record.timeline?.placed_at} />
                      {record.timeline?.accepted_at && <TimelineRow label="Accepted" at={record.timeline.accepted_at} />}
                      {record.timeline?.queued_at && <TimelineRow label="Joined the line" at={record.timeline.queued_at} />}
                      <TimelineRow label={outcome.label} at={record.timeline?.closed_at} />
                      <TimelineRow label="Open for">
                        {durationLabel(record.timeline?.placed_at, record.timeline?.closed_at)}
                      </TimelineRow>
                    </ul>
                  </Section>
                </>
              )
            )}
          </MotionDiv>
        </AnimatePresence>
      )}
    </div>
  );
}

export default RecordDetailPanel;
