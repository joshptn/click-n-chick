import { Linking, Pressable, StyleSheet, Switch, View } from "react-native";

import { turnPushOff, turnPushOn, usePush } from "../../lib/push";
import { toast } from "../../lib/toast";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { IconBell, IconBellOff } from "../icons";

const SUBTITLE = {
  unavailable: "Needs the installed app (not Expo Go)",
  unknown: "Checking...",
  on: "On for this phone",
  off: "Off for this phone",
  blocked: "Blocked in Android settings. Tap to open them",
} as const;

/**
 * Profile's notifications switch (phase 10, decided 2026-11-19): pushes on or
 * off for this account on this phone. The app asks once after sign-in; this
 * is how to change it afterwards. Blocked in Android's settings, it opens them.
 */
export function PushRow() {
  const { status, busy } = usePush();
  const on = status === "on";
  const disabled = busy || status === "unavailable" || status === "unknown";

  const toggle = async (next: boolean) => {
    try {
      if (next) await turnPushOn();
      else await turnPushOff();
    } catch (error) {
      toast.error("Notifications not changed", error instanceof Error ? error.message : "Please try again.");
    }
  };

  const Icon = on ? IconBell : IconBellOff;

  return (
    <Pressable
      accessibilityRole="switch"
      accessibilityState={{ checked: on, disabled, busy }}
      accessibilityLabel="Notifications"
      accessibilityHint={SUBTITLE[status]}
      disabled={disabled && status !== "blocked"}
      onPress={() => (status === "blocked" ? void Linking.openSettings() : void toggle(!on))}
      style={({ pressed }) => [styles.row, pressed && styles.pressed]}
      testID="menu-notifications"
    >
      <View style={styles.icon}>
        <Icon size={20} color={colors.orange} strokeWidth={1.9} />
      </View>
      <View style={styles.text}>
        <AppText family="display" weight="medium" size={15} color={colors.authInk}>
          Notifications
        </AppText>
        <AppText family="display" size={11.5} color={status === "blocked" ? colors.danger : colors.authHint} numberOfLines={1}>
          {SUBTITLE[status]}
        </AppText>
      </View>
      <Switch
        value={on}
        disabled={disabled || status === "blocked"}
        onValueChange={(next) => void toggle(next)}
        trackColor={{ false: "#e5e7eb", true: colors.orangeSoft }}
        thumbColor={on ? colors.orange : "#f9fafb"}
        testID="push-switch"
      />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", gap: 14, paddingVertical: 10, paddingHorizontal: 6, borderRadius: 14 },
  pressed: { backgroundColor: "#fff1e0" },
  icon: {
    width: 42,
    height: 42,
    borderRadius: 21,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "#fff1e0",
  },
  text: { flex: 1, gap: 1 },
});

export default PushRow;
