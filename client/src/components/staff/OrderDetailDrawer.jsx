import { Drawer } from "@mantine/core";
import {
  IconArrowRight,
  IconCircleCheck,
  IconClock,
  IconMapPin,
  IconNote,
  IconPhone,
  IconUser,
} from "@tabler/icons-react";

import { Line, Money, Section } from "./DetailParts";
import { advanceLabel, handoverLabel, paymentLabel } from "../../lib/agent";
import { formatTime } from "../../lib/orders";

/**
 * Everything needed to fulfil one order: who, where, what, and what it cost.
 *
 * Read from the board's own data by id, so it stays current as broadcasts move
 * the order along and closes itself onto a notice if the order leaves the board.
 */
function OrderDetailDrawer({ order, opened, onClose, busy, canAct, onAdvance, onReject, onCancel }) {
  const delivery = order?.order_type === "delivery";
  const finished = ["completed", "delivered", "cancelled"].includes(order?.status);
  const forward = order ? advanceLabel(order) : null;
  const mapUrl =
    order?.delivery?.latitude && order?.delivery?.longitude
      ? `https://www.google.com/maps/search/?api=1&query=${order.delivery.latitude},${order.delivery.longitude}`
      : null;

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
            <span className="font-display text-[15px] font-bold text-brand-600">{order.queue_label ?? `#${order.id}`}</span>
            <span className="rounded-full bg-[#f4f1ec] px-2 py-0.5 font-display text-[10.5px] font-semibold text-[#5f5b58]">
              {handoverLabel(order) ?? order.status_label}
            </span>
          </span>
        ) : (
          <span className="font-display text-[15px] font-semibold">Order</span>
        )
      }
      styles={{ header: { padding: "14px 20px", borderBottom: "1px solid #f3ede4" }, body: { padding: 0 } }}
    >
      {!order ? (
        <p className="m-0 px-5 py-10 text-center font-display text-[13px] text-[#8d8884]">
          This order is not on today&rsquo;s board.
        </p>
      ) : (
        <div className="flex min-h-[calc(100dvh-70px)] flex-col">
          <div className="flex-1">
            <Section title="Customer">
              <div className="flex flex-col gap-2">
                <Line icon={IconUser}>
                  {order.customer?.name || "Customer"}
                  {order.customer?.is_guest && (
                    <span className="ml-1.5 rounded bg-[#f4f1ec] px-1.5 text-[10.5px] font-semibold text-[#8d8884]">
                      Guest
                    </span>
                  )}
                </Line>
                {order.customer?.phone && (
                  <Line icon={IconPhone}>
                    <a href={`tel:${order.customer.phone}`} className="text-brand-600 no-underline hover:underline">
                      {order.customer.phone}
                    </a>
                  </Line>
                )}
                {order.details_confirmed_at ? (
                  <Line icon={IconCircleCheck}>
                    <span className="text-[#2f9e44]">Customer confirmed their details</span>
                  </Line>
                ) : (
                  !finished && (
                    <Line icon={IconClock}>
                      <span className="text-[#8d8884]">The customer may still edit their details</span>
                    </Line>
                  )
                )}
              </div>
            </Section>

            <Section title={delivery ? "Delivery" : "Pickup"}>
              <div className="flex flex-col gap-2">
                {delivery ? (
                  <>
                    <Line icon={IconMapPin}>
                      {order.delivery?.full_address || order.delivery?.location || "No address on file"}
                      {order.delivery?.distance_km != null && (
                        <span className="text-[#8d8884]"> · {Number(order.delivery.distance_km).toFixed(1)} km</span>
                      )}
                      {mapUrl && (
                        <a
                          href={mapUrl}
                          target="_blank"
                          rel="noreferrer"
                          className="ml-1.5 text-[12px] font-semibold text-brand-600 no-underline hover:underline"
                        >
                          Open map
                        </a>
                      )}
                    </Line>
                    {order.delivery?.note && <Line icon={IconNote}>{order.delivery.note}</Line>}
                  </>
                ) : (
                  <Line icon={IconClock}>
                    {order.pickup_at ? `Collecting at ${formatTime(order.pickup_at)}` : "Collecting as soon as it is ready"}
                  </Line>
                )}
                <Line icon={IconClock}>
                  <span className="text-[#6f6b68]">
                    Placed {formatTime(order.created_at)}
                    {order.waiting_minutes != null && !finished && ` · waiting ${order.waiting_minutes} min`}
                    {order.queue_position ? ` · #${order.queue_position} in line` : ""}
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
                {Number(order.discount_amount) > 0 && <Money label="Discount" value={order.discount_amount} negative />}
                {delivery && <Money label="Delivery fee" value={order.delivery_fee} />}
                <div className="mt-1 border-t border-dashed border-[#ece7e0] pt-2">
                  <Money label="Total" value={order.total_amount ?? order.subtotal} strong />
                </div>
                <p className="m-0 mt-1 font-display text-[11px] text-[#8d8884]">
                  {paymentLabel(order)}
                  {order.payment_method && ` · ${order.payment_status === "paid" ? "Paid" : order.payment_status ?? "Pending"}`}
                </p>
              </div>
            </Section>

            {order.cancellation_reason && (
              <Section title="Cancellation">
                <p className="m-0 font-display text-[13px] text-[#6f6b68]">{order.cancellation_reason}</p>
              </Section>
            )}
          </div>

          {!finished && canAct && (
            <div className="sticky bottom-0 flex flex-col gap-2 border-t border-[#f3ede4] bg-white px-5 py-3 text-[13px]">
              {order.status === "placed" ? (
                <div className="grid grid-cols-2 gap-2">
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => onAdvance(order)}
                    className="h-[36px] rounded-[9px] bg-[#2f9e44] font-display font-semibold text-white transition-colors hover:bg-[#278a3a] disabled:opacity-55"
                  >
                    Accept
                  </button>
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => onReject(order)}
                    className="h-[36px] rounded-[9px] bg-[#e5322d] font-display font-semibold text-white transition-colors hover:bg-[#cc2a25] disabled:opacity-55"
                  >
                    Reject
                  </button>
                </div>
              ) : (
                <>
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
                    className="h-[34px] rounded-[9px] border border-[#ffd7d5] bg-white font-display font-semibold text-[#e5322d] transition-colors hover:bg-[#fff1f1] disabled:opacity-55"
                  >
                    Cancel order
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

export default OrderDetailDrawer;
