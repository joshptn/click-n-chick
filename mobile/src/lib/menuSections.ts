import type { Food } from "../api/menu";

/**
 * The home menu in sections, as on the web (client/src/lib/menuSections.js,
 * decided 2026-11-26): BES Seller first (dishes marked best seller), then one
 * section per category in the order the Store Manager added them (by ID),
 * then any dish with no category. A best seller shows in both BES Seller and
 * its own category; categories with no dishes are left out.
 */
export type MenuSection = { key: string; label: string; foods: Food[]; featured?: boolean };

export const BEST_SELLER_KEY = "bes-seller";

export function menuSections(foods: Food[]): MenuSection[] {
  const bestSellers = foods.filter((food) => food.is_best_seller);
  const byCategory = new Map<number, { name: string; foods: Food[] }>();
  const uncategorised: Food[] = [];

  for (const food of foods) {
    const id = food.category?.id;

    if (id == null) {
      uncategorised.push(food);
      continue;
    }

    const group = byCategory.get(id) ?? { name: food.category?.name ?? "", foods: [] };
    group.foods.push(food);
    byCategory.set(id, group);
  }

  const categories = [...byCategory.entries()]
    .sort(([a], [b]) => a - b)
    .map(([id, group]) => ({ key: `category-${id}`, label: group.name, foods: group.foods }));

  return [
    ...(bestSellers.length > 0 ? [{ key: BEST_SELLER_KEY, label: "BES Seller", foods: bestSellers, featured: true }] : []),
    ...categories,
    ...(uncategorised.length > 0 ? [{ key: "others", label: "Others", foods: uncategorised }] : []),
  ];
}

export function dishCount(count: number) {
  return `${count} ${count === 1 ? "dish" : "dishes"}`;
}
