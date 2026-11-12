const SCRIPT_ID = "recaptcha-v3";

/**
 * The site key is public and fixed per deployment, so it ships in the build
 * (VITE_RECAPTCHA_SITE_KEY) and Google's script can start loading the moment an
 * auth page mounts, without first asking the API for it. Without the variable,
 * the API's config endpoint is asked instead, so a local setup still works.
 */
const BUILT_IN_SITE_KEY = (import.meta.env.VITE_RECAPTCHA_SITE_KEY ?? "").trim();

let configPromise = null;
let scriptPromise = null;

function loadConfig() {
  if (configPromise) return configPromise;

  if (BUILT_IN_SITE_KEY) {
    configPromise = Promise.resolve({ enabled: true, site_key: BUILT_IN_SITE_KEY, actions: RECAPTCHA_ACTIONS });

    return configPromise;
  }

  const url = import.meta.env.VITE_API_URL;

  configPromise = fetch(`${url}/api/config/recaptcha`, {
    headers: { Accept: "application/json" },
  })
    .then((response) => response.json())
    .catch(() => {
      // Asked again next time rather than remembering a transient failure as
      // "disabled", which would send every later submit without a token.
      configPromise = null;

      return { enabled: false, site_key: null, actions: {} };
    });

  return configPromise;
}

function loadScript(siteKey) {
  if (scriptPromise) return scriptPromise;

  scriptPromise = new Promise((resolve, reject) => {
    const existing = document.getElementById(SCRIPT_ID);

    if (existing) {
      existing.remove();
    }

    const script = document.createElement("script");
    script.id = SCRIPT_ID;
    script.src = `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(siteKey)}`;
    script.async = true;
    script.defer = true;
    script.onload = () => resolve();
    script.onerror = () => {
      // Forget the failure so the next call tries again; a blocked or flaky
      // download must not leave the page unable to submit until a reload.
      scriptPromise = null;
      script.remove();
      reject(new Error("reCAPTCHA failed to load."));
    };

    document.head.appendChild(script);
  });

  return scriptPromise;
}

export async function primeRecaptcha() {
  const config = await loadConfig();

  if (!config?.enabled || !config?.site_key) return;

  try {
    await loadScript(config.site_key);
  } catch {
    // executeRecaptcha retries at submit time.
  }
}

export async function recaptchaActions() {
  return (await loadConfig()).actions ?? {};
}

/**
 * How long a submit waits for Google before going without a token.
 *
 * api.js can load while the second script it fetches (gstatic's
 * recaptcha__en.js) is blocked or reset by the network, and then
 * grecaptcha.ready() never fires. Without a ceiling the submit waited forever on
 * "Signing In...". Past it, the request goes without a token and the server
 * decides: it refuses with a "reload and try again" message while reCAPTCHA is
 * on, so the person sees an error instead of a spinner that never ends.
 */
const EXECUTE_TIMEOUT_MS = 8000;

export async function executeRecaptcha(action) {
  const config = await loadConfig();

  if (!config?.enabled || !config?.site_key || !action) return null;

  let timer;

  try {
    const token = new Promise((resolve, reject) => {
      loadScript(config.site_key)
        .then(() => {
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
        })
        .catch(reject);
    });

    const timeout = new Promise((resolve) => {
      timer = setTimeout(() => resolve(null), EXECUTE_TIMEOUT_MS);
    });

    return await Promise.race([token, timeout]);
  } catch {
    return null;
  } finally {
    clearTimeout(timer);
  }
}

export async function withRecaptcha(body, action) {
  const token = await executeRecaptcha(action);

  return token ? { ...body, recaptcha_token: token } : body;
}

export const RECAPTCHA_ACTIONS = {
  REGISTER: "register",
  LOGIN: "login",
  OTP_RESEND: "otp_resend",
  OTP_VERIFY: "otp_verify",
  TWO_FACTOR_CHALLENGE: "two_factor_challenge",
  TWO_FACTOR_RESEND: "two_factor_resend",
  TWO_FACTOR_ENABLE: "two_factor_enable",
  PASSWORD_FORGOT: "password_forgot",
  PASSWORD_RESET: "password_reset",
  PASSWORD_CHANGE: "password_change",
  PLACE_ORDER: "place_order",
};

export default executeRecaptcha;
