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

/** The dial picker's step (decided 2026-11-20). */
const SLOT_MINUTES = 5;

const LAST_MINUTE_OF_DAY = 23 * 60 + 59;

function toMinutes(value: string | undefined) {
  const match = /^(\d{2}):(\d{2})$/.exec(value ?? "");
  return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

/**
 * The last collection time the app offers, "HH:mm". When the store closes
 * after midnight (closes_at "01:44" with opens_at "07:00"), the API still
 * checks a collection against the chosen day's own opening, so a time after
 * midnight would be refused: the day's times end at 23:55 instead.
 */
export function lastCollection(schedule: AdvanceSchedule | null | undefined): string | null {
  const opens = toMinutes(schedule?.opens_at);
  const closes = toMinutes(schedule?.closes_at);
  if (opens === null || closes === null) return null;
  if (closes > opens) return schedule?.closes_at ?? null;

  const last = Math.floor(LAST_MINUTE_OF_DAY / SLOT_MINUTES) * SLOT_MINUTES;
  return `${pad(Math.floor(last / 60))}:${pad(last % 60)}`;
}

/**
 * The chosen day and time in store time, with no offset ("2026-11-21T14:00:00").
 * The API reads it in the store's time zone, so a phone set to another zone
 * still books the time it showed (fixed 2026-11-20: a phone on UTC turned
 * 2:00 PM into 10:00 PM store time, past closing).
 */
export function toScheduleIso(date: string | null, time: string | null): string | null {
  if (!date || !parseDate(date) || !/^\d{2}:\d{2}$/.test(time ?? "")) return null;

  return `${date}T${time}:00`;
}

const WEEKDAYS_LONG = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
const MONTHS_LONG = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

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
