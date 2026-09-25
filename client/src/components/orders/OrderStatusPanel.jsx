import { motion } from "framer-motion";
import {
  IconAlertTriangle,
  IconBike,
  IconCalendarEvent,
  IconChefHat,
  IconCircleCheck,
  IconClock,
  IconCreditCard,
  IconInfoCircle,
  IconPencil,
  IconReceipt,
  IconSend,
  IconShoppingBag,
  IconUsers,
} from "@tabler/icons-react";

import Button from "../ui/Button";
import { cancelPrompt, isRefused, queueMessage } from "../../lib/orders";
import { formatCollection } from "../../lib/advance";

const MotionSpan = motion.span;

const HERO = {
  placed: {
    icon: IconReceipt,
    title: "Order placed!",
    body: "We have your order and are confirming it with the store.",
  },
  confirmed: {
    icon: IconCircleCheck,
    title: "Order confirmed!",
    body: "The store has accepted your order. It joins the kitchen queue next.",
  },
  preparing: {
    icon: IconChefHat,
    title: "We're cooking!",
    body: "Your food is being prepared right now.",
  },
  ready_for_pickup: {
    icon: IconShoppingBag,
    title: "Ready for pickup!",
    body: "Come and collect it at the counter. Show your order number.",
  },
  on_the_way: {
    icon: IconBike,
    title: "On the way!",
    body: "Your order has left the store and is heading to you.",
  },
  completed: {
    icon: IconCircleCheck,
    title: "Order completed!",
    body: "Thanks for collecting. Enjoy your meal!",
  },
  delivered: {
    icon: IconCircleCheck,
    title: "Order delivered!",
    body: "Your food has arrived. Enjoy your meal!",
  },
  cancelled: {
    icon: IconAlertTriangle,
    title: "Order cancelled",
    body: "This order will not be prepared.",
  },
};


const ADVANCE_HERO = {
  submitted: {
    icon: IconSend,
    title: "Request sent",
    body: "A Store Agent is checking whether the kitchen can make this on your date.",
  },
  accepted: {
    icon: IconCircleCheck,
    title: "Your request was accepted!",
    body: "Pay within 24 hours to lock in your collection date.",
  },
  awaiting_payment: {
    icon: IconCreditCard,
    title: "Ready for payment",
    body: "Pay within 24 hours of the store accepting to lock in your collection date.",
  },
  confirmed: {
    icon: IconCircleCheck,
    title: "Payment received!",
    body: "Your date is booked. Nothing happens in the kitchen until then.",
  },
  scheduled: {
    icon: IconCalendarEvent,
    title: "You're booked in",
    body: "We start preparing on your collection date. There is nothing to do until then.",
  },
  rejected: {
    icon: IconAlertTriangle,
    title: "Request declined",
    body: "The store could not take this one on. Nothing was charged.",
  },
};

