import { ActivityIndicator, Pressable, StyleSheet, type PressableProps } from "react-native";

import { colors } from "../theme/colors";
import { AppText } from "./AppText";

type ButtonProps = Omit<PressableProps, "children"> & {
  label: string;
  variant?: "primary" | "outline";
  loading?: boolean;
};

export function Button({ label, variant = "primary", loading = false, disabled, style, ...rest }: ButtonProps) {
  const primary = variant === "primary";
  const inactive = disabled || loading;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: Boolean(inactive), busy: loading }}
      disabled={inactive}
      style={(state) => [
        styles.base,
        primary ? styles.primary : styles.outline,
        state.pressed && styles.pressed,
        inactive && styles.inactive,
        typeof style === "function" ? style(state) : style,
      ]}
      {...rest}
    >
      {loading ? (
        <ActivityIndicator color={primary ? colors.white : colors.brand600} />
      ) : (
        <AppText weight="semibold" size={15} color={primary ? colors.white : colors.brand600}>
          {label}
        </AppText>
      )}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  base: {
    minHeight: 48,
    borderRadius: 999,
    paddingHorizontal: 20,
    alignItems: "center",
    justifyContent: "center",
  },
  primary: { backgroundColor: colors.brand500 },
  outline: { backgroundColor: colors.white, borderWidth: 1.5, borderColor: colors.brand500 },
  pressed: { opacity: 0.85 },
  inactive: { opacity: 0.5 },
});

export default Button;
