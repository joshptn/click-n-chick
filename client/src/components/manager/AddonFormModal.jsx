import { useEffect, useMemo, useState } from "react";
import { Checkbox, Modal } from "@mantine/core";
import { useMutation } from "@tanstack/react-query";
import { IconSearch } from "@tabler/icons-react";

import toast from "../app/Toast";
import PasswordConfirmModal from "./PasswordConfirmModal";
import Button from "../ui/Button";
import { SIZES, addonPriceText, saveAddon } from "../../lib/addons";
import { MAX_PRICE, fieldErrors, isArchived } from "../../lib/catalog";
import { formatPeso } from "../../lib/menu";

const EMPTY = { addon_name: "", addon_group: "", sized: false, addon_price: "", sizes: {}, food_ids: [] };

const fieldClass = (error) =>
  `w-full rounded-[10px] border bg-[#f9f8f6] px-3.5 font-display text-[14px] text-ink outline-none transition-colors placeholder:text-[#b8b2aa] focus:bg-white ${
    error ? "border-[#e5322d]" : "border-[#ece8e2] focus:border-brand-400"
  }`;

const pesos = (value) => String(value ?? "").replace(/[^\d]/g, "").slice(0, 5);

function FieldError({ message }) {
  if (!message) return null;

  return <p className="m-0 mt-1.5 font-display text-[12px] text-[#e5322d]">{message}</p>;
}

function Label({ htmlFor, required, hint, children }) {
  return (
    <label htmlFor={htmlFor} className="mb-2 flex items-baseline justify-between gap-2 font-display text-[13px] font-semibold text-ink">
      <span>
        {children}
        {required && <span className="ml-0.5 text-brand-500">*</span>}
      </span>
      {hint && <span className="font-normal text-[11.5px] text-[#a39f9b]">{hint}</span>}
    </label>
  );
}

/** The form's values from an add-on as the list has it. */
function valuesOf(addon) {
  if (!addon) return EMPTY;

  return {
    addon_name: addon.addon_name ?? "",
    addon_group: addon.addon_group ?? "",
    sized: Boolean(addon.is_sized),
    addon_price: addon.is_sized ? "" : String(Math.round(Number(addon.addon_price ?? 0))),
    sizes: Object.fromEntries((addon.sizes ?? []).map((size) => [size.size, String(Math.round(Number(size.price)))])),
    food_ids: (addon.food_ids ?? []).map(String),
  };
}

/** Prices keyed by size ('' for one price), to tell whether saving changes one. */
function priceList(values) {
  if (!values.sized) return { "": Number(values.addon_price || 0) };

  return Object.fromEntries(SIZES.filter((size) => values.sizes[size.key] !== undefined).map((size) => [size.key, Number(values.sizes[size.key] || 0)]));
}

/** The server's rule: a price it already had moved, or one price became sizes or back. */
function pricesMoved(before, after) {
  if (("" in before) !== ("" in after)) return true;

  return Object.entries(after).some(([key, price]) => key in before && before[key] !== price);
}

/**
 * The dishes that offer the add-on: searchable, a whole category at a time, or
 * every dish. Archived dishes can be linked too; they say so.
 */
