import { StyleSheet, View } from "react-native";

import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { IconAlertCircle } from "../icons";

/** A message above the form: the API's refusal, or why the customer was sent back. */
export function FormAlert({ message, tone = "error" }: { message?: string | null; tone?: "error" | "info" }) {
  if (!message) return null;

  const error = tone === "error";

  return (
    <View
      accessibilityRole="alert"
      accessibilityLiveRegion="polite"
      style={[styles.box, error ? styles.error : styles.info]}
      testID={error ? "form-error" : "form-notice"}
    >
      <IconAlertCircle size={18} color={error ? colors.danger : "#b7791f"} />
      <AppText family="display" size={13} color={error ? colors.danger : "#7a5620"} style={styles.text}>
        {message}
      </AppText>
    </View>
  );
}

const styles = StyleSheet.create({
  box: { flexDirection: "row", gap: 10, padding: 12, borderRadius: 12, borderWidth: 1 },
  error: { backgroundColor: colors.dangerSurface, borderColor: "#ffd7d5" },
  info: { backgroundColor: "#fff9e8", borderColor: "#ffe6a8" },
  text: { flex: 1, lineHeight: 19 },
});

export default FormAlert;
