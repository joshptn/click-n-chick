import { MENU_VIEWS } from "../../hooks/useMenuView";

/** Dishes | Add-ons, with how many of each. */
function ViewSwitch({ value, onChange, counts = {}, className = "" }) {
  return (
    <div role="tablist" aria-label="Show dishes or add-ons" className={`inline-flex rounded-full bg-[#f4f1ec] p-1 ${className}`} data-testid="menu-view">
      {MENU_VIEWS.map((view) => {
        const active = value === view.key;

        return (
          <button
            key={view.key}
            type="button"
            role="tab"
            aria-selected={active}
            onClick={() => onChange(view.key)}
            data-testid={`menu-view-${view.key}`}
            className={`inline-flex h-[30px] flex-1 items-center justify-center gap-1.5 whitespace-nowrap rounded-full px-3.5 font-display font-semibold transition-colors ${
              active ? "bg-white text-brand-600 shadow-[0_1px_3px_rgba(0,0,0,0.08)]" : "text-[#8d8884] hover:text-ink"
            }`}
          >
            {/* On the span: Mantine resets type on <button> itself. */}
            <span className="text-[12.5px]">{view.label}</span>
            {counts[view.key] !== undefined && (
              <span className={`rounded-full px-1.5 text-[10.5px] ${active ? "bg-brand-50 text-brand-700" : "bg-white/70 text-[#a39f9b]"}`}>
                {counts[view.key]}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}

export default ViewSwitch;
