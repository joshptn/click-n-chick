import { api } from "./api";
import { formatPeso } from "./menu";

/**
 * Add-ons (BR-17; decided 2026-11-24). The Store Manager keeps the catalogue -
 * name, group, price or a price per size, the dishes that offer it - and the
 * Store Agent switches an add-on, or one of its sizes, on and off.
 *
 * RealtimeContext invalidates this key on every `addon` broadcast, so both
 * pages follow each other's changes.
 */
export const ADDONS_KEY = ["addons"];

export const SIZES = [
  { key: "small", label: "Small", short: "S" },
  { key: "medium", label: "Medium", short: "M" },
  { key: "large", label: "Large", short: "L" },
];

export const sizeLabel = (key) => SIZES.find((size) => size.key === key)?.label ?? key;

export function fetchAddons({ signal } = {}) {
  return api.get("/api/addons", { signal });
}

/** `values`: { addon_name, addon_group, sized, addon_price, sizes: { small: "45", ... }, food_ids } */
export function saveAddon({ id, values, password }) {
  const sizes = SIZES.filter((size) => values.sizes[size.key] !== undefined && values.sizes[size.key] !== null).map((size) => ({
    size: size.key,
    price: Number(values.sizes[size.key]),
  }));

  const body = {
    addon_name: values.addon_name.trim(),
    addon_group: values.addon_group.trim(),
    addon_price: values.sized ? null : Number(values.addon_price),
    sizes: values.sized ? sizes : [],
    food_ids: values.food_ids.map(Number),
  };

  if (password) body.password = password;

  return id ? api.put(`/api/addons/${id}`, body) : api.post("/api/addons", body);
}

export function archiveAddon(id, archived) {
  return api.patch(`/api/addons/${id}/archive`, { archived });
}

export function deleteAddon(id) {
  return api.delete(`/api/addons/${id}`);
}

/** The Store Agent's switches. */
export function setAddonAvailability(id, isAvailable) {
  return api.patch(`/api/addons/${id}/availability`, { is_available: isAvailable });
}

export function setAddonSize(id, size, isAvailable) {
  return api.patch(`/api/addons/${id}/sizes/${size}`, { is_available: isAvailable });
}

/** The ticked add-ons, switched together. */
export function setAvailabilityOfAddons(ids, isAvailable) {
  return api.patch("/api/agent/addons/availability", { addon_ids: ids, is_available: isAvailable });
}

/** "₱65.00", or "₱45.00 – ₱65.00" across its sizes. */
export function addonPriceText(addon) {
  if (!addon?.is_sized || !addon.sizes?.length) return formatPeso(addon?.addon_price ?? 0);

  const prices = addon.sizes.map((size) => Number(size.price));
  const low = Math.min(...prices);
  const high = Math.max(...prices);

  return low === high ? formatPeso(low) : `${formatPeso(low)} – ${formatPeso(high)}`;
}

export const ADDON_STATUS_FILTERS = [
  { key: "all", label: "All statuses" },
  { key: "on_menu", label: "On menu" },
  { key: "archived", label: "Archived" },
];

export function matchesAddonStatus(addon, key) {
  if (key === "on_menu") return addon.is_available;
  if (key === "archived") return !addon.is_available;

  return true;
}

/** An add-on's prices: its one price, or each size's. */
const pricesOf = (addon) => (addon.is_sized && addon.sizes?.length ? addon.sizes.map((size) => Number(size.price)) : [Number(addon.addon_price ?? 0)]);

const lowestPrice = (addon) => Math.min(...pricesOf(addon));

/** Bands for add-on prices, which run far below a dish's. */
export const ADDON_PRICE_FILTERS = [
  { key: "any", label: "Any price", min: null, max: null },
  { key: "under_50", label: "Under ₱50", min: null, max: 49 },
  { key: "50_99", label: "₱50 – ₱99", min: 50, max: 99 },
  { key: "100_up", label: "₱100 and up", min: 100, max: null },
];

/** A sized add-on matches a band when any of its sizes is priced in it. */
export function matchesAddonPrice(addon, key) {
  const range = ADDON_PRICE_FILTERS.find((filter) => filter.key === key);

  if (!range || key === "any") return true;

  return pricesOf(addon).some((price) => (range.min == null || price >= range.min) && (range.max == null || price <= range.max));
}

/** Sized add-ons sort by their lowest price. */
export const ADDON_SORTS = [
  { key: "name", label: "Name A–Z", compare: (a, b) => a.addon_name.localeCompare(b.addon_name) },
  { key: "price_asc", label: "Price: low to high", compare: (a, b) => lowestPrice(a) - lowestPrice(b) || a.addon_name.localeCompare(b.addon_name) },
  { key: "price_desc", label: "Price: high to low", compare: (a, b) => lowestPrice(b) - lowestPrice(a) || a.addon_name.localeCompare(b.addon_name) },
];

export function matchesAddonText(addon, text) {
  const needle = String(text ?? "").trim().toLowerCase();

  if (!needle) return true;

  return (
    addon.addon_name.toLowerCase().includes(needle) ||
    String(addon.id) === needle.replace(/^#/, "") ||
    String(addon.addon_group ?? "").toLowerCase().includes(needle)
  );
}
