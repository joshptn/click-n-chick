import type { ReactNode } from "react";
import { Pressable, StyleSheet, View } from "react-native";

import { colors } from "../../theme/colors";
import { IconCheck } from "../icons";

/** A square checkbox with its label; the whole row toggles it. */
export function Checkbox({
  checked,
  onChange,
  children,
  testID,
}: {
  checked: boolean;
  onChange: (next: boolean) => void;
  children: ReactNode;
  testID?: string;
}) {
  return (
    <Pressable
      accessibilityRole="checkbox"
      accessibilityState={{ checked }}
      onPress={() => onChange(!checked)}
      style={styles.row}
      hitSlop={6}
      testID={testID}
    >
      <View style={[styles.box, checked && styles.checked]}>
        {checked && <IconCheck size={13} color={colors.white} strokeWidth={3} />}
      </View>
      <View style={styles.label}>{children}</View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", gap: 10 },
  box: {
    width: 18,
    height: 18,
    borderRadius: 4,
    borderWidth: 1.5,
    borderColor: "#c8ccd2",
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
  },
  checked: { backgroundColor: colors.orange, borderColor: colors.orange },
  label: { flex: 1 },
});

export default Checkbox;
