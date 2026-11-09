import { api } from "./api";
import { ROLES } from "./roles";

export const USERS_KEY = ["managed-users"];

export const usersKey = (params) => [...USERS_KEY, "list", params];

export const userKey = (id) => [...USERS_KEY, "detail", String(id)];

export const USERS_PER_PAGE = 20;

export function fetchUsers(params, { signal } = {}) {
  return api.get("/api/admin/users", { signal, params });
}

export function fetchUser(id, { signal } = {}) {
  return api.get(`/api/admin/users/${id}`, { signal });
}

/** `password` below is always the Manager's own step-up, sent only once asked for. */
const withStepUp = (body, password) => (password ? { ...body, password } : body);

export const createUser = (values, password) => api.post("/api/admin/users", withStepUp(values, password));

export const renameUser = (id, values) => api.patch(`/api/admin/users/${id}/profile`, values);

export const changeUserRole = (id, role, password) => api.patch(`/api/admin/users/${id}`, withStepUp({ role }, password));

export const suspendUser = (id, password) => api.post(`/api/admin/users/${id}/suspend`, withStepUp({}, password));

export const reactivateUser = (id, password) => api.post(`/api/admin/users/${id}/reactivate`, withStepUp({}, password));

export const deleteUser = (id, password) => api.delete(`/api/admin/users/${id}`, { body: withStepUp({}, password) });

export const ROLE_LABELS = {
  [ROLES.SUPER_ADMIN]: "Store Manager",
  [ROLES.ADMIN]: "Store Agent",
  [ROLES.CUSTOMER]: "Customer",
};

export const ROLE_OPTIONS = [ROLES.CUSTOMER, ROLES.ADMIN, ROLES.SUPER_ADMIN].map((key) => ({ key, label: ROLE_LABELS[key] }));

export const ROLE_PILL = {
  [ROLES.SUPER_ADMIN]: "bg-[#eef4ff] text-[#2563eb] ring-[#c9dafd]",
  [ROLES.ADMIN]: "bg-[#fff6ec] text-brand-600 ring-[#ffd6b3]",
  [ROLES.CUSTOMER]: "bg-[#f4f1ec] text-[#5f5b58] ring-[#e4ddd3]",
};

export const STATUS_META = {
  active: { label: "Active", pill: "bg-[#ecf9f0] text-[#2f9e44] ring-[#bfe8cb]" },
  suspended: { label: "Suspended", pill: "bg-[#fdecec] text-[#e5322d] ring-[#f8c9c7]" },
  unverified: { label: "Unverified", pill: "bg-[#fff8e1] text-[#c98500] ring-[#ffe59a]" },
};

export const DISCOUNT_LABELS = { senior: "Senior", senior_citizen: "Senior", pwd: "PWD" };

export const discountLabel = (type) => DISCOUNT_LABELS[String(type).toLowerCase()] ?? type;

export const STATUS_FILTERS = [
  { key: "all", label: "Any status" },
  { key: "active", label: "Active" },
  { key: "suspended", label: "Suspended" },
  { key: "unverified", label: "Unverified" },
];

export const USER_DEFAULTS = { q: "", role: "all", status: "all", discount: "all", page: "1" };

export function readUserFilters(searchParams) {
  const pick = (key, options) => {
    const value = searchParams.get(key);

    return options.includes(value) ? value : USER_DEFAULTS[key];
  };
  const page = Number.parseInt(searchParams.get("page") ?? "1", 10);

  return {
    q: searchParams.get("q") ?? "",
    role: pick("role", ["all", ...Object.values(ROLES)]),
    status: pick("status", STATUS_FILTERS.map((option) => option.key)),
    discount: pick("discount", ["all", "eligible"]),
    page: String(Number.isFinite(page) && page > 0 ? page : 1),
  };
}

export function userQueryParams(filters) {
  const params = {
    search: filters.q.trim() || undefined,
    role: filters.role !== "all" ? filters.role : undefined,
    status: filters.status !== "all" ? filters.status : undefined,
    discount: filters.discount !== "all" ? filters.discount : undefined,
    page: filters.page !== "1" ? filters.page : undefined,
    per_page: USERS_PER_PAGE,
  };

  return Object.fromEntries(Object.entries(params).filter(([, value]) => value !== undefined));
}
