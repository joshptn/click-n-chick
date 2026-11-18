import type { ComponentType } from "react";
import { ActivityIndicator, Pressable, StyleSheet, View } from "react-native";

import { colors } from "../../theme/colors";
import { AppText } from "../AppText";

type IconType = ComponentType<{ size?: number; color?: string; strokeWidth?: number }>;

type CheckoutButtonProps = {
  label: string;
  onPress: () => void;
  /** Grey and inert until the step's required fields are valid. */
  disabled?: boolean;
  /** Shows a spinner and refuses further taps, so nothing is sent twice. */
  loading?: boolean;
  variant?: "primary" | "outline";
  icon?: IconType;
  trailingIcon?: IconType;
  testID?: string;
  flex?: number;
};

const GREY = "#e5e7eb";
const GREY_TEXT = "#9ca3af";

/**
 * The checkout's buttons. Primary is the cart's "Proceed to Checkout" button
 * (orange, raised); while it cannot be pressed it is grey, not pale orange, so
 * a blocked step reads as blocked (decided 2026-11-19). Outline is Back,
 * Later and Cancel.
 */
export function CheckoutButton({
  label,
  onPress,
  disabled = false,
  loading = false,
  variant = "primary",
  icon: Icon,
  trailingIcon: Trailing,
  testID,
  flex,
}: CheckoutButtonProps) {
  const inactive = disabled || loading;
  const primary = variant === "primary";
  const textColor = primary ? (disabled ? GREY_TEXT : colors.white) : disabled ? GREY_TEXT : colors.authLabel;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: inactive, busy: loading }}
      disabled={inactive}
      onPress={onPress}
      testID={testID}
      style={({ pressed }) => [
        styles.base,
        flex !== undefined && { flex },
        primary ? (disabled ? styles.primaryDisabled : styles.primary) : styles.outline,
        pressed && styles.pressed,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={primary ? colors.white : colors.orange} />
      ) : (
        <View style={styles.row}>
          {Icon ? <Icon size={18} color={textColor} strokeWidth={2.1} /> : null}
          <AppText family="display" weight="semibold" size={15} color={textColor} numberOfLines={1}>
            {label}
          </AppText>
          {Trailing ? <Trailing size={17} color={textColor} strokeWidth={2.4} /> : null}
        </View>
      )}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  base: { height: 52, borderRadius: 14, alignItems: "center", justifyContent: "center", paddingHorizontal: 16 },
  row: { flexDirection: "row", alignItems: "center", gap: 8 },
  primary: {
    backgroundColor: colors.orange,
    elevation: 4,
    shadowColor: colors.orange,
    shadowOpacity: 0.35,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 4 },
  },
  primaryDisabled: { backgroundColor: GREY },
  outline: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.fieldBorder },
  pressed: { opacity: 0.88 },
});

export default CheckoutButton;
