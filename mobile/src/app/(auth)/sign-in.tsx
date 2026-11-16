import { useState } from "react";
import { StyleSheet, View } from "react-native";
import { router } from "expo-router";
import { Controller, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";

import { ApiError } from "../../api/client";
import { authApi, channelFor, isChallenge, type Channel } from "../../api/auth";
import { AuthButton } from "../../components/auth/AuthButton";
import { AuthCard } from "../../components/auth/AuthCard";
import { AuthTitle, Eyebrow, PromptRow, QuietLink, TextLink } from "../../components/auth/AuthText";
import { FormAlert } from "../../components/auth/FormAlert";
import { TextField } from "../../components/auth/TextField";
import { authFlow } from "../../lib/authFlow";
import { adoptSession, errorMessage } from "../../lib/signIn";

const schema = z.object({
  login: z.string().trim().min(1, "Enter your email or phone number."),
  password: z.string().min(1, "Enter your password."),
});

type Values = z.infer<typeof schema>;

/** Sign In (design of 2026-11-16). */
export default function SignIn() {
  const [notice] = useState(() => authFlow.takeNotice());
  const [error, setError] = useState<string | null>(null);

  const {
    control,
    handleSubmit,
    formState: { isValid, isSubmitting, errors },
  } = useForm<Values>({ resolver: zodResolver(schema), mode: "onTouched", defaultValues: { login: "", password: "" } });

  const submit = handleSubmit(async ({ login, password }) => {
    setError(null);

    try {
      const result = await authApi.login(login, password);

      if (isChallenge(result)) {
        authFlow.set({
          challengeToken: result.challenge_token,
          channel: result.two_factor_channel,
          masked: result.identifier ?? undefined,
          resendIn: 60,
        });
        router.push("/two-factor");
        return;
      }

      await adoptSession(result);
    } catch (caught) {
      const payload = caught instanceof ApiError ? caught.payload : {};

      // The password was right; a code is needed before the account can sign in.
      if (caught instanceof ApiError && caught.status === 403 && payload.status === "pending_verification") {
        // The identifier typed is not the one this account verified: the API says which to use.
        if (payload.sign_in_with_channel) {
          setError(caught.message);
          return;
        }

        authFlow.set({
          identifier: login,
          channel: (payload.verification_channel as Channel | undefined) ?? channelFor(login),
          masked: typeof payload.identifier === "string" ? payload.identifier : login,
          resendIn: Number(payload.resend_available_in ?? 0),
        });
        router.push("/verify");
        return;
      }

      setError(errorMessage(caught));
    }
  });

  return (
    <AuthCard tab="Sign In">
      <View style={styles.form}>
        <View style={styles.heading}>
          <Eyebrow>Welcome to Click n Chick</Eyebrow>
          <AuthTitle>Sign In</AuthTitle>
        </View>

        <FormAlert message={notice} tone="info" />
        <FormAlert message={error} />

        <Controller
          control={control}
          name="login"
          render={({ field }) => (
            <TextField
              label="Email or phone number"
              placeholder="Email address or phone number"
              value={field.value}
              onChangeText={field.onChange}
              onBlur={field.onBlur}
              error={errors.login?.message}
              autoCapitalize="none"
              autoCorrect={false}
              autoComplete="username"
              textContentType="username"
              returnKeyType="next"
              editable={!isSubmitting}
              testID="sign-in-login"
            />
          )}
        />

        <Controller
          control={control}
          name="password"
          render={({ field }) => (
            <TextField
              label="Password"
              placeholder="Password"
              secret
              value={field.value}
              onChangeText={field.onChange}
              onBlur={field.onBlur}
              error={errors.password?.message}
              autoComplete="current-password"
              textContentType="password"
              returnKeyType="go"
              onSubmitEditing={() => submit()}
              editable={!isSubmitting}
              testID="sign-in-password"
            />
          )}
        />

        <View style={styles.forgot}>
          <TextLink label="Forgot Password?" size={13} onPress={() => router.push("/forgot-password")} />
        </View>

        <AuthButton
          label="Sign In"
          onPress={() => submit()}
          disabled={!isValid}
          loading={isSubmitting}
          testID="sign-in-submit"
        />

        <View style={styles.footer}>
          <PromptRow prompt="Don't have an account?">
            <TextLink label="Sign up" onPress={() => router.replace("/sign-up")} testID="go-sign-up" />
          </PromptRow>
          <QuietLink label="Continue without an account" onPress={() => router.dismissTo("/home")} testID="continue-as-guest" />
        </View>
      </View>
    </AuthCard>
  );
}

const styles = StyleSheet.create({
  form: { gap: 16 },
  heading: { gap: 4, marginBottom: 2 },
  forgot: { alignItems: "flex-end", marginTop: -6 },
  footer: { alignItems: "center", gap: 10, marginTop: 4 },
});
