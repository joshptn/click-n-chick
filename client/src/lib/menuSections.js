/**
 * The home menu, grouped into sections (decided 2026-11-26): BES Seller first
 * (the dishes marked best seller), then one section per category in the order
 * the Store Manager added them (by ID), then any dish with no category. A
 * best seller shows in both BES Seller and its own category. Categories with
 * no dishes are left out.
 */
export const BEST_SELLER_KEY = "bes-seller";

const OTHERS_KEY = "others";

/** The element id a section is scrolled to: "menu-bes-seller", "menu-category-3". */
export function sectionAnchor(key) {
  return `menu-${key}`;
}

export function menuSections(foods) {
  const bestSellers = foods.filter((food) => food.is_best_seller);
  const byCategory = new Map();
  const uncategorised = [];

  for (const food of foods) {
    const id = food.category?.id;

    if (id == null) {
      uncategorised.push(food);
      continue;
    }

    if (!byCategory.has(id)) byCategory.set(id, { name: food.category.name, foods: [] });
    byCategory.get(id).foods.push(food);
  }

  const categories = [...byCategory.entries()]
    .sort(([a], [b]) => a - b)
    .map(([id, group]) => ({ key: `category-${id}`, label: group.name, foods: group.foods }));

  return [
    ...(bestSellers.length > 0 ? [{ key: BEST_SELLER_KEY, label: "BES Seller", foods: bestSellers, featured: true }] : []),
    ...categories,
    ...(uncategorised.length > 0 ? [{ key: OTHERS_KEY, label: "Others", foods: uncategorised }] : []),
  ];
}

export function dishCount(count) {
  return `${count} ${count === 1 ? "dish" : "dishes"}`;
}
