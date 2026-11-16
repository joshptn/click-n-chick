import { useState } from "react";
import { StyleSheet, View } from "react-native";
import { Redirect, router } from "expo-router";

import { ApiError } from "../../api/client";
import { authApi } from "../../api/auth";
import { AppText } from "../../components/AppText";
import { AuthButton } from "../../components/auth/AuthButton";
import { AuthCard } from "../../components/auth/AuthCard";
import { AuthTitle, Eyebrow, PromptRow, TextLink } from "../../components/auth/AuthText";
import { CODE_LENGTH, CodeInput } from "../../components/auth/CodeInput";
import { FormAlert } from "../../components/auth/FormAlert";
import { IconShieldCheck } from "../../components/icons";
import { useCooldown } from "../../hooks/useCooldown";
import { authFlow } from "../../lib/authFlow";
import { adoptSession, errorMessage } from "../../lib/signIn";
import { colors } from "../../theme/colors";

/**
 * The code that finishes a sign-up (design "Verify your phone"), worded for
 * email when the code went to an email address. Also where a store-created
 * account lands on its first sign-in.
 */
export default function VerifyAccount() {
  const [flow] = useState(() => authFlow.get());
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);
  const [verifying, setVerifying] = useState(false);
  const cooldown = useCooldown(flow.resendIn ?? 60);

  if (!flow.identifier) return <Redirect href="/sign-up" />;

  const identifier = flow.identifier;
  const email = flow.channel === "email";

  const verify = async () => {
    setError(null);
    setInfo(null);
    setVerifying(true);

    try {
      await adoptSession(await authApi.verifyCode(identifier, code));
    } catch (caught) {
      // The sign-up details waited on the server for a limited time and are gone.
      if (caught instanceof ApiError && caught.payload.reason === "registration_expired") {
        authFlow.set({ notice: caught.message });
        router.replace("/sign-up");
        return;
      }

      setError(errorMessage(caught, "That code is not correct."));
      setCode("");
    } finally {
      setVerifying(false);
    }
  };

  const resend = async () => {
    setError(null);

    try {
      const result = await authApi.resendCode(identifier);
      cooldown.restart(result.resend_available_in ?? 60);
      setInfo(result.message ?? "A new code is on its way.");
    } catch (caught) {
      const wait = caught instanceof ApiError ? Number(caught.payload.resend_available_in ?? 0) : 0;
      if (wait > 0) cooldown.restart(wait);
      setError(errorMessage(caught, "We could not send a new code. Please try again."));
    }
  };

  return (
    <AuthCard header={false}>
      <View style={styles.form}>
        <View style={styles.shield}>
          <IconShieldCheck size={20} color={colors.orange} />
        </View>

        <View style={styles.heading}>
          <Eyebrow>One last step for Click n Chick</Eyebrow>
          <AuthTitle size={28}>{email ? "Verify your email" : "Verify your phone"}</AuthTitle>
          <AppText family="display" size={14} color={colors.authMuted} style={styles.lead}>
            We sent a 6-digit code to{" "}
            <AppText family="display" weight="bold" size={14} color={colors.authInk}>
              {flow.masked ?? identifier}
            </AppText>
            . Enter it below to finish creating your account.
          </AppText>
        </View>

        <FormAlert message={info} tone="info" />
        <FormAlert message={error} />

        <View style={styles.field}>
          <AppText family="display" weight="medium" size={13} color={colors.authLabel}>
            Verification code *
          </AppText>
          <CodeInput variant="single" value={code} onChange={setCode} editable={!verifying} testID="verify-code" />
        </View>

        <AuthButton
          label="Verify"
          onPress={verify}
          disabled={code.length !== CODE_LENGTH}
          loading={verifying}
          testID="verify-submit"
        />

        <View style={styles.footer}>
          <TextLink
            label={cooldown.seconds > 0 ? `Resend code in ${cooldown.seconds}s` : "Resend code"}
            size={14}
            onPress={resend}
            disabled={cooldown.seconds > 0}
            testID="verify-resend"
          />
          <PromptRow prompt={email ? "Wrong email?" : "Wrong number?"}>
            <TextLink label="Start over" onPress={() => router.replace("/sign-up")} />
          </PromptRow>
        </View>
      </View>
    </AuthCard>
  );
}

const styles = StyleSheet.create({
  form: { gap: 18, paddingTop: 10 },
  shield: {
    alignSelf: "center",
    width: 44,
    height: 44,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: colors.orangeSoft,
    backgroundColor: colors.orangeTint,
    alignItems: "center",
    justifyContent: "center",
  },
  heading: { gap: 6 },
  lead: { lineHeight: 21 },
  field: { gap: 8 },
  footer: { alignItems: "center", gap: 14 },
});
