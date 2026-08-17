/**
 * One place that talks to the Laravel API.
 *
 * Before this, every caller hand-wrote its base URL, its Accept header and its
 * bearer token, which is how a request ends up authenticated in one screen and
 * anonymous in another. Auth screens still post directly because they run
 * before a session exists; everything after sign-in should come through here.
 */

import { deviceHeader } from "./deviceId";
import { endSession } from "./session";

const BASE_URL = import.meta.env.VITE_API_URL;

/** The token AuthContext persisted, or null. */
export function authToken() {
  try {
    return JSON.parse(localStorage.getItem("token")) || null;
  } catch {
    return null;
  }
}

export class ApiError extends Error {
  constructor(message, status, payload) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.payload = payload;
  }
}

function buildUrl(path, params) {
  const url = new URL(`${BASE_URL}${path}`);

  Object.entries(params ?? {}).forEach(([key, value]) => {
    // Skip empties so an untouched search box does not become `?search=`.
    if (value === undefined || value === null || value === "") return;
    url.searchParams.set(key, value);
  });

  return url.toString();
}

export async function apiFetch(path, { method = "GET", body, params, signal, auth = true } = {}) {
  const token = auth ? authToken() : null;

  const response = await fetch(buildUrl(path, params), {
    method,
    headers: {
      Accept: "application/json",
      ...(body ? { "Content-Type": "application/json" } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      // Lets the server keep this install's device row current, and tell the
      // device list which entry is "this device".
      ...deviceHeader(),
    },
    body: body ? JSON.stringify(body) : undefined,
    credentials: "include",
    signal,
  });

  // 204 and empty bodies are legitimate; treat an unparseable body as {}.
  const payload = await response.json().catch(() => ({}));

  // The session died underneath us - most often because another device signed
  // this one out (FR-01.13), or the token was revoked server-side. Every call
  // through here is already authenticated, so a 401 can only mean the
  // credential stopped working; there is nothing to retry and no signed-in UI
  // left to render. This is the guaranteed exit: the realtime push is faster
  // when Reverb is up, but this fires even when it is not.
  if (response.status === 401 && auth && token) {
    endSession({ reason: "session_ended" });
  }

  if (!response.ok) {
    throw new ApiError(payload.message || "Something went wrong. Please try again.", response.status, payload);
  }

  return payload;
}

export const api = {
  get: (path, options) => apiFetch(path, { ...options, method: "GET" }),
  post: (path, body, options) => apiFetch(path, { ...options, method: "POST", body }),
  patch: (path, body, options) => apiFetch(path, { ...options, method: "PATCH", body }),
  put: (path, body, options) => apiFetch(path, { ...options, method: "PUT", body }),
  delete: (path, options) => apiFetch(path, { ...options, method: "DELETE" }),
};

export default api;
