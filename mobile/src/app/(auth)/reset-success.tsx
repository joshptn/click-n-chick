import { useState } from "react";
import { StyleSheet, View } from "react-native";
import { Redirect } from "expo-router";

import { AppText } from "../../components/AppText";
import { AuthButton } from "../../components/auth/AuthButton";
import { AuthCard } from "../../components/auth/AuthCard";
import { AuthTitle } from "../../components/auth/AuthText";
import { FormAlert } from "../../components/auth/FormAlert";
import { IconCircleCheck } from "../../components/icons";
import { authFlow } from "../../lib/authFlow";
import { adoptSession, errorMessage } from "../../lib/signIn";
import { colors } from "../../theme/colors";

/**
 * Password reset done (design "Successful!"). The API already signed the
 * customer in; the session is held until they continue, as decided on
 * 2026-11-16, so the button takes them straight into the app.
 */
export default function ResetSuccess() {
  const [pending] = useState(() => authFlow.get().pending);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!pending) return <Redirect href="/sign-in" />;

  const continueIn = async () => {
    setBusy(true);
    setError(null);

    try {
      await adoptSession(pending);
    } catch (caught) {
      setError(errorMessage(caught));
      setBusy(false);
    }
  };

  return (
    <AuthCard>
      <View style={styles.body}>
        <View style={styles.badge}>
          <IconCircleCheck size={40} color={colors.orange} strokeWidth={1.8} />
        </View>

        <AuthTitle size={24}>Successful!</AuthTitle>

        <AppText family="display" size={14.5} color={colors.authHint} style={styles.text}>
          Congratulations! Your password has been successfully reset. Continue to start ordering.
        </AppText>

        <FormAlert message={error} />

        <View style={styles.action}>
          <AuthButton label="Continue" onPress={continueIn} loading={busy} testID="reset-continue" />
        </View>
      </View>
    </AuthCard>
  );
}

const styles = StyleSheet.create({
  body: { alignItems: "center", gap: 14, paddingTop: 8 },
  badge: {
    width: 80,
    height: 80,
    borderRadius: 40,
    backgroundColor: colors.orangeTint,
    alignItems: "center",
    justifyContent: "center",
    marginBottom: 6,
  },
  text: { textAlign: "center", lineHeight: 22 },
  action: { alignSelf: "stretch", marginTop: 8 },
});
