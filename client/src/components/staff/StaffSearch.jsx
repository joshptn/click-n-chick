import { IconSearch, IconX } from "@tabler/icons-react";

/** The staff pages' search box: a pill with its own clear button. */
function StaffSearch({ value, onChange, placeholder, title, label = placeholder, className = "" }) {
  return (
    <div className={`relative w-full text-[12.5px] md:max-w-[280px] ${className}`}>
      <IconSearch
        size={15}
        stroke={2.2}
        aria-hidden="true"
        className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[#a39f9b]"
      />
      <input
        type="search"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        title={title}
        aria-label={label}
        className="h-[36px] w-full rounded-full border border-[#ece7e0] bg-[#faf7f3] pl-9 pr-8 font-display text-[12.5px] text-ink outline-none transition-colors placeholder:text-[#a39f9b] focus:border-brand-300 focus:bg-white [&::-webkit-search-cancel-button]:appearance-none"
      />
      {value && (
        <button
          type="button"
          onClick={() => onChange("")}
          aria-label="Clear search"
          className="absolute right-3 top-1/2 -translate-y-1/2 bg-transparent text-[#a39f9b] transition-colors hover:text-ink"
        >
          <IconX size={14} stroke={2.4} />
        </button>
      )}
    </div>
  );
}

export default StaffSearch;
