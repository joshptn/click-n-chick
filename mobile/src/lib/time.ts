/**
 * Times for orders and notifications, in the phone's time zone, formatted by
 * hand so every Android version shows the same thing.
 */

const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

const DAY_MS = 24 * 60 * 60 * 1000;

function startOfDay(date: Date) {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
}

/** "3:05 PM". */
export function formatTime(iso: string | null | undefined): string {
  if (!iso) return "";

  const date = new Date(iso);
  const hours = date.getHours();
  const minutes = String(date.getMinutes()).padStart(2, "0");

  return `${hours % 12 === 0 ? 12 : hours % 12}:${minutes} ${hours >= 12 ? "PM" : "AM"}`;
}

/** "Today", "Yesterday", or "November 14, 2026". */
export function formatDay(iso: string | null | undefined): string {
  if (!iso) return "";

  const date = new Date(iso);
  const days = Math.round((startOfDay(new Date()) - startOfDay(date)) / DAY_MS);

  if (days === 0) return "Today";
  if (days === 1) return "Yesterday";

  return `${MONTHS[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()}`;
}

/** "Just now", "8 min ago", "3 h ago", then the day. */
export function timeAgo(iso: string | null | undefined, now = Date.now()): string {
  if (!iso) return "";

  const seconds = Math.max(0, Math.round((now - Date.parse(iso)) / 1000));

  if (seconds < 60) return "Just now";
  if (seconds < 3600) return `${Math.floor(seconds / 60)} min ago`;
  if (seconds < 6 * 3600) return `${Math.floor(seconds / 3600)} h ago`;

  const day = formatDay(iso);

  return day === "Today" ? formatTime(iso) : `${day}, ${formatTime(iso)}`;
}
