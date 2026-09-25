import { Link } from "react-router-dom";
import { IconArrowLeft, IconCalendarPlus, IconCheck } from "@tabler/icons-react";

const RULES = [
  "Collection only — advance orders cannot be delivered",
  "From tomorrow up to 30 days ahead",
  "A Store Agent accepts your request before you pay",
];

function AdvanceNotice() {
  return (
    <section
      aria-label="How advance ordering works"
      className="overflow-hidden rounded-[20px] bg-gradient-to-r from-brand-600 to-brand-500"
    >
      <div className="flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:px-7 sm:py-6">
        <div className="min-w-0">
          <p className="m-0 inline-flex items-center gap-2 font-display text-[11px] font-bold uppercase tracking-[0.14em] text-white/75">
            <IconCalendarPlus size={14} stroke={2.4} aria-hidden="true" />
            Advance Order
          </p>

          <h1 className="m-0 mt-1.5 font-display text-[21px] font-extrabold leading-tight tracking-[-0.3px] text-white sm:text-[25px]">
            Book the kitchen ahead of time
          </h1>

          <ul className="m-0 mt-3 flex list-none flex-col gap-1.5 p-0 sm:flex-row sm:flex-wrap sm:gap-x-5">
            {RULES.map((rule) => (
              <li
                key={rule}
                className="flex items-start gap-1.5 font-display text-[12px] leading-snug text-white/90"
              >
                <IconCheck size={13} stroke={3} aria-hidden="true" className="mt-0.5 shrink-0" />
                {rule}
              </li>
            ))}
          </ul>
        </div>

        <Link
          to="/home"
          className="inline-flex h-[40px] shrink-0 items-center gap-2 self-start rounded-full bg-white/15 px-4 font-display text-[12.5px] font-semibold text-white no-underline backdrop-blur-sm transition-colors hover:bg-white/25 sm:self-auto"
        >
          <IconArrowLeft size={15} stroke={2.2} aria-hidden="true" />
          Order for today
        </Link>
      </div>
    </section>
  );
}

export default AdvanceNotice;
