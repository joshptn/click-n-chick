/**
 * Loading reCAPTCHA v2 Invisible (decided 2026-11-22). The widget itself lives
 * in hooks/useRecaptcha.js; this only finds the site key and loads Google's
 * script once per tab.
 *
 * The site key is public and fixed per deployment, so it ships in the build
 * (VITE_RECAPTCHA_SITE_KEY) and the script can start loading as soon as a
 * guarded form opens. Without the variable, the API's config endpoint is asked
 * instead, so a local setup still works.
 */

const SCRIPT_ID = "recaptcha-v2";
const ONLOAD = "__clickNChickRecaptchaLoaded";

/** How long Google's script gets to become ready before the load counts as failed. */
const LOAD_TIMEOUT_MS = 15000;

const BUILT_IN_SITE_KEY = (import.meta.env.VITE_RECAPTCHA_SITE_KEY ?? "").trim();

let configPromise = null;
let scriptPromise = null;

/** The request body with the security check's token, when there is one. */
export function withToken(body, recaptchaToken) {
  return recaptchaToken ? { ...body, recaptcha_token: recaptchaToken } : body;
}

/**
 * Whether the check is on, and its site key.
 *
 * `known: false` when the API could not be asked; the caller then sends the
 * request without a token and lets the server decide, and the next call asks
 * again rather than remembering the failure.
 */
export function recaptchaConfig() {
  if (configPromise) return configPromise;

  if (BUILT_IN_SITE_KEY) {
    configPromise = Promise.resolve({ known: true, enabled: true, siteKey: BUILT_IN_SITE_KEY });

    return configPromise;
  }

  configPromise = fetch(`${import.meta.env.VITE_API_URL}/api/config/recaptcha`, {
    headers: { Accept: "application/json" },
  })
    .then((response) => {
      if (!response.ok) throw new Error(`HTTP ${response.status}`);

      return response.json();
    })
    .then((config) => ({
      known: true,
      enabled: Boolean(config?.enabled && config?.site_key),
      siteKey: config?.site_key ?? null,
    }))
    .catch(() => {
      configPromise = null;

      return { known: false, enabled: false, siteKey: null };
    });

  return configPromise;
}

/**
 * Google's script, in the mode where the page sets up its own widgets. Resolves
 * with `window.grecaptcha` once it is ready to render. A failed download is
 * forgotten, so the next call tries again.
 */
export function loadRecaptcha() {
  if (scriptPromise) return scriptPromise;

  scriptPromise = new Promise((resolve, reject) => {
    document.getElementById(SCRIPT_ID)?.remove();

    const script = document.createElement("script");

    const fail = () => {
      window.clearTimeout(timer);
      scriptPromise = null;
      script.remove();
      reject(new Error("reCAPTCHA failed to load."));
    };

    // api.js can arrive while the second file it fetches (from gstatic) is
    // blocked, and then the ready callback never comes. Past this, it counts as
    // a failed load and the next tap starts over.
    const timer = window.setTimeout(fail, LOAD_TIMEOUT_MS);

    window[ONLOAD] = () => {
      window.clearTimeout(timer);
      resolve(window.grecaptcha);
    };

    script.id = SCRIPT_ID;
    script.src = `https://www.google.com/recaptcha/api.js?render=explicit&onload=${ONLOAD}`;
    script.async = true;
    script.defer = true;
    script.onerror = fail;

    document.head.appendChild(script);
  });

  return scriptPromise;
}
