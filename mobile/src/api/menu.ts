import { api } from "./client";
import { colors } from "../theme/colors";

/**
 * Menu, category and poster reads: public, so they go without a token and a
 * guest can browse (client/src/lib/menu.js on the web).
 */

/** Mirrors Food::STOCK_* on the API. */
export type StockStatus = "untracked" | "in_stock" | "low_stock" | "out_of_stock";

export type Addon = { id: number; addon_name: string; addon_price: number };

export type AddonGroup = { group: string; addons: Addon[] };

/** FoodResource on the API. */
export type Food = {
  id: number;
  food_name: string;
  description: string | null;
  price: number;
  thumbnail: string | null;
  prep_time: number | null;
  is_best_seller: boolean;
  is_available: boolean;
  /** Null when the dish is made to order (no count kept). */
  stock_quantity: number | null;
  is_orderable: boolean;
  stock_status: StockStatus;
  category?: { id: number | null; name: string | null };
  addon_groups?: AddonGroup[];
};

export type Category = { id: number; name: string };

export type Poster = { id: number; poster_name: string; image: string };

/** "All" is not a category row; it means no category filter. */
export const ALL_CATEGORY = "all";

const PUBLIC = { auth: false } as const;

export const FOODS_KEY = "foods";

export function fetchFoods({ category, search, signal }: { category: string; search: string; signal?: AbortSignal }) {
  return api.get<{ data: Food[] }>("/api/foods", {
    ...PUBLIC,
    signal,
    params: {
      category: category !== ALL_CATEGORY ? category : undefined,
      search: search || undefined,
    },
  });
}

export function fetchFood(id: number, signal?: AbortSignal) {
  return api.get<{ data: Food }>(`/api/foods/${id}`, { ...PUBLIC, signal });
}

export function fetchCategories(signal?: AbortSignal) {
  return api.get<Category[] | { data: Category[] }>("/api/category", { ...PUBLIC, signal });
}

export function fetchPosters(signal?: AbortSignal) {
  return api.get<{ data: Poster[] }>("/api/posters", { ...PUBLIC, signal });
}

/** The one-line stock caption under a price, or null when there is nothing to say (as on the web). */
export function stockLabel(food: Pick<Food, "is_available" | "stock_status" | "stock_quantity">): string | null {
  if (!food.is_available) return "Currently unavailable";

  switch (food.stock_status) {
    case "out_of_stock":
      return "No more stock left";
    case "low_stock":
      return `Only ${food.stock_quantity} stock${food.stock_quantity === 1 ? "" : "s"} left!`;
    case "in_stock":
      return `${food.stock_quantity} in stock`;
    default:
      return null;
  }
}

/** Stock captions carry meaning through colour as well as words. */
export function stockColor(food: Pick<Food, "is_available" | "stock_status">): string {
  if (!food.is_available || food.stock_status === "out_of_stock") return colors.danger;
  if (food.stock_status === "low_stock") return "#d9760f";
  return colors.ink300;
}
