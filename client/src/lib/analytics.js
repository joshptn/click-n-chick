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
  { key: "weekly", label: "Weekly", title: "Weekly Sales", window: "Last 7 days" },
  { key: "daily", label: "Daily", title: "Daily Sales", window: "Last 30 days" },
  { key: "hourly", label: "Hourly", title: "Hourly Sales", window: "Today" },
];

const dayFormat = (options) => new Intl.DateTimeFormat("en-PH", { timeZone: STORE_TIMEZONE, ...options });
const WEEKDAY = dayFormat({ weekday: "short" });
const SHORT_DAY = dayFormat({ month: "short", day: "numeric" });
const LONG_DAY = dayFormat({ weekday: "short", month: "short", day: "numeric" });

const noonOf = (date) => new Date(`${date}T12:00:00+08:00`);

function formatHour(hour) {
  const twelve = hour % 12 === 0 ? 12 : hour % 12;

  return `${twelve} ${hour < 12 ? "AM" : "PM"}`;
}

/** One chart position per bucket, labelled for its view. */
export function chartPoints(sales, view) {
  return sales[view].map((bucket) => {
    if (view === "hourly") return { ...bucket, id: String(bucket.key), axis: formatHour(bucket.key), title: formatHour(bucket.key) };

    const date = noonOf(bucket.key);

    return { ...bucket, id: bucket.key, axis: view === "weekly" ? WEEKDAY.format(date) : SHORT_DAY.format(date), title: LONG_DAY.format(date) };
  });
}

/** Where a sales log row opens: its day of the Daily Sales Report. */
export function reportLinkFor(record) {
  return `/superadmin/reports/daily?${new URLSearchParams({ date: storeDate(0, new Date(record.closed_at)), order: String(record.id) })}`;
}
