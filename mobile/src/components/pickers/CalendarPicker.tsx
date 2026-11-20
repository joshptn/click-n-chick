import { useState } from "react";
import { Pressable, StyleSheet, View } from "react-native";

import { formatCalendarDate, formatDateValue, parseDateValue } from "../../lib/timeWindow";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { IconChevronLeft, IconChevronRight, IconInfoCircle } from "../icons";
import { DISABLED, PickerDialog } from "./PickerDialog";

const WEEKDAYS = ["S", "M", "T", "W", "T", "F", "S"];
const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
const PAST = "#c4cbd3";

const monthIndex = (date: Date) => date.getFullYear() * 12 + date.getMonth();

type Props = {
  visible: boolean;
  onClose: () => void;
  value: string | null;
  min: string | null | undefined;
  max: string | null | undefined;
  onConfirm: (value: string) => void;
  title?: string;
  /** Why a greyed-out day before `min` cannot be picked, given `min` as "Mon, Aug 17". */
  earlyMessage?: (first: string) => string;
  /** Why one after `max` cannot be picked, given `max`. */
  lateMessage?: (last: string) => string;
};

/**
 * The calendar picker (the user's design, 2026-11-20, as on the web): the
 * chosen date large at the top, the month with arrows, and the day grid. Only
 * days from `min` to `max` ("YYYY-MM-DD") can be picked; the rest are greyed
 * out and the arrows stop at their months. Answers "YYYY-MM-DD". Mounted only
 * while open, so it starts from the current value.
 */
export function CalendarPicker(props: Props) {
  if (!props.visible) return null;

  return <Calendar {...props} />;
}

function Calendar({
  onClose,
  value,
  min,
  max,
  onConfirm,
  title = "Select date",
  // Tapping a greyed-out day says why (decided 2026-11-20).
  earlyMessage = (first) => `The earliest date you can choose is ${first}.`,
  lateMessage = (last) => `The latest date you can choose is ${last}.`,
}: Props) {
  const [notice, setNotice] = useState<string | null>(null);
  const first = parseDateValue(min);
  const last = parseDateValue(max);
  const allowed = (date: Date) => (!first || date >= first) && (!last || date <= last);

  const current = parseDateValue(value);
  const valid = current !== null && allowed(current);
  const start = valid ? current : (first ?? new Date());

  const [chosen, setChosen] = useState<Date | null>(valid ? current : null);
  const [view, setView] = useState(() => new Date(start.getFullYear(), start.getMonth(), 1));

  const year = view.getFullYear();
  const month = view.getMonth();
  const offset = new Date(year, month, 1).getDay();
  const days = new Date(year, month + 1, 0).getDate();
  const cells: (Date | null)[] = [...Array<null>(offset).fill(null), ...Array.from({ length: days }, (_, index) => new Date(year, month, index + 1))];

  const canBack = !first || monthIndex(view) > monthIndex(first);
  const canForward = !last || monthIndex(view) < monthIndex(last);

  return (
    <PickerDialog visible onClose={onClose} onConfirm={() => chosen && onConfirm(formatDateValue(chosen))} confirmDisabled={!chosen} testID="calendar">
      <View style={styles.top}>
        <AppText family="display" weight="medium" size={12.5} color={colors.authMuted}>
          {title}
        </AppText>
        <AppText family="display" weight="medium" size={28} color={colors.authInk} style={styles.big}>
          {chosen ? formatCalendarDate(formatDateValue(chosen)) : "Choose a date"}
        </AppText>
      </View>

      <View style={styles.month}>
        <View style={styles.monthRow}>
          <AppText family="display" weight="semibold" size={14} color={colors.authInk}>
            {MONTHS[month]} {year}
          </AppText>
          <View style={styles.arrows}>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Previous month"
              disabled={!canBack}
              onPress={() => setView(new Date(year, month - 1, 1))}
              style={styles.arrow}
              hitSlop={4}
            >
              <IconChevronLeft size={18} color={canBack ? colors.authInk : DISABLED} strokeWidth={2.2} />
            </Pressable>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Next month"
              disabled={!canForward}
              onPress={() => setView(new Date(year, month + 1, 1))}
              style={styles.arrow}
              hitSlop={4}
            >
              <IconChevronRight size={18} color={canForward ? colors.authInk : DISABLED} strokeWidth={2.2} />
            </Pressable>
          </View>
        </View>

        <View style={styles.grid}>
          {WEEKDAYS.map((day, index) => (
            <View key={`${day}-${index}`} style={styles.cell}>
              <AppText family="display" weight="medium" size={13} color={colors.authInk}>
                {day}
              </AppText>
            </View>
          ))}

          {cells.map((date, index) => {
            if (!date) return <View key={`blank-${index}`} style={styles.cell} />;

            const enabled = allowed(date);
            const selected = chosen !== null && formatDateValue(chosen) === formatDateValue(date);

            return (
              <View key={formatDateValue(date)} style={styles.cell}>
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel={formatCalendarDate(formatDateValue(date))}
                  accessibilityState={{ selected, disabled: !enabled }}
                  onPress={() => {
                    if (enabled) {
                      setNotice(null);
                      setChosen(date);
                    } else if (first && date < first) {
                      setNotice(earlyMessage(formatCalendarDate(min)));
                    } else if (last) {
                      setNotice(lateMessage(formatCalendarDate(max)));
                    }
                  }}
                  style={[styles.day, selected && styles.daySelected]}
                  testID={`day-${formatDateValue(date)}`}
                >
                  <AppText family="display" size={14} color={selected ? colors.white : enabled ? colors.authInk : PAST}>
                    {date.getDate()}
                  </AppText>
                </Pressable>
              </View>
            );
          })}
        </View>
      </View>

      {notice ? (
        <View style={styles.notice} accessibilityLiveRegion="polite" testID="calendar-notice">
          <IconInfoCircle size={15} color="#8a6206" strokeWidth={2} />
          <AppText family="display" size={12.5} color="#8a6206" style={styles.noticeText}>
            {notice}
          </AppText>
        </View>
      ) : null}
    </PickerDialog>
  );
}

const styles = StyleSheet.create({
  top: { paddingHorizontal: 24, paddingBottom: 12 },
  big: { marginTop: 10 },
  month: { borderTopWidth: 1, borderTopColor: "#e9e2d9", paddingHorizontal: 14, paddingTop: 12 },
  monthRow: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", paddingHorizontal: 8 },
  arrows: { flexDirection: "row", gap: 4 },
  arrow: { width: 36, height: 36, borderRadius: 18, alignItems: "center", justifyContent: "center" },
  grid: { flexDirection: "row", flexWrap: "wrap", marginTop: 6 },
  cell: { width: `${100 / 7}%`, height: 42, alignItems: "center", justifyContent: "center" },
  day: { width: 38, height: 38, borderRadius: 19, alignItems: "center", justifyContent: "center" },
  daySelected: { backgroundColor: colors.orange },
  notice: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: 8,
    marginHorizontal: 18,
    marginTop: 4,
    padding: 10,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: "#ffe6a8",
    backgroundColor: "#fff8e1",
  },
  noticeText: { flex: 1 },
});
