import type { ReactNode } from "react";
import { StyleSheet, View } from "react-native";

import { AppText } from "../AppText";
import { colors } from "../../theme/colors";
import { AuthButton } from "./AuthButton";
import { AuthCard } from "./AuthCard";
import { AuthTitle, BackLink, PromptRow, TextLink } from "./AuthText";
import { CODE_LENGTH, CodeInput } from "./CodeInput";
import { FormAlert } from "./FormAlert";

type CodeScreenProps = {
  title: string;
  /** Where the code went, shown in bold under the title. */
  sentTo: string;
  code: string;
  onCodeChange: (code: string) => void;
  onSubmit: () => void;
  submitLabel?: string;
  busy?: boolean;
  onBack: () => void;
  /** Seconds left before "Resend" works again. */
  cooldown: number;
  onResend: () => void;
  resendLabel: string;
  error?: string | null;
  info?: string | null;
  children?: ReactNode;
};

/**
 * The six-box code screen (design "Check your email"), shared by the reset
 * code and two-step sign-in.
 */
export function CodeScreen({
  title,
  sentTo,
  code,
  onCodeChange,
  onSubmit,
  submitLabel = "Verify Code",
  busy = false,
  onBack,
  cooldown,
  onResend,
  resendLabel,
  error,
  info,
  children,
}: CodeScreenProps) {
  return (
    <AuthCard>
      <BackLink onPress={onBack} />

      <View style={styles.form}>
        <View style={styles.heading}>
          <AuthTitle>{title}</AuthTitle>
          <AppText family="display" size={14} color={colors.authMuted}>
            We sent a 6-digit code to
          </AppText>
          <AppText family="display" weight="semibold" size={14} color={colors.authLabel}>
            {sentTo}
          </AppText>
        </View>

        {children}

        <FormAlert message={info} tone="info" />
        <FormAlert message={error} />

        <CodeInput value={code} onChange={onCodeChange} editable={!busy} testID="code-input" />

        <AuthButton
          label={submitLabel}
          onPress={onSubmit}
          disabled={code.length !== CODE_LENGTH}
          loading={busy}
          testID="code-submit"
        />

        <PromptRow prompt="Didn't get it?">
          <TextLink
            label={cooldown > 0 ? `${resendLabel} in ${cooldown}s` : resendLabel}
            onPress={onResend}
            disabled={cooldown > 0}
            testID="code-resend"
          />
        </PromptRow>
      </View>
    </AuthCard>
  );
}

const styles = StyleSheet.create({
  form: { gap: 20 },
  heading: { gap: 4 },
});

export default CodeScreen;
