import { useState } from "react";
import { Modal } from "@mantine/core";
import { AnimatePresence, motion, useReducedMotion } from "framer-motion";
import {
  IconArrowRight,
  IconCircleCheck,
  IconCircleX,
  IconExternalLink,
  IconHourglassHigh,
  IconMail,
  IconPhone,
  IconPhotoOff,
  IconZoomIn,
} from "@tabler/icons-react";

import { CLAIM_TYPE, claimLabel, claimantName, formatStamp, initialsOf } from "../../lib/agent";

const MotionDiv = motion.div;

function IdPhoto({ src, name, onEnlarge }) {
  const [failed, setFailed] = useState(false);

  if (!src || failed) {
    return (
      <div className="grid aspect-[16/10] w-full place-items-center rounded-[9px] bg-white/80 text-center ring-1 ring-[#ffe1c6]">
        <span className="flex flex-col items-center gap-1 px-4 font-display text-[11.5px] text-[#8d8884]">
          <IconPhotoOff size={22} stroke={1.7} />
          The ID photo could not be loaded.
          {src && (
            <a href={src} target="_blank" rel="noopener noreferrer" className="font-semibold text-brand-600 underline">
              Open it directly
            </a>
          )}
        </span>
      </div>
    );
  }

  return (
    <button
      type="button"
      onClick={onEnlarge}
      aria-label={`Enlarge the ID photo ${name} submitted`}
      className="group relative block aspect-[16/10] w-full overflow-hidden rounded-[9px] bg-white ring-1 ring-[#ffe1c6] outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
    >
      <img
        src={src}
        alt={`ID submitted by ${name}`}
        onError={() => setFailed(true)}
        className="h-full w-full object-contain"
        draggable={false}
      />
      <span className="absolute bottom-1.5 right-1.5 inline-flex items-center gap-1 rounded-full bg-black/55 px-2 py-0.5 font-display text-[10px] font-medium text-white opacity-90 transition-opacity group-hover:opacity-100">
        <IconZoomIn size={12} stroke={2.2} />
        Enlarge
      </span>
    </button>
  );
}

function Outcome({ claim }) {
  const approved = claim.discount_status === "approved";
  const reviewer = [claim.verifier?.first_name, claim.verifier?.last_name].filter(Boolean).join(" ");
  const by = [reviewer && `by ${reviewer}`, formatStamp(claim.verified_at)].filter(Boolean).join(" · ");

  return (
    <div data-testid="claim-outcome" data-status={claim.discount_status}>
      <p
        className={`m-0 flex h-[44px] items-center justify-center gap-2 rounded-[10px] font-display text-[13.5px] font-semibold ${
          approved ? "bg-[#ecf9f0] text-[#2f9e44]" : "bg-[#fdecec] text-[#e5322d]"
        }`}
      >
        {approved ? <IconCircleCheck size={18} stroke={2.1} /> : <IconCircleX size={18} stroke={2.1} />}
        {approved ? "Discount Approved" : "Discount Rejected"}
      </p>

      {!approved && claim.rejection_reason && (
        <p className="m-0 mt-2 rounded-[10px] bg-[#faf6f0] px-3 py-2 font-display text-[11.5px] leading-relaxed text-[#6f6b68]">
          <span className="font-semibold text-ink">Reason sent to the customer:</span> {claim.rejection_reason}
        </p>
      )}

      {by && <p className="m-0 mt-1.5 text-center font-display text-[10.5px] text-[#a39f9b]">{by}</p>}
    </div>
  );
}

/**
 * The Citizen ID Verification Card: the photo the customer sent, who sent it,
 * what an approval grants, and the decision.
 *
 * Approval is on the account, not on an order - once approved, the customer
 * may take the discount at checkout on food items, one discounted order a day.
 */
