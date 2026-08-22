import { IconCheck, IconChevronRight } from "@tabler/icons-react";
import { AnimatePresence, motion } from "framer-motion";

const MotionDiv = motion.div;

/**
 * One checkout step, in whichever of its three states applies.
 *
 * Active   - orange ring, full contents.
 * Done     - collapsed, green tick, a "Done" pill, and clickable to reopen.
 * Upcoming - collapsed, greyed, inert.
 *
 * Collapsed steps stay in the DOM as headers rather than disappearing, so the
 * customer can see the whole flow and how far along it they are without
 * scrolling back to the rail.
 */
function StepCard({
  index,
  title,
  state = "upcoming",
  summary,
  headerAction,
  onReopen,
  children,
  className = "",
}) {
  const isActive = state === "active";
  const isDone = state === "done";
  const canReopen = isDone && typeof onReopen === "function";

  // A control in the header cannot live inside a <button>, so the reopen
  // affordance steps aside when there is one. Only active steps carry an
  // action, and an active step is not reopenable anyway.
  const Header = canReopen && !headerAction ? "button" : "div";

  return (
    <section
      aria-current={isActive ? "step" : undefined}
      className={`overflow-hidden rounded-[16px] border bg-white transition-[border-color,box-shadow] duration-200 ${
        isActive
          ? "border-brand-500 shadow-[0_2px_14px_-6px_rgba(255,139,43,0.45)]"
          : "border-[#f0e9df]"
      } ${className}`}
    >
      <Header
        type={Header === "button" ? "button" : undefined}
        onClick={Header === "button" ? onReopen : undefined}
        className={`flex w-full items-center gap-3 bg-transparent px-5 py-4 text-left ${
          Header === "button" ? "cursor-pointer transition-colors hover:bg-[#faf7f3]" : ""
        }`}
      >
        <span
          aria-hidden="true"
          className={`grid h-8 w-8 shrink-0 place-items-center rounded-full font-display text-[13px] font-bold ${
            isDone
              ? "bg-[#2f9e44] text-white"
              : isActive
                ? "bg-brand-500 text-white"
                : "bg-[#eae5de] text-[#95908a]"
          }`}
        >
          {isDone ? <IconCheck size={17} stroke={3} /> : index}
        </span>

        <span className="min-w-0 flex-1">
          <span
            className={`block font-display text-[15px] font-bold ${
              isActive ? "text-brand-600" : isDone ? "text-ink" : "text-[#a39f9b]"
            }`}
          >
            {title}
          </span>

          {isDone && summary && (
            <span className="mt-0.5 block truncate font-display text-[12.5px] text-[#8d8884]">
              {summary}
            </span>
          )}
        </span>

        {headerAction}

        {isDone && (
          <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-[#e9f8ee] px-3 py-1 font-display text-[11.5px] font-bold text-[#2f9e44]">
            Done
            {canReopen && <IconChevronRight size={13} stroke={2.6} aria-hidden="true" />}
          </span>
        )}
      </Header>

      <AnimatePresence initial={false}>
        {isActive && (
          <MotionDiv
            key="body"
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: "auto", opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            transition={{ duration: 0.24, ease: "easeOut" }}
            className="overflow-hidden"
          >
            <div className="border-t border-[#f5f0e9] px-5 pb-5 pt-4">{children}</div>
          </MotionDiv>
        )}
      </AnimatePresence>
    </section>
  );
}

export default StepCard;
