const SCRIPT_ID = "recaptcha-v3";

let configPromise = null;
let scriptPromise = null;

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

export async function primeRecaptcha() {
  const config = await loadConfig();

  if (!config?.enabled || !config?.site_key) return;

  try {
    await loadScript(config.site_key);
  } catch {
    // executeRecaptcha reports the failure at submit time.
  }
}


export async function recaptchaActions() {
  return (await loadConfig()).actions ?? {};
}

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
    
    return null;
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
  TWO_FACTOR_ENABLE: "two_factor_enable",
  PASSWORD_FORGOT: "password_forgot",
  PASSWORD_RESET: "password_reset",
  PASSWORD_CHANGE: "password_change",
  PLACE_ORDER: "place_order",
};

export default executeRecaptcha;
