import { ActivityIndicator, KeyboardAvoidingView, Platform, ScrollView, StyleSheet, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";

import { AppText } from "../../components/AppText";
import { PasswordSection } from "../../components/account/PasswordSection";
import { ScreenHeader } from "../../components/account/ScreenHeader";
import { TwoFactorSection } from "../../components/account/TwoFactorSection";
import { useProfile } from "../../hooks/useProfile";
import { colors } from "../../theme/colors";

/**
 * Password and two-step verification on one screen (the user's "Change
 * Password" reference, 2026-11-18): the password change first, then where
 * sign-in codes go.
 */
export default function Security() {
  const { data, isLoading } = useProfile();

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right", "bottom"]}>
      <ScreenHeader title="Change Password" />

      {isLoading || !data ? (
        <View style={styles.loading}>
          <ActivityIndicator color={colors.orange} />
        </View>
      ) : (
        <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === "ios" ? "padding" : undefined}>
          <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
            <PasswordSection user={data.user} />

            <View style={styles.divider} />

            <AppText family="display" weight="semibold" size={12.5} color={colors.authHint} style={styles.label}>
              SIGN-IN CODES
            </AppText>
            <View style={styles.card}>
              <TwoFactorSection user={data.user} />
            </View>
          </ScrollView>
        </KeyboardAvoidingView>
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  flex: { flex: 1 },
  loading: { flex: 1, alignItems: "center", justifyContent: "center" },
  content: { paddingHorizontal: 20, paddingTop: 8, paddingBottom: 32, gap: 14 },
  divider: { height: 1, backgroundColor: "#f1ebe3", marginVertical: 12 },
  label: { letterSpacing: 0.6, marginLeft: 4 },
  card: {
    padding: 16,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: "#f0e9df",
    backgroundColor: colors.white,
  },
});
