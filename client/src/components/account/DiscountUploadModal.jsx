import { useEffect, useRef, useState } from "react";
import { Modal } from "@mantine/core";
import { AnimatePresence, motion } from "framer-motion";
import {
  IconCircleCheck,
  IconFileTypeJpg,
  IconInfoCircle,
  IconUpload,
  IconX,
} from "@tabler/icons-react";

import Button from "../ui/Button";
import { DISCOUNT_TYPE_LABEL } from "../../lib/profile";

/**
 * Submit a statutory-discount ID for Store Agent review.
 *
 * Two states in one dialog: the form, and the confirmation that replaces it on
 * success. Keeping the confirmation here rather than as a toast matters -
 * "an agent will review this" is the part people need to read, and a toast
 * that auto-dismisses in four seconds is the wrong place for it.
 *
 * Client-side type and size checks mirror the server's `mimes:jpg,jpeg,png`
 * and `max:5120`. They exist to fail fast on a 12 MB photo before it is
 * uploaded over mobile data, not as the validation - the server re-checks.
 */

const MAX_BYTES = 5 * 1024 * 1024;
const ACCEPTED = ["image/jpeg", "image/png"];

const MotionDiv = motion.div;

function formatSize(bytes) {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function DiscountUploadModal({ opened, onClose, discountType, percentage = 20, onSubmit, isSubmitting, submitted }) {
  const [file, setFile] = useState(null);
  const [error, setError] = useState(null);
  const [dragging, setDragging] = useState(false);
  const inputRef = useRef(null);

  const label = DISCOUNT_TYPE_LABEL[discountType] ?? "Discount";

  // A reopened dialog must not still be holding the previous attempt's file.
  useEffect(() => {
    if (!opened) {
      setFile(null);
      setError(null);
      setDragging(false);
    }
  }, [opened]);

  const accept = (candidate) => {
    if (!candidate) return;

    if (!ACCEPTED.includes(candidate.type)) {
      setError("That file type is not supported. Upload a .jpg or .png image.");
      return;
    }

    if (candidate.size > MAX_BYTES) {
      setError(`That image is ${formatSize(candidate.size)}. The limit is 5 MB.`);
      return;
    }

    setError(null);
    setFile(candidate);
  };

  const clearFile = () => {
    setFile(null);
    setError(null);
    // Without this, re-picking the SAME file fires no change event.
    if (inputRef.current) inputRef.current.value = "";
  };

  return (
    <Modal
      opened={opened}
      onClose={onClose}
      centered
      radius="lg"
      size={470}
      withCloseButton={!submitted}
      title={
        submitted ? null : (
          <div>
            <p className="m-0 font-display text-[16px] font-bold text-ink">{label} Verification</p>
            <p className="m-0 mt-0.5 font-display text-[12.5px] text-[#8d8884]">
              Upload your valid ID to unlock the discount
            </p>
          </div>
        )
      }
    >
      <AnimatePresence mode="wait" initial={false}>
        {submitted ? (
          <MotionDiv
            key="done"
            initial={{ opacity: 0, scale: 0.96 }}
            animate={{ opacity: 1, scale: 1 }}
            transition={{ duration: 0.24, ease: "easeOut" }}
            className="grid place-items-center px-2 py-6 text-center"
          >
            <span
              aria-hidden="true"
              className="grid h-[60px] w-[60px] place-items-center rounded-full bg-[#e9f8ee] text-[#2f9e44]"
            >
              <IconCircleCheck size={34} stroke={1.8} />
            </span>

            <h3 className="m-0 mt-4 font-display text-[18px] font-bold text-ink">Submitted for Review</h3>

            <p className="m-0 mt-2 max-w-[320px] font-display text-[13px] leading-relaxed text-[#6f6b68]">
              Your ID has been sent to a Store Agent. You&rsquo;ll be notified once your{" "}
              <span className="font-semibold text-brand-600">
                {percentage}% {label} Discount
              </span>{" "}
              is approved.
            </p>

            <Button size="sm" className="mt-5" onClick={onClose}>
              Done
            </Button>
          </MotionDiv>
        ) : (
          <MotionDiv
            key="form"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ duration: 0.18 }}
          >
            <div className="mt-1 rounded-[14px] bg-gradient-to-r from-brand-500 to-brand-600 px-4 py-3.5">
              <p className="m-0 font-display text-[14px] font-bold text-white">
                {percentage}% {label} Discount
              </p>
              <p className="m-0 mt-0.5 font-display text-[12px] leading-snug text-white/85">
                Applicable once per day. After agent approval.
              </p>
            </div>

            <div className="mt-4 flex items-center justify-between gap-3">
              <span className="font-display text-[11.5px] font-bold uppercase tracking-[0.08em] text-[#8d8884]">
                Upload {label} ID
              </span>
              <span className="rounded-full border border-[#ffd9b8] bg-[#fff6ec] px-2.5 py-0.5 font-display text-[11px] font-bold text-brand-600">
                Required
              </span>
            </div>

            <input
              ref={inputRef}
              type="file"
              accept=".jpg,.jpeg,.png"
              className="hidden"
              onChange={(e) => accept(e.target.files?.[0])}
            />

            {file ? (
              <div className="mt-2.5 flex items-center gap-3 rounded-[14px] border border-[#c8ebd4] bg-[#f2fbf5] px-3.5 py-3">
                <span
                  aria-hidden="true"
                  className="grid h-10 w-10 shrink-0 place-items-center rounded-[10px] bg-white text-[#2f9e44]"
                >
                  <IconFileTypeJpg size={20} stroke={1.8} />
                </span>

                <div className="min-w-0 flex-1">
                  <p className="m-0 truncate font-display text-[13px] font-semibold text-ink">{file.name}</p>
                  <p className="m-0 font-display text-[12px] text-[#2f9e44]">
                    Ready to submit &middot; {formatSize(file.size)}
                  </p>
                </div>

                <button
                  type="button"
                  onClick={clearFile}
                  disabled={isSubmitting}
                  aria-label="Remove selected file"
                  className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-transparent text-[#8d8884] transition-colors hover:bg-white hover:text-ink disabled:opacity-40"
                >
                  <IconX size={16} stroke={2.2} />
                </button>
              </div>
            ) : (
              <div
                onDragOver={(e) => {
                  e.preventDefault();
                  setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(e) => {
                  e.preventDefault();
                  setDragging(false);
                  accept(e.dataTransfer.files?.[0]);
                }}
                className={`mt-2.5 rounded-[14px] border-2 border-dashed px-4 py-7 text-center transition-colors ${
                  dragging ? "border-brand-500 bg-brand-50" : "border-[#e4ded6] bg-white"
                }`}
              >
                <span
                  aria-hidden="true"
                  className="mx-auto grid h-11 w-11 place-items-center rounded-full bg-[#f4f1ec] text-[#8d8884]"
                >
                  <IconUpload size={19} stroke={1.9} />
                </span>

                <p className="m-0 mt-2.5 font-display text-[13.5px] font-semibold text-ink">
                  Drop your ID here or{" "}
                  <button
                    type="button"
                    onClick={() => inputRef.current?.click()}
                    className="bg-transparent font-display text-[13.5px] font-bold text-brand-600 underline underline-offset-2"
                  >
                    browse files
                  </button>
                </p>
                <p className="m-0 mt-0.5 font-display text-[11.5px] text-[#a39f9b]">
                  .jpg .png supported. Max 5 MB
                </p>
              </div>
            )}

            {error && (
              <p role="alert" className="m-0 mt-2 font-display text-[12.5px] text-[#e5322d]">
                {error}
              </p>
            )}

            <div className="mt-4 rounded-[14px] border border-[#d6e6fb] bg-[#f4f9ff] px-4 py-3.5">
              <p className="m-0 flex items-center gap-1.5 font-display text-[11.5px] font-bold uppercase tracking-[0.06em] text-[#1c7ed6]">
                <IconInfoCircle size={14} stroke={2.2} aria-hidden="true" />
                Important notice
              </p>

              <ul className="m-0 mt-2 list-none space-y-1.5 p-0">
                {[
                  "Your ID will be reviewed by a Store Agent. This may take a short while.",
                  `Once approved, the ${percentage}% discount can be used once per day only.`,
                  "You will be notified once your verification is complete.",
                ].map((line) => (
                  <li key={line} className="flex gap-2 font-display text-[12.5px] leading-snug text-[#41618a]">
                    <span aria-hidden="true" className="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-[#1c7ed6]" />
                    {line}
                  </li>
                ))}
              </ul>
            </div>

            <div className="mt-5 grid grid-cols-2 gap-2.5">
              <Button variant="outline" onClick={onClose} disabled={isSubmitting}>
                Cancel
              </Button>
              <Button
                disabled={!file || isSubmitting}
                loading={isSubmitting}
                loadingLabel="Submitting&hellip;"
                onClick={() => onSubmit(file)}
              >
                Submit for Review
              </Button>
            </div>
          </MotionDiv>
        )}
      </AnimatePresence>
    </Modal>
  );
}

export default DiscountUploadModal;
