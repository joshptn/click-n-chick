import { useEffect, useState } from "react";
import {
  IconBriefcase,
  IconCircleCheckFilled,
  IconHome,
  IconMapPin,
  IconPencil,
  IconPlus,
  IconTrash,
} from "@tabler/icons-react";
import { AnimatePresence, motion } from "framer-motion";

import Button from "../ui/Button";

/**
 * Saved delivery addresses.
 *
 * Map picking is a later module, so this collects a typed address and nothing
 * else. The latitude/longitude columns already exist and the API already
 * accepts them - this component simply does not supply them yet, which is why
 * adding the map later is a change here and not a change to the schema.
 *
 * "Default" is a real property rather than "the first one": the server
 * promotes another address when the default is deleted, so the account never
 * ends up with several addresses and no pick for checkout.
 */

const LABELS = [
  { value: "home", label: "Home", icon: IconHome },
  { value: "work", label: "Work", icon: IconBriefcase },
  { value: "other", label: "Other", icon: IconMapPin },
];

const MotionDiv = motion.div;

const EMPTY = { label: "home", full_address: "", delivery_note: "" };

function AddressForm({ initial, onCancel, onSubmit, isSaving }) {
  const [form, setForm] = useState(initial ?? EMPTY);

  useEffect(() => {
    setForm(initial ?? EMPTY);
  }, [initial]);

  const set = (key) => (e) => setForm((prev) => ({ ...prev, [key]: e.target.value }));

  return (
    <MotionDiv
      initial={{ opacity: 0, height: 0 }}
      animate={{ opacity: 1, height: "auto" }}
      exit={{ opacity: 0, height: 0 }}
      transition={{ duration: 0.2, ease: "easeOut" }}
      className="overflow-hidden"
    >
      <form
        onSubmit={(e) => {
          e.preventDefault();
          onSubmit(form);
        }}
        className="rounded-[14px] border-2 border-brand-500 bg-white p-3.5"
      >
        <div className="flex flex-wrap gap-1.5">
          {LABELS.map((option) => {
            // Local alias - see the note in Profile.jsx.
            const Glyph = option.icon;

            return (
              <button
                key={option.value}
                type="button"
                onClick={() => setForm((prev) => ({ ...prev, label: option.value }))}
                aria-pressed={form.label === option.value}
                className={`inline-flex h-[34px] items-center gap-1.5 rounded-[9px] px-3 font-display text-[12.5px] font-semibold transition-colors ${
                  form.label === option.value
                    ? "bg-brand-500 text-white"
                    : "border border-[#ece7e0] bg-white text-[#6f6b68] hover:border-brand-300"
                }`}
              >
                <Glyph size={14} stroke={2} aria-hidden="true" />
                {option.label}
              </button>
            );
          })}
        </div>

        <label htmlFor="address-street" className="mt-3 block font-display text-[12.5px] font-semibold text-ink">
          Street Address <span className="text-[#e5322d]">*</span>
        </label>
        <input
          id="address-street"
          value={form.full_address}
          onChange={set("full_address")}
          required
          maxLength={500}
          placeholder="e.g. Larlin Village, Blk 1, Lot 5"
          className="mt-1.5 h-[42px] w-full rounded-[10px] border border-[#ece7e0] bg-white px-3.5 font-display text-[13.5px] text-ink outline-none transition-colors placeholder:text-[#b8b2aa] focus:border-brand-500"
        />

        <label htmlFor="address-note" className="mt-3 block font-display text-[12.5px] font-semibold text-ink">
          Delivery Note <span className="font-normal text-[#a39f9b]">(optional)</span>
        </label>
        <input
          id="address-note"
          value={form.delivery_note ?? ""}
          onChange={set("delivery_note")}
          maxLength={255}
          placeholder="e.g. Ring doorbell, Leave at gate…"
          className="mt-1.5 h-[42px] w-full rounded-[10px] border border-[#ece7e0] bg-white px-3.5 font-display text-[13.5px] text-ink outline-none transition-colors placeholder:text-[#b8b2aa] focus:border-brand-500"
        />

        <div className="mt-3.5 grid grid-cols-2 gap-2">
          <Button
            type="submit"
            size="sm"
            loading={isSaving}
            loadingLabel="Saving&hellip;"
            disabled={form.full_address.trim() === ""}
          >
            Save Address
          </Button>
          <Button type="button" variant="outline" size="sm" onClick={onCancel} disabled={isSaving}>
            Cancel
          </Button>
        </div>
      </form>
    </MotionDiv>
  );
}

