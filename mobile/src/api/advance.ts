import { api } from "./client";
import type { Blocker, QuoteDiscount, QuoteLine } from "./checkout";

/**
 * Advance orders (phase 9), as the web uses them (client/src/lib/advance.js):
 * a request for a collection date from tomorrow up to 30 days ahead, sent
 * from the account's advance cart. The store accepts it first; it is paid
 * after that, from the order's page. Accounts only.
 */

export type AdvanceSchedule = {
  /** "2026-11-20" */
  earliest_date: string;
  latest_date: string;
  max_days_ahead: number;
  /** "07:00": the first collection time of a day. */
  opens_at: string;
  /** "19:45": the last one. */
  closes_at: string;
  timezone: string;
  /** Ordering for tomorrow closed with the store, so the earliest is the day after. */
  tomorrow_closed: boolean;
  requested_at: string | null;
};

export type AdvanceQuote = {
  fulfilment_type: "pickup";
  schedule: AdvanceSchedule;
  items: QuoteLine[];
  item_count: number;
  subtotal: number;
  contact: { name: string; phone: string };
  /** `used_today` here means used on the chosen date. */
  discount: QuoteDiscount;
  total: number;
  blockers: Blocker[];
  can_submit: boolean;
};

export type AdvanceInput = {
  cart_item_ids: number[];
  scheduled_for: string | null;
  apply_discount: boolean;
};

export type AdvanceSent = {
  message?: string;
  order: { id: number; status: string; status_label: string; scheduled_for: string | null; total_amount: number; item_count: number };
};

export const advanceApi = {
  quote: (input: AdvanceInput, signal?: AbortSignal) => api.post<AdvanceQuote>("/api/advance-orders/quote", input, { signal }),
  submit: (input: AdvanceInput) => api.post<AdvanceSent>("/api/advance-orders", input),
};

export const SCHEDULE_BLOCKERS = [
  "SCHEDULE_REQUIRED",
  "SCHEDULE_INVALID",
  "SCHEDULE_TOO_SOON",
  "SCHEDULE_TOO_FAR",
  "SCHEDULE_BEFORE_OPENING",
  "SCHEDULE_AFTER_CLOSING",
];
export const CONTACT_BLOCKERS = ["CONTACT_NAME_REQUIRED", "CONTACT_PHONE_INVALID"];

function parseDate(date: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);
  return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null;
}

function pad(value: number) {
  return String(value).padStart(2, "0");
}

/** Every bookable day, "YYYY-MM-DD", from the API's earliest to its latest. */
export function bookableDates(schedule: AdvanceSchedule | null | undefined): string[] {
  const start = schedule ? parseDate(schedule.earliest_date) : null;
  const end = schedule ? parseDate(schedule.latest_date) : null;
  if (!start || !end) return [];

  const dates: string[] = [];
  for (const day = new Date(start); day <= end && dates.length < 62; day.setDate(day.getDate() + 1)) {
    dates.push(`${day.getFullYear()}-${pad(day.getMonth() + 1)}-${pad(day.getDate())}`);
  }

  return dates;
}

const SLOT_MINUTES = 15;

const LAST_MINUTE_OF_DAY = 23 * 60 + 59;

function toMinutes(value: string | undefined) {
  const match = /^(\d{2}):(\d{2})$/.exec(value ?? "");
  return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

/**
 * The last collection time the app offers, "HH:mm". When the store closes
 * after midnight (closes_at "01:44" with opens_at "07:00"), the API still
 * checks a collection against the chosen day's own opening, so a time after
 * midnight would be refused: the day's list ends at 23:45 instead.
 */
export function lastCollection(schedule: AdvanceSchedule | null | undefined): string | null {
  const opens = toMinutes(schedule?.opens_at);
  const closes = toMinutes(schedule?.closes_at);
  if (opens === null || closes === null) return null;
  if (closes > opens) return schedule?.closes_at ?? null;

  const last = Math.floor(LAST_MINUTE_OF_DAY / SLOT_MINUTES) * SLOT_MINUTES;
  return `${pad(Math.floor(last / 60))}:${pad(last % 60)}`;
}

/** Collection times every 15 minutes, "HH:mm", from opening to the last collection. */
export function collectionTimes(schedule: AdvanceSchedule | null | undefined): string[] {
  const opens = toMinutes(schedule?.opens_at);
  const closes = toMinutes(lastCollection(schedule) ?? undefined);
  if (opens === null || closes === null) return [];

  const times: string[] = [];
  for (let at = Math.ceil(opens / SLOT_MINUTES) * SLOT_MINUTES; at <= closes; at += SLOT_MINUTES) {
    times.push(`${pad(Math.floor(at / 60))}:${pad(at % 60)}`);
  }

  return times;
}

/** The chosen day and time as the ISO instant the API reads (the phone's time zone, as on the web). */
export function toScheduleIso(date: string | null, time: string | null): string | null {
  const day = date ? parseDate(date) : null;
  const match = /^(\d{2}):(\d{2})$/.exec(time ?? "");
  if (!day || !match) return null;

  day.setHours(Number(match[1]), Number(match[2]), 0, 0);
  return day.toISOString();
}

const WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
const WEEKDAYS_LONG = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
const MONTHS_LONG = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

/** { weekday: "Thu", day: "20", month: "Nov" } for a date chip. */
export function dateParts(date: string) {
  const day = parseDate(date);
  if (!day) return { weekday: "", day: "", month: "" };
  return { weekday: WEEKDAYS[day.getDay()], day: String(day.getDate()), month: MONTHS[day.getMonth()] };
}

/** "Thursday, November 20" */
export function formatCollectionDay(isoOrDate: string | null | undefined): string {
  if (!isoOrDate) return "";
  const day = /^\d{4}-\d{2}-\d{2}$/.test(isoOrDate) ? parseDate(isoOrDate) : new Date(isoOrDate);
  if (!day || Number.isNaN(day.getTime())) return "";
  return `${WEEKDAYS_LONG[day.getDay()]}, ${MONTHS_LONG[day.getMonth()]} ${day.getDate()}`;
}

/** "9:30 AM" from "09:30". */
export function formatClock(time: string): string {
  const match = /^(\d{2}):(\d{2})$/.exec(time);
  if (!match) return time;
  const hours = Number(match[1]);
  return `${hours % 12 === 0 ? 12 : hours % 12}:${match[2]} ${hours >= 12 ? "PM" : "AM"}`;
}
