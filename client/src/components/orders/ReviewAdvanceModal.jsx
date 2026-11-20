import { Modal } from "@mantine/core";
import { useMediaQuery } from "@mantine/hooks";
import { IconAlertTriangle, IconLock } from "@tabler/icons-react";

import Button from "../ui/Button";
import { OrderSummaryCard } from "./OrderReceipt";

/**
 * Review Advance Order (decided 2026-11-20): shown on every Pay now, before
 * Xendit's page. Continuing records the review on the order and opens payment.
 */
function ReviewAdvanceModal({ order, opened, onClose, onContinue, isOpening }) {
  const wide = useMediaQuery("(min-width: 36em)", true);

  return (
    <Modal
      opened={opened}
      onClose={isOpening ? () => {} : onClose}
      centered
      radius="lg"
      size="min(460px, 100%)"
      fullScreen={!wide}
      title={<span className="font-display text-[17px] font-bold text-ink">Review Advance Order</span>}
      data-testid="review-advance-order"
    >
      {order && (
        <div className="flex flex-col gap-3.5">
          <OrderSummaryCard order={order} />

          <p className="m-0 flex items-start gap-2 rounded-[12px] bg-[#fff4e8] px-3.5 py-3 font-display text-[12.5px] font-semibold leading-snug text-brand-800 ring-1 ring-inset ring-[#ffd8a8]">
            <IconAlertTriangle size={16} stroke={2.2} aria-hidden="true" className="mt-[1px] shrink-0" />
            No cancellation and refund after the store confirms your payment.
          </p>

          <Button
            size="md"
            className="w-full"
            loading={isOpening}
            loadingLabel="Opening payment&hellip;"
            onClick={onContinue}
            data-testid="review-continue"
          >
            <IconLock size={16} stroke={2.2} aria-hidden="true" />
            Continue to Payment
          </Button>
        </div>
      )}
    </Modal>
  );
}

export default ReviewAdvanceModal;