function AddressRow({ address, onEdit, onDelete, onMakeDefault, isBusy }) {
  const meta = LABELS.find((entry) => entry.value === address.label) ?? LABELS[2];
  const Icon = meta.icon;

  return (
    <li className="rounded-[14px] border border-[#f0e9df] bg-white px-3.5 py-3">
      <div className="flex items-start gap-2.5">
        <span
          aria-hidden="true"
          className="grid h-8 w-8 shrink-0 place-items-center rounded-[9px] bg-[#fff6ec] text-brand-600"
        >
          <Icon size={16} stroke={1.9} />
        </span>

        <div className="min-w-0 flex-1">
          <p className="m-0 flex flex-wrap items-center gap-1.5 font-display text-[13px] font-bold text-ink">
            {meta.label}
            {address.is_default && (
              <span className="inline-flex items-center gap-1 rounded-full bg-[#e9f8ee] px-2 py-0.5 font-display text-[10.5px] font-bold text-[#2f9e44]">
                <IconCircleCheckFilled size={10} aria-hidden="true" />
                Default
              </span>
            )}
          </p>
          <p className="m-0 mt-0.5 font-display text-[12.5px] leading-snug text-[#6f6b68]">
            {address.full_address}
          </p>
          {address.delivery_note && (
            <p className="m-0 mt-0.5 font-display text-[12px] italic leading-snug text-[#a39f9b]">
              {address.delivery_note}
            </p>
          )}
        </div>

        <div className="flex shrink-0 gap-0.5">
          <button
            type="button"
            onClick={() => onEdit(address)}
            disabled={isBusy}
            aria-label={`Edit ${meta.label} address`}
            className="grid h-7 w-7 place-items-center rounded-[7px] bg-transparent text-[#8d8884] transition-colors hover:bg-[#f7f4f0] hover:text-ink disabled:opacity-40"
          >
            <IconPencil size={14} stroke={1.9} />
          </button>
          <button
            type="button"
            onClick={() => onDelete(address)}
            disabled={isBusy}
            aria-label={`Delete ${meta.label} address`}
            className="grid h-7 w-7 place-items-center rounded-[7px] bg-transparent text-[#8d8884] transition-colors hover:bg-[#fff1f1] hover:text-[#e5322d] disabled:opacity-40"
          >
            <IconTrash size={14} stroke={1.9} />
          </button>
        </div>
      </div>

      {!address.is_default && (
        <button
          type="button"
          onClick={() => onMakeDefault(address)}
          disabled={isBusy}
          className="mt-2 bg-transparent font-display text-[12px] font-semibold text-brand-600 hover:underline disabled:opacity-40"
        >
          Set as default
        </button>
      )}
    </li>
  );
}

function AddressManager({ addresses = [], onCreate, onUpdate, onDelete, onMakeDefault, isSaving, isBusy }) {
  const [editing, setEditing] = useState(null);

  const close = () => setEditing(null);

  const handleSubmit = async (form) => {
    const action = editing?.id ? onUpdate(editing.id, form) : onCreate(form);

    await action.then(close).catch(() => {});
  };

  return (
    <section className="rounded-2xl border border-[#f0e9df] bg-white p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="m-0 font-display text-[11px] font-bold uppercase tracking-[0.1em] text-[#a39f9b]">
            Delivery addresses
          </p>
          <h2 className="m-0 mt-0.5 font-display text-[16px] font-bold text-ink">Manage Addresses</h2>
        </div>

        <Button size="sm" onClick={() => setEditing({ ...EMPTY })} disabled={Boolean(editing)}>
          <IconPlus size={15} stroke={2.2} aria-hidden="true" />
          Add New
        </Button>
      </div>

      <AnimatePresence initial={false}>
        {editing && (
          <div className="mt-3.5">
            <AddressForm
              key={editing.id ?? "new"}
              initial={editing}
              onCancel={close}
              onSubmit={handleSubmit}
              isSaving={isSaving}
            />
          </div>
        )}
      </AnimatePresence>

      {addresses.length === 0 && !editing ? (
        <div className="mt-4 rounded-[14px] border border-dashed border-[#e4ded6] px-4 py-7 text-center">
          <IconMapPin size={26} stroke={1.5} aria-hidden="true" className="mx-auto text-[#d9d3cb]" />
          <p className="m-0 mt-2 font-display text-[13px] font-semibold text-[#6f6b68]">No saved addresses yet</p>
          <p className="m-0 font-display text-[12px] text-[#a39f9b]">
            Add one so checkout can fill it in for you.
          </p>
        </div>
      ) : (
        <ul className="m-0 mt-3.5 grid list-none gap-2.5 p-0">
          {addresses.map((address) => (
            <AddressRow
              key={address.id}
              address={address}
              onEdit={setEditing}
              onDelete={onDelete}
              onMakeDefault={onMakeDefault}
              isBusy={isBusy}
            />
          ))}
        </ul>
      )}
    </section>
  );
}

export default AddressManager;
