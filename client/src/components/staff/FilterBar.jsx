import StaffSearch from "./StaffSearch";

function SegmentTabs({ items, value, onChange, label, testIdPrefix }) {
  return (
    <div className="max-w-full overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
      <div role="group" aria-label={label} className="inline-flex rounded-[12px] bg-[#f8f5f0] p-1 ring-1 ring-inset ring-[#f0e9df]">
        {items.map((tab) => {
          const active = value === tab.key;

          return (
            <button
              key={tab.key}
              type="button"
              aria-pressed={active}
              onClick={() => onChange(tab.key)}
              data-testid={`${testIdPrefix}-${tab.key}`}
              className={`inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-[9px] px-3.5 py-1.5 font-display font-semibold transition-colors ${
                active ? "bg-brand-500 text-white shadow-[0_4px_12px_-4px_rgba(255,139,43,0.6)]" : "bg-transparent text-[#5f5b58] hover:text-ink"
              }`}
            >
              {/* On the span: Mantine resets type on <button> itself. */}
              <span className="text-[12.5px]">{tab.label}</span>
              {tab.count != null && (
                <span className={`grid h-[18px] min-w-[18px] place-items-center rounded-full px-1 text-[10px] font-bold ${active ? "bg-white/30 text-white" : "bg-[#e9e4dc] text-[#5f5b58]"}`}>
                  {tab.count}
                </span>
              )}
            </button>
          );
        })}
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
