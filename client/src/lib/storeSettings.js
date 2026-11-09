import { api } from "./api";

export const SETTINGS_KEY = ["store-settings"];

export const settingKey = (section) => [...SETTINGS_KEY, section];

export function fetchSetting(section, { signal } = {}) {
  return api.get(`/api/admin/settings/${section}`, { signal });
}

/** `password` is the Manager's own step-up, sent only once the server asks for it. */
export function saveSetting(section, body, password) {
  return api.put(`/api/admin/settings/${section}`, password ? { ...body, password } : body);
}

function rangeError(value, min, max) {
  const number = String(value).trim() === "" ? Number.NaN : Number(value);

  if (!Number.isFinite(number)) return "Enter a number.";
  if (number < min) return `Must be at least ${min}.`;
  if (number > max) return `Must be ${max} or less.`;

  return null;
}

const withoutPassing = (errors) => Object.fromEntries(Object.entries(errors).filter(([, message]) => message));

/* These are for quick feedback; the server applies the same limits and decides. */

export function validateHours({ opens_at: opens, closes_at: closes }) {
  return withoutPassing({
    opens_at: opens ? null : "Choose an opening time.",
    closes_at: !closes ? "Choose a closing time." : closes === opens ? "Opening and closing time cannot be the same." : null,
  });
}

export function validateDelivery(values) {
  const errors = {
    base_fee: rangeError(values.base_fee, 0, 10000),
    base_km: rangeError(values.base_km, 0, 100),
    extra_fee_per_km: rangeError(values.extra_fee_per_km, 0, 10000),
    max_driving_km: rangeError(values.max_driving_km, 1, 100),
  };

  if (!errors.base_km && !errors.max_driving_km && Number(values.max_driving_km) < Number(values.base_km)) {
    errors.max_driving_km = "Cannot be shorter than the distance the base fee covers.";
  }

  return withoutPassing(errors);
}

export function validateDiscount({ percentage }, minimum) {
  const number = Number(percentage);

  if (String(percentage).trim() !== "" && Number.isFinite(number) && number < minimum) {
    return { percentage: `Cannot go below the statutory ${minimum}%.` };
  }

  return withoutPassing({ percentage: rangeError(percentage, minimum, 100) });
}

export const toNumbers = (values) => Object.fromEntries(Object.entries(values).map(([key, value]) => [key, Number(value)]));
