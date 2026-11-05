import { IconChevronDown } from "@tabler/icons-react";

/** The staff pages' filter dropdown: a pill that turns orange while it filters. */
function FilterSelect({ label, value, onChange, options, active, testId }) {
  return (
    <label className="relative inline-flex min-w-0 items-center text-[12px]">
      <span className="sr-only">{label}</span>
      <select
        value={value}
        onChange={(event) => onChange(event.target.value)}
        data-testid={testId}
        className={`h-[34px] w-full appearance-none rounded-full border bg-white pl-3.5 pr-8 font-display font-medium outline-none transition-colors focus:border-brand-400 ${
          active ? "border-brand-400 text-brand-700" : "border-[#ece7e0] text-[#5f5b58]"
        }`}
      >
        {options.map((option) => (
          <option key={option.key} value={option.key}>
            {option.label}
          </option>
        ))}
      </select>
      <IconChevronDown size={14} stroke={2.2} className="pointer-events-none absolute right-3 text-[#a39f9b]" />
    </label>
  );
}

export default FilterSelect;
