import {
  IconAlertTriangle,
  IconCalendarEvent,
  IconChevronRight,
  IconClock,
  IconInfoCircle,
  IconUser,
} from "@tabler/icons-react";

import { useState } from "react";

import Button from "../ui/Button";
import CalendarPicker from "../ui/CalendarPicker";
import PickerField from "../ui/PickerField";
import TimeDialPicker from "../ui/TimeDialPicker";
import { daysFromToday } from "../../lib/advance";
import { formatCalendarDate, formatClock, toMinutes } from "../../lib/timeWindow";

const SCHEDULE_CODES = [
  "SCHEDULE_REQUIRED",
  "SCHEDULE_INVALID",
  "SCHEDULE_TOO_SOON",
  "SCHEDULE_TOO_FAR",
  "SCHEDULE_BEFORE_OPENING",
  "SCHEDULE_AFTER_CLOSING",
];

const CONTACT_CODES = ["CONTACT_NAME_REQUIRED", "CONTACT_PHONE_INVALID"];

function Field({ label, htmlFor, hint, children }) {
  return (
    <div className="min-w-0 flex-1">
      <label
        htmlFor={htmlFor}
        className="mb-1.5 block font-display text-[12.5px] font-bold text-ink"
      >
        {label}
      </label>

      {children}

      {hint && <p className="m-0 mt-1.5 font-display text-[11.5px] text-[#a39f9b]">{hint}</p>}
    </div>
  );
}

/**
 * Choosing when to collect.
 *
 * The window is server-computed (BR-16a, BR-16a-1, BR-16b) and arrives on the
 * quote, so the min/max here are the same numbers the submission is checked
 * against rather than a second copy of the rules.
 */
