import { useState } from "react";
import { StyleSheet, View } from "react-native";
import { Redirect, router } from "expo-router";
import { Controller, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";

import { ApiError } from "../../api/client";
import { authApi, isChallenge } from "../../api/auth";
import { AuthButton } from "../../components/auth/AuthButton";
import { AuthCard } from "../../components/auth/AuthCard";
import { AuthTitle, BackLink, Subtitle } from "../../components/auth/AuthText";
import { FormAlert } from "../../components/auth/FormAlert";
import { TextField } from "../../components/auth/TextField";
import { authFlow } from "../../lib/authFlow";
import { passwordProblem } from "../../lib/passwordRules";
import { errorMessage, fieldErrors } from "../../lib/signIn";

const schema = z
  .object({
    password: z.string().superRefine((value, ctx) => {
      const problem = passwordProblem(value);
      if (problem) ctx.addIssue({ code: "custom", message: problem });
    }),
    password_confirmation: z.string().min(1, "Re-enter the new password."),
  })
  .refine((values) => values.password === values.password_confirmation, {
    path: ["password_confirmation"],
    message: "The two passwords do not match.",
  });

type Values = z.infer<typeof schema>;

/**
 * Set a new password (design of 2026-11-16). Sends it with the code from the
 * previous screen; a refused code goes back there, a refused password stays
 * here on its field.
 */
export default function ResetPassword() {
  const [flow] = useState(() => authFlow.get());
  const [error, setError] = useState<string | null>(null);

  const {
    control,
    handleSubmit,
    setError: setFieldError,
    formState: { isValid, isSubmitting, errors },
  } = useForm<Values>({
    resolver: zodResolver(schema),
    mode: "onTouched",
    defaultValues: { password: "", password_confirmation: "" },
  });

  if (!flow.identifier || !flow.resetCode) return <Redirect href="/forgot-password" />;

  const identifier = flow.identifier;
  const code = flow.resetCode;

  const submit = handleSubmit(async (values) => {
    setError(null);

    try {
      const result = await authApi.resetPassword({ identifier, code, ...values });

      if (isChallenge(result)) {
        // Two-step sign-in is on: the password is changed, a code finishes signing in.
        authFlow.set({
          challengeToken: result.challenge_token,
          channel: result.two_factor_channel,
          masked: result.identifier ?? undefined,
          resendIn: 60,
          resetCode: undefined,
        });
        router.replace("/two-factor");
        return;
      }

      authFlow.set({ pending: result, resetCode: undefined });
      router.replace("/reset-success");
    } catch (caught) {
      // The code was wrong, expired or used up: back to the code screen to say so.
      if (caught instanceof ApiError && typeof caught.payload.reason === "string") {
        authFlow.set({ resetCodeError: caught.message });
        router.back();
        return;
      }

      const byField = fieldErrors(caught);

      if (byField.password) {
        setFieldError("password", { message: byField.password });
        return;
      }

      setError(errorMessage(caught, "Your password could not be changed. Please try again."));
    }
  });

  return (
    <AuthCard>
      <BackLink onPress={() => (router.canGoBack() ? router.back() : router.replace("/forgot-password"))} />

      <View style={styles.form}>
        <View style={styles.heading}>
          <AuthTitle>Set a new password</AuthTitle>
          <Subtitle>Create a new password for your account. You will use it the next time you sign in.</Subtitle>
        </View>

        <FormAlert message={error} />

        <Controller
          control={control}
          name="password"
          render={({ field }) => (
            <TextField
              label="Password"
              placeholder="Enter new password"
              secret
              value={field.value}
              onChangeText={field.onChange}
              onBlur={field.onBlur}
              error={errors.password?.message}
              autoComplete="new-password"
              textContentType="newPassword"
              autoFocus
              editable={!isSubmitting}
              testID="reset-password"
            />
          )}
        />

        <Controller
          control={control}
          name="password_confirmation"
          render={({ field }) => (
            <TextField
              label="Confirm Password"
              placeholder="Re-enter new password"
              secret
              value={field.value}
              onChangeText={field.onChange}
              onBlur={field.onBlur}
              error={errors.password_confirmation?.message}
              autoComplete="new-password"
              textContentType="newPassword"
              returnKeyType="done"
              onSubmitEditing={() => submit()}
              editable={!isSubmitting}
              testID="reset-password-confirmation"
            />
          )}
        />

        <AuthButton
          label="Update Password"
          onPress={() => submit()}
          disabled={!isValid}
          loading={isSubmitting}
          testID="reset-submit"
        />
      </View>
    </AuthCard>
  );
}

const styles = StyleSheet.create({
  form: { gap: 18 },
  heading: { gap: 6 },
});
