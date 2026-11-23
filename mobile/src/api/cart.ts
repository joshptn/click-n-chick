import { api } from "./client";

/**
 * The carts (CartController on the API), for an account or a guest. The two
 * live at different paths: /api/cart with the bearer token, /api/guest/cart
 * with the guest's X-Guest-Token. Each account also has an advance-order cart
 * (mode "advance", phase 9), separate from today's; a guest has none.
 */

export type CartScope = "account" | "guest";

export type AddonPick = { id: number; size?: string | null };

export type AddToCart = { foodId: number; quantity: number; addons?: AddonPick[]; addonIds?: number[] };
export type CartMode = "immediate" | "advance";

export type CartLine = {
  id: number;
  food_id: number;
  food_name: string;
  thumbnail: string | null;
  quantity: number;
  base_price: number;
  /** `addon_name` carries the size picked: "Iced Tea (Large)". */
  addons: { id: number; addon_name: string; addon_price: number; size?: string | null }[];
  addons_total: number;
  /** Add-ons switched off since the line was added; checkout stops on them. */
  unavailable_addons?: string[];
  unit_price: number;
  subtotal: number;
  is_orderable: boolean;
  stock_status: string | null;
  /** Null when made to order: no cap but the API's 99. */
  stock_quantity: number | null;
};

export type Cart = {
  cart: CartLine[];
  item_count: number;
  line_count: number;
  subtotal: number;
  total: number;
  has_unavailable_items: boolean;
};

/** Every cart answer: the whole cart again, plus a message on writes. */
export type CartPayload = Cart & {
  message?: string;
  /** A guest's key, minted on the first add. Null for an account. */
  guest_token?: string | null;
  merged?: number;
};

export const EMPTY_CART: Cart = {
  cart: [],
  item_count: 0,
  line_count: 0,
  subtotal: 0,
  total: 0,
  has_unavailable_items: false,
};

function base(scope: CartScope) {
  return scope === "guest" ? "/api/guest/cart" : "/api/cart";
}

/** A guest's requests carry no bearer token, so a stale one can never ride along. */
function auth(scope: CartScope) {
  return { auth: scope === "account" };
}

export const cartApi = {
  get: (scope: CartScope, mode: CartMode, signal?: AbortSignal) =>
    api.get<CartPayload>(base(scope), { ...auth(scope), signal, params: { mode } }),

  /** `addons`: each with the size picked for one that comes in sizes (decided 2026-11-24). */
  add: (scope: CartScope, mode: CartMode, input: AddToCart) =>
    api.post<CartPayload>(
      `${base(scope)}/items`,
      {
        food_id: input.foodId,
        quantity: input.quantity,
        addons: [...(input.addons ?? []), ...(input.addonIds ?? []).map((id) => ({ id }))],
        mode,
      },
      auth(scope)
    ),

  setQuantity: (scope: CartScope, lineId: number, quantity: number) =>
    api.patch<CartPayload>(`${base(scope)}/items/${lineId}`, { quantity }, auth(scope)),

  remove: (scope: CartScope, lineId: number) =>
    api.delete<CartPayload>(`${base(scope)}/items/${lineId}`, auth(scope)),

  removeMany: (scope: CartScope, mode: CartMode, ids: number[]) =>
    api.delete<CartPayload>(`${base(scope)}/items`, { ...auth(scope), body: { ids, mode } }),

  /** Brings the guest cart named by X-Guest-Token into the signed-in account. */
  merge: () => api.post<CartPayload>("/api/cart/merge"),
};
