import { api, authToken } from "./api";
import { withToken } from "./recaptcha";

/**
 * Paying through Xendit's hosted checkout (FR-04.2, decided 2026-11-17).
 *
 * Placing an order opens a payment page on Xendit and the browser goes there.
 * Xendit sends the customer back to /payment/return, a fixed address that names
 * no order - a guest's tracking token must never be handed to a third party -
 * so the order to return to is remembered here before leaving. In this
 * browser rather than this tab (decided 2026-11-22): a payment app often hands
 * the customer back in a new tab, which a tab's own storage would not reach.
 */

const RETURN_KEY = "payment_return";

/** Long enough to cover the 10 minutes to pay and a slow return, no longer. */
const RETURN_TTL_MS = 60 * 60 * 1000;

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

/**
 * A guest's token rides after "#" (decided 2026-11-22): the part of an address
 * a browser never sends, so it stays out of the web host's request logs.
 */
export function trackingPath({ orderId, token }) {
  return token ? `/track#${token}` : `/orders/${orderId}`;
}

export function rememberPaymentReturn(target) {
  try {
    const { orderId = null, token = null } = target ?? {};

    localStorage.setItem(RETURN_KEY, JSON.stringify({ orderId, token, at: Date.now() }));
  } catch {
    // Storage blocked: the return page falls back to the order list.
  }
}

/** The order this browser last left to pay for, within the hour. */
export function paymentReturnTarget() {
  try {
    const saved = JSON.parse(localStorage.getItem(RETURN_KEY) ?? "null");

    if (!saved || !(saved.orderId || saved.token)) return null;
    if (!Number.isFinite(saved.at) || Date.now() - saved.at > RETURN_TTL_MS) return null;

    return saved.token ? { token: saved.token } : { orderId: saved.orderId };
  } catch {
    return null;
  }
}

/** Paid: nothing more to come back to. */
export function clearPaymentReturn() {
  try {
    localStorage.removeItem(RETURN_KEY);
  } catch {
    // Nothing to clear.
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
