import { api, authToken } from "./api";
import { withToken } from "./recaptcha";

/**
 * Paying through Xendit's hosted checkout (FR-04.2, decided 2026-11-17).
 *
 * Placing an order opens a payment page on Xendit and the browser goes there.
 * Xendit sends the customer back to /payment/return, a fixed address that names
 * no order - a guest's tracking token must never be handed to a third party -
 * so the order to return to is remembered here, in this tab, before leaving.
 */

const RETURN_KEY = "payment_return";

/**
 * Place the order and get its payment page. Signed in or as a guest; only a
 * guest's order carries the security check's token (decided 2026-11-22).
 */
export function placeOrder(payload, recaptchaToken = null) {
  return authToken()
    ? api.post("/api/order/place", payload)
    : api.post("/api/guest/orders", withToken(payload, recaptchaToken));
}

function holding(token) {
  return { headers: { "X-Order-Token": token } };
}

/**
 * Open the payment page again (or for the first time, for an advance order).
 * `reviewed`: the customer came through Review Advance Order, which the server
 * records (decided 2026-11-20).
 */
export function payOrder({ orderId, token, reviewed = false }) {
  return token
    ? api.post("/api/guest/order/pay", undefined, holding(token))
    : api.post(`/api/orders/${orderId}/pay`, reviewed ? { reviewed: true } : undefined);
}

/** Where the payment stands. Asks Xendit when it is still open, so a payment shows at once. */
export function checkPayment({ orderId, token }, { signal } = {}) {
  return token
    ? api.get("/api/guest/order/payment", { ...holding(token), signal })
    : api.get(`/api/orders/${orderId}/payment`, { signal });
}

export function trackingPath({ orderId, token }) {
  return token ? `/track/${token}` : `/orders/${orderId}`;
}

export function rememberPaymentReturn(target) {
  try {
    sessionStorage.setItem(RETURN_KEY, JSON.stringify(target));
  } catch {
    // Storage blocked: the return page falls back to the order list.
  }
}

/** The order this tab left to pay for, if any. Kept until another payment replaces it. */
export function paymentReturnTarget() {
  try {
    const target = JSON.parse(sessionStorage.getItem(RETURN_KEY) ?? "null");

    return target && (target.orderId || target.token) ? target : null;
  } catch {
    return null;
  }
}

/** Remember where to come back to, then leave for Xendit's page. */
export function goToPayment(url, target) {
  rememberPaymentReturn(target);
  window.location.assign(url);
}

/** Minutes and seconds left before a deadline, or null once it has passed. */
export function timeLeft(dueIso, now = Date.now()) {
  if (!dueIso) return null;

  const ms = new Date(dueIso).getTime() - now;

  if (Number.isNaN(ms) || ms <= 0) return null;

  const minutes = Math.floor(ms / 60000);
  const seconds = Math.floor((ms % 60000) / 1000);

  return `${minutes}:${String(seconds).padStart(2, "0")}`;
}
