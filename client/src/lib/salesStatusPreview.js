/**
 * TEMPORARY - a visual placeholder for the Sales Status column.
 *
 * BR-23's tiers (Best Seller, High Performer, No Sales Yet) have no finalised
 * rules, so nothing here classifies anything. Every dish reads "Not rated"
 * unless the server ever sends a real `sales_status`. The column and its filter
 * read only through this file, which keeps the placeholder out of the stock
 * logic and lets it be replaced whole once the rules are settled.
 */
export const SALES_STATUS_PREVIEW = [
  { key: "best_seller", label: "Best Seller", tone: "border-brand-500 text-brand-600" },
  { key: "high_performer", label: "High Performer", tone: "border-[#8b5cf6] text-[#7c3aed]" },
  { key: "no_sales", label: "No Sales Yet", tone: "border-[#d9d3cb] text-[#8d8884]" },
  { key: "unrated", label: "Not rated", tone: "border-dashed border-[#d9d3cb] text-[#a39f9b]" },
];

export const SALES_STATUS_NOTE = "Sales status is a preview. Its rules are not final, so every dish reads “Not rated” for now.";

export function salesStatusOf(food) {
  const key = food?.sales_status;

  return SALES_STATUS_PREVIEW.find((status) => status.key === key) ?? SALES_STATUS_PREVIEW[3];
}

export function matchesSalesStatus(food, key) {
  return key === "all" || salesStatusOf(food).key === key;
}
