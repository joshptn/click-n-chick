import type { ReactNode } from "react";
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";

import { colors } from "../../theme/colors";
import { AppText } from "../AppText";

type AuthCardProps = {
  children: ReactNode;
  /** The orange "Click n Chick" bar along the top (the phone-verify design has none). */
  header?: boolean;
  /** The tab title under the bar, with its orange underline (Sign In / Sign Up). */
  tab?: string;
};

/**
 * The card every auth screen sits in (designs of 2026-11-16): white, rounded,
 * with a soft shadow, centred on a white page. Scrolls and lifts above the
 * keyboard on small phones.
 */
export function AuthCard({ children, header = true, tab }: AuthCardProps) {
  return (
    <SafeAreaView style={styles.page} edges={["top", "bottom", "left", "right"]}>
      <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === "ios" ? "padding" : undefined}>
        <ScrollView
          contentContainerStyle={styles.scroll}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
        >
          <View style={styles.card}>
            {header && (
              <View style={styles.header}>
                <AppText family="body" weight="bold" size={20} color={colors.white}>
                  Click n Chick
                </AppText>
              </View>
            )}

            {tab && (
              <View style={styles.tab}>
                <AppText family="display" weight="medium" size={14} color={colors.orange}>
                  {tab}
                </AppText>
              </View>
            )}

            <View style={styles.body}>{children}</View>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: colors.white },
  flex: { flex: 1 },
  scroll: { flexGrow: 1, justifyContent: "center", paddingHorizontal: 20, paddingVertical: 32 },
  card: {
    backgroundColor: colors.white,
    borderRadius: 18,
    overflow: "hidden",
    elevation: 12,
    shadowColor: "#000",
    shadowOpacity: 0.12,
    shadowRadius: 24,
    shadowOffset: { width: 0, height: 10 },
  },
  header: {
    height: 60,
    backgroundColor: colors.orange,
    alignItems: "center",
    justifyContent: "center",
  },
  tab: {
    height: 44,
    alignItems: "center",
    justifyContent: "center",
    borderBottomWidth: 1.5,
    borderBottomColor: colors.orange,
  },
  body: { paddingHorizontal: 24, paddingTop: 22, paddingBottom: 26 },
});

export default AuthCard;
