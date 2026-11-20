import { useEffect, useState } from "react";
import { Modal } from "@mantine/core";
import { IconChevronLeft, IconChevronRight, IconInfoCircle } from "@tabler/icons-react";

import { formatCalendarDate, formatDateValue as format, parseDateValue as parse } from "../../lib/timeWindow";

const WEEKDAYS = ["S", "M", "T", "W", "T", "F", "S"];
const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
const monthIndex = (date) => date.getFullYear() * 12 + date.getMonth();

/**
 * The calendar picker (the user's design, 2026-11-20): the chosen date large
 * at the top, the month with arrows, and the day grid. Only days from `min`
 * to `max` ("YYYY-MM-DD") can be picked; the rest are greyed out and the
 * arrows stop at their months. Answers "YYYY-MM-DD".
 */
function CalendarPicker({
  opened,
  onClose,
  value,
  min,
  max,
  onConfirm,
  title = "Select date",
  // Tapping a greyed-out day says why (decided 2026-11-20).
  earlyMessage = (first) => `The earliest date you can choose is ${first}.`,
  lateMessage = (last) => `The latest date you can choose is ${last}.`,
}) {
  const first = parse(min);
  const last = parse(max);

  const [chosen, setChosen] = useState(null);
  const [notice, setNotice] = useState(null);
  const [view, setView] = useState(() => new Date());

  useEffect(() => {
    if (!opened) return;

    const current = parse(value);
    const start = current && (!first || current >= first) && (!last || current <= last) ? current : first ?? new Date();

    setChosen(current && start === current ? current : null);
    setNotice(null);
    setView(new Date(start.getFullYear(), start.getMonth(), 1));
    // Only on opening.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [opened]);

  const year = view.getFullYear();
  const month = view.getMonth();
  const offset = new Date(year, month, 1).getDay();
  const days = new Date(year, month + 1, 0).getDate();

  const canBack = !first || monthIndex(view) > monthIndex(first);
  const canForward = !last || monthIndex(view) < monthIndex(last);

  const cells = [...Array(offset).fill(null), ...Array.from({ length: days }, (_, index) => new Date(year, month, index + 1))];

  const allowed = (date) => (!first || date >= first) && (!last || date <= last);

  const arrow =
    "grid h-9 w-9 place-items-center rounded-full bg-transparent text-ink transition-colors hover:bg-[#fff1e3] disabled:cursor-not-allowed disabled:text-[#d6cfc6] disabled:hover:bg-transparent";

  return (
    <Modal opened={opened} onClose={onClose} centered radius="lg" size={360} withCloseButton={false} padding={0} data-testid="calendar">
      <div className="px-6 pt-5">
        <p className="m-0 font-display text-[12.5px] font-medium text-[#6f6b68]">{title}</p>
        <p className="m-0 mt-3 pb-3 font-display text-[30px] font-medium leading-tight text-ink">
          {chosen ? formatCalendarDate(format(chosen)) : "Choose a date"}
        </p>
      </div>

      <div className="border-t border-[#e9e2d9] px-4 pb-2 pt-3">
        <div className="flex items-center justify-between px-2">
          <p className="m-0 font-display text-[14px] font-semibold text-ink">
            {MONTHS[month]} {year}
          </p>
          <div className="flex items-center gap-1">
            <button type="button" disabled={!canBack} onClick={() => setView(new Date(year, month - 1, 1))} aria-label="Previous month" className={arrow}>
              <IconChevronLeft size={18} stroke={2.2} />
            </button>
            <button type="button" disabled={!canForward} onClick={() => setView(new Date(year, month + 1, 1))} aria-label="Next month" className={arrow}>
              <IconChevronRight size={18} stroke={2.2} />
            </button>
          </div>
        </div>

        <div className="mt-2 grid grid-cols-7 place-items-center gap-y-1">
          {WEEKDAYS.map((day, index) => (
            <span key={`${day}-${index}`} className="grid h-10 w-10 place-items-center font-display text-[13px] font-medium text-ink">
              {day}
            </span>
          ))}

          {cells.map((date, index) => {
            if (!date) return <span key={`blank-${index}`} className="h-10 w-10" />;

            const enabled = allowed(date);
            const selected = chosen && format(chosen) === format(date);

            return (
              <button
                key={format(date)}
                type="button"
                aria-disabled={!enabled}
                onClick={() => {
                  if (enabled) {
                    setNotice(null);
                    setChosen(date);
                  } else if (first && date < first) {
                    setNotice(earlyMessage(formatCalendarDate(min)));
                  } else if (last) {
                    setNotice(lateMessage(formatCalendarDate(max)));
                  }
                }}
                aria-pressed={Boolean(selected)}
                aria-label={formatCalendarDate(format(date))}
                className={`grid h-10 w-10 place-items-center rounded-full font-display transition-colors disabled:cursor-not-allowed ${
                  selected
                    ? "bg-brand-500 text-white"
                    : enabled
                      ? "bg-transparent text-ink hover:bg-[#fff1e3]"
                      : "cursor-not-allowed bg-transparent text-[#c4cbd3]"
                }`}
              >
                <span className="text-[14px]">{date.getDate()}</span>
              </button>
            );
          })}
        </div>
      </div>

      {notice && (
        <p
          role="status"
          className="m-0 mx-5 mb-1 flex items-start gap-2 rounded-[10px] bg-[#fff8e1] px-3 py-2.5 font-display text-[12.5px] leading-snug text-[#8a6206] ring-1 ring-inset ring-[#ffe6a8]"
          data-testid="calendar-notice"
        >
          <IconInfoCircle size={15} stroke={2} aria-hidden="true" className="mt-px shrink-0" />
          {notice}
        </p>
      )}

      <div className="flex justify-end gap-6 px-6 pb-5 pt-2">
        <button type="button" onClick={onClose} className="bg-transparent font-display font-semibold text-brand-600 hover:text-brand-800">
          <span className="text-[14px]">Cancel</span>
        </button>
        <button
          type="button"
          disabled={!chosen}
          onClick={() => onConfirm(format(chosen))}
          data-testid="calendar-ok"
          className="bg-transparent font-display font-semibold text-brand-600 hover:text-brand-800 disabled:cursor-not-allowed disabled:text-[#cfc8bf]"
        >
          <span className="text-[14px]">OK</span>
        </button>
      </div>
    </Modal>
  );
}

export default CalendarPicker;
