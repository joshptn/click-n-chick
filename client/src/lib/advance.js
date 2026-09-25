import { api } from "./api";

export function fetchAdvanceQuote(payload, options) {
  return api.post("/api/advance-orders/quote", payload, options);
}

export function submitAdvanceOrder(payload, options) {
  return api.post("/api/advance-orders", payload, options);
}

export function toScheduleIso(date, time) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date ?? "") || !/^\d{2}:\d{2}$/.test(time ?? "")) {
    return null;
  }

  const [year, month, day] = date.split("-").map(Number);
  const [hours, minutes] = time.split(":").map(Number);

  const when = new Date(year, month - 1, day, hours, minutes, 0, 0);

  return Number.isNaN(when.getTime()) ? null : when.toISOString();
}

export function formatCollection(iso) {
  if (!iso) return null;

  const when = new Date(iso);

  if (Number.isNaN(when.getTime())) return null;

  return when.toLocaleString("en-PH", {
    weekday: "long",
    day: "numeric",
    month: "long",
    hour: "numeric",
    minute: "2-digit",
  });
}

export function formatCollectionDate(iso) {
  if (!iso) return null;

  const when = new Date(iso);

  if (Number.isNaN(when.getTime())) return null;

  return when.toLocaleDateString("en-PH", { weekday: "long", day: "numeric", month: "long" });
}

export function daysFromToday(date) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date ?? "")) return null;

  const [year, month, day] = date.split("-").map(Number);
  const target = new Date(year, month - 1, day);
  const today = new Date();

  target.setHours(0, 0, 0, 0);
  today.setHours(0, 0, 0, 0);

  return Math.round((target - today) / 86400000);
}
