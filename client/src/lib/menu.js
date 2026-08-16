import { api } from "./api";

/**
 * Menu, category and poster reads.
 *
 * All three are public, so they pass auth:false - a guest browsing the
 * landing page must not be blocked by a missing token.
 */

export const PESO = "₱";

/** Mirrors Food::STOCK_* on the server. */
export const STOCK = {
  UNTRACKED: "untracked",
  IN: "in_stock",
  LOW: "low_stock",
  OUT: "out_of_stock",
};

/** 'All' is a UI-only pseudo-category, not a row. */
export const ALL_CATEGORY = "all";

export function formatPeso(amount) {
  return `${PESO}${Number(amount ?? 0).toLocaleString("en-PH", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

/** The one-line stock caption under a price, or null when there is nothing to say. */
export function stockLabel(food) {
  if (!food?.is_available) return "Currently unavailable";

  switch (food.stock_status) {
    case STOCK.OUT:
      return "No more stock left";
    case STOCK.LOW:
      return `Only ${food.stock_quantity} stock${food.stock_quantity === 1 ? "" : "s"} left!`;
    case STOCK.IN:
      return `${food.stock_quantity} in stock`;
    default:
      return null;
  }
}

/** Stock captions carry meaning through colour as well as words. */
export function stockTone(food) {
  if (!food?.is_available || food.stock_status === STOCK.OUT) return "text-[#e5322d]";
  if (food.stock_status === STOCK.LOW) return "text-[#d9760f]";
  return "text-[#8d8884]";
}

export function fetchFoods({ category, search, bestSeller } = {}) {
  return api.get("/api/foods", {
    auth: false,
    params: {
      category: category && category !== ALL_CATEGORY ? category : undefined,
      search: search || undefined,
      best_seller: bestSeller ? 1 : undefined,
    },
  });
}

export function fetchFood(id) {
  return api.get(`/api/foods/${id}`, { auth: false });
}

export function fetchCategories() {
  return api.get("/api/category", { auth: false });
}

export function fetchPosters() {
  return api.get("/api/posters", { auth: false });
}
