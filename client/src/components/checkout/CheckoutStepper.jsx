import { IconCheck } from "@tabler/icons-react";
import { motion } from "framer-motion";

const MotionSpan = motion.span;

/**
 * The progress rail at the top of checkout: three steps for an account, two
 * for a guest, who has no discount step.
 *
 * Its width follows the number of steps and each line runs the whole way to
 * the next step, so a guest's two steps join up rather than leaving a gap
 * (fixed 2026-11-24). Purely a readout of `current`/`completed` - it never navigates. Steps are
 * reached by finishing the one before, so a clickable rail would offer a route
 * the flow does not actually allow.
 */
function CheckoutStepper({ steps, current, completed = [] }) {
  return (
    <ol
      className="m-0 flex w-full list-none items-center gap-1 p-0 sm:gap-2"
      style={{ maxWidth: steps.length * 175 }}
      aria-label="Checkout progress"
    >
      {steps.map((step, index) => {
        const isDone = completed.includes(step.id);
        const isCurrent = step.id === current;
        const isLast = index === steps.length - 1;

        return (
          <li key={step.id} className="flex flex-1 items-center gap-1 last:flex-none sm:gap-2">
            <div className="flex shrink-0 items-center gap-2">
              <span
                aria-hidden="true"
                className={`grid h-7 w-7 shrink-0 place-items-center rounded-full font-display text-[12.5px] font-bold transition-colors duration-200 ${
                  isDone
                    ? "bg-[#2f9e44] text-white"
                    : isCurrent
                      ? "bg-brand-500 text-white"
                      : "bg-[#eae5de] text-[#95908a]"
                }`}
              >
                {isDone ? <IconCheck size={15} stroke={3} /> : index + 1}
              </span>

              <span
                className={`font-display text-[13.5px] font-semibold transition-colors duration-200 ${
                  isCurrent ? "text-brand-600" : isDone ? "text-ink" : "text-[#a39f9b]"
                }`}
              >
                <span className="hidden sm:inline">{step.label}</span>
                <span className="sm:hidden">{step.shortLabel ?? step.label}</span>
              </span>

              <span className="sr-only">
                {isDone ? "completed" : isCurrent ? "current step" : "not started"}
              </span>
            </div>

            {!isLast && (
              <span className="mx-1 h-px w-full min-w-[16px] flex-1 overflow-hidden bg-[#e5e0d8] sm:mx-2">
                <MotionSpan
                  initial={false}
                  animate={{ scaleX: isDone ? 1 : 0 }}
                  transition={{ duration: 0.3, ease: "easeOut" }}
                  style={{ transformOrigin: "left" }}
                  className="block h-full w-full bg-[#2f9e44]"
                />
              </span>
            )}
          </li>
        );
      })}
    </ol>
  );
}

export default CheckoutStepper;
