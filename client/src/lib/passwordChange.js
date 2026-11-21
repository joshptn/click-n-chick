/**
 * Changing the password from inside an authenticated session (BR-33).
 *
 * Two calls, and the OTP is not optional. BR-33 requires the account to
 * re-prove itself over a verified channel before the password moves, so the
 * form gathers the passwords, then a code is sent and redeemed together with
 * them. The server rejects the change outright without a live code.
 *
 * Succeeding revokes every session on the account, this one included - which
 * is the correct outcome for a password change and why the caller must sign
 * the user out rather than carry on.
 */

import api from "./api";

/** Send the confirmation code to `channel` ("sms" | "email"). */
export function requestPasswordChangeCode(channel) {
  return api.post("/api/user/password/request-code", { channel });
}

export function changePassword({ currentPassword, password, passwordConfirmation, code }) {
  return api.post("/api/user/password", {
    current_password: currentPassword,
    password,
    password_confirmation: passwordConfirmation,
    code: code.trim(),
  });
}
