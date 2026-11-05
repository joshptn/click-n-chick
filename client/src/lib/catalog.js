import { api } from "./api";

/**
 * The Store Manager's catalogue (BR-29, UC-MENU-007..014).
 *
 * The list is the menu endpoint itself - every dish, archived or not - keyed
 * under "foods" so the `menu` broadcast refreshes it whenever any dish changes,
 * here or on the Store Agent's page.
 */
export const CATALOG_KEY = ["foods", "catalog"];

export const catalogItemKey = (id) => ["foods", "catalog-item", String(id)];

export const CATEGORIES_KEY = ["categories"];

export const IMAGE_TYPES = ["image/png", "image/jpeg", "image/webp", "image/gif"];

export const IMAGE_MAX_BYTES = 5 * 1024 * 1024;

export const MAX_PRICE = 99999;

export function fetchCatalog({ signal } = {}) {
  return api.get("/api/foods", { signal });
}

export function fetchCatalogFood(id, { signal } = {}) {
  return api.get(`/api/foods/${id}`, { signal });
}

/**
 * Create or edit a dish. Multipart, because of the photo; an edit is sent as
 * POST with `_method=PUT`, since PHP does not parse files on a real PUT.
 */
export function saveFood({ id, values, image, password }) {
  const form = new FormData();

  form.append("food_name", values.food_name.trim());
  form.append("category_id", String(values.category_id));
  // Number() first: the server's integer rule refuses a leading zero.
  form.append("price", String(Number(values.price)));
  form.append("description", values.description.trim());

  if (image) form.append("thumbnail", image);
  if (password) form.append("password", password);

  if (!id) return api.post("/api/foods", form);

  form.append("_method", "PUT");

  return api.post(`/api/foods/${id}`, form);
}

export function archiveFood(id, archived) {
  return api.patch(`/api/foods/${id}/archive`, { archived });
}

export function deleteFood(id) {
  return api.delete(`/api/foods/${id}`);
}

export function createCategory(name) {
  return api.post("/api/category", { name });
}

export function renameCategory(id, name) {
  return api.put(`/api/category/${id}`, { name });
}

export function deleteCategory(id) {
  return api.delete(`/api/category/${id}`);
}

/** Archived is the availability switch, by the Store Manager's decision. */
export const isArchived = (food) => !food?.is_available;

export const STATUS_FILTERS = [
  { key: "all", label: "All statuses" },
  { key: "on_menu", label: "On menu" },
  { key: "archived", label: "Archived" },
];

export function matchesStatus(food, key) {
  if (key === "on_menu") return !isArchived(food);
  if (key === "archived") return isArchived(food);

  return true;
}

/** The first validation message the server gave for each field. */
export function fieldErrors(error) {
  const errors = error?.payload?.errors ?? {};

  return Object.fromEntries(Object.entries(errors).map(([key, messages]) => [key, messages?.[0]]));
}

/** Why a chosen photo cannot be used, or null. Mirrors the server's rule. */
export function imageProblem(file) {
  if (!file) return null;
  if (!IMAGE_TYPES.includes(file.type)) return "Use a PNG, JPG, WEBP or GIF image.";
  if (file.size > IMAGE_MAX_BYTES) return "That photo is over 5 MB. Choose a smaller one.";

  return null;
}
