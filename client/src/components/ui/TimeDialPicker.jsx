import { useEffect, useState } from "react";
import { Modal } from "@mantine/core";
import { IconInfoCircle } from "@tabler/icons-react";

import {
  firstHourOf,
  firstMinuteOf,
  formatClock,
  fromMinutes,
  hourAllowed,
  isAllowed,
  periodAllowed,
  timeWindow,
  toMinutes,
} from "../../lib/timeWindow";

const DIAL = 256;
const CENTER = DIAL / 2;
const RADIUS = 100;
const HOURS = [12, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];
const MINUTES = [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55];

function spot(index) {
  const angle = (index * 30 * Math.PI) / 180;

  return { left: CENTER + RADIUS * Math.sin(angle), top: CENTER - RADIUS * Math.cos(angle) };
}

const pad = (value) => String(value).padStart(2, "0");

/**
 * The clock-dial time picker (decided 2026-11-20): hour first, then minutes in
 * 5-minute steps, with AM/PM beside them. Only times between `min` and `max`
 * ("HH:mm") can be picked; the rest are greyed out. Answers "HH:mm".
 *
 * Tapping a greyed-out hour, minute or AM/PM says why instead of doing nothing
 * (decided 2026-11-20): `earlyMessage` for one before the window, `lateMessage`
 * for one after it, each given the first or last bookable time.
 */
