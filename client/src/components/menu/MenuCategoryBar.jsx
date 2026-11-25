import { useEffect, useRef } from "react";

import { sectionAnchor } from "../../lib/menuSections";

/**
 * The category bar of the home menu (the user's design, 2026-11-26): it sticks
 * under the header, underlines the section being read, and a tab glides the
 * page to its section. One row, scrolling sideways when the tabs do not fit;
 * the active tab is kept in view.
 */
function MenuCategoryBar({ sections, active, onPick }) {
  const scroller = useRef(null);
  const tabs = useRef({});

  useEffect(() => {
    const bar = scroller.current;
    const tab = tabs.current[active];

    if (!bar || !tab || bar.scrollWidth <= bar.clientWidth) return;

    // The bar's own scroll only: scrollIntoView would also move the page and
    // cut short its glide.
    bar.scrollTo({ left: Math.max(0, tab.offsetLeft - (bar.clientWidth - tab.offsetWidth) / 2), behavior: "smooth" });
  }, [active]);

  return (
    <nav
      aria-label="Menu categories"
      className="sticky top-[68px] z-30 -mx-4 mt-4 border-b border-[#f0e9df] bg-white sm:mx-0 sm:rounded-t-[4px]"
      data-testid="menu-category-bar"
    >
      <div ref={scroller} className="relative flex overflow-x-auto px-2 [scrollbar-width:none] sm:px-3 [&::-webkit-scrollbar]:hidden">
        {sections.map((section) => {
          const current = section.key === active;

          return (
            <a
              key={section.key}
              ref={(node) => {
                tabs.current[section.key] = node;
              }}
              href={`#${sectionAnchor(section.key)}`}
              onClick={(event) => {
                event.preventDefault();
                onPick(section.key);
              }}
              aria-current={current ? "true" : undefined}
              data-testid={`menu-tab-${section.key}`}
              className={`relative flex h-12 shrink-0 items-center px-3.5 font-display text-[11.5px] font-bold uppercase tracking-[0.14em] no-underline transition-colors sm:px-4 ${
                current ? "text-ink" : "text-[#8d8884] hover:text-ink"
              }`}
            >
              {section.label}
              <span
                aria-hidden="true"
                className={`absolute inset-x-3.5 bottom-0 h-[2.5px] rounded-full bg-brand-500 transition-opacity sm:inset-x-4 ${current ? "opacity-100" : "opacity-0"}`}
              />
            </a>
          );
        })}
      </div>
    </nav>
  );
}

export default MenuCategoryBar;