function OrderStatusPanel({
  order,
  onEdit,
  onCancel,
  onConfirmReceipt,
  onConfirmDetails,
  isCancelling,
  isConfirming,
  isConfirmingDetails,
}) {
  const isAdvance = Boolean(order.is_advance);
  const hero =
    (isAdvance ? ADVANCE_HERO[order.status] : null) ?? HERO[order.status] ?? HERO.placed;
  const Icon = hero.icon;
  const queue = queueMessage(order.queue);

  const cancellation = order.cancellation ?? null;
  const refundable = cancellation?.refundable ?? false;
  const prompt = cancelPrompt(order);
  const collectAt = isAdvance ? formatCollection(order.scheduled_for) : null;

  const editable = order.editable ?? {};
  const openFields = [
    editable.address && "the address",
    editable.note && "the note for the rider",
    editable.pickup_at && "your collection time",
  ].filter(Boolean);

  const anythingEditable = openFields.length > 0;
  const editableLabel =
    openFields.length === 1
      ? openFields[0]
      : `${openFields.slice(0, -1).join(", ")} or ${openFields[openFields.length - 1]}`;

  const tone = isRefused(order)
    ? { ring: "bg-[#fdecec]", dot: "bg-[#c92a2a]" }
    : order.is_terminal
      ? { ring: "bg-[#e9f8ee]", dot: "bg-[#2f9e44]" }
      : { ring: "bg-[#fff4e8]", dot: "bg-brand-500" };

  return (
    <div className="flex flex-col gap-3.5">
      <section className="overflow-hidden rounded-[16px] border border-[#f0e9df] bg-white px-5 py-9 text-center sm:px-7">
        <MotionSpan
          initial={{ scale: 0.85, opacity: 0 }}
          animate={{ scale: 1, opacity: 1 }}
          transition={{ type: "spring", stiffness: 260, damping: 20 }}
          className={`mx-auto grid h-[72px] w-[72px] place-items-center rounded-full ${tone.ring}`}
        >
          <span className={`grid h-[58px] w-[58px] place-items-center rounded-full text-white ${tone.dot}`}>
            <Icon size={28} stroke={2.2} aria-hidden="true" />
          </span>
        </MotionSpan>

        <h2 className="m-0 mt-4 font-display text-[24px] font-extrabold leading-tight text-ink sm:text-[27px]">
          {hero.title}
        </h2>

        <p className="m-0 mx-auto mt-1.5 max-w-[440px] font-display text-[13.5px] leading-relaxed text-[#6f6b68]">
          {isRefused(order) && order.cancellation_reason ? order.cancellation_reason : hero.body}
        </p>

        {collectAt && !isRefused(order) && (
          <div className="mx-auto mt-5 flex max-w-[360px] items-center gap-3 rounded-[12px] bg-[#fff4e8] px-4 py-3 text-left">
            <IconCalendarEvent size={19} stroke={2} aria-hidden="true" className="shrink-0 text-brand-600" />
            <span className="min-w-0">
              <span className="block font-display text-[10.5px] font-bold uppercase tracking-[0.7px] text-brand-700/70">
                Collection
              </span>
              <span className="block font-display text-[13.5px] font-bold text-ink">{collectAt}</span>
            </span>
          </div>
        )}

        {queue && (
          <div className="mx-auto mt-5 flex max-w-[360px] items-center gap-3 rounded-[12px] bg-[#faf7f3] px-4 py-3 text-left">
            <IconUsers size={19} stroke={2} aria-hidden="true" className="shrink-0 text-brand-500" />
            <span className="min-w-0">
              <span className="block font-display text-[13.5px] font-bold text-ink">{queue.headline}</span>
              <span className="block font-display text-[12px] leading-snug text-[#6f6b68]">{queue.detail}</span>
            </span>
          </div>
        )}
      </section>

      {order.can_confirm_receipt && (
        <section className="rounded-[16px] border border-[#c8ebd4] bg-[#f2fbf5] px-5 py-4">
          <p className="m-0 font-display text-[14px] font-bold text-[#2c6b3f]">Has your order arrived?</p>
          <p className="m-0 mt-1 font-display text-[12.5px] leading-snug text-[#3d7a52]">
            Let us know once you have it, so we can close the order off. No rush &mdash; the store can also
            confirm it for you.
          </p>

          <Button
            size="md"
            className="mt-3 bg-[#2f9e44] shadow-none hover:bg-[#268c3a]"
            loading={isConfirming}
            loadingLabel="Confirming&hellip;"
            onClick={onConfirmReceipt}
          >
            <IconCircleCheck size={17} stroke={2.2} aria-hidden="true" />
            Yes, I received my order
          </Button>
        </section>
      )}

      {order.can_confirm_details && (
        <section className="rounded-[16px] border border-[#f0e9df] bg-white px-5 py-4">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p className="m-0 min-w-0 font-display text-[12.5px] leading-snug text-[#6f6b68]">
              Everything correct? Tell us and we can get started sooner. You can still cancel for a full
              refund until the kitchen begins.
            </p>

            <Button
              size="sm"
              loading={isConfirmingDetails}
              loadingLabel="Sending&hellip;"
              onClick={onConfirmDetails}
              className="shrink-0"
            >
              <IconCircleCheck size={15} stroke={2.2} aria-hidden="true" />
              Details are correct
            </Button>
          </div>
        </section>
      )}

      {order.details_confirmed_at && !order.is_terminal && (
        <p className="m-0 flex items-center justify-center gap-1.5 font-display text-[12px] text-[#2f9e44]">
          <IconCircleCheck size={13} stroke={2.2} aria-hidden="true" />
          You confirmed these details. The kitchen can start any time.
        </p>
      )}

      {anythingEditable && (
        <section className="rounded-[16px] border border-[#f0e9df] bg-white px-5 py-4">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p className="m-0 min-w-0 font-display text-[12.5px] leading-snug text-[#6f6b68]">
              Typed something wrong? You can still fix
              {" "}{editableLabel}{" "}
              while the kitchen has your order.
            </p>

            <Button variant="outline" size="sm" onClick={onEdit} className="shrink-0">
              <IconPencil size={15} stroke={2.2} aria-hidden="true" />
              Correct my order
            </Button>
          </div>
        </section>
      )}

      {!order.is_terminal && (
        <section
          className={`rounded-[16px] border px-5 py-4 ${
            refundable ? "border-[#f0e9df] bg-white" : "border-[#ffe6a8] bg-[#fff9e8]"
          }`}
        >
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p
              className={`m-0 flex min-w-0 items-start gap-2 font-display text-[12.5px] leading-snug ${
                refundable ? "text-[#6f6b68]" : "text-[#8a6206]"
              }`}
            >
              {!refundable && (
                <IconInfoCircle size={15} stroke={2} aria-hidden="true" className="mt-px shrink-0" />
              )}
              {cancellation?.message ?? "You can still cancel this order."}
            </p>

            <Button
              variant="ghost"
              size="sm"
              loading={isCancelling}
              loadingLabel="Working&hellip;"
              onClick={onCancel}
              className="shrink-0 border border-[#f3d4d4] text-[#c92a2a] hover:bg-[#fdecec]"
            >
              {prompt.action}
            </Button>
          </div>
        </section>
      )}

      {order.estimated_time_of_completion && !order.is_terminal && (
        <p className="m-0 flex items-center justify-center gap-1.5 font-display text-[12px] text-[#a39f9b]">
          <IconClock size={13} stroke={2} aria-hidden="true" />
          Estimated {order.order_type === "delivery" ? "arrival" : "ready"} in about{" "}
          {order.estimated_time_of_completion} minutes
        </p>
      )}
    </div>
  );
}

export default OrderStatusPanel;
