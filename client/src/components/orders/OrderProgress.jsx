import {
  IconBike,
  IconCheck,
  IconChefHat,
  IconHome,
  IconReceipt,
  IconShoppingBag,
  IconX,
} from "@tabler/icons-react";


const ICONS = {
  placed: IconReceipt,
  confirmed: IconCheck,
  preparing: IconChefHat,
  ready_for_pickup: IconShoppingBag,
  on_the_way: IconBike,
  completed: IconHome,
  delivered: IconHome,
};

function OrderProgress({ steps = [], stepIndex, stepCount, isCancelled = false }) {
  if (steps.length === 0) return null;

  const doneCount = steps.filter((step) => step.state === "done").length;
  const filled = isCancelled ? 0 : Math.min(1, doneCount / Math.max(1, steps.length - 1));

  return (
    <section
      aria-label="Order progress"
      className="rounded-[16px] border border-[#f0e9df] bg-white px-5 pb-6 pt-4 sm:px-7"
    >
      <div className="mb-5 flex items-center justify-between gap-3">
        <h2 className="m-0 font-display text-[15px] font-extrabold text-ink">Progress of your order</h2>

        <span
          className={`shrink-0 rounded-full px-3 py-1 font-display text-[11.5px] font-bold ${
            isCancelled ? "bg-[#fdecec] text-[#c92a2a]" : "bg-[#fff4e8] text-brand-700"
          }`}
        >
          {isCancelled ? "Cancelled" : `${stepIndex ?? 0} of ${stepCount ?? steps.length} steps`}
        </span>
      </div>

      <div className="relative">
        <div
          aria-hidden="true"
          className="absolute left-[22px] right-[22px] top-[21px] h-[3px] rounded-full bg-[#f0e9df]"
        >
          <div
            className={`h-full rounded-full transition-[width] duration-500 ease-out ${
              isCancelled ? "bg-[#e8e4de]" : "bg-[#2f9e44]"
            }`}
            style={{ width: `${Math.min(100, filled * 100)}%` }}
          />
        </div>

        <ol className="relative m-0 flex list-none items-start justify-between gap-1 p-0">
          {steps.map((step) => {
            const Icon = ICONS[step.key] ?? IconCheck;
            const done = !isCancelled && step.state === "done";
            const current = !isCancelled && step.state === "current";

            return (
              <li key={step.key} className="flex min-w-0 flex-1 flex-col items-center gap-2">
                <span
                  aria-hidden="true"
                  className={`grid h-11 w-11 shrink-0 place-items-center rounded-full border-[3px] transition-colors duration-300 ${
                    done
                      ? "border-[#2f9e44] bg-[#2f9e44] text-white"
                      : current
                        ? "border-brand-500 bg-brand-500 text-white shadow-[0_0_0_5px_rgba(255,139,43,0.18)]"
                        : "border-[#f0e9df] bg-white text-[#c9c3bb]"
                  }`}
                >
                  {isCancelled ? (
                    <IconX size={19} stroke={2.4} />
                  ) : (
                    <Icon size={19} stroke={2.2} />
                  )}
                </span>

                <span
                  className={`text-center font-display text-[11.5px] leading-tight ${
                    current
                      ? "font-bold text-brand-600"
                      : done
                        ? "font-semibold text-[#2f9e44]"
                        : "text-[#a39f9b]"
                  }`}
                >
                  {step.label}
                </span>
              </li>
            );
          })}
        </ol>
      </div>
    </section>
  );
}

export default OrderProgress;
