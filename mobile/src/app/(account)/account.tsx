import { useState } from "react";
import { StyleSheet, View } from "react-native";

import { AppText } from "../../components/AppText";
import { Button } from "../../components/Button";
import { Screen } from "../../components/Screen";
import { authApi } from "../../api/auth";
import { currentToken, endSession } from "../../lib/session";
import { colors } from "../../theme/colors";

/**
 * Signed-in only. The account pages arrive in phase 8. Ending the session
 * clears the cache and the stored token, and the protected routes leave this
 * screen by themselves.
 */
export default function Account() {
  const [signingOut, setSigningOut] = useState(false);

  const signOut = async () => {
    setSigningOut(true);

    const token = currentToken();

    // Ends this sign-in on the server too (which also drops its push token).
    // The phone signs out either way: a failed request must not keep it in.
    if (token) await authApi.logout(token).catch(() => undefined);

    await endSession();
  };

  return (
    <Screen>
      <View style={styles.block}>
        <AppText family="display" weight="bold" size={24}>
          My account
        </AppText>
        <AppText color={colors.ink500}>Your profile, addresses and orders will be here.</AppText>
      </View>
      <Button label="Sign out" variant="outline" loading={signingOut} onPress={signOut} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  block: { gap: 6, paddingTop: 12 },
});
