import { useEffect, useMemo, useState } from "react";
import { Modal } from "@mantine/core";
import { useQuery } from "@tanstack/react-query";
import {
  IconArrowLeft,
  IconClock,
  IconMinus,
  IconPhotoOff,
  IconPlus,
  IconShoppingBagPlus,
  IconTrash,
} from "@tabler/icons-react";

import { fetchFood, formatPeso, stockLabel, stockTone } from "../../lib/menu";

function FoodDetailModal({ food, opened, onClose, onAdd, isAdding = false }) {
  const [quantity, setQuantity] = useState(1);
  const [selectedAddons, setSelectedAddons] = useState([]);

  const { data } = useQuery({
    queryKey: ["food", food?.id],
    queryFn: () => fetchFood(food.id),
    enabled: Boolean(food?.id) && opened,
    staleTime: 60 * 1000,
  });

  const detail = data?.data ?? food;

  useEffect(() => {
    setQuantity(1);
    setSelectedAddons([]);
  }, [food?.id, opened]);

  const addonGroups = useMemo(() => detail?.addon_groups ?? [], [detail]);

  const addonIndex = useMemo(
    () => new Map(addonGroups.flatMap((group) => group.addons).map((addon) => [addon.id, addon])),
    [addonGroups]
  );

  const addonsTotal = selectedAddons.reduce(
    (sum, id) => sum + Number(addonIndex.get(id)?.addon_price ?? 0),
    0
  );

  if (!detail) return null;

  const orderable = detail.is_orderable;
  const label = stockLabel(detail);
  const lineTotal = (Number(detail.price) + addonsTotal) * quantity;

  const maxQuantity = detail.stock_quantity ?? 99;

  const toggleAddon = (id) =>
    setSelectedAddons((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));

  const handleAdd = () => {
    onAdd({ foodId: detail.id, quantity, addonIds: selectedAddons });
  };

  return (
    <Modal
      opened={opened}
      onClose={onClose}
      size="min(980px, 94vw)"
      centered
      padding={0}
      radius="5px"
      withCloseButton={false}
      overlayProps={{ backgroundOpacity: 0.5, blur: 3 }}
      styles={{ content: { overflow: "hidden" } }}
      classNames={{ body: "p-0" }}
    >
      <div className="grid max-h-[88dvh] grid-cols-1 overflow-hidden md:grid-cols-[minmax(0,0.85fr)_minmax(0,1fr)]">

        <div className="relative hidden min-h-[260px] bg-[#f4f1ec] md:block">
          {detail.thumbnail ? (
            <img
              src={detail.thumbnail}
              alt={detail.food_name}
              className={`h-full w-full object-cover ${orderable ? "" : "grayscale"}`}
            />
          ) : (
            <span className="grid h-full w-full place-items-center text-[#c9c2b8]">
              <IconPhotoOff size={30} stroke={1.6} aria-hidden="true" />
            </span>
          )}

          {detail.is_best_seller && (
            <span className="absolute left-5 top-5 rounded-full bg-brand-500 px-3.5 py-1.5 font-display text-[10px] font-extrabold uppercase tracking-[0.08em] text-white">
              Best Seller
            </span>
          )}

          {detail.prep_time ? (
            <span className="absolute bottom-5 left-5 inline-flex items-center gap-1.5 rounded-full bg-cocoa-900/80 px-3 py-1.5 font-display text-[11.5px] font-semibold text-white backdrop-blur-sm">
              <IconClock size={13} stroke={2.2} aria-hidden="true" />
              {detail.prep_time} mins
            </span>
          ) : null}
        </div>
        <div className="flex max-h-[88dvh] min-h-0 flex-col overflow-y-auto overflow-x-hidden bg-white px-6 pb-6 pt-5 contain-paint sm:px-8">

          <div className="mb-4 flex items-center justify-between gap-3">
            <button
              type="button"
              onClick={onClose}
              className="inline-flex items-center gap-2 rounded-full bg-[#faf7f3] py-2 pl-3 pr-4 font-display text-[13px] font-semibold text-ink transition-colors hover:bg-[#f0e9df]"
            >
              <IconArrowLeft size={16} stroke={2.2} aria-hidden="true" />
              Back to Menu
            </button>
          </div>

          <h2 className="m-0 font-display text-[26px] font-extrabold leading-tight tracking-[-0.5px] text-ink sm:text-[30px]">
            {detail.food_name}
          </h2>

          <p className="mt-2 flex items-baseline gap-2">
            <span className="font-display text-[26px] font-extrabold leading-none text-brand-600">
              {formatPeso(detail.price)}
            </span>
            <span className="font-display text-[13px] text-[#8d8884]">per serving</span>
          </p>

          <hr className="my-5 border-0 border-t border-[#f0e9df]" />

          <h3 className="m-0 font-display text-[14px] font-bold text-ink">Description</h3>
          <p className="mt-2 font-display text-[13.5px] leading-relaxed text-[#6f6b68]">
            {detail.description}
          </p>

          {label && (
            <p className={`mt-3 flex items-center gap-2 font-display text-[12.5px] font-semibold ${stockTone(detail)}`}>
              <span aria-hidden="true" className="inline-block h-2 w-2 shrink-0 rounded-full bg-current" />
              {label}
            </p>
          )}

          {addonGroups.length > 0 && (
            <div className="mt-6">
              <div className="flex items-center gap-2.5">
                <h3 className="m-0 font-display text-[15px] font-bold text-ink">Add &ndash; ons</h3>
                <span className="rounded-full bg-[#f4f1ec] px-2.5 py-1 font-display text-[10.5px] font-semibold text-[#8d8884]">
                  optional
                </span>
              </div>

              {addonGroups.map((group) => (
                <fieldset key={group.group} className="mt-5 border-0 p-0">
                  <legend className="mb-2.5 flex w-full items-center justify-between">
                    <span className="font-display text-[13.5px] font-bold text-ink">{group.group}</span>
                    <span className="rounded-full bg-[#f4f1ec] px-2.5 py-1 font-display text-[10.5px] font-semibold text-[#8d8884]">
                      Optional
                    </span>
                  </legend>

                  <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
                    {group.addons.map((addon) => {
                      const checked = selectedAddons.includes(addon.id);

                      return (
                        <label
                          key={addon.id}
                          className={`cursor-pointer rounded-[12px] border-2 px-3 py-2.5 transition-colors ${
                            checked
                              ? "border-brand-500 bg-[#fff6ee]"
                              : "border-transparent bg-[#faf7f3] hover:bg-[#f4f1ec]"
                          }`}
                        >
                          <input
                            type="checkbox"
                            checked={checked}
                            onChange={() => toggleAddon(addon.id)}
                            className="sr-only"
                          />
                          <span className="block font-display text-[12.5px] font-semibold leading-tight text-ink">
                            {addon.addon_name}
                          </span>
                          <span className="mt-0.5 block font-display text-[12px] font-bold text-brand-600">
                            +{formatPeso(addon.addon_price)}
                          </span>
                        </label>
                      );
                    })}
                  </div>
                </fieldset>
              ))}
            </div>
          )}

          <div className="sticky bottom-0 -mx-6 mt-7 flex items-center gap-3 border-t border-[#f0e9df] bg-white px-6 pb-1 pt-4 sm:-mx-8 sm:px-8">
            <div className="flex shrink-0 items-center gap-2">
              <button
                type="button"
                onClick={() => setQuantity((q) => Math.max(1, q - 1))}
                disabled={!orderable || quantity <= 1}
                aria-label={quantity <= 1 ? "Remove item" : "Decrease quantity"}
                className="grid h-9 w-9 place-items-center rounded-full bg-[#f4f1ec] text-ink transition-colors hover:bg-[#e9e3da] disabled:cursor-not-allowed disabled:text-[#c0bab2]"
              >
                {quantity <= 1 ? <IconTrash size={15} stroke={2} /> : <IconMinus size={15} stroke={2.6} />}
              </button>

              <span
                aria-live="polite"
                className="min-w-[26px] text-center font-display text-[15px] font-bold text-ink"
              >
                {quantity}
              </span>

              <button
                type="button"
                onClick={() => setQuantity((q) => Math.min(maxQuantity, q + 1))}
                disabled={!orderable || quantity >= maxQuantity}
                aria-label="Increase quantity"
                className="grid h-9 w-9 place-items-center rounded-full bg-brand-500 text-white transition-colors hover:bg-brand-600 disabled:cursor-not-allowed disabled:bg-brand-200"
              >
                <IconPlus size={15} stroke={2.6} />
              </button>
            </div>

            <button
              type="button"
              onClick={handleAdd}
              disabled={!orderable || isAdding}
              className="inline-flex h-[50px] flex-1 items-center justify-center gap-2 rounded-[12px] bg-brand-500 px-5 font-display text-[14.5px] font-bold text-white transition-all duration-150 hover:bg-brand-600 active:translate-y-px disabled:cursor-not-allowed disabled:bg-brand-200"
            >
              <IconShoppingBagPlus size={18} stroke={2} aria-hidden="true" />
              {orderable
                ? `${isAdding ? "Adding" : "Add to Cart"} — ${formatPeso(lineTotal)}`
                : "Unavailable"}
            </button>
          </div>
        </div>
      </div>
    </Modal>
  );
}

export default FoodDetailModal;
