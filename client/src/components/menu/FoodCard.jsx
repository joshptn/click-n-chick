import { IconPhotoOff, IconPlus } from "@tabler/icons-react";
import { motion } from "framer-motion";

import { formatPeso, stockLabel, stockTone } from "../../lib/menu";

const MotionArticle = motion.article;

/**
 * One dish on the menu grid.
 *
 * The greyed-out state is driven entirely by `is_orderable`, which the server
 * computes from is_available AND stock_quantity. Nothing here re-derives it:
 * if the card looks orderable but the API disagrees, the API wins, and the
 * request it would send is refused anyway.
 *
 * A sold-out card is dimmed but stays readable and focusable - it is
 * information, not a disabled control. Only the Add button is actually
 * disabled.
 */
function FoodCard({ food, onSelect, onQuickAdd, isAdding = false, index = 0 }) {
  const orderable = food.is_orderable;
  const label = stockLabel(food);

  return (
    <MotionArticle
      initial={{ opacity: 0, y: 16 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.32, delay: Math.min(index, 8) * 0.04, ease: "easeOut" }}
      className={`group flex flex-col overflow-hidden rounded-[16px] border border-[#f0e9df] bg-white transition-shadow duration-200 ${
        orderable ? "hover:shadow-[0_10px_30px_-14px_rgba(65,33,17,0.28)]" : ""
      }`}
    >
      <button
        type="button"
        onClick={() => onSelect(food)}
        aria-label={`View ${food.food_name}`}
        className="relative block aspect-[4/3] w-full cursor-pointer overflow-hidden border-0 bg-[#f4f1ec] p-0"
      >
        {food.thumbnail ? (
          <img
            src={food.thumbnail}
            alt={food.food_name}
            loading="lazy"
            className={`h-full w-full object-cover transition-all duration-500 ${
              orderable ? "group-hover:scale-105" : "grayscale"
            }`}
          />
        ) : (
          <span className="grid h-full w-full place-items-center text-[#c9c2b8]">
            <IconPhotoOff size={26} stroke={1.6} aria-hidden="true" />
          </span>
        )}

        {food.is_best_seller && (
          <span className="absolute left-0 top-3 rounded-r-[6px] bg-brand-500 py-1 pl-3 pr-2.5 font-display text-[9.5px] font-extrabold uppercase tracking-[0.08em] text-white">
            Best Seller
          </span>
        )}

        {/* A wash rather than an overlay label: the reason is spelled out
            under the price, where the eye already is. */}
        {!orderable && <span aria-hidden="true" className="absolute inset-0 bg-white/45" />}
      </button>

      <div className="flex flex-1 flex-col gap-1 px-3.5 pb-3.5 pt-3">
        <h3
          className={`m-0 line-clamp-2 font-display text-[13.5px] font-semibold leading-snug ${
            orderable ? "text-ink" : "text-[#a39f9b]"
          }`}
        >
          {food.food_name}
        </h3>

        {label && (
          <p className={`m-0 flex items-center gap-1.5 font-display text-[11.5px] font-medium ${stockTone(food)}`}>
            {(!orderable || food.stock_status === "low_stock") && (
              <span aria-hidden="true" className="inline-block h-[5px] w-[5px] shrink-0 rounded-full bg-current" />
            )}
            {label}
          </p>
        )}

        <p
          className={`mb-2 mt-auto pt-1 font-display text-[16px] font-extrabold leading-none ${
            orderable ? "text-brand-600" : "text-[#c0bab2]"
          }`}
        >
          {formatPeso(food.price)}
        </p>

        <button
          type="button"
          disabled={!orderable || isAdding}
          onClick={() => onQuickAdd(food)}
          aria-label={orderable ? `Add ${food.food_name} to your order` : `${food.food_name} is unavailable`}
          className="inline-flex h-[38px] w-full items-center justify-center gap-1.5 rounded-[10px] bg-brand-500 font-display text-[13px] font-semibold text-white transition-all duration-150 hover:bg-brand-600 active:translate-y-px disabled:cursor-not-allowed disabled:bg-brand-200 disabled:text-white/80 disabled:hover:bg-brand-200"
        >
          <IconPlus size={15} stroke={2.6} aria-hidden="true" />
          {isAdding ? "Adding..." : "Add"}
        </button>
      </div>
    </MotionArticle>
  );
}

export default FoodCard;
