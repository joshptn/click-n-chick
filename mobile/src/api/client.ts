import { getDeviceId } from "../lib/deviceId";
import { currentToken, endSession } from "../lib/session";

/**
 * One place that talks to the Laravel API, as client/src/lib/api.js does on
 * the web.
 *
 * The base URL is EXPO_PUBLIC_API_URL. A phone in Expo Go cannot reach
 * `localhost`, so in development it is the computer's LAN address
 * (http://192.168.x.x:8000, with the API started on 0.0.0.0) or the Render API.
 */
const BASE_URL = (process.env.EXPO_PUBLIC_API_URL ?? "").replace(/\/+$/, "");

/** Long enough for Render waking up; short enough not to hang a screen. */
const TIMEOUT_MS = 20_000;

export class ApiError extends Error {
  status: number;
  payload: Record<string, unknown>;

  constructor(message: string, status: number, payload: Record<string, unknown> = {}) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.payload = payload;
  }
}

type Params = Record<string, string | number | boolean | null | undefined>;

export type RequestOptions = {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  params?: Params;
  signal?: AbortSignal;
  /** False for public endpoints: no bearer token is sent. */
  auth?: boolean;
  headers?: Record<string, string>;
};

function buildUrl(path: string, params?: Params): string {
  const query = Object.entries(params ?? {})
    // Skip empties so an untouched search box does not become `?search=`.
    .filter(([, value]) => value !== undefined && value !== null && value !== "")
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`)
    .join("&");

  return `${BASE_URL}${path}${query ? `?${query}` : ""}`;
}

export async function apiFetch<T = unknown>(
  path: string,
  { method = "GET", body, params, signal, auth = true, headers: extraHeaders }: RequestOptions = {}
): Promise<T> {
  if (!BASE_URL) {
    throw new ApiError("The app is not set up with a server address (EXPO_PUBLIC_API_URL).", 0);
  }

  const token = auth ? currentToken() : null;
  const deviceId = await getDeviceId();
  const multipart = typeof FormData !== "undefined" && body instanceof FormData;

  // The caller's signal and our timeout both cancel the request.
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
  const onCallerAbort = () => controller.abort();
  signal?.addEventListener("abort", onCallerAbort);

  let response: Response;

  try {
    response = await fetch(buildUrl(path, params), {
      method,
      headers: {
        Accept: "application/json",
        ...(body !== undefined && !multipart ? { "Content-Type": "application/json" } : {}),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...(deviceId ? { "X-Device-Id": deviceId } : {}),
        ...(extraHeaders ?? {}),
      },
      body: multipart ? (body as FormData) : body !== undefined ? JSON.stringify(body) : undefined,
      signal: controller.signal,
    });
  } catch (error) {
    if (signal?.aborted) throw error;

    throw new ApiError(
      controller.signal.aborted
        ? "The server took too long to answer. Please try again."
        : "Can't reach Click n Chick. Check your connection and try again.",
      0,
      { reason: "network" }
    );
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener("abort", onCallerAbort);
  }

  // 204 and empty bodies are legitimate; an unparseable body counts as {}.
  const payload = ((await response.json().catch(() => ({}))) ?? {}) as Record<string, unknown>;

  // Sent as one session, answered after it ended (signed out, or another
  // account signed in meanwhile). The data belongs to the old session, and its
  // 401 must not end the session that replaced it.
  if (token && currentToken() !== token) {
    throw new ApiError("You were signed out before this finished.", 0, { reason: "session_changed" });
  }

  // The token stopped working: signed out from another device, password
  // changed, or expired. Nothing to retry.
  if (response.status === 401 && token) {
    await endSession();
  }

  if (!response.ok) {
    const message = typeof payload.message === "string" ? payload.message : "Something went wrong. Please try again.";
    throw new ApiError(message, response.status, payload);
  }

  return payload as T;
}

export const api = {
  get: <T = unknown>(path: string, options?: RequestOptions) => apiFetch<T>(path, { ...options, method: "GET" }),
  post: <T = unknown>(path: string, body?: unknown, options?: RequestOptions) =>
    apiFetch<T>(path, { ...options, method: "POST", body }),
  put: <T = unknown>(path: string, body?: unknown, options?: RequestOptions) =>
    apiFetch<T>(path, { ...options, method: "PUT", body }),
  patch: <T = unknown>(path: string, body?: unknown, options?: RequestOptions) =>
    apiFetch<T>(path, { ...options, method: "PATCH", body }),
  delete: <T = unknown>(path: string, options?: RequestOptions) => apiFetch<T>(path, { ...options, method: "DELETE" }),
};

export default api;
