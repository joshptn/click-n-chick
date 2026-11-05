import { formatPeso } from "../../lib/menu";

/** The building blocks of the staff detail drawers, so they read alike. */
export function Section({ title, children }) {
  return (
    <section className="border-b border-[#f3ede4] px-5 py-3 last:border-b-0">
      <h3 className="m-0 mb-2 font-display text-[10px] font-bold uppercase tracking-[0.6px] text-[#a39f9b]">{title}</h3>
      {children}
    </section>
  );
}

export function Line({ icon, children }) {
  const Icon = icon;

  return (
    <p className="m-0 flex items-start gap-2 font-display text-[12.5px] leading-snug text-ink">
      <Icon size={15} stroke={1.9} className="mt-[1px] shrink-0 text-[#a39f9b]" />
      <span className="min-w-0">{children}</span>
    </p>
  );
}

export function Money({ label, value, strong = false, negative = false }) {
  return (
    <div className="flex justify-between font-display text-[12.5px]">
      <span className={strong ? "font-semibold text-ink" : "text-[#6f6b68]"}>{label}</span>
      <span className={strong ? "text-[14px] font-bold text-ink" : "text-ink"}>
        {negative ? "−" : ""}
        {formatPeso(value)}
      </span>
    </div>
  );
}
