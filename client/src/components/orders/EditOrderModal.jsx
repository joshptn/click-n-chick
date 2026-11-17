import { useEffect, useState } from "react";
import { Modal } from "@mantine/core";
import { IconAlertTriangle, IconPhone, IconUser } from "@tabler/icons-react";

import Button from "../ui/Button";
import { toLocalMobile } from "../../lib/checkout";

/**
 * Change the order's contact name and mobile number (BR-21b), the only details
 * that can change after paying. This order's copy only: the profile stays as
 * it is.
 */
function EditOrderModal({ order, opened, onClose, onSave, isSaving, error }) {
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");

  useEffect(() => {
    if (!opened || !order) return;

    setName(order.contact?.name ?? "");
    setPhone(toLocalMobile(order.contact?.phone) || (order.contact?.phone ?? ""));
  }, [opened, order]);

  if (!order) return null;

  const changes = {};

  if (name.trim() !== (order.contact?.name ?? "")) changes.contact_name = name.trim();
  if (phone.trim() !== (toLocalMobile(order.contact?.phone) || (order.contact?.phone ?? ""))) {
    changes.contact_phone = phone.trim();
  }

  const nothingChanged = Object.keys(changes).length === 0;
  const fieldClass =
    "h-[46px] w-full rounded-[10px] border border-[#ece7e0] bg-white pl-10 pr-3.5 font-display text-[14px] text-ink outline-none transition-colors focus:border-brand-400";

  return (
    <Modal opened={opened} onClose={isSaving ? () => {} : onClose} title="Change contact details" centered radius="md">
      <p className="m-0 font-display text-[12.5px] leading-relaxed text-[#6f6b68]">
        Who the store should call about this order. This changes this order only, not your profile.
      </p>

      <label className="mt-4 block font-display text-[12px] font-semibold text-ink">
        {order.order_type === "delivery" ? "Recipient's name" : "Name of who will collect it"}
        <span className="relative mt-1.5 block">
          <IconUser size={16} stroke={2} aria-hidden="true" className="absolute left-3.5 top-1/2 -translate-y-1/2 text-[#a39f9b]" />
          <input value={name} onChange={(event) => setName(event.target.value.slice(0, 255))} className={fieldClass} />
        </span>
      </label>

      <label className="mt-3 block font-display text-[12px] font-semibold text-ink">
        Mobile number
        <span className="relative mt-1.5 block">
          <IconPhone size={16} stroke={2} aria-hidden="true" className="absolute left-3.5 top-1/2 -translate-y-1/2 text-[#a39f9b]" />
          <input
            type="tel"
            inputMode="tel"
            placeholder="09123456789"
            value={phone}
            onChange={(event) => setPhone(event.target.value.slice(0, 20))}
            className={fieldClass}
          />
        </span>
      </label>

      {error && (
        <p className="m-0 mt-3 flex items-start gap-2 rounded-[10px] bg-[#fdecec] px-3 py-2.5 font-display text-[12.5px] leading-snug text-[#c92a2a]">
          <IconAlertTriangle size={15} stroke={2} aria-hidden="true" className="mt-px shrink-0" />
          {error}
        </p>
      )}

      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" size="sm" onClick={onClose} disabled={isSaving}>
          Keep as is
        </Button>
        <Button size="sm" loading={isSaving} loadingLabel="Saving&hellip;" disabled={nothingChanged} onClick={() => onSave(changes)}>
          Save changes
        </Button>
      </div>
    </Modal>
  );
}

export default EditOrderModal;
