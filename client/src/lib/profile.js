/**
 * Profile, addresses and discount eligibility.
 *
 * Every mutation here resolves to the SAME shape as fetchProfile, so a caller
 * can write the response straight back into the cache instead of firing a
 * follow-up GET. That matters for more than round trips: saving a phone number
 * can clear its verification server-side, and the response carries that
 * consequence with it rather than leaving the screen showing a stale state.
 */

import api, { apiFetch, authToken } from "./api";
import { deviceHeader } from "./deviceId";

const BASE_URL = import.meta.env.VITE_API_URL;

export function fetchProfile({ signal } = {}) {
  return api.get("/api/profile", { signal });
}

export function updateProfile(body) {
  return api.put("/api/profile", body);
}

/**
 * Upload a file.
 *
 * Not routed through apiFetch: that helper JSON-encodes its body and sets
 * Content-Type, and a multipart request needs the browser to set that header
 * itself so it can add the MIME boundary. Setting it by hand produces a body
 * the server cannot parse.
 */
async function postFile(path, formData) {
  const token = authToken();

  const response = await fetch(`${BASE_URL}${path}`, {
    method: "POST",
    headers: {
      Accept: "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...deviceHeader(),
    },
    body: formData,
    credentials: "include",
  });

  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    const error = new Error(payload.message || "Upload failed. Please try again.");
    error.status = response.status;
    error.payload = payload;
    throw error;
  }

  return payload;
}

export function uploadAvatar(file) {
  const form = new FormData();
  form.append("avatar", file);

  return postFile("/api/profile/avatar", form);
}

export function removeAvatar() {
  return api.delete("/api/profile/avatar");
}

// --- Addresses -------------------------------------------------------------

export function createAddress(body) {
  return api.post("/api/addresses", body);
}

export function updateAddress(id, body) {
  return api.put(`/api/addresses/${id}`, body);
}

export function deleteAddress(id) {
  return apiFetch(`/api/addresses/${id}`, { method: "DELETE" });
}

export function setDefaultAddress(id) {
  return api.patch(`/api/addresses/${id}/default`, {});
}

// --- Discount eligibility --------------------------------------------------

export function fetchDiscountStanding({ signal } = {}) {
  return api.get("/api/discount", { signal });
}

/**
 * Apply for a statutory discount.
 *
 * Rejects with `payload.code` of ALREADY_PENDING or ALREADY_ELIGIBLE when a
 * live claim already exists - the server is the authority on that, not the
 * button's disabled state.
 */
export function applyForDiscount({ type, file }) {
  const form = new FormData();
  form.append("discount_type", type);
  form.append("id_image", file);

  return postFile("/api/discount", form);
}

export const DISCOUNT_TYPES = {
  SENIOR: "senior",
  PWD: "pwd",
};

export const DISCOUNT_TYPE_LABEL = {
  [DISCOUNT_TYPES.SENIOR]: "Senior Citizen",
  [DISCOUNT_TYPES.PWD]: "PWD",
};

export const CLAIM_STATUS = {
  PENDING: "pending",
  APPROVED: "approved",
  REJECTED: "rejected",
};
