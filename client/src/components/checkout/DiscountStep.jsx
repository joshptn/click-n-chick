import { Link } from "react-router-dom";
import {
  IconAlertTriangle,
  IconChevronRight,
  IconClockHour4,
  IconDiscount2,
  IconInfoCircle,
} from "@tabler/icons-react";

import Button from "../ui/Button";
import { formatPeso } from "../../lib/menu";

function Unavailable({ discount }) {
  const states = {
    none: {
      icon: IconDiscount2,
      title: "No discount on this account",
      body: "Senior Citizens and PWDs can apply once with a photo of their ID. Approval is usually same-day.",
      action: { to: "/account/profile", label: "Apply for a discount" },
    },
    pending: {
      icon: IconClockHour4,
      title: "Your application is being reviewed",
      body: "A store agent is checking your ID. You can still place this order - the discount will be available once you are approved.",
      action: null,
    },
    rejected: {
      icon: IconAlertTriangle,
      title: "Your last application was not approved",
      body: "You can apply again with a clearer photo of your ID.",
      action: { to: "/account/profile", label: "Apply again" },
    },
  };

  const state = states[discount.status] ?? states.none;
  const Icon = state.icon;

  return (
    <div className="rounded-[12px] border border-[#f0e9df] bg-[#faf7f3] px-4 py-3.5">
      <p className="m-0 flex items-center gap-2 font-display text-[13.5px] font-bold text-ink">
        <Icon size={16} stroke={2} aria-hidden="true" className="shrink-0 text-[#8d8884]" />
        {state.title}
      </p>

      <p className="m-0 mt-1 font-display text-[12.5px] leading-snug text-[#6f6b68]">{state.body}</p>

      {state.action && (
        <Link
          to={state.action.to}
          className="mt-2 inline-flex items-center gap-1 font-display text-[12.5px] font-bold text-brand-600 no-underline hover:underline"
        >
          {state.action.label}
          <IconChevronRight size={13} stroke={2.6} aria-hidden="true" />
        </Link>
      )}
    </div>
  );
}

function DiscountStep({
  discount,
  applied,
  onChange,
  isQuoting,
  onContinue,
  onBack,
  continueLabel = "Continue to Payment",
}) {
  if (!discount) {
    return (
      <div className="space-y-3">
        <div className="h-[76px] animate-pulse rounded-[12px] bg-[#f4f1ec]" />
        <div className="h-[50px] animate-pulse rounded-[12px] bg-[#f7f4f0]" />
      </div>
    );
  }

  const spent = discount.eligible && discount.used_today;
  const offerable = discount.eligible && !discount.used_today;

  return (
    <div className="flex flex-col gap-4">
      {offerable && (
        <label
          className={`flex cursor-pointer items-start gap-3 rounded-[12px] border px-4 py-3.5 transition-colors ${
            applied ? "border-brand-500 bg-[#fff8f1]" : "border-[#e8e4de] bg-white hover:bg-[#faf7f3]"
          }`}
        >
          <input
            type="checkbox"
            checked={applied}
            onChange={(event) => onChange(event.target.checked)}
            className="mt-0.5 h-[18px] w-[18px] shrink-0 cursor-pointer accent-brand-500"
          />

          <span className="min-w-0 flex-1">
            <span className="block font-display text-[14px] font-bold text-ink">
              Use my discount for the day
            </span>
            <span className="block font-display text-[12.5px] leading-snug text-[#6f6b68]">
              {discount.type_label ?? "Senior Citizens and PWDs"} receive {discount.percentage}% off the
              subtotal ({formatPeso(discount.available_amount)})
            </span>
          </span>
        </label>
      )}

      {spent && (
        <div className="rounded-[12px] border border-[#ffe6a8] bg-[#fff9e8] px-4 py-3.5">
          <p className="m-0 flex items-center gap-2 font-display text-[13.5px] font-bold text-[#8a6206]">
            <IconClockHour4 size={16} stroke={2} aria-hidden="true" className="shrink-0" />
            You have used your discount today
          </p>
          <p className="m-0 mt-1 font-display text-[12.5px] leading-snug text-[#8a6206]">
            It is one order per day, and it resets tomorrow. You can still place this order at the
            normal price.
          </p>
        </div>
      )}

      {!discount.eligible && <Unavailable discount={discount} />}

      <p className="m-0 flex items-start gap-2 font-display text-[12px] leading-snug text-[#8d8884]">
        <IconInfoCircle size={14} stroke={2} aria-hidden="true" className="mt-px shrink-0 text-brand-500" />
        Discount applies to the meal items only, not to add-ons and delivery fee.
      </p>

      <div className="mt-1 flex flex-col gap-2.5 sm:flex-row sm:items-center">
        <Button
          variant="ghost"
          size="lg"
          onClick={onBack}
          className="border border-[#ece7e0] text-[#6f6b68] sm:w-[210px]"
        >
          Back
        </Button>

        <Button
          size="lg"
          fullWidth
          onClick={onContinue}
          loading={isQuoting}
          loadingLabel="Checking&hellip;"
        >
          {continueLabel}
          <IconChevronRight size={16} stroke={2.6} aria-hidden="true" />
        </Button>
      </div>
    </div>
  );
}

export default DiscountStep;
