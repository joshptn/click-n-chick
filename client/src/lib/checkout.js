import { api } from "./api";

/**
 * Checkout reads.
 *
 * Every figure on the checkout screen comes from the server. Nothing here
 * computes a delivery fee, a distance, or a total - the client sends what the
 * customer chose and renders what comes back, so the price on screen is the
 * price the order will be written with.
 */

export const FULFILMENT = {
  PICKUP: "pickup",
  DELIVERY: "delivery",
};

/** The whole checkout verdict: lines, fees, totals, and what is blocking. */
export function fetchCheckoutQuote(payload, options) {
  return api.post("/api/checkout/quote", payload, options);
}

/** Just the destination half, for re-pricing while the pin moves. */
export function fetchDeliveryQuote({ latitude, longitude }, options) {
  return api.post("/api/delivery/quote", { latitude, longitude }, options);
}

/**
 * Address search, proxied through Laravel (never Nominatim directly).
 *
 * `signal` matters here: the caller aborts the previous search when a new one
 * starts, so a slow response for "apa" cannot land after "apalit" and
 * overwrite the newer results.
 */
export function searchAddress(query, { signal } = {}) {
  return api.get("/api/geocode/search", { params: { q: query }, signal });
}

export function reverseGeocode({ latitude, longitude }, { signal } = {}) {
  return api.get("/api/geocode/reverse", { params: { latitude, longitude }, signal });
}

export function fetchAddresses() {
  return api.get("/api/addresses");
}

/**
 * A `<input type="time">` value plus today's date, as an ISO instant.
 *
 * The input gives "18:30" with no date and no zone. The server reads it in
 * the store's timezone, so what is sent must be an unambiguous instant rather
 * than a bare wall-clock string.
 *
 * A time earlier than now is read as tomorrow. Someone selecting 7am at 9pm
 * means the morning, not fourteen hours ago - and the server's own window
 * check has the final say either way.
 */
export function pickupTimeToIso(value) {
  if (typeof value !== "string" || !/^\d{2}:\d{2}$/.test(value)) return null;

  const [hours, minutes] = value.split(":").map(Number);
  const when = new Date();

  when.setHours(hours, minutes, 0, 0);

  if (when.getTime() < Date.now()) {
    when.setDate(when.getDate() + 1);
  }

  return when.toISOString();
}

/** The reverse, for putting a server-supplied instant back into the input. */
export function isoToPickupTime(iso) {
  if (!iso) return "";

  const when = new Date(iso);

  if (Number.isNaN(when.getTime())) return "";

  return `${String(when.getHours()).padStart(2, "0")}:${String(when.getMinutes()).padStart(2, "0")}`;
}

/**
 * A Philippine mobile number in the form people actually write it.
 *
 * Profiles store +639XXXXXXXXX. Nobody reads that back off a form and
 * recognises their own number, so the field shows 09XXXXXXXXX. The server
 * accepts either and normalises again on its side.
 */
export function toLocalMobile(value) {
  const digits = String(value ?? "").replace(/[\s\-()]/g, "");
  const international = digits.match(/^\+?63(9\d{9})$/);

  if (international) return `0${international[1]}`;

  return /^09\d{9}$/.test(digits) ? digits : "";
}

/** The first blocker matching any of `codes`, or null. */
export function blockerFor(quote, ...codes) {
  return quote?.blockers?.find((blocker) => codes.includes(blocker.code)) ?? null;
}

/** Blockers the Dispatch step is responsible for clearing. */
export const DISPATCH_BLOCKERS = [
  "STORE_CLOSED",
  "STORE_CLOSED_MANUALLY",
  "DELIVERY_UNAVAILABLE",
  "LOCATION_REQUIRED",
  "OUTSIDE_SERVICE_AREA",
  "PICKUP_TIME_REQUIRED",
  "PICKUP_TIME_INVALID",
  "PICKUP_TIME_TOO_SOON",
  "PICKUP_TIME_TOO_LATE",
  "CONTACT_NAME_REQUIRED",
  "CONTACT_PHONE_INVALID",
  "EMPTY_SELECTION",
  "ITEM_UNAVAILABLE",
];
