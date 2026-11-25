import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { IconClockHour4, IconRosetteDiscountCheck, IconTag } from "@tabler/icons-react";

import DiscountUploadModal from "../account/DiscountUploadModal";
import toast from "../app/Toast";
import { useCartState } from "../../context/useCart";
import { CART_MODE } from "../../lib/cartModes";
import { formatPeso } from "../../lib/menu";
import {
  CLAIM_STATUS,
  DISCOUNT_STANDING_KEY,
  DISCOUNT_TYPES,
  DISCOUNT_TYPE_LABEL,
  applyForDiscount,
  fetchDiscountStanding,
} from "../../lib/profile";
import { discountLine, discountSaving } from "../../lib/statutoryDiscount";

/** "₱126" for a whole amount, "₱126.43" otherwise. */
function pesos(amount) {
  return Number.isInteger(amount) ? `₱${amount.toLocaleString("en-PH")}` : formatPeso(amount);
}

const TYPES = [
  { value: DISCOUNT_TYPES.SENIOR, label: "Senior" },
  { value: DISCOUNT_TYPES.PWD, label: "PWD" },
];

/**
 * The Senior Citizen and PWD banner on the Advance Order page (the user's
 * design, 2026-11-26). The customer picks Senior or PWD and applies right
 * here: Apply opens the same ID upload as Profile, so nobody has to leave
 * the menu to apply.
 *
 * What it would save is worked out from the ticked lines of the advance cart,
 * as checkout does: one unit of the highest-priced dish marked for the
 * discount. With no such dish, it shows the rate instead. Once applied it
 * stays, saying "Under review" or "Verified"; a refused application just
 * offers "Apply again" (the reason is on Profile and in the notification).
 */
