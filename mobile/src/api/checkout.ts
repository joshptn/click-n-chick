import { api } from "./client";
import type { Order } from "./orders";

/**
 * Checkout and payment (phase 11), as the web uses them
 * (client/src/lib/checkout.js and payments.js). An account checks out at
 * /api/..., a guest at /api/guest/... with the cart's X-Guest-Token; a guest's
 * requests never carry a bearer token.
 *
 * The API is the authority on everything here: the quote prices the order and
 * lists what still blocks it, and placing it re-checks all of that.
 */

export type Fulfilment = "delivery" | "pickup";
export type CheckoutScope = "account" | "guest";

export type Blocker = { code: string; message: string };

export type QuoteLine = {
  id: number;
  food_id: number;
  food_name: string;
  quantity: number;
  addons: { id: number; addon_name: string; addon_price: number }[];
  subtotal: number;
};

export type QuoteDiscount = {
  eligible: boolean;
  status: "approved" | "pending" | "rejected" | "none";
  type: "senior" | "pwd" | null;
  type_label: string | null;
  percentage: number | string;
  used_today: boolean;
  can_apply: boolean;
  available_amount: number;
  applied: boolean;
  amount: number;
};

export type DeliveryLeg = {
  max_driving_km: number;
  origin: { latitude: number; longitude: number } | null;
  distance_km: number | null;
  /** [latitude, longitude] pairs along the road. */
  geometry: [number, number][] | null;
  within_service_area: boolean;
  routing_available: boolean;
  fee: number | null;
  message: string | null;
};

export type Quote = {
  fulfilment_type: Fulfilment;
  items: QuoteLine[];
  item_count: number;
  subtotal: number;
  contact: { name: string; phone: string; email: string };
  delivery: DeliveryLeg | null;
  delivery_fee: number;
  pickup: { earliest: string; latest: string; requested_at: string | null } | null;
  discount: QuoteDiscount;
  total: number;
  blockers: Blocker[];
  can_place: boolean;
};

export type QuoteInput = {
  fulfilment_type: Fulfilment;
  cart_item_ids: number[];
  latitude: number | null;
  longitude: number | null;
  full_address: string | null;
  location: string | null;
  delivery_note: string | null;
  pickup_at: string | null;
  contact_name: string;
  contact_phone: string;
  contact_email: string;
  apply_discount: boolean;
};

export type Placed = {
  message?: string;
  order: Order & { payment_due_at?: string | null };
  payment: { url: string; expires_at: string | null; due_at: string | null };
  /** A guest's key to the order (X-Order-Token). Absent for an account. */
  tracking_token?: string;
};

/** The order a payment is for: an account's by id, a guest's by its token. */
export type PaymentTarget = { orderId?: number | string; token?: string };

export type PaymentOrder = Order & {
  awaiting_payment: boolean;
  payment_due_at: string | null;
  payment_status: string | null;
};

export type PaymentStatus = { paid: boolean; order: PaymentOrder };
export type PaymentStart = { paid: boolean; payment: { url: string; expires_at: string | null } | null; order: PaymentOrder };

export type Place = {
  label: string;
  full_address: string;
  latitude: number;
  longitude: number;
  locality: string | null;
};

function base(scope: CheckoutScope) {
  return scope === "guest" ? "/api/guest" : "/api";
}

function auth(scope: CheckoutScope) {
  return { auth: scope === "account" };
}

function holding(token: string) {
  return { auth: false, headers: { "X-Order-Token": token } };
}

export const checkoutApi = {
  quote: (scope: CheckoutScope, input: QuoteInput, signal?: AbortSignal) =>
    api.post<Quote>(`${base(scope)}/checkout/quote`, input, { ...auth(scope), signal }),

  /** Creates the order waiting for payment and opens Xendit's page for it. */
  place: (scope: CheckoutScope, input: QuoteInput) =>
    api.post<Placed>(scope === "guest" ? "/api/guest/orders" : "/api/order/place", input, auth(scope)),

  search: async (scope: CheckoutScope, query: string, signal?: AbortSignal) =>
    (await api.get<{ results: Place[] }>(`${base(scope)}/geocode/search`, { ...auth(scope), params: { q: query }, signal })).results,

  reverse: async (scope: CheckoutScope, point: { latitude: number; longitude: number }, signal?: AbortSignal) =>
    (await api.get<{ result: Place }>(`${base(scope)}/geocode/reverse`, { ...auth(scope), params: point, signal })).result,
};

export const paymentApi = {
  /**
   * Open the payment page again (the same one while it is still open).
   * `reviewed`: the customer came through Review Advance Order, which the
   * API records (decided 2026-11-20).
   */
  pay: (target: PaymentTarget, reviewed = false) =>
    target.token
      ? api.post<PaymentStart>("/api/guest/order/pay", undefined, holding(target.token))
      : api.post<PaymentStart>(`/api/orders/${target.orderId}/pay`, reviewed ? { reviewed: true } : undefined),

  /** Where the payment stands; the API asks Xendit while it is still open. */
  status: (target: PaymentTarget, signal?: AbortSignal) =>
    target.token
      ? api.get<PaymentStatus>("/api/guest/order/payment", { ...holding(target.token), signal })
      : api.get<PaymentStatus>(`/api/orders/${target.orderId}/payment`, { signal }),
};

export function blockerFor(quote: Quote | null | undefined, ...codes: string[]): Blocker | null {
  return quote?.blockers.find((blocker) => codes.includes(blocker.code)) ?? null;
}

export const STORE_BLOCKERS = ["STORE_CLOSED", "STORE_CLOSED_MANUALLY", "DELIVERY_UNAVAILABLE"];
export const ITEM_BLOCKERS = ["EMPTY_SELECTION", "ITEM_UNAVAILABLE", "ITEM_STOCK_SHORT"];
export const DISCOUNT_BLOCKERS = ["DISCOUNT_NOT_ELIGIBLE", "DISCOUNT_ALREADY_USED"];

/** 09XXXXXXXXX from +639..., 639... or 09...; "" when it is not a PH mobile number. */
export function toLocalMobile(value: string | null | undefined): string {
  const digits = String(value ?? "").replace(/[\s\-()]/g, "");
  const international = digits.match(/^\+?63(9\d{9})$/);

  if (international) return `0${international[1]}`;

  return /^09\d{9}$/.test(digits) ? digits : "";
}

export function isEmail(value: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim());
}

