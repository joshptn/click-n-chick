/**
 * The bookable times of one day, for the dial picker (decided 2026-11-20, as
 * on the web): every 5 minutes from `min` to `max` ("HH:mm", inclusive).
 * Anything outside is greyed out and cannot be picked; the API still checks
 * the result.
 */
export const TIME_STEP_MINUTES = 5;

const LAST_MINUTE = 23 * 60 + 59;

export type TimeWindow = { first: number; last: number; step: number };

export function toMinutes(value: string | null | undefined): number | null {
  const match = /^(\d{2}):(\d{2})$/.exec(value ?? "");

  return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

export function fromMinutes(total: number): string {
  return `${String(Math.floor(total / 60)).padStart(2, "0")}:${String(total % 60).padStart(2, "0")}`;
}

/** "HH:mm" in the phone's time zone, from an ISO instant. */
export function isoToClock(iso: string | null | undefined): string | null {
  if (!iso) return null;

  const at = new Date(iso);

  if (Number.isNaN(at.getTime())) return null;

  return `${String(at.getHours()).padStart(2, "0")}:${String(at.getMinutes()).padStart(2, "0")}`;
}

/** The ISO instant of "HH:mm" on the same (phone-local) day as `dayIso`. */
export function clockOnDay(dayIso: string, clock: string): string | null {
  const total = toMinutes(clock);
  const day = new Date(dayIso);

  if (total === null || Number.isNaN(day.getTime())) return null;

  day.setHours(Math.floor(total / 60), total % 60, 0, 0);

  return day.toISOString();
}

/**
 * The window in whole steps, or null when nothing is bookable. A closing time
 * past midnight (max before min) ends the day's window at its last step
 * before midnight.
 */
export function timeWindow(min: string | null | undefined, max: string | null | undefined, step = TIME_STEP_MINUTES): TimeWindow | null {
  const from = toMinutes(min);
  let to = toMinutes(max);

  if (from === null || to === null) return null;
  if (to < from) to = LAST_MINUTE;

  const first = Math.ceil(from / step) * step;
  const last = Math.floor(to / step) * step;

  return first <= last ? { first, last, step } : null;
}

export function isAllowed(window: TimeWindow | null, total: number): boolean {
  return Boolean(window) && total % window!.step === 0 && total >= window!.first && total <= window!.last;
}

/** Whether any minute of this hour (0-23) can be picked. */
export function hourAllowed(window: TimeWindow | null, hour: number): boolean {
  return Boolean(window) && hour * 60 + 59 >= window!.first && hour * 60 <= window!.last;
}

/** Whether any hour of the morning (false) or afternoon (true) can be picked. */
export function periodAllowed(window: TimeWindow | null, pm: boolean): boolean {
  for (let hour = pm ? 12 : 0; hour < (pm ? 24 : 12); hour += 1) {
    if (hourAllowed(window, hour)) return true;
  }

  return false;
}

/** The first bookable minute of this hour, or null. */
export function firstMinuteOf(window: TimeWindow, hour: number): number | null {
  for (let minute = 0; minute < 60; minute += window.step) {
    if (isAllowed(window, hour * 60 + minute)) return minute;
  }

  return null;
}

/** The first bookable hour of the morning or afternoon, or null. */
export function firstHourOf(window: TimeWindow, pm: boolean): number | null {
  for (let hour = pm ? 12 : 0; hour < (pm ? 24 : 12); hour += 1) {
    if (hourAllowed(window, hour)) return hour;
  }

  return null;
}

/** "1:30 PM" from "13:30". */
export function formatClockValue(value: string | null | undefined): string {
  const total = toMinutes(value);

  if (total === null) return "";

  const hours = Math.floor(total / 60);

  return `${hours % 12 === 0 ? 12 : hours % 12}:${String(total % 60).padStart(2, "0")} ${hours >= 12 ? "PM" : "AM"}`;
}

const SHORT_DAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
const SHORT_MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

/** A local date from "YYYY-MM-DD", or null. */
export function parseDateValue(value: string | null | undefined): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value ?? "");

  return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null;
}

/** "YYYY-MM-DD" from a local date. */
export function formatDateValue(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

/** "Mon, Aug 17" from "YYYY-MM-DD". */
export function formatCalendarDate(value: string | null | undefined): string {
  const date = parseDateValue(value);

  return date ? `${SHORT_DAYS[date.getDay()]}, ${SHORT_MONTHS[date.getMonth()]} ${date.getDate()}` : "";
}
