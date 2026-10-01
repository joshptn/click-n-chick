import { api, authToken } from "./api";


export const FULFILMENT = {
  PICKUP: "pickup",
  DELIVERY: "delivery",
};

function base() {
  return authToken() ? "/api" : "/api/guest";
}

export function fetchCheckoutQuote(payload, options) {
  return api.post(`${base()}/checkout/quote`, payload, options);
}

export function fetchDeliveryQuote({ latitude, longitude }, options) {
  return api.post(`${base()}/delivery/quote`, { latitude, longitude }, options);
}

export function searchAddress(query, { signal } = {}) {
  return api.get(`${base()}/geocode/search`, { params: { q: query }, signal });
}

export function reverseGeocode({ latitude, longitude }, { signal } = {}) {
  return api.get(`${base()}/geocode/reverse`, { params: { latitude, longitude }, signal });
}

export function fetchAddresses() {
  return api.get("/api/addresses");
}

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

export function isoToPickupTime(iso) {
  if (!iso) return "";

  const when = new Date(iso);

  if (Number.isNaN(when.getTime())) return "";

  return `${String(when.getHours()).padStart(2, "0")}:${String(when.getMinutes()).padStart(2, "0")}`;
}

export function toLocalMobile(value) {
  const digits = String(value ?? "").replace(/[\s\-()]/g, "");
  const international = digits.match(/^\+?63(9\d{9})$/);

  if (international) return `0${international[1]}`;

  return /^09\d{9}$/.test(digits) ? digits : "";
}

export function blockerFor(quote, ...codes) {
  return quote?.blockers?.find((blocker) => codes.includes(blocker.code)) ?? null;
}
export const DISPATCH_BLOCKERS = [
  "STORE_CLOSED",
  "STORE_CLOSED_MANUALLY",
  "DELIVERY_UNAVAILABLE",
  "LOCATION_REQUIRED",
  "OUTSIDE_SERVICE_AREA",
  "ROUTING_UNAVAILABLE",
  "NO_ROUTE_FOUND",
  "PICKUP_TIME_REQUIRED",
  "PICKUP_TIME_INVALID",
  "PICKUP_TIME_TOO_SOON",
  "PICKUP_TIME_TOO_LATE",
  "CONTACT_NAME_REQUIRED",
  "CONTACT_PHONE_INVALID",
  "CONTACT_EMAIL_REQUIRED",
  "CONTACT_EMAIL_INVALID",
  "EMPTY_SELECTION",
  "ITEM_UNAVAILABLE",
];