function ScheduleStep({
  value,
  onChange,
  quote,
  isQuoting,
  showErrors,
  onContinue,
  onBack,
}) {
  const schedule = quote?.schedule ?? null;
  const contact = quote?.contact ?? null;
  const blockers = quote?.blockers ?? [];

  const scheduleError = showErrors
    ? blockers.find((blocker) => SCHEDULE_CODES.includes(blocker.code))
    : null;

  const contactError = blockers.find((blocker) => CONTACT_CODES.includes(blocker.code));

  const lead = daysFromToday(value.date);

  // Decided 2026-11-20: our own calendar and clock dial, which only offer
  // what the window allows (the browser's boxes accepted anything typed).
  const [openPicker, setOpenPicker] = useState(null);
  // The day's last collection; a closing time past midnight ends the list
  // before midnight, since a collection is checked against its own day.
  const lastTime =
    schedule && toMinutes(schedule.closes_at) > toMinutes(schedule.opens_at) ? schedule.closes_at : "23:55";

  return (
    <div className="flex flex-col gap-5">
      <p className="m-0 flex items-start gap-2 rounded-[12px] bg-[#faf7f3] px-3.5 py-3 font-display text-[12.5px] leading-snug text-[#6f6b68]">
        <IconInfoCircle size={15} stroke={2} aria-hidden="true" className="mt-px shrink-0 text-brand-500" />
        Your request goes to the store first. Once a Store Agent accepts it you will be asked to pay,
        and nothing is charged before then.
      </p>

      <div className="flex flex-col gap-4 sm:flex-row">
        <Field
          label="Collection date"
          htmlFor="advance-date"
          hint={
            schedule
              ? `${formatCalendarDate(schedule.earliest_date)} to ${formatCalendarDate(schedule.latest_date)}`
              : "Tomorrow onwards"
          }
        >
          <PickerField
            id="advance-date"
            icon={IconCalendarEvent}
            value={value.date ? formatCalendarDate(value.date) : ""}
            placeholder="Choose a date"
            onOpen={() => setOpenPicker("date")}
            invalid={Boolean(scheduleError)}
            disabled={!schedule}
            ariaLabel={value.date ? `Collection date ${formatCalendarDate(value.date)}. Change` : "Choose a collection date"}
          />
        </Field>

        <Field
          label="Collection time"
          htmlFor="advance-time"
          hint={schedule ? `From ${formatClock(schedule.opens_at)} to ${formatClock(lastTime)}` : null}
        >
          <PickerField
            id="advance-time"
            icon={IconClock}
            value={value.time ? formatClock(value.time) : ""}
            placeholder="Choose a time"
            onOpen={() => setOpenPicker("time")}
            invalid={Boolean(scheduleError)}
            disabled={!schedule}
            ariaLabel={value.time ? `Collection time ${formatClock(value.time)}. Change` : "Choose a collection time"}
          />
        </Field>
      </div>

      {scheduleError && (
        <p className="m-0 flex items-start gap-2 font-display text-[12.5px] font-semibold leading-snug text-[#e5322d]">
          <IconAlertTriangle size={15} stroke={2.2} aria-hidden="true" className="mt-px shrink-0" />
          {scheduleError.message}
        </p>
      )}

      {!scheduleError && lead !== null && lead > 0 && (
        <p className="m-0 font-display text-[12px] text-[#8d8884]">
          That is {lead === 1 ? "tomorrow" : `in ${lead} days`}.
        </p>
      )}

      {schedule?.tomorrow_closed && (
        <p className="m-0 flex items-start gap-2 rounded-[12px] bg-[#fff9e8] px-3.5 py-3 font-display text-[12px] leading-snug text-[#8a6206]">
          <IconAlertTriangle size={14} stroke={2.2} aria-hidden="true" className="mt-px shrink-0" />
          Ordering for tomorrow closed when the store did, so the earliest we can take is the day
          after.
        </p>
      )}

      <div className="rounded-[12px] border border-[#f0e9df] bg-[#faf7f3] px-4 py-3.5">
        <p className="m-0 mb-2 font-display text-[11px] font-bold uppercase tracking-[0.7px] text-[#a39f9b]">
          Collected by
        </p>

        <p className="m-0 flex items-center gap-2 font-display text-[13px] text-ink">
          <IconUser size={15} stroke={2} aria-hidden="true" className="[overflow-wrap:anywhere] shrink-0 text-brand-500" />
          {contact?.name || "—"}
          {contact?.phone && <span className="text-[#8d8884]">· {contact.phone}</span>}
        </p>

        <p className="m-0 mt-2 font-display text-[11.5px] leading-snug text-[#a39f9b]">
          Taken from your account. Change it on your profile and every open order updates with it.
        </p>

        {contactError && (
          <p className="m-0 mt-2 font-display text-[12px] font-semibold text-[#e5322d]">
            {contactError.message}
          </p>
        )}
      </div>

      <div className="flex flex-col gap-2.5 sm:flex-row sm:items-center">
        <Button
          variant="ghost"
          size="lg"
          onClick={onBack}
          className="border border-[#ece7e0] text-[#6f6b68] sm:w-[210px]"
        >
          Back to menu
        </Button>

        <Button
          size="lg"
          fullWidth
          onClick={onContinue}
          loading={isQuoting}
          loadingLabel="Checking&hellip;"
        >
          Continue
          <IconChevronRight size={16} stroke={2.6} aria-hidden="true" />
        </Button>
      </div>

      <CalendarPicker
        opened={openPicker === "date"}
        onClose={() => setOpenPicker(null)}
        value={value.date}
        min={schedule?.earliest_date}
        max={schedule?.latest_date}
        earlyMessage={(first) =>
          schedule?.tomorrow_closed
            ? `Orders for tomorrow closed when the store did today, so the earliest date is ${first}.`
            : `Advance orders start from tomorrow. The earliest date is ${first}.`
        }
        lateMessage={(last) => `You can book up to ${schedule?.max_days_ahead ?? 30} days ahead, until ${last}.`}
        onConfirm={(date) => {
          onChange({ date });
          setOpenPicker(null);
        }}
      />

      <TimeDialPicker
        opened={openPicker === "time"}
        onClose={() => setOpenPicker(null)}
        value={value.time}
        min={schedule?.opens_at}
        max={lastTime}
        title="Select collection time"
        earlyMessage={(first) => `The store opens at ${first}, so that is the earliest collection.`}
        lateMessage={(last) => `The last collection is ${last}, shortly before the store closes.`}
        onConfirm={(time) => {
          onChange({ time });
          setOpenPicker(null);
        }}
      />
    </div>
  );
}

export default ScheduleStep;
