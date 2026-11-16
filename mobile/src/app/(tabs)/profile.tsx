import { useState } from "react";
import { StyleSheet, View } from "react-native";
import { router } from "expo-router";
import { SafeAreaView } from "react-native-safe-area-context";

import { authApi } from "../../api/auth";
import { AppText } from "../../components/AppText";
import { Button } from "../../components/Button";
import { AppHeader } from "../../components/menu/AppHeader";
import { useUser } from "../../hooks/useUser";
import { currentToken, endSession } from "../../lib/session";
import { colors } from "../../theme/colors";

/**
 * Signed in only (the tabs layout guards it). The account pages arrive in
 * phase 8; until then this holds sign-out.
 */
export default function Profile() {
  const user = useUser();
  const [signingOut, setSigningOut] = useState(false);

  const name = [user?.first_name, user?.last_name].filter(Boolean).join(" ");

  const signOut = async () => {
    setSigningOut(true);

    const token = currentToken();

    // Ends this sign-in on the server too (which also drops its push token).
    // The phone signs out either way: a failed request must not keep it in.
    if (token) await authApi.logout(token).catch(() => undefined);

    await endSession();
    // Back to the landing page, as a signed-out start.
    router.replace("/");
  };

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right"]}>
      <AppHeader />
      <View style={styles.body}>
        <View style={styles.block}>
          <AppText family="display" weight="semibold" size={22} color={colors.authInk}>
            {name || "My account"}
          </AppText>
          <AppText family="display" size={13.5} color={colors.ink500}>
            Your profile, addresses and orders will be here in a later update.
          </AppText>
        </View>
        <Button label="Sign out" variant="outline" loading={signingOut} onPress={signOut} />
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  body: { padding: 20, gap: 20 },
  block: { gap: 6 },
});
