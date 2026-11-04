import { motion, useReducedMotion } from "framer-motion";
import { IconCircleCheck, IconCircleX, IconId } from "@tabler/icons-react";

import { CLAIM_TYPE, claimLabel, claimantName, formatStamp, timeAgo } from "../../lib/agent";

const MotionButton = motion.button;

function StatusPill({ claim }) {
  const type = CLAIM_TYPE[claim.discount_type]?.short ?? "ID";

  if (claim.discount_status === "approved") {
    return (
      <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-[#ecf9f0] px-2 py-0.5 font-display text-[10px] font-semibold text-[#2f9e44]">
        <IconCircleCheck size={12} stroke={2.2} />
        Approved
      </span>
    );
  }

  if (claim.discount_status === "rejected") {
    return (
      <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-[#fdecec] px-2 py-0.5 font-display text-[10px] font-semibold text-[#e5322d]">
        <IconCircleX size={12} stroke={2.2} />
        Rejected
      </span>
    );
  }

  return (
    <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-[#eef4ff] px-2 py-0.5 font-display text-[10px] font-semibold text-[#2563eb]">
      <IconId size={12} stroke={2.2} />
      {type} ID
    </span>
  );
}

/**
 * One application in the review queue. Pending ones say how long the customer
 * has waited; decided ones say when they were settled.
 */
function ClaimCard({ claim, selected, onSelect }) {
  const reduceMotion = useReducedMotion();
  const pending = claim.discount_status === "pending";
  const type = CLAIM_TYPE[claim.discount_type]?.card ?? "Discount ID";

  return (
    <MotionButton
      type="button"
      layout={!reduceMotion}
      initial={reduceMotion ? false : { opacity: 0, y: 6 }}
      animate={{ opacity: 1, y: 0 }}
      exit={reduceMotion ? undefined : { opacity: 0, x: -12, transition: { duration: 0.18 } }}
      transition={{ type: "spring", stiffness: 420, damping: 36 }}
      onClick={() => onSelect(claim)}
      aria-pressed={selected}
      aria-label={`Application ${claimLabel(claim)}, ${claimantName(claim)}, ${type}`}
      data-testid="claim-card"
      data-status={claim.discount_status}
      className={`flex w-full items-center gap-3 rounded-[12px] border px-4 py-3 text-left outline-none transition-[background-color,border-color,box-shadow] focus-visible:ring-2 focus-visible:ring-brand-300 ${
        selected
          ? "border-brand-500 bg-[#fff6ec] shadow-[0_6px_18px_-12px_rgba(255,139,43,0.7)]"
          : "border-[#f0e9df] bg-white shadow-[0_2px_10px_-6px_rgba(65,33,17,0.16)] hover:border-brand-200"
      }`}
    >
      <span className="min-w-0 flex-1">
        <span className="block font-display text-[12.5px] font-bold text-brand-600">{claimLabel(claim)}</span>
        <span className="mt-0.5 block truncate font-display text-[13px] font-medium text-ink">{claimantName(claim)}</span>
        <span className="mt-0.5 block truncate font-display text-[11px] text-[#8d8884]">
          {type} ·{" "}
          {pending ? `submitted ${timeAgo(claim.created_at)}` : `reviewed ${formatStamp(claim.verified_at)}`}
        </span>
      </span>

      <StatusPill claim={claim} />
    </MotionButton>
  );
}

export default ClaimCard;