function ClaimReviewPanel({ claim, percentage, busy, approving, canAct, onApprove, onReject, nextClaim, onNext }) {
  const reduceMotion = useReducedMotion();
  const [enlarged, setEnlarged] = useState(false);

  if (!claim) {
    return (
      <div className="grid h-full min-h-[280px] place-items-center px-6 text-center">
        <p className="m-0 font-display text-[12.5px] text-[#a39f9b]">Select an application to review the ID.</p>
      </div>
    );
  }

  const name = claimantName(claim);
  const type = CLAIM_TYPE[claim.discount_type] ?? { short: "ID", card: "Discount ID" };
  const pending = claim.discount_status === "pending";
  const rejected = claim.discount_status === "rejected";
  const rate = percentage ?? 20;

  return (
    <div className="flex flex-col" data-testid="claim-panel" data-claim={claim.id}>
      <div className="flex items-center justify-between gap-2 border-b border-[#f3ede4] px-5 py-4">
        <div className="min-w-0">
          <p className="m-0 font-display text-[11px] font-bold uppercase tracking-[0.6px] text-ink">Verification portal</p>
          <p className="m-0 mt-0.5 font-display text-[12px] font-bold text-brand-600">Application #{claimLabel(claim)}</p>
        </div>
      </div>

      <AnimatePresence mode="wait" initial={false}>
        <MotionDiv
          key={claim.id}
          initial={reduceMotion ? false : { opacity: 0, y: 6 }}
          animate={{ opacity: 1, y: 0 }}
          exit={reduceMotion ? undefined : { opacity: 0, y: -4 }}
          transition={{ duration: 0.18 }}
          className="flex flex-col gap-3 px-5 py-4"
        >
          <div className="flex items-center justify-between gap-2">
            <h2 className="m-0 font-display text-[13px] font-bold text-ink">Citizen ID Verification Card</h2>
            <span className="rounded-full bg-brand-500 px-2.5 py-0.5 font-display text-[9.5px] font-bold uppercase tracking-[0.5px] text-white">
              {type.short}
            </span>
          </div>

          <div>
            <p className="m-0 font-display text-[15px] font-bold text-ink">{type.card}</p>
            <p className="m-0 mt-0.5 font-display text-[11.5px] text-[#8d8884]">
              {pending ? "Submitted for verification" : "Submitted"} · {formatStamp(claim.created_at)}
            </p>
          </div>

          <div className="flex flex-col gap-3 rounded-[12px] border border-[#ffcda1] bg-[#fff6ec] p-3">
            <IdPhoto src={claim.id_image} name={name} onEnlarge={() => setEnlarged(true)} />

            <div className="flex flex-col items-center gap-1.5 pb-1">
              <span className="grid h-10 w-10 place-items-center rounded-[10px] bg-brand-500 font-display text-[14px] font-bold text-white">
                {initialsOf(name)}
              </span>
              <span className="font-display text-[13px] font-semibold text-brand-600">{name}</span>
            </div>
          </div>

          {(claim.user?.email || claim.user?.phone_number) && (
            <div className="flex flex-col gap-1 font-display text-[11.5px] text-[#6f6b68]">
              {claim.user?.email && (
                <span className="flex items-center gap-1.5 truncate">
                  <IconMail size={13} stroke={1.9} className="shrink-0 text-[#a39f9b]" />
                  {claim.user.email}
                </span>
              )}
              {claim.user?.phone_number && (
                <span className="flex items-center gap-1.5">
                  <IconPhone size={13} stroke={1.9} className="shrink-0 text-[#a39f9b]" />
                  {claim.user.phone_number}
                </span>
              )}
            </div>
          )}

          <div>
            <div className="flex items-center justify-between rounded-[10px] bg-[#f7f4f0] px-3 py-2.5 font-display text-[12px]">
              <span className="text-[#5f5b58]">
                {rate}% Discount {pending ? "on approval" : rejected ? "not granted" : "Applied"}
              </span>
              <span className={`font-bold ${rejected ? "text-[#a39f9b] line-through" : "text-[#2f9e44]"}`}>−{rate}%</span>
            </div>
            <p className="m-0 mt-1.5 font-display text-[10.5px] leading-relaxed text-[#a39f9b]">
              Taken at checkout on food items, not add-ons · one discounted order a day.
            </p>
          </div>

          {pending && !canAct ? (
            <p
              data-testid="claim-awaiting"
              className="m-0 flex h-[44px] items-center justify-center gap-2 rounded-[10px] bg-[#fff8e1] font-display text-[13px] font-semibold text-[#c98500]"
            >
              <IconHourglassHigh size={17} stroke={2.1} />
              Awaiting Store Agent review
            </p>
          ) : pending ? (
            <div className="grid grid-cols-[1.4fr_1fr] gap-2.5 text-[13px]">
              <button
                type="button"
                onClick={() => onApprove(claim)}
                disabled={busy}
                data-testid="claim-approve"
                className="inline-flex h-[44px] items-center justify-center gap-1.5 rounded-[10px] bg-[#2f9e44] px-3 font-display text-[13px] font-semibold text-white shadow-[0_6px_16px_-8px_rgba(47,158,68,0.8)] transition-colors hover:bg-[#278a3a] disabled:cursor-not-allowed disabled:opacity-55"
              >
                <IconCircleCheck size={17} stroke={2.1} className="shrink-0" />
                <span className="whitespace-nowrap text-[13px]">{approving ? "Approving…" : "Approve Discount"}</span>
              </button>
              <button
                type="button"
                onClick={() => onReject(claim)}
                disabled={busy}
                data-testid="claim-reject"
                className="inline-flex h-[44px] items-center justify-center gap-1.5 rounded-[10px] border border-brand-500 bg-white px-3 font-display text-[13px] font-semibold text-brand-600 transition-colors hover:bg-brand-50 disabled:cursor-not-allowed disabled:opacity-55"
              >
                <IconCircleX size={17} stroke={2.1} className="shrink-0" />
                <span className="text-[13px]">Reject</span>
              </button>
            </div>
          ) : (
            <Outcome claim={claim} />
          )}

          {!pending && nextClaim && (
            <button
              type="button"
              onClick={() => onNext(nextClaim)}
              className="inline-flex items-center justify-center gap-1.5 self-center bg-transparent font-display font-semibold text-brand-600 hover:text-brand-800"
            >
              <span className="text-[12px]">Review the next application</span>
              <IconArrowRight size={14} stroke={2.1} />
            </button>
          )}
        </MotionDiv>
      </AnimatePresence>

      <Modal
        opened={enlarged}
        onClose={() => setEnlarged(false)}
        size="xl"
        centered
        radius="md"
        title={`${type.card} · ${name}`}
      >
        <img
          src={claim.id_image}
          alt={`ID submitted by ${name}`}
          className="mx-auto block max-h-[72vh] w-auto max-w-full rounded-[8px] object-contain"
        />
        <a
          href={claim.id_image}
          target="_blank"
          rel="noopener noreferrer"
          className="mt-3 inline-flex items-center gap-1 font-display text-[12px] font-semibold text-brand-600"
        >
          Open the original in a new tab
          <IconExternalLink size={13} stroke={2} />
        </a>
      </Modal>
    </div>
  );
}

export default ClaimReviewPanel;
