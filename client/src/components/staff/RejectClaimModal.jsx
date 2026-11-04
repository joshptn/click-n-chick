import { useEffect, useState } from "react";
import { Modal, Textarea } from "@mantine/core";

import Button from "../ui/Button";
import { CLAIM_REJECTION_REASONS, claimLabel, claimantName } from "../../lib/agent";

const MAX_REASON = 500;

/**
 * Reject a discount application.
 *
 * The reason is required: it is the customer's notification, and the only way
 * they know what to fix before applying again with a new photo.
 */
function RejectClaimModal({ claim, opened, onClose, onConfirm, loading }) {
  const [reason, setReason] = useState("");

  useEffect(() => {
    if (opened) setReason("");
  }, [opened, claim?.id]);

  if (!claim) return null;

  const trimmed = reason.trim();

  return (
    <Modal
      opened={opened}
      onClose={loading ? () => {} : onClose}
      title={`Reject application #${claimLabel(claim)}?`}
      centered
      radius="md"
    >
      <p className="m-0 font-display text-[13px] leading-relaxed text-[#6f6b68]">
        {claimantName(claim)} is told why, and can apply again with a new photo.
      </p>

      <div className="mt-4 flex flex-wrap gap-1.5 text-[12px]">
        {CLAIM_REJECTION_REASONS.map((preset) => (
          <button
            key={preset}
            type="button"
            onClick={() => setReason(preset)}
            className={`rounded-full border px-3 py-1 font-display text-[12px] transition-colors ${
              reason === preset
                ? "border-brand-500 bg-brand-50 text-brand-700"
                : "border-[#ece7e0] bg-white text-[#6f6b68] hover:border-brand-200"
            }`}
          >
            {preset}
          </button>
        ))}
      </div>

      <Textarea
        mt="sm"
        label="Reason for the customer"
        description="Required. Say what to fix so the next photo can be approved."
        value={reason}
        onChange={(event) => setReason(event.currentTarget.value.slice(0, MAX_REASON))}
        autosize
        minRows={2}
        maxRows={5}
        radius="md"
        data-autofocus
        data-testid="claim-reject-reason"
      />

      <div className="mt-5 flex justify-end gap-2 text-[13px]">
        <Button variant="ghost" size="sm" onClick={onClose} disabled={loading}>
          Keep reviewing
        </Button>
        <button
          type="button"
          disabled={loading || trimmed === ""}
          onClick={() => onConfirm(trimmed)}
          data-testid="claim-reject-confirm"
          className="inline-flex h-[38px] items-center justify-center rounded-[9px] bg-[#e5322d] px-4 font-display text-[13px] font-semibold text-white transition-colors hover:bg-[#cc2a25] disabled:cursor-not-allowed disabled:opacity-55"
        >
          {loading ? "Rejecting…" : "Reject application"}
        </button>
      </div>
    </Modal>
  );
}

export default RejectClaimModal;