function DiscountBanner() {
  const queryClient = useQueryClient();
  const [type, setType] = useState(DISCOUNT_TYPES.SENIOR);
  const [modalOpen, setModalOpen] = useState(false);
  const [submitted, setSubmitted] = useState(false);

  const { data: standing } = useQuery({
    queryKey: DISCOUNT_STANDING_KEY,
    queryFn: fetchDiscountStanding,
    staleTime: 60 * 1000,
  });

  const { cart, selectedIds } = useCartState(CART_MODE.ADVANCE);

  const percentage = Number(standing?.percentage ?? 20);

  const saving = useMemo(() => {
    const line = discountLine(cart.filter((entry) => selectedIds.includes(entry.id)));

    return line ? discountSaving(line.base_price, percentage) : 0;
  }, [cart, selectedIds, percentage]);

  const apply = useMutation({
    mutationFn: applyForDiscount,
    onSuccess: (payload) => {
      queryClient.setQueryData(DISCOUNT_STANDING_KEY, (prev) => ({ ...prev, ...payload }));
      queryClient.invalidateQueries({ queryKey: ["profile"] });
      setSubmitted(true);
    },
    onError: (error) => {
      toast.error(error?.message ?? "We could not submit your application.");
      // A claim already exists: refresh so the banner stops offering Apply.
      if (error?.payload?.code) queryClient.invalidateQueries({ queryKey: DISCOUNT_STANDING_KEY });
    },
  });

  if (!standing) return null;

  const status = standing.claim?.discount_status ?? null;
  const claimType = DISCOUNT_TYPE_LABEL[standing.claim?.discount_type] ?? "Senior Citizen or PWD";

  const closeModal = () => {
    setModalOpen(false);
    setSubmitted(false);
  };

  return (
    <section
      aria-label="Senior Citizen and PWD discount"
      className="overflow-hidden rounded-[18px] bg-gradient-to-br from-[#e05f1b] via-[#ee7a25] to-[#f6a03f] p-4 text-white sm:p-5"
      data-testid="discount-banner"
    >
      <div className="flex items-start justify-between gap-3">
        <div className="flex min-w-0 items-center gap-3">
          <span className="grid h-11 w-11 shrink-0 place-items-center rounded-[12px] bg-white/20">
            <IconTag size={22} stroke={2} aria-hidden="true" />
          </span>
          <div className="min-w-0">
            <p className="m-0 font-display text-[12px] font-medium text-white/85">PWD &amp; Senior Citizens Privilege</p>
            <h2 className="m-0 font-display text-[18px] font-extrabold leading-tight tracking-[-0.2px] sm:text-[21px]">
              Get {percentage}% Off Your Order
            </h2>
          </div>
        </div>

        <div
          className="shrink-0 rounded-[12px] bg-white/15 px-3 py-1.5 text-center ring-1 ring-inset ring-white/30"
          data-testid="discount-banner-badge"
        >
          {saving > 0 ? (
            <>
              <p className="m-0 font-display text-[9px] font-semibold uppercase tracking-[0.08em] text-white/85">You save</p>
              <p className="m-0 font-display text-[20px] font-extrabold leading-tight">{pesos(saving)}</p>
              <p className="m-0 font-display text-[9px] text-white/85">on this order</p>
            </>
          ) : (
            <>
              <p className="m-0 font-display text-[20px] font-extrabold leading-tight">{percentage}%</p>
              <p className="m-0 font-display text-[9px] font-semibold uppercase tracking-[0.08em] text-white/85">Off</p>
            </>
          )}
        </div>
      </div>

      <div className="mt-4 flex flex-col gap-2.5 sm:flex-row sm:items-stretch">
        {status === CLAIM_STATUS.PENDING ? (
          <p
            className="m-0 flex min-h-[46px] flex-1 items-center gap-2 rounded-[12px] bg-white/15 px-4 py-2 font-display text-[13px] font-semibold ring-1 ring-inset ring-white/30"
            data-testid="discount-banner-pending"
          >
            <IconClockHour4 size={18} stroke={2} aria-hidden="true" className="shrink-0" />
            Under review: a Store Agent is checking your {claimType} ID.
          </p>
        ) : status === CLAIM_STATUS.APPROVED ? (
          <p
            className="m-0 flex min-h-[46px] flex-1 items-center gap-2 rounded-[12px] bg-white/15 px-4 py-2 font-display text-[13px] font-semibold ring-1 ring-inset ring-white/30"
            data-testid="discount-banner-approved"
          >
            <IconRosetteDiscountCheck size={18} stroke={2} aria-hidden="true" className="shrink-0" />
            Verified: choose your {claimType} discount at checkout.
          </p>
        ) : (
          <>
            <div role="radiogroup" aria-label="Discount type" className="flex shrink-0 rounded-[12px] bg-white/10 p-1 ring-1 ring-inset ring-white/40">
              {TYPES.map((option) => {
                const chosen = option.value === type;

                return (
                  <button
                    key={option.value}
                    type="button"
                    role="radio"
                    aria-checked={chosen}
                    onClick={() => setType(option.value)}
                    data-testid={`discount-banner-${option.value}`}
                    className={`h-[38px] flex-1 rounded-[9px] px-5 font-display transition-colors sm:flex-none ${
                      chosen ? "bg-white/30 text-white" : "text-white/85 hover:bg-white/10"
                    }`}
                  >
                    {/* On the span: Mantine resets type on <button> itself. */}
                    <span className={`text-[13px] ${chosen ? "font-bold" : "font-semibold"}`}>{option.label}</span>
                  </button>
                );
              })}
            </div>

            <button
              type="button"
              onClick={() => setModalOpen(true)}
              data-testid="discount-banner-apply"
              className="h-[46px] flex-1 rounded-[12px] bg-white px-4 font-display text-brand-600 shadow-[0_6px_16px_-8px_rgba(120,40,0,0.5)] transition-colors hover:bg-[#fff7ef]"
            >
              <span className="text-[14.5px] font-bold">
                {status === CLAIM_STATUS.REJECTED
                  ? "Apply again"
                  : saving > 0
                    ? `Apply — Save ${formatPeso(saving)}`
                    : "Apply for the discount"}
              </span>
            </button>
          </>
        )}
      </div>

      <DiscountUploadModal
        opened={modalOpen}
        onClose={closeModal}
        discountType={type}
        percentage={percentage}
        onSubmit={(file) => apply.mutate({ type, file })}
        isSubmitting={apply.isPending}
        submitted={submitted}
      />
    </section>
  );
}

export default DiscountBanner;
