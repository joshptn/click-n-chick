import type { ComponentType } from "react";
import { Pressable, StyleSheet, View } from "react-native";

import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { IconChevronRight } from "../icons";

type IconComponent = ComponentType<{ size?: number; color?: string; strokeWidth?: number }>;

/** A row of the Profile screen's lists: a soft orange icon, a title, a hint and a chevron. */
export function MenuRow({
  icon: Icon,
  title,
  subtitle,
  onPress,
  testID,
}: {
  icon: IconComponent;
  title: string;
  subtitle: string;
  onPress: () => void;
  testID?: string;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityHint={subtitle}
      onPress={onPress}
      style={({ pressed }) => [styles.row, pressed && styles.pressed]}
      testID={testID}
    >
      <View style={styles.icon}>
        <Icon size={20} color={colors.orange} strokeWidth={1.9} />
      </View>
      <View style={styles.text}>
        <AppText family="display" weight="medium" size={15} color={colors.authInk}>
          {title}
        </AppText>
        <AppText family="display" size={11.5} color={colors.authHint} numberOfLines={1}>
          {subtitle}
        </AppText>
      </View>
      <IconChevronRight size={20} color={colors.authMuted} strokeWidth={2} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", gap: 14, paddingVertical: 12, paddingHorizontal: 6, borderRadius: 14 },
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

export default MenuRow;
