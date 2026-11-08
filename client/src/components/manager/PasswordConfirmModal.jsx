import { useEffect, useState } from "react";
import { Modal } from "@mantine/core";
import { IconLock } from "@tabler/icons-react";

import Button from "../ui/Button";
import Input from "../ui/Input";

/**
 * The server's step-up for a sensitive change (PasswordConfirmation): it asks
 * once, then trusts this session for a few minutes. The caller resends the
 * same change with the password; a wrong one comes back here inline.
 */
function PasswordConfirmModal({ opened, onClose, onConfirm, loading, error, title, description, zIndex }) {
  const [password, setPassword] = useState("");

  useEffect(() => {
    if (opened) setPassword("");
  }, [opened]);

  return (
    <Modal
      opened={opened}
      onClose={loading ? () => {} : onClose}
      centered
      radius={16}
      zIndex={zIndex}
      title={
        <span className="flex items-center gap-2 font-display text-[16px] font-bold text-cocoa-900">
          <IconLock size={18} stroke={2} className="text-brand-600" />
          {title}
        </span>
      }
    >
      <form
        onSubmit={(event) => {
          event.preventDefault();
          if (password) onConfirm(password);
        }}
      >
        <p className="m-0 font-display text-[12.5px] leading-relaxed text-[#6f6b68]">{description}</p>
        <div className="mt-3">
          <Input
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            placeholder="Your account password"
            autoComplete="current-password"
            invalid={Boolean(error)}
            data-autofocus
            data-testid="confirm-password"
          />
        </div>
        {error && <p className="m-0 mt-2 font-display text-[12px] text-[#e5322d]">{error}</p>}

        <div className="mt-5 flex justify-end gap-2">
          <Button variant="ghost" size="sm" onClick={onClose} disabled={loading}>
            Cancel
          </Button>
          <Button type="submit" size="sm" loading={loading} disabled={!password} data-testid="confirm-password-submit">
            Confirm and save
          </Button>
        </div>
      </form>
    </Modal>
  );
}

export default PasswordConfirmModal;
