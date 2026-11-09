import { useEffect, useState } from "react";
import { Modal } from "@mantine/core";
import { IconAlertTriangle } from "@tabler/icons-react";

import Button from "../ui/Button";
import { StepUpCancelled } from "../../hooks/useStepUp";

/**
 * Says what an account action does and waits to be confirmed. `acknowledge`
 * adds a tick the button waits for, for what cannot be undone.
 */
function ConfirmActionModal({ opened, onClose, onConfirm, title, points = [], confirmLabel, danger = false, acknowledge }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [understood, setUnderstood] = useState(false);

  useEffect(() => {
    if (opened) {
      setError(null);
      setUnderstood(false);
    }
  }, [opened]);

  const confirm = async () => {
    setBusy(true);
    setError(null);

    try {
      await onConfirm();
      onClose();
    } catch (failure) {
      if (!(failure instanceof StepUpCancelled)) setError(failure.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      opened={opened}
      onClose={busy ? () => {} : onClose}
      centered
      radius={16}
      title={<span className={`font-display text-[16px] font-bold ${danger ? "text-[#c4302b]" : "text-ink"}`}>{title}</span>}
    >
      <div data-testid="confirm-action">
        <div className={`flex gap-2.5 rounded-[12px] p-3 ${danger ? "border border-[#f8c9c7] bg-[#fdf3f2]" : "bg-[#faf6f0]"}`}>
          {danger && <IconAlertTriangle size={18} stroke={2} className="mt-[1px] shrink-0 text-[#e5322d]" />}
          <ul className="m-0 flex list-none flex-col gap-1 p-0 font-display text-[12.5px] leading-relaxed text-[#5f5b58]">
            {points.map((point) => (
              <li key={point}>{point}</li>
            ))}
          </ul>
        </div>

        {acknowledge && (
          <label className="mt-3 flex cursor-pointer items-start gap-2 font-display text-[12.5px] text-ink">
            <input
              type="checkbox"
              checked={understood}
              onChange={(event) => setUnderstood(event.target.checked)}
              disabled={busy}
              data-testid="confirm-understood"
              className="mt-[3px] h-4 w-4 accent-[#e5322d]"
            />
            <span>{acknowledge}</span>
          </label>
        )}

        {error && (
          <p className="m-0 mt-3 rounded-[10px] bg-[#fdecec] px-3 py-2 font-display text-[12px] text-[#c4302b]" role="alert" data-testid="confirm-error">
            {error}
          </p>
        )}

        <div className="mt-5 flex justify-end gap-2 text-[13px]">
          <Button variant="ghost" size="sm" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <button
            type="button"
            disabled={busy || (Boolean(acknowledge) && !understood)}
            onClick={confirm}
            data-testid="confirm-submit"
            className={`inline-flex h-[38px] items-center justify-center rounded-[9px] px-4 font-display font-semibold text-white transition-colors disabled:cursor-not-allowed disabled:opacity-55 ${
              danger ? "bg-[#e5322d] hover:bg-[#cc2a25]" : "bg-brand-500 hover:bg-brand-600"
            }`}
          >
            <span className="text-[13px]">{busy ? "Working…" : confirmLabel}</span>
          </button>
        </div>
      </div>
    </Modal>
  );
}

export default ConfirmActionModal;
