import { useEffect, useState } from "react";
import { Modal, Textarea } from "@mantine/core";

import Button from "../ui/Button";

/**
 * A decision that must say why: the reason becomes the customer's notification.
 * Presets fill the box; the confirm button waits for a reason long enough for
 * the server to take.
 */
function ReasonModal({
  opened,
  onClose,
  onConfirm,
  loading,
  resetKey,
  title,
  description,
  presets = [],
  label = "Reason for the customer",
  hint,
  minLength = 1,
  maxLength = 500,
  keepLabel = "Keep reviewing",
  confirmLabel,
  busyLabel,
  testId,
}) {
  const [reason, setReason] = useState("");

  useEffect(() => {
    if (opened) setReason("");
  }, [opened, resetKey]);

  const trimmed = reason.trim();

  return (
    <Modal opened={opened} onClose={loading ? () => {} : onClose} title={title} centered radius="md">
      {description && <p className="m-0 font-display text-[13px] leading-relaxed text-[#6f6b68]">{description}</p>}

      {presets.length > 0 && (
        <div className="mt-4 flex flex-wrap gap-1.5 text-[12px]">
          {presets.map((preset) => (
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
      )}

      <Textarea
        mt="sm"
        label={label}
        description={hint}
        value={reason}
        onChange={(event) => setReason(event.currentTarget.value.slice(0, maxLength))}
        autosize
        minRows={2}
        maxRows={5}
        radius="md"
        data-autofocus
        data-testid={testId ? `${testId}-reason` : undefined}
      />

      <div className="mt-5 flex justify-end gap-2 text-[13px]">
        <Button variant="ghost" size="sm" onClick={onClose} disabled={loading}>
          {keepLabel}
        </Button>
        <button
          type="button"
          disabled={loading || trimmed.length < minLength}
          onClick={() => onConfirm(trimmed)}
          data-testid={testId ? `${testId}-confirm` : undefined}
          className="inline-flex h-[38px] items-center justify-center rounded-[9px] bg-[#e5322d] px-4 font-display text-[13px] font-semibold text-white transition-colors hover:bg-[#cc2a25] disabled:cursor-not-allowed disabled:opacity-55"
        >
          {loading ? busyLabel : confirmLabel}
        </button>
      </div>
    </Modal>
  );
}

export default ReasonModal;
