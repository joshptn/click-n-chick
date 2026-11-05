import { useEffect, useMemo, useRef } from "react";
import { useQuery } from "@tanstack/react-query";

import { ALL_CATEGORY, fetchCategories } from "../../lib/menu";

/**
 * The category chips.
 *
 * 'All' is prepended here rather than seeded as a row, because it means "no
 * category filter" rather than naming a category a dish could belong to.
 */
function CategoryTabs({ value, onChange }) {
  const { data, isLoading } = useQuery({
    queryKey: ["categories"],
    queryFn: fetchCategories,
    staleTime: 5 * 60 * 1000,
  });

  const categories = useMemo(() => (Array.isArray(data) ? data : (data?.data ?? [])), [data]);

  // The selected tab is a name (the menu filters by it), so remember which
  // category it is: renamed live, the selection follows the new name; deleted,
  // it falls back to All rather than leaving an empty menu under a dead tab.
  const selectedId = useRef(null);

  useEffect(() => {
    if (!data || value === ALL_CATEGORY) {
      selectedId.current = null;
      return;
    }

    const byName = categories.find((category) => category.name === value);

    if (byName) {
      selectedId.current = byName.id;
      return;
    }

    const byId = categories.find((category) => category.id === selectedId.current);

    onChange(byId ? byId.name : ALL_CATEGORY);
  }, [data, categories, value, onChange]);

  if (isLoading) {
    return (
      <div className="flex gap-2.5 overflow-hidden" aria-hidden="true">
        {Array.from({ length: 6 }).map((_, i) => (
          <span key={i} className="h-[38px] w-[92px] shrink-0 animate-pulse rounded-full bg-[#f0e9df]" />
        ))}
      </div>
    );
  }

  const chips = [{ id: ALL_CATEGORY, name: "All" }, ...categories];

  return (
    <div
      role="tablist"
      aria-label="Menu categories"
      // Scrolls rather than wraps on narrow screens, so the row stays one line
      // and the selected chip can be scrolled back into view.
      className="-mx-1 flex gap-2.5 overflow-x-auto px-1 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
    >
      {chips.map((category) => {
        const key = category.id === ALL_CATEGORY ? ALL_CATEGORY : category.name;
        const selected = value === key;

        return (
          <button
            key={category.id}
            type="button"
            role="tab"
            aria-selected={selected}
            onClick={() => onChange(key)}
            className={`h-[38px] shrink-0 rounded-full border px-5 font-display text-[13px] font-semibold transition-all duration-150 ${
              selected
                ? "border-brand-500 bg-brand-500 text-white shadow-[0_4px_12px_-4px_rgba(255,139,43,0.6)]"
                : "border-[#ece7e0] bg-white text-ink hover:border-[#d9d3cb] hover:bg-[#faf7f3]"
            }`}
          >
            {category.name}
          </button>
        );
      })}
    </div>
  );
}

export default CategoryTabs;
