import { useState } from "react";
import { Pressable, StyleSheet, View } from "react-native";

import {
  firstHourOf,
  firstMinuteOf,
  formatClockValue,
  fromMinutes,
  hourAllowed,
  isAllowed,
  periodAllowed,
  timeWindow,
  toMinutes,
} from "../../lib/timeWindow";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { IconInfoCircle } from "../icons";
import { DISABLED, PickerDialog } from "./PickerDialog";

const DIAL = 240;
const CENTER = DIAL / 2;
const RADIUS = 92;
const HOURS = [12, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];
const MINUTES = [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55];

const pad = (value: number) => String(value).padStart(2, "0");

function spot(index: number) {
  const angle = (index * 30 * Math.PI) / 180;

  return { x: CENTER + RADIUS * Math.sin(angle), y: CENTER - RADIUS * Math.cos(angle) };
}

type Props = {
  visible: boolean;
  onClose: () => void;
  value: string | null;
  min: string | null | undefined;
  max: string | null | undefined;
  onConfirm: (value: string) => void;
  title?: string;
  /** Why a greyed-out time before the window cannot be picked, given the first bookable time. */
  earlyMessage?: (first: string) => string;
  /** Why one after the window cannot be picked, given the last bookable time. */
  lateMessage?: (last: string) => string;
};

/**
 * The clock-dial time picker (decided 2026-11-20, as on the web): hour first,
 * then minutes in 5-minute steps, with AM/PM beside them. Only times between
 * `min` and `max` ("HH:mm") can be picked; the rest are greyed out. Answers
 * "HH:mm". Mounted only while open, so it starts from the current value.
 * Tapping a greyed-out hour, minute or AM/PM says why (decided 2026-11-20).
 */
export function TimeDialPicker(props: Props) {
  if (!props.visible) return null;

  return <Dial {...props} />;
}

