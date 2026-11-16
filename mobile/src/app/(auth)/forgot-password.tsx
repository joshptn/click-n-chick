import { useState } from "react";
import { StyleSheet, View } from "react-native";
import { router } from "expo-router";
import { Controller, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";

import { authApi, channelFor } from "../../api/auth";
import { AuthButton } from "../../components/auth/AuthButton";
import { AuthCard } from "../../components/auth/AuthCard";
import { AuthTitle, BackLink, Subtitle } from "../../components/auth/AuthText";
import { FormAlert } from "../../components/auth/FormAlert";
import { TextField } from "../../components/auth/TextField";
import { authFlow } from "../../lib/authFlow";
import { errorMessage } from "../../lib/signIn";

const schema = z.object({
  identifier: z.string().trim().min(1, "Enter your email or phone number."),
});

type Values = z.infer<typeof schema>;

/** Forgot password (design of 2026-11-16): asks for a reset code. */
export default function ForgotPassword() {
  const [error, setError] = useState<string | null>(null);

  const {
    control,
    handleSubmit,
    formState: { isValid, isSubmitting, errors },
  } = useForm<Values>({ resolver: zodResolver(schema), mode: "onTouched", defaultValues: { identifier: "" } });

  const submit = handleSubmit(async ({ identifier }) => {
    setError(null);
    const value = identifier.trim();

    try {
      const result = await authApi.forgotPassword(value);

      // The API answers the same whether or not the account exists.
      authFlow.set({
        identifier: value,
        channel: channelFor(value),
        masked: value,
        resendIn: result.resend_available_in ?? 60,
        resetCode: undefined,
        resetCodeError: undefined,
      });
      router.push("/reset-code");
    } catch (caught) {
      // A 429 already says when to try again.
      setError(errorMessage(caught));
    }
  });

  return (
    <AuthCard>
      <BackLink label="Back to login" onPress={() => (router.canGoBack() ? router.back() : router.replace("/sign-in"))} />

      <View style={styles.form}>
        <View style={styles.heading}>
          <AuthTitle>Forgot password</AuthTitle>
          <Subtitle>Enter your email or phone number to reset the password</Subtitle>
        </View>

        <FormAlert message={error} />

        <Controller
          control={control}
          name="identifier"
          render={({ field }) => (
            <TextField
              label="Email or phone number"
              placeholder="you@email.com or 0917…"
              value={field.value}
              onChangeText={field.onChange}
              onBlur={field.onBlur}
              error={errors.identifier?.message}
              autoCapitalize="none"
              autoCorrect={false}
              autoComplete="username"
              autoFocus
              returnKeyType="send"
              onSubmitEditing={() => submit()}
              editable={!isSubmitting}
              testID="forgot-identifier"
            />
          )}
        />

        <AuthButton
          label="Reset Password"
          onPress={() => submit()}
          disabled={!isValid}
          loading={isSubmitting}
          testID="forgot-submit"
        />
      </View>
    </AuthCard>
  );
}

const styles = StyleSheet.create({
  form: { gap: 18 },
  heading: { gap: 4 },
});
