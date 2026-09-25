import { IconCalendarEvent, IconCheck, IconLock, IconSend } from "@tabler/icons-react";

import Button from "../ui/Button";
import { formatCollection } from "../../lib/advance";
import { formatPeso } from "../../lib/menu";

function AdvanceSummary({
  quote,
  isLoading,
  canSubmit = false,
  isSubmitting = false,
  acknowledged = false,
  onAcknowledge,
  onSubmit,
}) {
  const items = quote?.items ?? [];
  const discount = quote?.discount ?? null;
  const collectAt = formatCollection(quote?.schedule?.requested_at);

  if (isLoading && !quote) {
    return (
      <aside className="rounded-[16px] border border-[#f0e9df] bg-white p-5">
        <div className="h-4 w-32 animate-pulse rounded bg-[#f4f1ec]" />
        <div className="mt-5 space-y-3">
          {Array.from({ length: 3 }).map((_, index) => (
            <div key={index} className="h-9 animate-pulse rounded bg-[#f7f4f0]" />
          ))}
        </div>
      </aside>
    );
  }

  return (
    <aside
      aria-label="Advance order summary"
      className="overflow-hidden rounded-[16px] border border-[#f0e9df] bg-white"
    >
      <h2 className="m-0 px-5 pb-3 pt-4 font-display text-[15px] font-extrabold text-brand-600">
        Your Request
      </h2>

      <div className="mx-5 mb-3 flex items-start gap-2.5 rounded-[12px] bg-[#fff4e8] px-3.5 py-3">
        <IconCalendarEvent
          size={16}
          stroke={2.2}
          aria-hidden="true"
          className="mt-px shrink-0 text-brand-600"
        />

        <span className="min-w-0">
          <span className="block font-display text-[10.5px] font-bold uppercase tracking-[0.7px] text-brand-700/70">
            Collection
          </span>
          <span className="block font-display text-[12.5px] font-bold leading-snug text-ink">
            {collectAt ?? "Not chosen yet"}
          </span>
        </span>
      </div>

      <ul className="m-0 list-none border-b border-[#f0e9df] px-5 pb-4 pt-0">
        {items.map((line) => (
          <li key={line.id} className="flex items-start justify-between gap-3 py-1.5">
            <span className="min-w-0 flex-1">
              <span className="block font-display text-[13.5px] font-semibold leading-snug text-ink">
                {line.food_name}
              </span>
              <span className="block font-display text-[11.5px] text-[#8d8884]">
                {line.quantity}&times;
                {line.addons.length > 0 &&
                  ` (${line.addons.map((addon) => addon.addon_name).join(", ")})`}
              </span>
            </span>

            <span className="shrink-0 font-display text-[13.5px] font-semibold text-ink">
              {formatPeso(line.subtotal)}
            </span>
          </li>
        ))}

        {items.length === 0 && (
          <li className="py-3 text-center font-display text-[12.5px] text-[#a39f9b]">
            Nothing selected yet.
          </li>
        )}
      </ul>

      <div className="space-y-2 px-5 py-3.5">
        <div className="flex items-center justify-between gap-3 font-display text-[13px] text-[#6f6b68]">
          <span>Subtotal</span>
          <span className="font-semibold text-ink">{formatPeso(quote?.subtotal)}</span>
        </div>

        {discount?.applied && (
          <div className="flex items-center justify-between gap-3 font-display text-[13px] font-semibold text-brand-600">
            <span>
              {discount.type_label} Discount ({discount.percentage}%)
            </span>
            <span>&minus;{formatPeso(discount.amount)}</span>
          </div>
        )}
      </div>

      <div className="px-5 pb-4">
        <div className="flex items-center justify-between gap-3 rounded-[12px] bg-[#faf7f3] px-4 py-3">
          <span className="font-display text-[14px] font-bold text-ink">Estimated Total</span>
          <span className="font-display text-[19px] font-extrabold leading-none text-brand-600">
            {formatPeso(quote?.total)}
          </span>
        </div>

        <p className="m-0 mt-2 text-center font-display text-[11px] leading-snug text-[#a39f9b]">
          Payable only after the store accepts
        </p>

        <Button
          size="lg"
          fullWidth
          className="mt-3"
          disabled={!canSubmit || !acknowledged}
          loading={isSubmitting}
          loadingLabel="Sending&hellip;"
          onClick={onSubmit}
        >
          {canSubmit && acknowledged ? (
            <IconSend size={17} stroke={2} aria-hidden="true" />
          ) : (
            <IconLock size={15} stroke={2} aria-hidden="true" />
          )}
          Send Request
        </Button>

        <label className="mt-3 flex cursor-pointer items-start gap-2.5 border-t border-[#f4f1ec] pt-3">
          <span className="relative mt-[1px] flex h-[18px] w-[18px] shrink-0 items-center justify-center">
            <input
              type="checkbox"
              checked={acknowledged}
              onChange={(event) => onAcknowledge?.(event.target.checked)}
              className="peer h-full w-full cursor-pointer appearance-none rounded-full border-2 border-[#ddd6cd] bg-white transition-colors checked:border-brand-500 checked:bg-brand-500"
            />
            <IconCheck
              size={11}
              stroke={3.5}
              aria-hidden="true"
              className="pointer-events-none absolute text-white opacity-0 transition-opacity peer-checked:opacity-100"
            />
          </span>

          <span className="font-display text-[11.5px] leading-snug text-[#8d8884]">
            I understand this is a <span className="font-semibold text-brand-600">request</span>, that
            the store may decline it, and that prices may change before I pay
          </span>
        </label>
      </div>
    </aside>
  );
}

export default AdvanceSummary;
