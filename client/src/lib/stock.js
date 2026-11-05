import { api } from "./api";

/**
 * The Store Agent's stock page (BR-29).
 *
 * Keyed under "foods" on purpose: RealtimeContext invalidates that prefix on
 * every `menu` broadcast, and every sale, cancellation and stock change sends
 * one - so the page follows orders landing elsewhere without its own channel.
 */
export const STOCK_KEY = ["foods", "stock"];

export function fetchStock({ signal } = {}) {
  return api.get("/api/agent/stock", { signal });
}

/** +/- a few units, applied under the server's lock so a sale in between is kept. */
export function adjustStock(id, by) {
  return api.patch(`/api/foods/${id}/stock`, { adjust_by: by });
}

/**
 * A recount, or null to make it to order. `expected` is the count the agent was
 * looking at; the server refuses (STOCK_CHANGED) if it has moved since.
 */
export function setStock(id, count, expected) {
  return api.patch(`/api/foods/${id}/stock`, { stock_quantity: count, expected });
}

export function setAvailability(id, isAvailable) {
  return api.patch(`/api/foods/${id}/availability`, { is_available: isAvailable });
}

/** The stock filter, by what the server says - never re-derived from the count. */
export const STOCK_FILTERS = [
  { key: "all", label: "All stock" },
  { key: "in_stock", label: "In stock" },
  { key: "low_stock", label: "Low stock" },
  { key: "out_of_stock", label: "Sold out" },
  { key: "untracked", label: "Made to order" },
  { key: "switched_off", label: "Switched off" },
];

export function matchesStock(food, key) {
  if (key === "all") return true;
  if (key === "switched_off") return !food.is_available;

  return food.stock_status === key;
}

export const PRICE_FILTERS = [
  { key: "any", label: "Any price", min: null, max: null },
  { key: "under_100", label: "Under ₱100", min: null, max: 99 },
  { key: "100_199", label: "₱100 – ₱199", min: 100, max: 199 },
  { key: "200_499", label: "₱200 – ₱499", min: 200, max: 499 },
  { key: "500_up", label: "₱500 and up", min: 500, max: null },
];

export function matchesPrice(food, key) {
  const range = PRICE_FILTERS.find((filter) => filter.key === key);

  if (!range) return true;

  const price = Number(food.price);

  return (range.min == null || price >= range.min) && (range.max == null || price <= range.max);
}

export const SORTS = [
  { key: "name", label: "Name A–Z", compare: (a, b) => a.food_name.localeCompare(b.food_name) },
  { key: "price_asc", label: "Price: low to high", compare: (a, b) => a.price - b.price },
  { key: "price_desc", label: "Price: high to low", compare: (a, b) => b.price - a.price },
  {
    key: "stock_asc",
    label: "Stock: lowest first",
    // Sold out first, made to order last: what needs the agent's eye leads.
    compare: (a, b) => (a.available_quantity ?? Infinity) - (b.available_quantity ?? Infinity),
  },
];

export function matchesText(food, term) {
  const needle = term.trim().toLowerCase().replace(/^#/, "");

  if (!needle) return true;

  return [food.food_name, String(food.id), food.category?.name]
    .filter(Boolean)
    .some((value) => String(value).toLowerCase().includes(needle));
}

const CATEGORY_TONES = {
  "ala carte": "bg-brand-500 text-white",
  chicken: "bg-brand-500 text-white",
  combos: "bg-[#ffc94d] text-[#6b4a00]",
  sides: "bg-[#2fbf71] text-white",
  drinks: "bg-[#4c8df6] text-white",
  breakfast: "bg-[#f0628a] text-white",
  group: "bg-[#8b5cf6] text-white",
  party: "bg-[#14b8a6] text-white",
};

const FALLBACK_TONES = ["bg-[#8d8884] text-white", "bg-[#a16207] text-white", "bg-[#0e7490] text-white"];

/** A steady colour per category, so a pill reads the same on every row. */
export function categoryTone(name) {
  const key = String(name ?? "").trim().toLowerCase();

  if (CATEGORY_TONES[key]) return CATEGORY_TONES[key];

  const hash = [...key].reduce((sum, char) => sum + char.charCodeAt(0), 0);

  return FALLBACK_TONES[hash % FALLBACK_TONES.length];
}
