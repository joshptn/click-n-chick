import { useEffect, useState } from "react";
import { Modal, NumberInput } from "@mantine/core";
import { IconAlertTriangle, IconCalendarEvent, IconClockHour4, IconPhone, IconUser } from "@tabler/icons-react";

import Button from "../ui/Button";
import {
  PREPARATION_MINUTES,
  URGENT_PAY_MINUTES,
  advanceName,
  collectionLabel,
  formatStamp,
  payByIfAccepted,
} from "../../lib/agent";
import { formatPeso } from "../../lib/menu";

/**
 * Accepting an advance request (decided 2026-11-20): the order is summarised,
 * and the Store Agent says how many minutes the kitchen needs for it.
 *
 * For a collection today or tomorrow that time sets the customer's pay-by time
 * (collection less preparation time), shown live; Accept is refused once that
 * time has passed. A later date keeps the 24-hour rule, and the preparation
 * time is only kept with the order.
 */
function AcceptAdvanceModal({ order, opened, onClose, onConfirm, loading }) {
  const [minutes, setMinutes] = useState("");
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    if (opened) {
      setMinutes("");
      setNow(Date.now());
    }
  }, [opened, order?.id]);

  // The pay-by window shrinks while the modal is open.
  useEffect(() => {
    if (!opened) return undefined;

    const timer = setInterval(() => setNow(Date.now()), 30 * 1000);

    return () => clearInterval(timer);
  }, [opened]);

  const value = typeof minutes === "number" ? minutes : Number.NaN;
  const inRange = Number.isInteger(value) && value >= PREPARATION_MINUTES.min && value <= PREPARATION_MINUTES.max;
  const payBy = order ? payByIfAccepted(order, inRange ? value : Number.NaN, now) : null;
  const minutesLeft = payBy?.at ? Math.floor((payBy.at.getTime() - now) / 60000) : null;
  const passed = Boolean(payBy?.soon && minutesLeft !== null && minutesLeft <= 0);
  const urgent = Boolean(payBy?.soon && minutesLeft !== null && minutesLeft > 0 && minutesLeft <= URGENT_PAY_MINUTES);

  const items = order?.items ?? [];

  return (
    <Modal
      opened={opened}
      onClose={loading ? () => {} : onClose}
      centered
      radius="md"
      title={
        <span className="font-display text-[16px] font-bold text-ink">
          Accept {order ? `${advanceName(order)}'s request` : "request"}?
        </span>
      }
      data-testid="advance-accept-modal"
    >
      {order && (
        <div className="flex flex-col gap-4">
          <div className="rounded-[12px] bg-[#faf6f0] px-3.5 py-3 ring-1 ring-inset ring-[#f0e9df]">
            <div className="flex flex-col gap-1.5 font-display text-[12.5px] text-ink">
              <span className="flex items-center gap-2">
                <IconCalendarEvent size={15} stroke={2} className="text-brand-600" />
                <span className="font-semibold">{collectionLabel(order)}</span>
              </span>
              <span className="flex items-center gap-2">
                <IconUser size={15} stroke={2} className="[overflow-wrap:anywhere] text-[#a39f9b]" />
                {order.customer?.name || "Customer"}
              </span>
              {order.customer?.phone && (
                <span className="flex items-center gap-2">
                  <IconPhone size={15} stroke={2} className="text-[#a39f9b]" />
                  {order.customer.phone}
                </span>
              )}
            </div>

            <ul className="m-0 mt-3 flex list-none flex-col gap-1.5 border-t border-dashed border-[#e8e1d6] p-0 pt-2.5">
              {items.map((item) => (
                <li key={item.id} className="flex items-start justify-between gap-3 font-display text-[12.5px]">
                  <span className="min-w-0 text-ink">
                    <span className="[overflow-wrap:anywhere] font-semibold">{item.quantity}×</span> {item.food_name}
                    {item.addons?.length > 0 && (
                      <span className="block text-[11px] text-[#8d8884]">+ {item.addons.join(", ")}</span>
                    )}
                  </span>
                  <span className="shrink-0 text-[#6f6b68]">{formatPeso(item.subtotal)}</span>
                </li>
              ))}
            </ul>

            {Number(order.discount_amount) > 0 && (
              <div className="mt-2 flex justify-between font-display text-[12px] text-[#2f9e44]">
                <span>SC/PWD discount</span>
                <span>−{formatPeso(order.discount_amount)}</span>
              </div>
            )}
            <div className="mt-2 flex justify-between border-t border-[#e8e1d6] pt-2 font-display text-[13.5px] font-bold text-ink">
              <span>Total</span>
              <span>{formatPeso(order.total_amount)}</span>
            </div>
          </div>

          <NumberInput
            label="Preparation time (minutes)"
            description="How long the kitchen needs to prepare this order."
            placeholder="e.g. 60"
            value={minutes}
            onChange={setMinutes}
            min={PREPARATION_MINUTES.min}
            max={PREPARATION_MINUTES.max}
            allowDecimal={false}
            allowNegative={false}
            clampBehavior="none"
            radius="md"
            data-autofocus
            data-testid="advance-preparation-time"
            error={
              minutes !== "" && !inRange
                ? `Enter ${PREPARATION_MINUTES.min} to ${PREPARATION_MINUTES.max} minutes.`
                : undefined
            }
          />

          {inRange && payBy?.at && (
            <p
              className={`m-0 flex items-start gap-2 rounded-[10px] px-3 py-2.5 font-display text-[12px] leading-snug ring-1 ring-inset ${
                passed
                  ? "bg-[#fdecec] text-[#c92a2a] ring-[#ffd7d5]"
                  : urgent
                    ? "bg-[#fff8e1] text-[#9a6700] ring-[#ffe8a3]"
                    : "bg-[#f4f9ff] text-[#2557b5] ring-[#dbe8ff]"
              }`}
              data-testid="advance-pay-by"
            >
              {passed || urgent ? (
                <IconAlertTriangle size={15} stroke={2} className="mt-[1px] shrink-0" />
              ) : (
                <IconClockHour4 size={15} stroke={2} className="mt-[1px] shrink-0" />
              )}
              <span>
                {passed
                  ? `The customer would have had to pay by ${formatStamp(payBy.at.toISOString())}, which has passed. Enter a shorter time or decline the request.`
                  : payBy.soon
                    ? `The customer must pay by ${formatStamp(payBy.at.toISOString())}, or it is cancelled.${
                        urgent ? " That is a short window; they are told the minutes left." : ""
                      }`
                    : `The customer has until ${formatStamp(payBy.at.toISOString())} to pay (24 hours), or the order is cancelled.`}
              </span>
            </p>
          )}

          <div className="flex justify-end gap-2 text-[13px]">
            <Button variant="ghost" size="sm" onClick={onClose} disabled={loading}>
              Keep reviewing
            </Button>
            <button
              type="button"
              disabled={loading || !inRange || passed}
              onClick={() => onConfirm(value)}
              data-testid="advance-accept-confirm"
              className="inline-flex h-[38px] items-center justify-center rounded-[9px] bg-[#2f9e44] px-4 font-display text-[13px] font-semibold text-white transition-colors hover:bg-[#278a3a] disabled:cursor-not-allowed disabled:opacity-55"
            >
              {loading ? "Accepting…" : "Accept request"}
            </button>
          </div>
        </div>
      )}
    </Modal>
  );
}

export default AcceptAdvanceModal;