function Dial({
  onClose,
  value,
  min,
  max,
  onConfirm,
  title = "Select time",
  earlyMessage = (first) => `The earliest time you can choose is ${first}.`,
  lateMessage = (last) => `The last time you can choose is ${last}.`,
}: Props) {
  const range = timeWindow(min, max);
  const [notice, setNotice] = useState<string | null>(null);

  const explain = (minutes: number) => {
    if (!range) return;
    setNotice(
      minutes < range.first ? earlyMessage(formatClockValue(fromMinutes(range.first))) : lateMessage(formatClockValue(fromMinutes(range.last)))
    );
  };
  const current = toMinutes(value);
  const start = range ? (current !== null && isAllowed(range, current) ? current : range.first) : 0;

  const [hour, setHour] = useState(Math.floor(start / 60));
  const [minute, setMinute] = useState(start % 60);
  const [mode, setMode] = useState<"hour" | "minute">("hour");

  const pm = hour >= 12;
  const hour12 = hour % 12 === 0 ? 12 : hour % 12;
  const chosen = hour * 60 + minute;

  const pickHour = (label: number) => {
    if (!range) return;
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

  const pickPeriod = (toPm: boolean) => {
    if (!range || toPm === pm) return;

    if (!periodAllowed(range, toPm)) {
      // A whole morning closed is before the window; a whole afternoon, after.
      explain(toPm ? 24 * 60 : 0);
      return;
    }

    setNotice(null);

    let next = (hour % 12) + (toPm ? 12 : 0);
    if (!hourAllowed(range, next)) next = firstHourOf(range, toPm) ?? next;

    setHour(next);
    if (!isAllowed(range, next * 60 + minute)) setMinute(firstMinuteOf(range, next) ?? 0);
  };

  const labels = mode === "hour" ? HOURS : MINUTES;
  const selectedIndex = mode === "hour" ? hour % 12 : minute / 5;
  const hand = spot(selectedIndex);
  const mid = { x: (CENTER + hand.x) / 2, y: (CENTER + hand.y) / 2 };

  return (
    <PickerDialog
      visible
      onClose={onClose}
      onConfirm={() => onConfirm(fromMinutes(chosen))}
      confirmDisabled={!range || !isAllowed(range, chosen)}
      testID="time-dial"
    >
      <View style={styles.body}>
        <AppText family="display" weight="medium" size={12.5} color={colors.authMuted}>
          {title}
        </AppText>

        {!range ? (
          <AppText family="display" size={13} color={colors.authHint} style={styles.empty}>
            No times are available.
          </AppText>
        ) : (
          <>
            <View style={styles.header}>
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={`Hour ${hour12}`}
                onPress={() => setMode("hour")}
                style={[styles.box, mode === "hour" ? styles.boxActive : styles.boxIdle]}
              >
                <AppText family="display" weight="medium" size={40} color={mode === "hour" ? colors.brand600 : colors.authInk}>
                  {pad(hour12)}
                </AppText>
              </Pressable>
              <AppText family="display" weight="bold" size={36} color={colors.authInk}>
                :
              </AppText>
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={`Minute ${pad(minute)}`}
                onPress={() => setMode("minute")}
                style={[styles.box, mode === "minute" ? styles.boxActive : styles.boxIdle]}
              >
                <AppText family="display" weight="medium" size={40} color={mode === "minute" ? colors.brand600 : colors.authInk}>
                  {pad(minute)}
                </AppText>
              </Pressable>
              <View style={styles.periods}>
                {[false, true].map((isPm) => {
                  const active = pm === isPm;
                  const enabled = periodAllowed(range, isPm);

                  return (
                    <Pressable
                      key={isPm ? "pm" : "am"}
                      accessibilityRole="button"
                      accessibilityState={{ selected: active, disabled: !enabled }}
                      onPress={() => pickPeriod(isPm)}
                      style={[styles.period, isPm && styles.periodDivider, active && styles.periodActive]}
                    >
                      <AppText family="display" weight="semibold" size={13} color={!enabled ? DISABLED : active ? "#8a3d0b" : colors.authMuted}>
                        {isPm ? "PM" : "AM"}
                      </AppText>
                    </Pressable>
                  );
                })}
              </View>
            </View>

            {notice ? (
              <View style={styles.notice} accessibilityLiveRegion="polite" testID="time-dial-notice">
                <IconInfoCircle size={15} color="#8a6206" strokeWidth={2} />
                <AppText family="display" size={12.5} color="#8a6206" style={styles.noticeText}>
                  {notice}
                </AppText>
              </View>
            ) : null}

            <View style={styles.dial}>
              {/* The hand: a bar from the centre to the chosen number. */}
              <View
                style={[
                  styles.hand,
                  { left: mid.x - RADIUS / 2, top: mid.y - 1, transform: [{ rotate: `${selectedIndex * 30 - 90}deg` }] },
                ]}
              />
              <View style={styles.pivot} />
              <View style={[styles.knob, { left: hand.x - 20, top: hand.y - 20 }]} />

              {labels.map((label, index) => {
                const at = spot(index);
                const enabled = mode === "hour" ? hourAllowed(range, (label % 12) + (pm ? 12 : 0)) : isAllowed(range, hour * 60 + label);
                const selected = index === selectedIndex;

                return (
                  <Pressable
                    key={label}
                    accessibilityRole="button"
                    accessibilityLabel={mode === "hour" ? `${label} o'clock` : `${pad(label)} minutes`}
                    accessibilityState={{ selected, disabled: !enabled }}
                    onPress={() => {
                      if (mode === "hour") {
                        pickHour(label);
                      } else if (isAllowed(range, hour * 60 + label)) {
                        setNotice(null);
                        setMinute(label);
                      } else {
                        explain(hour * 60 + label);
                      }
                    }}
                    style={[styles.number, { left: at.x - 20, top: at.y - 20 }]}
                    testID={`dial-${mode}-${label}`}
                  >
                    <AppText family="display" weight="medium" size={15} color={selected ? colors.white : enabled ? colors.authInk : DISABLED}>
                      {mode === "hour" ? label : pad(label)}
                    </AppText>
                  </Pressable>
                );
              })}
            </View>
          </>
        )}
      </View>
    </PickerDialog>
  );
}

const styles = StyleSheet.create({
  body: { paddingHorizontal: 22 },
  empty: { marginTop: 14, marginBottom: 6 },
  header: { flexDirection: "row", alignItems: "center", gap: 6, marginTop: 14 },
  box: { flex: 1, maxWidth: 88, height: 68, borderRadius: 10, borderWidth: 2, alignItems: "center", justifyContent: "center" },
  boxActive: { borderColor: colors.orange, backgroundColor: "#fff4e8" },
  boxIdle: { borderColor: "#f0d9c2", backgroundColor: "#faf7f3" },
  periods: { marginLeft: 4, width: 50, borderRadius: 10, borderWidth: 1, borderColor: "#d9cfc4", overflow: "hidden" },
  period: { height: 34, alignItems: "center", justifyContent: "center", backgroundColor: colors.white },
  periodDivider: { borderTopWidth: 1, borderTopColor: "#d9cfc4" },
  periodActive: { backgroundColor: "#ffe1c2" },
  dial: { width: DIAL, height: DIAL, borderRadius: DIAL / 2, backgroundColor: "#f6f0e9", alignSelf: "center", marginTop: 20 },
  hand: { position: "absolute", width: RADIUS, height: 2, backgroundColor: colors.orange },
  pivot: { position: "absolute", width: 8, height: 8, borderRadius: 4, backgroundColor: colors.orange, left: CENTER - 4, top: CENTER - 4 },
  knob: { position: "absolute", width: 40, height: 40, borderRadius: 20, backgroundColor: colors.orange },
  notice: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: 8,
    marginTop: 14,
    padding: 10,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: "#ffe6a8",
    backgroundColor: "#fff8e1",
  },
  noticeText: { flex: 1 },
  number: { position: "absolute", width: 40, height: 40, borderRadius: 20, alignItems: "center", justifyContent: "center" },
});