function TimeDialPicker({
  opened,
  onClose,
  value,
  min,
  max,
  onConfirm,
  title = "Select time",
  earlyMessage = (first) => `The earliest time you can choose is ${first}.`,
  lateMessage = (last) => `The last time you can choose is ${last}.`,
}) {
  const range = timeWindow(min, max);
  const [notice, setNotice] = useState(null);

  // Why a greyed-out time cannot be picked: before the window, or after it.
  const explain = (minutes) =>
    setNotice(
      minutes < range.first
        ? earlyMessage(formatClock(fromMinutes(range.first)))
        : lateMessage(formatClock(fromMinutes(range.last)))
    );

  const [hour, setHour] = useState(0);
  const [minute, setMinute] = useState(0);
  const [mode, setMode] = useState("hour");

  useEffect(() => {
    if (!opened || !range) return;

    const current = toMinutes(value);
    const start = current !== null && isAllowed(range, current) ? current : range.first;

    setHour(Math.floor(start / 60));
    setMinute(start % 60);
    setMode("hour");
    setNotice(null);
    // Only on opening: the range object is rebuilt every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [opened]);

  const pm = hour >= 12;
  const hour12 = hour % 12 === 0 ? 12 : hour % 12;
  const chosen = hour * 60 + minute;

  const pickHour = (label) => {
    const next = (label % 12) + (pm ? 12 : 0);

    if (!hourAllowed(range, next)) {
      explain(next * 60 + 59 < range.first ? next * 60 : next * 60 + 59);

      return;
    }

    setNotice(null);
    setHour(next);
    if (!isAllowed(range, next * 60 + minute)) setMinute(firstMinuteOf(range, next) ?? 0);
    setMode("minute");
  };

  const pickMinute = (next) => {
    if (!isAllowed(range, hour * 60 + next)) {
      explain(hour * 60 + next);

      return;
    }

    setNotice(null);
    setMinute(next);
  };

  const pickPeriod = (toPm) => {
    if (toPm === pm) return;

    if (!periodAllowed(range, toPm)) {
      // A whole morning closed is before the window; a whole afternoon, after.
      explain(toPm ? 24 * 60 : 0);

      return;
    }

    setNotice(null);

    let next = (hour % 12) + (toPm ? 12 : 0);
    if (!hourAllowed(range, next)) next = firstHourOf(range, toPm);

    setHour(next);
    if (!isAllowed(range, next * 60 + minute)) setMinute(firstMinuteOf(range, next) ?? 0);
  };

  const labels = mode === "hour" ? HOURS : MINUTES;
  const selectedIndex = mode === "hour" ? hour % 12 : minute / 5;
  const hand = spot(selectedIndex);

  const box = (active) =>
    `grid h-[72px] w-[96px] place-items-center rounded-[10px] border-2 font-display text-[44px] font-medium leading-none transition-colors ${
      active ? "border-brand-500 bg-[#fff4e8] text-brand-600" : "border-[#f0d9c2] bg-[#faf7f3] text-ink hover:border-brand-300"
    }`;

  const period = (isPm) => {
    const active = pm === isPm;
    const enabled = periodAllowed(range, isPm);

    return (
      <button
        type="button"
        aria-disabled={!enabled}
        onClick={() => pickPeriod(isPm)}
        aria-pressed={active}
        className={`grid h-9 w-full place-items-center font-display font-semibold transition-colors ${
          !enabled
            ? "cursor-not-allowed bg-white text-[#cfc8bf]"
            : active
              ? "bg-[#ffe1c2] text-[#8a3d0b]"
              : "bg-white text-[#6f6b68] hover:bg-[#fff6ec]"
        }`}
      >
        <span className="text-[14px]">{isPm ? "PM" : "AM"}</span>
      </button>
    );
  };

  return (
    <Modal
      opened={opened}
      onClose={onClose}
      centered
      radius="lg"
      size={344}
      withCloseButton={false}
      padding={24}
      data-testid="time-dial"
    >
      <p className="m-0 font-display text-[12.5px] font-medium text-[#6f6b68]">{title}</p>

      {!range ? (
        <p className="m-0 mt-4 font-display text-[13px] text-[#8d8884]">No times are available.</p>
      ) : (
        <>
          <div className="mt-4 flex items-center gap-2">
            <button type="button" onClick={() => setMode("hour")} aria-label="Hour" className={box(mode === "hour")}>
              <span className="text-[44px]">{pad(hour12)}</span>
            </button>
            <span className="font-display text-[40px] font-bold leading-none text-ink">:</span>
            <button type="button" onClick={() => setMode("minute")} aria-label="Minute" className={box(mode === "minute")}>
              <span className="text-[44px]">{pad(minute)}</span>
            </button>
            <div className="ml-auto flex w-[52px] flex-col overflow-hidden rounded-[10px] border border-[#d9cfc4]">
              {period(false)}
              <span className="h-px bg-[#d9cfc4]" />
              {period(true)}
            </div>
          </div>

          {notice && (
            <p
              role="status"
              className="m-0 mt-4 flex items-start gap-2 rounded-[10px] bg-[#fff8e1] px-3 py-2.5 font-display text-[12.5px] leading-snug text-[#8a6206] ring-1 ring-inset ring-[#ffe6a8]"
              data-testid="time-dial-notice"
            >
              <IconInfoCircle size={15} stroke={2} aria-hidden="true" className="mt-px shrink-0" />
              {notice}
            </p>
          )}

          <div className="relative mx-auto mt-6 rounded-full bg-[#f6f0e9]" style={{ width: DIAL, height: DIAL }}>
            {/* The hand, from the centre to the chosen number. */}
            <span
              aria-hidden="true"
              className="absolute w-[2px] origin-bottom bg-brand-500"
              style={{
                left: CENTER - 1,
                top: CENTER - RADIUS,
                height: RADIUS,
                transform: `rotate(${selectedIndex * 30}deg)`,
              }}
            />
            <span aria-hidden="true" className="absolute h-2 w-2 rounded-full bg-brand-500" style={{ left: CENTER - 4, top: CENTER - 4 }} />
            <span
              aria-hidden="true"
              className="absolute h-10 w-10 rounded-full bg-brand-500"
              style={{ left: hand.left - 20, top: hand.top - 20 }}
            />

            {labels.map((label, index) => {
              const at = spot(index);
              const enabled =
                mode === "hour"
                  ? hourAllowed(range, (label % 12) + (pm ? 12 : 0))
                  : isAllowed(range, hour * 60 + label);
              const selected = index === selectedIndex;

              return (
                <button
                  key={label}
                  type="button"
                  aria-disabled={!enabled}
                  onClick={() => (mode === "hour" ? pickHour(label) : pickMinute(label))}
                  aria-label={mode === "hour" ? `${label} o'clock` : `${pad(label)} minutes`}
                  aria-pressed={selected}
                  className={`absolute grid h-10 w-10 place-items-center rounded-full bg-transparent font-display transition-colors ${
                    selected ? "text-white" : enabled ? "text-ink hover:bg-[#ffe9d4]" : "cursor-not-allowed text-[#cfc8bf]"
                  }`}
                  style={{ left: at.left - 20, top: at.top - 20 }}
                >
                  <span className="text-[15px] font-medium">{mode === "hour" ? label : pad(label)}</span>
                </button>
              );
            })}
          </div>
        </>
      )}

      <div className="mt-6 flex justify-end gap-6">
        <button type="button" onClick={onClose} className="bg-transparent font-display font-semibold text-brand-600 hover:text-brand-800">
          <span className="text-[14px]">Cancel</span>
        </button>
        <button
          type="button"
          disabled={!range || !isAllowed(range, chosen)}
          onClick={() => onConfirm(fromMinutes(chosen))}
          data-testid="time-dial-ok"
          className="bg-transparent font-display font-semibold text-brand-600 hover:text-brand-800 disabled:cursor-not-allowed disabled:text-[#cfc8bf]"
        >
          <span className="text-[14px]">OK</span>
        </button>
      </div>
    </Modal>
  );
}

export default TimeDialPicker;
