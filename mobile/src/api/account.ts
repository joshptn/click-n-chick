import { File } from "expo-file-system";
import { api } from "./client";
import type { Channel } from "./auth";

/**
 * The account pages' endpoints, as the web's Account Settings and Security
 * pages use them (client/src/lib/profile.js, devices.js, twoFactor.js,
 * passwordChange.js). Every profile change answers with the whole profile, so
 * a screen writes the answer straight into its cache.
 */

export type AccountUser = {
  id: number;
  first_name: string | null;
  last_name: string | null;
  email: string | null;
  phone_number: string | null;
  avatar: string | null;
  email_verified_at: string | null;
  phone_verified_at: string | null;
  two_factor_enabled?: boolean;
  two_factor_channel?: Channel | null;
};

export type DiscountType = "senior" | "pwd";

export type DiscountClaim = {
  id: number;
  discount_type: DiscountType;
  discount_status: "pending" | "approved" | "rejected";
  rejection_reason: string | null;
};

export type DiscountStanding = {
  claim: DiscountClaim | null;
  is_eligible: boolean;
  can_apply: boolean;
  percentage: number | string;
};

export type Profile = {
  user: AccountUser;
  profile: {
    email_editable: boolean;
    /** Why the phone number cannot be changed right now, or null. */
    phone_locked_reason: string | null;
    privacy_consent: boolean;
  };
  /** Null for staff, who never reach the app. */
  discount: DiscountStanding | null;
  loyalty_points: number;
  message?: string;
};

export type Device = {
  id: number;
  name: string | null;
  platform: string | null;
  last_ip_address: string | null;
  /** "Apalit, Philippines": approximate, from the API's GeoLite2 lookup. */
  location: string | null;
  last_seen_at: string | null;
  first_seen_at: string | null;
  is_trusted: boolean;
  active_session_count: number;
  is_active: boolean;
  is_current: boolean;
};

export type DeviceList = {
  devices: Device[];
  current_device_id: number | null;
  current_device_trusted: boolean;
};

export type CodeRequest = {
  message?: string;
  channel: Channel;
  /** Masked: "j•••@gmail.com". */
  identifier?: string | null;
  expires_in_minutes?: number;
  resend_available_in?: number;
};

export type ChannelOption = {
  channel: Channel;
  label: string;
  available: boolean;
  reason: string | null;
};

/** A picked photo, as React Native's FormData takes a file. */
export type PickedFile = { uri: string; name: string; type: string };

export const PROFILE_KEY = ["profile"] as const;
export const DEVICES_KEY = ["devices"] as const;
export const CHANNELS_KEY = ["verification-channels"] as const;

function fileForm(field: string, file: PickedFile, extra: Record<string, string> = {}) {
  const form = new FormData();

  Object.entries(extra).forEach(([key, value]) => form.append(key, value));
  // Expo SDK 58 replaces the global fetch with expo/fetch, which cannot send
  // React Native's { uri, name, type } file parts (it throws before any
  // request is made). An expo-file-system File is a Blob it can read.
  form.append(field, new File(file.uri) as unknown as Blob, file.name);

  return form;
}

export const accountApi = {
  profile: (signal?: AbortSignal) => api.get<Profile>("/api/profile", { signal }),

  updateProfile: (body: { first_name: string; last_name: string; phone_number?: string; privacy_consent: boolean }) =>
    api.put<Profile>("/api/profile", body),

  uploadAvatar: (file: PickedFile) => api.post<Profile>("/api/profile/avatar", fileForm("avatar", file)),

  removeAvatar: () => api.delete<Profile>("/api/profile/avatar"),

  /** Refused with code ALREADY_PENDING or ALREADY_ELIGIBLE when a claim is live. */
  applyForDiscount: (type: DiscountType, file: PickedFile) =>
    api.post<DiscountStanding & { message?: string }>("/api/discount", fileForm("id_image", file, { discount_type: type })),

  channels: async (signal?: AbortSignal) =>
    (await api.get<{ channels: ChannelOption[] }>("/api/verification/channels", { signal, auth: false })).channels,

  // Password (BR-33): a code to a verified channel, then the change. Success
  // ends every session, this one included.
  requestPasswordCode: (channel: Channel) => api.post<CodeRequest>("/api/user/password/request-code", { channel }),

  changePassword: (body: { current_password: string; password: string; password_confirmation: string; code: string }) =>
    api.post<{ message?: string }>("/api/user/password", body),

  // Two-step verification (UC-PROF-006): on by a code, off by the password.
  requestTwoFactorCode: (channel: Channel) => api.post<CodeRequest>("/api/2fa/enable", { channel }),

  confirmTwoFactor: (code: string) => api.post<{ message?: string; user?: AccountUser }>("/api/2fa/confirm", { code }),

  disableTwoFactor: (password: string) =>
    api.post<{ message?: string; user?: AccountUser }>("/api/2fa/disable", { password }),

  // Devices (FR-01.13).
  devices: (signal?: AbortSignal) => api.get<DeviceList>("/api/user/devices", { signal }),

  /** current_device_revoked: this phone's own sign-in has just ended. */
  revokeDevice: (id: number) =>
    api.delete<{ message?: string; revoked_sessions: number; current_device_revoked: boolean }>(`/api/user/devices/${id}`),

  /** Granting trust needs the password; removing it does not. */
  setTrust: (id: number, trusted: boolean, password?: string) =>
    api.patch<{ message?: string }>(`/api/user/devices/${id}/trust`, { trusted, ...(trusted ? { password } : {}) }),

  signOutOthers: () => api.post<{ message?: string; revoked_sessions: number }>("/api/user/devices/sign-out-others"),
};
