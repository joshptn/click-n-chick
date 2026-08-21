/** The scheme://host:port of a URL, or null when it is unset or unparseable. */
function originOf(value) {
  try {
    return new URL(String(value ?? "").trim()).origin;
  } catch {
    return null;
  }
}

/** The websocket origins Reverb will be reached on. */
function reverbOrigins(env) {
  const host = String(env.VITE_REVERB_HOST ?? "").trim();

  if (!host) return [];

  const port = String(env.VITE_REVERB_PORT ?? "").trim();
  const secure = String(env.VITE_REVERB_SCHEME ?? "").trim() === "https";
  const authority = port ? `${host}:${port}` : host;

  return secure ? [`wss://${authority}`] : [`ws://${authority}`, `wss://${authority}`];
}

export function buildPolicy(env, { dev = false } = {}) {
  const api = originOf(env.VITE_API_URL);
  const sockets = reverbOrigins(env);

  const directives = {
    "default-src": ["'self'"],
    "script-src": [
      "'self'",
      "https://www.google.com/recaptcha/",
      "https://www.gstatic.com/recaptcha/",
      ...(dev ? ["'unsafe-inline'", "'unsafe-eval'"] : []),
    ],
    "style-src": ["'self'", "'unsafe-inline'", "https://fonts.googleapis.com"],

    "font-src": ["'self'", "https://fonts.gstatic.com", "data:"],
    "img-src": ["'self'", "data:", "https://res.cloudinary.com", "https://images.unsplash.com"],

    "connect-src": [
      "'self'",
      ...(api ? [api] : []),
      ...sockets,
      "https://www.google.com",
      "https://api.iconify.design",
      "https://api.unisvg.com",
      "https://api.simplesvg.com",
      ...(dev ? ["ws:", "wss:"] : []),
    ],
    "frame-src": ["https://www.google.com/recaptcha/"],
    "object-src": ["'none'"],
    "base-uri": ["'self'"],
    "form-action": ["'self'"],
  };

  return Object.entries(directives)
    .map(([directive, values]) => `${directive} ${values.join(" ")}`)
    .join("; ");
}

/** Vite plugin that stamps the policy into index.html. */
export default function contentSecurityPolicy(env) {
  let isDev = false;

  return {
    name: "chick-n-click-csp",

    configResolved(config) {
      isDev = config.command === "serve";
    },

    transformIndexHtml(html) {
      return {
        html,
        tags: [
          {
            tag: "meta",
            attrs: {
              "http-equiv": "Content-Security-Policy",
              content: buildPolicy(env, { dev: isDev }),
            },
            injectTo: "head-prepend",
          },
        ],
      };
    },
  };
}
