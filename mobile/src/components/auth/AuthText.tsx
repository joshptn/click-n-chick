import type { ReactNode } from "react";
import { Pressable, StyleSheet, View } from "react-native";

import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { IconArrowLeft } from "../icons";

/** "Welcome to Click n Chick" above the title. */
export function Eyebrow({ children }: { children: ReactNode }) {
  return (
    <AppText family="display" weight="medium" size={12} color={colors.orange}>
      {children}
    </AppText>
  );
}

export function AuthTitle({ children, size = 22 }: { children: ReactNode; size?: number }) {
  return (
    <AppText family="display" weight="bold" size={size} color={colors.authInk} accessibilityRole="header">
      {children}
    </AppText>
  );
}

export function Subtitle({ children }: { children: ReactNode }) {
  return (
    <AppText family="display" size={14} color={colors.authMuted} style={styles.subtitle}>
      {children}
    </AppText>
  );
}

/** An orange text button: "Sign in", "Resend email", "Forgot Password?". */
export function TextLink({
  label,
  onPress,
  size = 15,
  disabled = false,
  testID,
}: {
  label: string;
  onPress: () => void;
  size?: number;
  disabled?: boolean;
  testID?: string;
}) {
  return (
    <Pressable accessibilityRole="button" onPress={onPress} disabled={disabled} hitSlop={8} testID={testID}>
      <AppText family="display" weight="semibold" size={size} color={disabled ? colors.authHint : colors.orange}>
        {label}
      </AppText>
    </Pressable>
  );
}

/** A grey text button: "Continue without an account". */
export function QuietLink({ label, onPress, testID }: { label: string; onPress: () => void; testID?: string }) {
  return (
    <Pressable accessibilityRole="button" onPress={onPress} hitSlop={8} testID={testID}>
      <AppText family="display" size={13.5} color={colors.authMuted}>
        {label}
      </AppText>
    </Pressable>
  );
}

/** "Don't have an account? Sign up". */
export function PromptRow({ prompt, children }: { prompt: string; children: ReactNode }) {
  return (
    <View style={styles.prompt}>
      <AppText family="display" size={13} color={colors.authHint}>
        {prompt}
      </AppText>
      {children}
    </View>
  );
}

/** "← Back" at the top of the code and password screens. */
export function BackLink({ label = "Back", onPress }: { label?: string; onPress: () => void }) {
  return (
    <Pressable accessibilityRole="button" onPress={onPress} hitSlop={10} style={styles.back}>
      <IconArrowLeft size={16} color={colors.authHint} />
      <AppText family="display" size={14} color={colors.authHint}>
        {label}
      </AppText>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  subtitle: { lineHeight: 21 },
  prompt: { flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 8, flexWrap: "wrap" },
  back: { flexDirection: "row", alignItems: "center", gap: 6, alignSelf: "flex-start", marginBottom: 18 },
});
