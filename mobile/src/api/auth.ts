import { api } from "./client";

/**
 * The sign-in, sign-up and recovery endpoints, as the web app uses them
 * (client/src/pages/auth). The app sends no reCAPTCHA token: the API lets the
 * native app through and relies on its rate limits (FR-01.8, O-06).
 */

export type Channel = "sms" | "email";

export type AuthUser = {
  id: number;
  role: "customer" | "admin" | "super_admin";
  first_name?: string | null;
  last_name?: string | null;
};

/** A signed-in session. */
export type SessionPayload = {
  user: AuthUser;
  token: string;
  device_id?: number | null;
};

/** The password was right but a code is needed first (two-step verification). */
export type ChallengePayload = {
  two_factor_required: true;
  challenge_token: string;
  two_factor_channel: Channel;
  /** Masked: "+639••••••607" or "j•••@gmail.com". */
  identifier?: string | null;
};

export type CodeSent = {
  message?: string;
  /** Seconds until another code may be asked for. */
  resend_available_in?: number;
};

export type Registered = CodeSent & {
  verification_channel?: Channel;
  identifier?: string;
};

export function isChallenge(payload: SessionPayload | ChallengePayload): payload is ChallengePayload {
  return (payload as ChallengePayload).two_factor_required === true;
}

/** The same test the API uses to tell an email address from a phone number. */
export function channelFor(identifier: string): Channel {
  return identifier.includes("@") ? "email" : "sms";
}

const PUBLIC = { auth: false } as const;

export const authApi = {
  login: (login: string, password: string) =>
    api.post<SessionPayload | ChallengePayload>("/api/login", { login, password }, PUBLIC),

  register: (body: {
    first_name: string;
    last_name: string;
    identifier: string;
    password: string;
    password_confirmation: string;
  }) => api.post<Registered>("/api/register", body, PUBLIC),

  verifyCode: (identifier: string, code: string) =>
    api.post<SessionPayload>("/api/otp/verify", { identifier, code }, PUBLIC),

  resendCode: (identifier: string) => api.post<CodeSent>("/api/otp/resend", { identifier }, PUBLIC),

  twoFactor: (challengeToken: string, code: string) =>
    api.post<SessionPayload>("/api/2fa/challenge", { challenge_token: challengeToken, code }, PUBLIC),

  twoFactorResend: (challengeToken: string) =>
    api.post<CodeSent>("/api/2fa/resend", { challenge_token: challengeToken }, PUBLIC),

  forgotPassword: (identifier: string) => api.post<CodeSent>("/api/password/forgot", { identifier }, PUBLIC),

  resetPassword: (body: { identifier: string; code: string; password: string; password_confirmation: string }) =>
    api.post<SessionPayload | ChallengePayload>("/api/password/reset", body, PUBLIC),

  /** End a session by its token: the signed-in one, or one the app will not keep. */
  logout: (token: string) =>
    api.post<{ message: string }>("/api/logout", undefined, {
      auth: false,
      headers: { Authorization: `Bearer ${token}` },
    }),
};
