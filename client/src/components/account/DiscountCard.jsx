import { useState } from "react";
import {
  IconAlertTriangle,
  IconChevronDown,
  IconClockHour4,
  IconDiscount2,
  IconRosetteDiscountCheck,
} from "@tabler/icons-react";

import Button from "../ui/Button";
import DiscountUploadModal from "./DiscountUploadModal";
import { CLAIM_STATUS, DISCOUNT_TYPES, DISCOUNT_TYPE_LABEL } from "../../lib/profile";

/**
 * Statutory discount eligibility, in whichever of its four states applies.
 *
 * Not rendered for staff at all - the server omits `discount` from their
 * profile payload, so there is nothing here to hide client-side.
 *
 * Scope is deliberately eligibility only: applying, and the agent's decision.
 * Using the discount happens at checkout and is a later module, which is why
 * an approved card says what the customer HAS rather than offering to spend it.
 */

function StatusShell({ tone, icon, title, children }) {
  // See the note in Profile.jsx - capitalised params trip no-unused-vars here.
  const Glyph = icon;

  const tones = {
    approved: { ring: "border-[#c8ebd4]", bg: "bg-[#f2fbf5]", fg: "text-[#2f9e44]", body: "text-[#2c6b3f]" },
    pending: { ring: "border-[#ffe6a8]", bg: "bg-[#fff9e8]", fg: "text-[#b8820a]", body: "text-[#7a5620]" },
    rejected: { ring: "border-[#ffd7d5]", bg: "bg-[#fff1f1]", fg: "text-[#e5322d]", body: "text-[#9c2b28]" },
    idle: { ring: "border-[#ffd9b8]", bg: "bg-[#fff6ec]", fg: "text-brand-600", body: "text-[#7a5620]" },
  }[tone];

  return (
    <div className={`flex items-start gap-3 rounded-[14px] border ${tones.ring} ${tones.bg} px-4 py-3.5`}>
      <Glyph size={20} stroke={1.9} className={`mt-0.5 shrink-0 ${tones.fg}`} aria-hidden="true" />

      <div className="min-w-0 flex-1">
        <p className={`m-0 font-display text-[13.5px] font-bold ${tones.fg}`}>{title}</p>
        <div className={`mt-0.5 font-display text-[12.5px] leading-relaxed ${tones.body}`}>{children}</div>
      </div>
    </div>
  );
}

function DiscountCard({ discount, onApply, isSubmitting, justSubmitted, onDismissSubmitted }) {
  const [type, setType] = useState(DISCOUNT_TYPES.SENIOR);
  const [modalOpen, setModalOpen] = useState(false);

  if (!discount) return null;

  const { claim, can_apply: canApply, percentage } = discount;
  const status = claim?.discount_status ?? null;

  const openModal = () => setModalOpen(true);

  const closeModal = () => {
    setModalOpen(false);
    // Clears the success panel so the next open starts on the form.
    if (justSubmitted) onDismissSubmitted();
  };

  return (
    <>
      <section className="rounded-[14px] border border-[#ffd9b8] bg-[#fff6ec] px-4 py-3.5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex min-w-0 items-start gap-2.5">
            <IconDiscount2 size={20} stroke={1.9} className="mt-0.5 shrink-0 text-brand-600" aria-hidden="true" />

            <div className="min-w-0">
              <p className="m-0 font-display text-[13.5px] font-bold text-brand-700">Discount Eligibility</p>
              <p className="m-0 font-display text-[12.5px] text-[#a07a4a]">
                {status === CLAIM_STATUS.APPROVED
                  ? `${percentage}% ${DISCOUNT_TYPE_LABEL[claim.discount_type]} discount active`
                  : "Apply for discounts?"}
              </p>
            </div>
          </div>

          {canApply && (
            <div className="flex shrink-0 items-center gap-2">
              {/* Native select: it is a two-option choice on a form, and a
                  custom dropdown here would lose keyboard and mobile
                  behaviour for nothing. */}
              <div className="relative">
                <select
                  value={type}
                  onChange={(e) => setType(e.target.value)}
                  aria-label="Discount type"
                  className="h-[38px] appearance-none rounded-[9px] border border-[#ffd9b8] bg-white pl-3.5 pr-8 font-display text-[13px] font-semibold text-brand-700 outline-none transition-colors focus:border-brand-500"
                >
                  <option value={DISCOUNT_TYPES.SENIOR}>Senior Citizen</option>
                  <option value={DISCOUNT_TYPES.PWD}>PWD</option>
                </select>
                <IconChevronDown
                  size={15}
                  stroke={2.2}
                  aria-hidden="true"
                  className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-brand-600"
                />
              </div>

              <Button size="sm" onClick={openModal}>
                Apply
              </Button>
            </div>
          )}
        </div>

        {status && (
          <div className="mt-3">
            {status === CLAIM_STATUS.APPROVED && (
              <StatusShell tone="approved" icon={IconRosetteDiscountCheck} title="Approved">
                Your {DISCOUNT_TYPE_LABEL[claim.discount_type]} discount is verified. It will be available to
                use at checkout, once per day.
              </StatusShell>
            )}

            {status === CLAIM_STATUS.PENDING && (
              <StatusShell tone="pending" icon={IconClockHour4} title="Waiting for review">
                Your {DISCOUNT_TYPE_LABEL[claim.discount_type]} ID is with a Store Agent. We&rsquo;ll notify you
                as soon as it is approved.
              </StatusShell>
            )}

            {status === CLAIM_STATUS.REJECTED && (
              <StatusShell tone="rejected" icon={IconAlertTriangle} title="Not approved">
                {claim.rejection_reason || "Your application was not approved."}
                <span className="mt-1 block">You can fix the problem and apply again.</span>
              </StatusShell>
            )}
          </div>
        )}
      </section>

      <DiscountUploadModal
        opened={modalOpen}
        onClose={closeModal}
        discountType={type}
        percentage={percentage}
        onSubmit={(file) => onApply({ type, file })}
        isSubmitting={isSubmitting}
        submitted={justSubmitted}
      />
    </>
  );
}

export default DiscountCard;
