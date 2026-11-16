import { ApiError } from "../api/client";
import { authApi, type SessionPayload } from "../api/auth";
import { authFlow } from "./authFlow";
import { startSession } from "./session";

export const STAFF_MESSAGE =
  "Store Agent and Store Manager accounts use the website in a browser. The app is for customers.";

/**
 * Take a session the API issued: sign-in, a verified code, a reset.
 *
 * The app is for customers only (PRD §9), so a staff account's session is
 * ended on the server straight away and refused here, as the installed PWA
 * does. The protected routes then leave the auth screens by themselves.
 */
export async function adoptSession(payload: SessionPayload): Promise<void> {
  if (payload.user?.role !== "customer") {
    authApi.logout(payload.token).catch(() => {
      // It expires on its own; nothing on the phone keeps it.
    });
    throw new Error(STAFF_MESSAGE);
  }

  authFlow.clear();
  await startSession(payload.token);
}

/** The most useful line from an API error: a field's first message, else the message. */
export function errorMessage(error: unknown, fallback = "Something went wrong. Please try again."): string {
  if (error instanceof ApiError) {
    const errors = error.payload.errors as Record<string, string[]> | undefined;
    const first = errors ? Object.values(errors)[0]?.[0] : undefined;

    return first ?? error.message ?? fallback;
  }

  return error instanceof Error && error.message ? error.message : fallback;
}

/** Field errors from a 422, for react-hook-form's setError. */
export function fieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof ApiError)) return {};

  const errors = (error.payload.errors ?? {}) as Record<string, string[]>;

  return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0] ?? ""]));
}
