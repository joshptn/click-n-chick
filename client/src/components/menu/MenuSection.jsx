import FoodCard from "./FoodCard";
import { dishCount, sectionAnchor } from "../../lib/menuSections";

/** Cards whose photos load first: the top row of the first section, seen first. */
const FIRST_ROW = 3;

function Heading({ section }) {
  if (section.featured) {
    return (
      <div className="mb-4 border-b border-[#f0e9df] pb-4 pt-2">
        <p className="m-0 font-display text-[11px] font-bold uppercase tracking-[0.16em] text-brand-600">From the menu</p>
        <h2 className="m-0 mt-1.5 font-display text-[24px] font-extrabold tracking-[-0.4px] text-cocoa-900">{section.label}</h2>
        <p className="m-0 mt-1.5 font-display text-[13px] text-[#6f6b68]">
          From crispy wings to comforting classics, find your next BES favorite.
        </p>
      </div>
    );
  }

  return (
    <div className="mb-3.5 flex items-baseline justify-between gap-3">
      <h2 className="m-0 font-display text-[18px] font-extrabold tracking-[-0.2px] text-cocoa-900">{section.label}</h2>
      <span className="shrink-0 font-display text-[11.5px] text-[#a39f9b]">{dishCount(section.foods.length)}</span>
    </div>
  );
}

/**
 * One section of the home menu: its heading and its dishes. `scroll-mt` keeps
 * the heading clear of the sticky header (68px) and category bar (48px) when
 * a tab scrolls to it.
 */
function MenuSection({ section, first = false, isAdding, isAdvance = false, onSelect, onQuickAdd }) {
  return (
    <section id={sectionAnchor(section.key)} aria-label={section.label} className="scroll-mt-[128px] pt-4" data-testid="menu-section">
      <Heading section={section} />

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
        {section.foods.map((food, index) => (
          <FoodCard
            key={food.id}
            food={food}
            index={index}
            priority={first && index < FIRST_ROW}
            isAdding={isAdding}
            isAdvance={isAdvance}
            onSelect={onSelect}
            onQuickAdd={onQuickAdd}
          />
        ))}
      </div>
    </section>
  );
}

export default MenuSection;
