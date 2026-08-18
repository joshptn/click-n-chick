/**
 * Two-factor enrolment and management (UC-PROF-006 / BR-31).
 *
 * Three calls, and they are deliberately not symmetric:
 *
 *   enable  -> sends a code to the chosen channel. Changes nothing yet.
 *   confirm -> proves the code arrived, and only then is 2FA on.
 *   disable -> re-checks the account password, and turns it off.
 *
 * Turning it ON requires possession of the channel; turning it OFF requires
 * the password. That asymmetry is the point: an OTP to disable would lock a
 * user out permanently the day they lose their phone, while a password is
 * exactly what an attacker holding a stolen session does not have.
 */

import api from "./api";
import { RECAPTCHA_ACTIONS, withRecaptcha } from "./recaptcha";

/**
 * Ask for an enrolment code on `channel` ("sms" | "email").
 *
 * Also the resend path: calling it again supersedes the outstanding code, so
 * there is no separate resend endpoint to keep in step. Rejects with a 422
 * carrying `reason: "channel_unavailable"` when the deployment cannot deliver
 * over that channel, and `reason: "missing_identifier"` when the account has
 * no address or number on it.
 */
export async function requestTwoFactorCode(channel) {
  return api.post(
    "/api/2fa/enable",
    await withRecaptcha({ channel }, RECAPTCHA_ACTIONS.TWO_FACTOR_ENABLE)
  );
}

/** Redeem the enrolment code. On success 2FA is live from the next login. */
export function confirmTwoFactor(code) {
  return api.post("/api/2fa/confirm", { code: code.trim() });
}

/**
 * Turn 2FA off.
 *
 * A wrong password rejects with a 422 whose payload carries
 * `code: "PASSWORD_REQUIRED"`, which the caller shows inline rather than as a
 * toast - the form they need to correct is still open.
 */
export function disableTwoFactor(password) {
  return api.post("/api/2fa/disable", { password });
}
