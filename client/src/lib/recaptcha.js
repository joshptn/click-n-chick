/**
 * reCAPTCHA v3 on the browser side.
 *
 * v3 has no visible challenge: the script mints a scored token in the
 * background and the server decides. Everything about whether it is on, which
 * site key to use and which action names exist comes from
 * GET /api/config/recaptcha, so there is no VITE_ key here to fall out of step
 * with the backend.
 *
 * Every helper degrades to null rather than throwing. A null token means "send
 * the request without one", which is exactly right while reCAPTCHA is
 * unconfigured - the server skips the check in that state too.
 */

const SCRIPT_ID = "recaptcha-v3";

let configPromise = null;
let scriptPromise = null;

/** Fetched once per page load - availability is a property of the deployment. */
function loadConfig() {
  if (configPromise) return configPromise;

  const url = import.meta.env.VITE_API_URL;

  configPromise = fetch(`${url}/api/config/recaptcha`, {
    headers: { Accept: "application/json" },
  })
    .then((response) => response.json())
    .catch(() => ({ enabled: false, site_key: null, actions: {} }));

  return configPromise;
}

function loadScript(siteKey) {
  if (scriptPromise) return scriptPromise;

  scriptPromise = new Promise((resolve, reject) => {
    if (document.getElementById(SCRIPT_ID)) {
      resolve();
      return;
    }

    const script = document.createElement("script");
    script.id = SCRIPT_ID;
    script.src = `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(siteKey)}`;
    script.async = true;
    script.defer = true;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error("reCAPTCHA failed to load."));

    document.head.appendChild(script);
  });

  return scriptPromise;
}

/**
 * Start loading the script before there is anything to submit.
 *
 * v3 scores on behavioural signals gathered while the page is open, so minting
 * a token the instant the script lands scores worse than one minted after the
 * user has spent a moment on the form. Called from AuthLayout, which every
 * guarded screen already renders. No-op while reCAPTCHA is off.
 */
export async function primeRecaptcha() {
  const config = await loadConfig();

  if (!config?.enabled || !config?.site_key) return;

  try {
    await loadScript(config.site_key);
  } catch {
    // Nothing to do here - executeRecaptcha reports the failure at submit time.
  }
}

/** The action names the server will check tokens against. */
export async function recaptchaActions() {
  return (await loadConfig()).actions ?? {};
}

/**
 * Mint a token for one action, or null if reCAPTCHA is off or unreachable.
 *
 * The action must match the one its route declares - the server rejects a
 * token minted anywhere else, which is what stops a token from one form being
 * replayed against another.
 */
export async function executeRecaptcha(action) {
  const config = await loadConfig();

  if (!config?.enabled || !config?.site_key || !action) return null;

  try {
    await loadScript(config.site_key);

    return await new Promise((resolve, reject) => {
      if (!window.grecaptcha) {
        reject(new Error("reCAPTCHA is unavailable."));
        return;
      }

      window.grecaptcha.ready(() => {
        window.grecaptcha
          .execute(config.site_key, { action })
          .then(resolve)
          .catch(reject);
      });
    });
  } catch {
    // Let the request go without a token. The server answers 422 with
    // RECAPTCHA_FAILED if it genuinely required one, which surfaces a real
    // error to the user instead of a silent dead form.
    return null;
  }
}

/**
 * Body-building helper: adds recaptcha_token only when there is one.
 *
 * Keeps call sites to a single await and stops an explicit `undefined` from
 * being serialised into the payload.
 */
export async function withRecaptcha(body, action) {
  const token = await executeRecaptcha(action);

  return token ? { ...body, recaptcha_token: token } : body;
}

/**
 * Mirrors App\Services\Recaptcha\RecaptchaAction. Kept as literals so a call
 * site reads plainly; the server is still the authority and rejects anything
 * that does not match the route it hits.
 */
export const RECAPTCHA_ACTIONS = {
  REGISTER: "register",
  LOGIN: "login",
  OTP_RESEND: "otp_resend",
  OTP_VERIFY: "otp_verify",
  TWO_FACTOR_CHALLENGE: "two_factor_challenge",
  TWO_FACTOR_ENABLE: "two_factor_enable",
  PASSWORD_FORGOT: "password_forgot",
  PASSWORD_RESET: "password_reset",
  PASSWORD_CHANGE: "password_change",
};

export default executeRecaptcha;
