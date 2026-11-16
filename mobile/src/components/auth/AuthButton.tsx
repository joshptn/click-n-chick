import { ActivityIndicator, Pressable, StyleSheet } from "react-native";

import { colors } from "../../theme/colors";
import { AppText } from "../AppText";

type AuthButtonProps = {
  label: string;
  onPress: () => void;
  /** Pale orange and inert, as in the designs, until the form can be sent. */
  disabled?: boolean;
  loading?: boolean;
  testID?: string;
};

/** The full-width orange button of the auth designs. */
export function AuthButton({ label, onPress, disabled = false, loading = false, testID }: AuthButtonProps) {
  const inactive = disabled || loading;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: inactive, busy: loading }}
      disabled={inactive}
      onPress={onPress}
      testID={testID}
      style={({ pressed }) => [
        styles.button,
        { backgroundColor: disabled ? colors.orangeSoft : colors.orange },
        pressed && styles.pressed,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={colors.white} />
      ) : (
        <AppText family="display" weight="semibold" size={15} color={colors.white}>
          {label}
        </AppText>
      )}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  button: { height: 50, borderRadius: 14, alignItems: "center", justifyContent: "center" },
  pressed: { opacity: 0.88 },
});

export default AuthButton;
