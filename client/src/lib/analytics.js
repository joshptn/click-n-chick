import { api } from "./api";
import { STORE_TIMEZONE, fetchRecords, storeDate } from "./records";

/**
 * The Analytics Dashboard's data.
 *
 * Deliberately not keyed under "orders": every admin.orders broadcast refetches
 * that whole tree, and a busy counter sends several a minute. These keys are
 * refreshed through analyticsRefresher below instead.
 */
export const OPERATIONS_KEY = ["analytics", "operations"];
export const SALES_ANALYTICS_KEY = ["analytics", "sales"];
export const SALES_LOG_KEY = ["analytics", "log"];

export const salesLogKey = (params) => [...SALES_LOG_KEY, params];

export const fetchOperations = ({ signal } = {}) => api.get("/api/admin/reports/operations", { signal });

export const fetchSalesAnalytics = ({ signal } = {}) => api.get("/api/admin/reports/analytics", { signal });

const SALES_LOG_ROWS = 5;

/** Today's completed orders, from Order Records. */
export function fetchSalesLog({ date, search }, { signal } = {}) {
  return fetchRecords({ from: date, to: date, outcome: "completed", search: search || undefined, per_page: SALES_LOG_ROWS }, { signal });
}

const REFRESH_WINDOW_MS = 2000;

/** Only an order ending changes a sale or the log. */
const CLOSING_STATUSES = new Set(["completed", "delivered", "cancelled", "rejected"]);

/**
 * Turns a burst of broadcasts into one refresh.
 *
 * The first event opens a short window and anything else in it joins that
 * refresh, so a rush costs one request per window however many orders move.
 * The cards follow every event; the sales only an order closing. With no
 * dashboard open, invalidating fetches nothing. In a hidden tab it only marks
 * the data stale, and returning to the tab fetches it once.
 */
export function analyticsRefresher(queryClient) {
  let timer = null;
  let closing = false;

  const flush = () => {
    const refetchType = document.hidden ? "none" : "active";

    timer = null;
    queryClient.invalidateQueries({ queryKey: OPERATIONS_KEY, refetchType });

    if (closing) {
      queryClient.invalidateQueries({ queryKey: SALES_ANALYTICS_KEY, refetchType });
      queryClient.invalidateQueries({ queryKey: SALES_LOG_KEY, refetchType });
    }

    closing = false;
  };

  return {
    order(status) {
      if (CLOSING_STATUSES.has(status)) closing = true;

      timer ??= window.setTimeout(flush, REFRESH_WINDOW_MS);
    },
    cancel() {
      window.clearTimeout(timer);
      timer = null;
      closing = false;
    },
  };
}

export const CHART_VIEWS = [
  { key: "weekly", label: "Weekly" },
  { key: "daily", label: "Daily" },
  { key: "hourly", label: "Hourly" },
];

const dayFormat = (options) => new Intl.DateTimeFormat("en-PH", { timeZone: STORE_TIMEZONE, ...options });
const WEEKDAY = dayFormat({ weekday: "short" });
const LONG_DAY = dayFormat({ weekday: "short", month: "short", day: "numeric" });
const FULL_WEEKDAY = dayFormat({ weekday: "long" });
const FULL_DATE = dayFormat({ month: "short", day: "numeric", year: "numeric" });
const STORE_HOUR = new Intl.DateTimeFormat("en-GB", { timeZone: STORE_TIMEZONE, hour: "2-digit", hourCycle: "h23" });

const noonOf = (date) => new Date(`${date}T12:00:00+08:00`);
const weekday = (date) => WEEKDAY.format(noonOf(date));
const longDay = (date) => LONG_DAY.format(noonOf(date));

function formatHour(hour) {
  const twelve = hour % 12 === 0 ? 12 : hour % 12;

  return `${twelve}${hour < 12 ? "AM" : "PM"}`;
}

const clockTime = (hour, minute) => `${hour % 12 === 0 ? 12 : hour % 12}:${String(minute).padStart(2, "0")}`;

/** The hour the Hourly tab opens on: now, if the store is trading, else the day's last sale. */
function defaultHour(day) {
  const current = day.key === storeDate() ? day.hours.find((hour) => hour.key === Number(STORE_HOUR.format(new Date()))) : undefined;

  return current ?? day.hours.findLast((hour) => hour.orders > 0) ?? day.hours[0];
}

/**
 * What the chart shows for a selection: the week by day, one day by hour, or
 * one hour by quarter hour. Each point says where clicking it leads, and the
 * day and hour views say which selections sit either side of them. A day that
 * has rolled out of the week falls back to today.
 */
export function chartState(sales, { view, date, hour }) {
  const day = sales.days.find((entry) => entry.key === date) ?? sales.days.at(-1);

  if (view === "weekly") {
    return {
      view,
      date: day.key,
      hour,
      title: "Weekly Sales",
      subtitle: "Last 7 days",
      drillHint: "Click to see by hour",
      previous: null,
      following: null,
      points: sales.days.map((entry) => ({
        ...entry,
        id: entry.key,
        axis: weekday(entry.key),
        title: longDay(entry.key),
        next: { view: "daily", date: entry.key, hour: null },
      })),
    };
  }

  if (view === "daily") {
    const index = sales.days.indexOf(day);
    const dayAt = (at) => (sales.days[at] ? { view, date: sales.days[at].key, hour: null } : null);

    return {
      view,
      date: day.key,
      hour,
      title: FULL_WEEKDAY.format(noonOf(day.key)),
      subtitle: FULL_DATE.format(noonOf(day.key)),
      drillHint: "Click to see every 15 minutes",
      previous: dayAt(index - 1),
      following: dayAt(index + 1),
      points: day.hours.map((entry) => ({
        ...entry,
        id: String(entry.key),
        axis: formatHour(entry.key),
        title: `${weekday(day.key)}, ${formatHour(entry.key)}`,
        next: { view: "hourly", date: day.key, hour: entry.key },
      })),
    };
  }

  const selected = day.hours.find((entry) => entry.key === hour) ?? defaultHour(day);
  const index = day.hours.indexOf(selected);
  const hourAt = (at) => (day.hours[at] ? { view, date: day.key, hour: day.hours[at].key } : null);

  return {
    view,
    date: day.key,
    hour: selected.key,
    title: `${weekday(day.key)}, ${formatHour(selected.key)}`,
    subtitle: FULL_DATE.format(noonOf(day.key)),
    drillHint: null,
    previous: hourAt(index - 1),
    following: hourAt(index + 1),
    points: selected.quarters.map((entry) => ({
      ...entry,
      id: String(entry.key),
      axis: clockTime(selected.key, entry.key),
      title: `${clockTime(selected.key, entry.key)} to ${entry.key === 45 ? clockTime((selected.key + 1) % 24, 0) : clockTime(selected.key, entry.key + 15)}`,
      next: null,
    })),
  };
}

/** Where a sales log row opens: its day of the Daily Sales Report. */
export function reportLinkFor(record) {
  return `/superadmin/reports/daily?${new URLSearchParams({ date: storeDate(0, new Date(record.closed_at)), order: String(record.id) })}`;
}