function DishPicker({ foods, categories, value, onChange, error }) {
  const [search, setSearch] = useState("");
  const picked = useMemo(() => new Set(value), [value]);

  const sections = useMemo(() => {
    const needle = search.trim().toLowerCase();
    const shown = foods
      .filter((food) => !needle || food.food_name.toLowerCase().includes(needle))
      .sort((a, b) => a.food_name.localeCompare(b.food_name));

    const named = categories
      .map((category) => ({ key: String(category.id), name: category.name, foods: shown.filter((food) => food.category?.id === category.id) }))
      .filter((section) => section.foods.length > 0);

    const loose = shown.filter((food) => !categories.some((category) => category.id === food.category?.id));

    return loose.length > 0 ? [...named, { key: "none", name: "No category", foods: loose }] : named;
  }, [foods, categories, search]);

  const setMany = (ids, on) => {
    const next = new Set(picked);

    ids.forEach((id) => (on ? next.add(id) : next.delete(id)));
    onChange([...next]);
  };

  const all = foods.map((food) => String(food.id));

  return (
    <div className={`rounded-[12px] border ${error ? "border-[#e5322d]" : "border-[#ece8e2]"} bg-white`} data-testid="addon-dishes">
      <div className="flex flex-wrap items-center gap-2 border-b border-[#f3ede4] px-3 py-2.5">
        <span className="relative min-w-[160px] flex-1">
          <IconSearch size={14} stroke={2} className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-[#a39f9b]" />
          <input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search dishes…"
            aria-label="Search dishes"
            className="h-[32px] w-full rounded-full border border-[#ece8e2] bg-[#f9f8f6] pl-8 pr-3 font-display text-[12.5px] text-ink outline-none focus:border-brand-400 focus:bg-white"
          />
        </span>
        <button
          type="button"
          onClick={() => setMany(all, true)}
          className="rounded-full bg-[#fff6ec] px-3 py-1.5 font-display font-semibold text-brand-600 hover:bg-[#ffecd9]"
        >
          <span className="text-[11.5px]">Every dish</span>
        </button>
        <button
          type="button"
          onClick={() => onChange([])}
          disabled={value.length === 0}
          className="rounded-full px-3 py-1.5 font-display font-semibold text-[#8d8884] hover:bg-[#f4f1ec] disabled:opacity-40"
        >
          <span className="text-[11.5px]">None</span>
        </button>
      </div>

      <div className="max-h-[240px] overflow-y-auto px-3 py-2">
        {sections.length === 0 ? (
          <p className="m-0 py-4 text-center font-display text-[12.5px] text-[#a39f9b]">{foods.length === 0 ? "There are no dishes yet." : "No dish matches."}</p>
        ) : (
          sections.map((section) => {
            const ids = section.foods.map((food) => String(food.id));
            const count = ids.filter((id) => picked.has(id)).length;

            return (
              <fieldset key={section.key} className="m-0 mb-2 border-0 p-0 last:mb-0">
                <legend className="w-full">
                  <Checkbox
                    size="xs"
                    color="orange"
                    checked={count === ids.length}
                    indeterminate={count > 0 && count < ids.length}
                    onChange={(event) => setMany(ids, event.currentTarget.checked)}
                    label={
                      <span className="font-display text-[11px] font-bold uppercase tracking-[0.5px] text-[#8d8884]">
                        {section.name} · {count}/{ids.length}
                      </span>
                    }
                  />
                </legend>
                <div className="mt-1.5 grid grid-cols-1 gap-x-3 gap-y-1.5 pl-5 sm:grid-cols-2">
                  {section.foods.map((food) => (
                    <Checkbox
                      key={food.id}
                      size="xs"
                      color="orange"
                      checked={picked.has(String(food.id))}
                      onChange={(event) => setMany([String(food.id)], event.currentTarget.checked)}
                      label={
                        <span className="font-display text-[12.5px] text-ink">
                          {food.food_name}
                          {isArchived(food) && <span className="ml-1 text-[11px] text-[#a39f9b]">(archived)</span>}
                        </span>
                      }
                    />
                  ))}
                </div>
              </fieldset>
            );
          })
        )}
      </div>

      <p className="m-0 border-t border-[#f3ede4] px-3 py-2 font-display text-[11.5px] text-[#8d8884]">
        {value.length === 0 ? "Not offered with any dish yet." : `Offered with ${value.length} ${value.length === 1 ? "dish" : "dishes"}.`}
      </p>
    </div>
  );
}

/**
 * Add an add-on, or edit one (decided 2026-11-24): its name, the group it is
 * listed under on the dish screen, one price or a price for each size it
 * comes in, and the dishes that offer it.
 *
 * A price change on an existing add-on asks for the password - the server
 * asks, this shows the prompt and sends the same change again - and every
 * account is told, as for a dish.
 */
function AddonFormModal({ opened, addon = null, onClose, onSaved, foods, categories, groups }) {
  const editing = Boolean(addon);
  const [values, setValues] = useState(EMPTY);
  const [errors, setErrors] = useState({});
  const [askPassword, setAskPassword] = useState(false);
  const [passwordError, setPasswordError] = useState(null);

  useEffect(() => {
    if (opened) {
      setValues(valuesOf(addon));
      setErrors({});
      setAskPassword(false);
      setPasswordError(null);
    }
  }, [opened, addon]);

  const priceChange = editing && pricesMoved(priceList(valuesOf(addon)), priceList(values));

  const save = useMutation({
    mutationFn: (password) => saveAddon({ id: addon?.id, values, password }),
    onSuccess: (response) => {
      setAskPassword(false);
      toast.success(response?.message, editing ? "Changes saved" : "Add-on added");
      onSaved?.(response?.data);
      onClose();
    },
    onError: (error) => {
      const code = error?.payload?.error_code;

      if (code === "PASSWORD_CONFIRMATION_REQUIRED") {
        setPasswordError(null);
        setAskPassword(true);

        return;
      }

      if (code === "PASSWORD_CONFIRMATION_INVALID" || code === "PASSWORD_CONFIRMATION_THROTTLED") {
        setPasswordError(error.message);
        setAskPassword(true);

        return;
      }

      setAskPassword(false);

      if (error.status === 422) {
        const found = fieldErrors(error);
        const sizeError = Object.entries(found).find(([key]) => key.startsWith("sizes"))?.[1];

        setErrors({ ...found, sizes: sizeError });
        toast.error("Check the highlighted fields.", "Not saved");

        return;
      }

      toast.error(error.message, "Not saved");
    },
  });

  const set = (key, value) => {
    setValues((current) => ({ ...current, [key]: value }));
    setErrors((current) => ({ ...current, [key]: undefined }));
  };

  const setSize = (key, value) => {
    setValues((current) => {
      const sizes = { ...current.sizes };

      if (value === undefined) delete sizes[key];
      else sizes[key] = value;

      return { ...current, sizes };
    });
    setErrors((current) => ({ ...current, sizes: undefined }));
  };

  const validate = () => {
    const found = {};
    const tooHigh = (price) => Number(price) > MAX_PRICE;

    if (!values.addon_name.trim()) found.addon_name = "Enter the add-on's name.";
    if (!values.addon_group.trim()) found.addon_group = "Enter the group it is listed under, such as Drinks.";

    if (values.sized) {
      const picked = SIZES.filter((size) => values.sizes[size.key] !== undefined);

      if (picked.length === 0) found.sizes = "Tick at least one size.";
      else if (picked.some((size) => values.sizes[size.key] === "")) found.sizes = "Enter a price for each size ticked.";
      else if (picked.some((size) => tooHigh(values.sizes[size.key]))) found.sizes = `A price can be at most ${formatPeso(MAX_PRICE)}.`;
    } else if (values.addon_price === "") {
      found.addon_price = "Enter the price.";
    } else if (tooHigh(values.addon_price)) {
      found.addon_price = `The price can be at most ${formatPeso(MAX_PRICE)}.`;
    }

    setErrors(found);

    return Object.keys(found).length === 0;
  };

  const submit = (event) => {
    event.preventDefault();

    if (validate()) save.mutate(undefined);
  };

  const busy = save.isPending;

  return (
    <>
      <Modal
        opened={opened}
        onClose={busy ? () => {} : onClose}
        centered
        radius={16}
        size="min(640px, 94vw)"
        title={
          <span className="block">
            <span className="block font-display text-[17px] font-bold text-cocoa-900">{editing ? "Edit Add-on" : "Add Add-on"}</span>
            <span className="block font-display text-[12px] font-normal text-[#8d8884]">
              {editing ? `Editing: ${addon.addon_name}` : "An extra customers can pick with a dish, such as a drink or a side."}
            </span>
          </span>
        }
        styles={{ header: { borderBottom: "1px solid #f3ede4", paddingBottom: 12 } }}
      >
        <form onSubmit={submit} noValidate className="flex flex-col gap-5 pt-4" data-testid="addon-form">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <Label htmlFor="addon_name" required>
                Name
              </Label>
              <input
                id="addon_name"
                value={values.addon_name}
                onChange={(event) => set("addon_name", event.target.value.slice(0, 100))}
                placeholder="e.g. Iced Tea"
                data-autofocus
                aria-invalid={Boolean(errors.addon_name)}
                data-testid="addon-name"
                className={`${fieldClass(errors.addon_name)} h-[44px]`}
              />
              <FieldError message={errors.addon_name} />
            </div>

            <div>
              <Label htmlFor="addon_group" required>
                Group
              </Label>
              <input
                id="addon_group"
                value={values.addon_group}
                onChange={(event) => set("addon_group", event.target.value.slice(0, 50))}
                placeholder="e.g. Drinks"
                aria-invalid={Boolean(errors.addon_group)}
                data-testid="addon-group"
                className={`${fieldClass(errors.addon_group)} h-[44px]`}
              />
              <div className="mt-1.5 flex flex-wrap gap-1.5">
                {groups.map((group) => (
                  <button
                    key={group}
                    type="button"
                    onClick={() => set("addon_group", group)}
                    className={`rounded-full px-2.5 py-1 font-display font-semibold transition-colors ${
                      values.addon_group.trim().toLowerCase() === group.toLowerCase()
                        ? "bg-brand-500 text-white"
                        : "bg-[#f4f1ec] text-[#6f6b68] hover:bg-[#ece6de]"
                    }`}
                  >
                    <span className="text-[11px]">{group}</span>
                  </button>
                ))}
              </div>
              <FieldError message={errors.addon_group} />
            </div>
          </div>

          <div>
            <Label hint="Whole pesos">Price</Label>
            <div className="mb-3 inline-flex rounded-full bg-[#f4f1ec] p-1" role="radiogroup" aria-label="How it is priced">
              {[
                { key: false, label: "One price" },
                { key: true, label: "By size" },
              ].map((option) => (
                <button
                  key={option.label}
                  type="button"
                  role="radio"
                  aria-checked={values.sized === option.key}
                  onClick={() => set("sized", option.key)}
                  data-testid={option.key ? "addon-by-size" : "addon-one-price"}
                  className={`rounded-full px-3.5 py-1.5 font-display font-semibold transition-colors ${
                    values.sized === option.key ? "bg-white text-brand-600 shadow-[0_1px_3px_rgba(0,0,0,0.08)]" : "text-[#8d8884] hover:text-ink"
                  }`}
                >
                  <span className="text-[12px]">{option.label}</span>
                </button>
              ))}
            </div>

            {values.sized ? (
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-3" data-testid="addon-sizes">
                {SIZES.map((size) => {
                  const on = values.sizes[size.key] !== undefined;

                  return (
                    <div
                      key={size.key}
                      className={`rounded-[12px] border px-3 py-2.5 transition-colors ${on ? "border-[#ffd6b3] bg-[#fffaf4]" : "border-[#ece8e2] bg-white"}`}
                    >
                      <Checkbox
                        size="xs"
                        color="orange"
                        checked={on}
                        onChange={(event) => setSize(size.key, event.currentTarget.checked ? "" : undefined)}
                        label={<span className="font-display text-[12.5px] font-semibold text-ink">{size.label}</span>}
                        data-testid={`addon-size-${size.key}`}
                      />
                      <div className="relative mt-2">
                        <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 font-display text-[13px] text-[#a39f9b]">₱</span>
                        <input
                          inputMode="numeric"
                          value={on ? values.sizes[size.key] : ""}
                          onChange={(event) => setSize(size.key, pesos(event.target.value))}
                          disabled={!on}
                          placeholder={on ? "0" : "—"}
                          aria-label={`${size.label} price`}
                          data-testid={`addon-size-price-${size.key}`}
                          className={`${fieldClass(null)} h-[38px] pl-7 disabled:cursor-not-allowed disabled:opacity-50`}
                        />
                      </div>
                    </div>
                  );
                })}
              </div>
            ) : (
              <div className="relative max-w-[220px]">
                <span className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 font-display text-[14px] text-[#a39f9b]">₱</span>
                <input
                  inputMode="numeric"
                  value={values.addon_price}
                  onChange={(event) => set("addon_price", pesos(event.target.value))}
                  placeholder="0"
                  aria-label="Price"
                  aria-invalid={Boolean(errors.addon_price)}
                  data-testid="addon-price"
                  className={`${fieldClass(errors.addon_price)} h-[44px] pl-8`}
                />
              </div>
            )}

            <FieldError message={errors.addon_price ?? errors.sizes} />
            <p className="m-0 mt-1.5 font-display text-[11.5px] text-[#a39f9b]">
              {values.sized
                ? "Customers pick a size. The Store Agent switches a size off when it runs out."
                : "One price, whatever the customer picks."}
            </p>
            {priceChange && (
              <p className="m-0 mt-1.5 font-display text-[11.5px] leading-snug text-[#b26a00]" data-testid="addon-price-change-note">
                Was {addonPriceText(addon)}. Saving asks for your password, and every account is told about the new price.
              </p>
            )}
          </div>

          <div>
            <Label hint="Customers see it on these dishes">Offered with</Label>
            <DishPicker foods={foods} categories={categories} value={values.food_ids} onChange={(ids) => set("food_ids", ids)} error={errors.food_ids} />
            <FieldError message={errors.food_ids} />
          </div>

          <div className="grid grid-cols-2 gap-2.5 border-t border-[#f3ede4] pt-4">
            <Button variant="outline" onClick={onClose} disabled={busy} className="!border-[#e4ddd3] !text-[#5f5b58]">
              Cancel
            </Button>
            <Button type="submit" loading={busy && !askPassword} disabled={busy} data-testid="addon-save">
              {editing ? "Save Changes" : "Add Add-on"}
            </Button>
          </div>
        </form>
      </Modal>

      <PasswordConfirmModal
        opened={askPassword}
        onClose={() => setAskPassword(false)}
        onConfirm={(password) => save.mutate(password)}
        loading={save.isPending}
        error={passwordError}
        zIndex={400}
        title="Confirm the price change"
        description={
          addon
            ? `Changing the price of ${addon.addon_name} needs your password. Every account will be told about the new price.`
            : "Changing a price needs your password."
        }
      />
    </>
  );
}

export default AddonFormModal;
