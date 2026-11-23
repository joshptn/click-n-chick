import { useLayoutEffect, useRef, useState } from "react";

import StaffSearch from "./StaffSearch";

/**
 * On a phone the tabs scroll sideways in one row. From `sm` up they wrap, in
 * the room left beside the bar's other controls (decided 2026-11-24, the
 * user's layout): the first tab (ALL) keeps its place at the start of the
 * first row, each row after it starts beside ALL rather than under it, and
 * every row is its own rounded bar.
 *
 * How: the tabs sit inline in one span whose background, padding, corners and
 * ring repeat on every line it wraps onto (`box-decoration-break: clone`), so
 * each row gets its own bar. A hanging indent the width of the first tab -
 * measured, since its count changes - starts the second row and later ones
 * beside it. Type size 0 keeps the bars' height to the tabs and the padding;
 * the 44px line height leaves a 6px gap between the rows.
 *
 * `basis-0` keeps the tabs from claiming the whole row before they wrap; the
 * 280px floor sends the other controls below only when the tabs would
 * otherwise be squeezed.
 */
function SegmentTabs({ items, value, onChange, label, testIdPrefix }) {
  const firstRef = useRef(null);
  const [hang, setHang] = useState(0);

  useLayoutEffect(() => {
    const first = firstRef.current;

    if (!first) return undefined;

    const measure = () => setHang(first.getBoundingClientRect().width);
    const observer = new ResizeObserver(measure);

    measure();
    observer.observe(first);

    return () => observer.disconnect();
  }, []);

  return (
    <div className="max-w-full overflow-x-auto [scrollbar-width:none] sm:min-w-[280px] sm:flex-1 sm:basis-0 sm:overflow-visible [&::-webkit-scrollbar]:hidden">
      <div
        role="group"
        aria-label={label}
        className="whitespace-nowrap text-[0px] leading-[44px] sm:whitespace-normal"
        style={{ paddingLeft: hang, textIndent: -hang }}
      >
        <span className="box-decoration-clone rounded-[12px] bg-[#f8f5f0] px-1 py-[19px] ring-1 ring-inset ring-[#f0e9df]">
          {items.map((tab, index) => {
            const active = value === tab.key;

            return (
              <button
                key={tab.key}
                ref={index === 0 ? firstRef : undefined}
                type="button"
                aria-pressed={active}
                onClick={() => onChange(tab.key)}
                data-testid={`${testIdPrefix}-${tab.key}`}
                className={`inline-flex h-[30px] items-center gap-2 whitespace-nowrap rounded-[9px] px-3.5 align-middle indent-0 font-display font-semibold leading-[18px] transition-colors ${
                  active ? "bg-brand-500 text-white shadow-[0_4px_12px_-4px_rgba(255,139,43,0.6)]" : "bg-transparent text-[#5f5b58] hover:text-ink"
                }`}
              >
                {/* On the span: Mantine resets type on <button> itself. */}
                <span className="text-[12.5px] leading-[18px]">{tab.label}</span>
                {tab.count != null && (
                  <span className={`grid h-[18px] min-w-[18px] place-items-center rounded-full px-1 text-[10px] font-bold leading-none ${active ? "bg-white/30 text-white" : "bg-[#e9e4dc] text-[#5f5b58]"}`}>
                    {tab.count}
                  </span>
                )}
              </button>
            );
          })}
        </span>
      </div>
    </div>
  );
}

/** The result count and the way back to an unfiltered list. */
export function FilterMeta({ children, onClear }) {
  if (!children && !onClear) return null;

  return (
    <div className="col-span-2 flex items-center gap-3 font-display text-[11.5px] text-[#8d8884] sm:ml-auto">
      {children && <span data-testid="result-count">{children}</span>}
      {onClear && (
        <button type="button" onClick={onClear} className="bg-transparent font-semibold text-brand-600 hover:text-brand-800">
          <span className="text-[11.5px]">Clear filters</span>
        </button>
      )}
    </div>
  );
}

/**
 * The staff pages' filter card: search, then tabs for the filter used most,
 * then any other filters (`children`) pushed to the right. Wraps by itself, so
 * it fits beside a side panel as well as across a full page.
 *
 * A page with many filters puts them in `below`, a second row starting at the
 * same left edge; `searchBelow` moves the search box to the start of that row.
 */
function FilterBar({ search, tabs, children, below, searchBelow = false, testId = "filter-bar" }) {
  const searchBox = search && (
    <div className="col-span-2 w-full sm:w-auto sm:min-w-[180px] sm:max-w-[260px] sm:flex-1">
      <StaffSearch {...search} />
    </div>
  );

  return (
    <div data-testid={testId} className="flex flex-wrap items-center gap-3 rounded-[16px] bg-white pl-15 pr-10 py-3 ring-1 ring-[#f0e9df]">
      {!searchBelow && searchBox}

      {tabs && <SegmentTabs {...tabs} />}

      {children && <div className="grid w-full grid-cols-2 items-center gap-2 sm:ml-auto sm:flex sm:w-auto sm:flex-wrap sm:justify-end">{children}</div>}

      {below && (
        <div className="grid w-full grid-cols-2 items-center gap-2 sm:flex sm:flex-wrap">
          {searchBelow && searchBox}
          {below}
        </div>
      )}
    </div>
  );
}

export default FilterBar;
