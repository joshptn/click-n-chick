import { useState } from "react";
import { StyleSheet, View } from "react-native";
import { router } from "expo-router";
import { Controller, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";

import { authApi, channelFor } from "../../api/auth";
import { AppText } from "../../components/AppText";
import { AuthButton } from "../../components/auth/AuthButton";
import { AuthCard } from "../../components/auth/AuthCard";
import { AuthTitle, Eyebrow, PromptRow, QuietLink, TextLink } from "../../components/auth/AuthText";
import { Checkbox } from "../../components/auth/Checkbox";
import { FormAlert } from "../../components/auth/FormAlert";
import { TextField } from "../../components/auth/TextField";
import { authFlow } from "../../lib/authFlow";
import { passwordProblem } from "../../lib/passwordRules";
import { errorMessage, fieldErrors } from "../../lib/signIn";
import { colors } from "../../theme/colors";

const schema = z
  .object({
    identifier: z.string().trim().min(1, "Enter your email or phone number."),
    first_name: z.string().trim().min(1, "Enter your first name."),
    last_name: z.string().trim().min(1, "Enter your last name."),
    password: z.string().superRefine((value, ctx) => {
      const problem = passwordProblem(value);
      if (problem) ctx.addIssue({ code: "custom", message: problem });
    }),
    password_confirmation: z.string().min(1, "Confirm your password."),
    terms: z.boolean().refine((value) => value, "Agree to the terms to continue."),
  })
  .refine((values) => values.password === values.password_confirmation, {
    path: ["password_confirmation"],
    message: "The two passwords do not match.",
  });

type Values = z.input<typeof schema>;

const API_FIELDS = ["identifier", "first_name", "last_name", "password"] as const;

/**
 * Sign Up (design of 2026-11-16). One identifier, an email address or a phone
 * number; no account exists until the code sent to it is confirmed.
 */
export default function SignUp() {
  const [notice] = useState(() => authFlow.takeNotice());
  const [error, setError] = useState<string | null>(null);

  const {
    control,
    handleSubmit,
    setError: setFieldError,
    formState: { isValid, isSubmitting, errors },
  } = useForm<Values>({
    resolver: zodResolver(schema),
    mode: "onTouched",
    defaultValues: { identifier: "", first_name: "", last_name: "", password: "", password_confirmation: "", terms: false },
  });

  const submit = handleSubmit(async (values) => {
    setError(null);

    const identifier = values.identifier.trim();

    try {
      const result = await authApi.register({
        identifier,
        first_name: values.first_name.trim(),
        last_name: values.last_name.trim(),
        password: values.password,
        password_confirmation: values.password_confirmation,
      });

      authFlow.set({
        identifier,
        channel: result.verification_channel ?? channelFor(identifier),
        masked: result.identifier ?? identifier,
        resendIn: result.resend_available_in ?? 60,
      });
      router.push("/verify");
    } catch (caught) {
      const byField = fieldErrors(caught);
      let shown = false;

      for (const field of API_FIELDS) {
        if (byField[field]) {
          setFieldError(field, { message: byField[field] });
          shown = true;
        }
      }

      if (!shown) setError(errorMessage(caught, "We could not create your account. Please try again."));
    }
  });

  return (
    <AuthCard tab="Sign Up">
      <View style={styles.form}>
        <View style={styles.heading}>
          <Eyebrow>Welcome to Click n Chick</Eyebrow>
          <AuthTitle>Sign Up</AuthTitle>
        </View>

        <FormAlert message={notice} tone="info" />
        <FormAlert message={error} />

        <Controller
          control={control}
          name="identifier"
          render={({ field }) => (
            <TextField
              label="Email or phone number"
              placeholder="09192342131 or you@email.com"
              value={field.value}
              onChangeText={field.onChange}
              onBlur={field.onBlur}
              error={errors.identifier?.message}
              autoCapitalize="none"
              autoCorrect={false}
              autoComplete="username"
              editable={!isSubmitting}
              testID="sign-up-identifier"
            />
          )}
        />

        <View style={styles.names}>
          <View style={styles.name}>
            <Controller
              control={control}
              name="first_name"
              render={({ field }) => (
                <TextField
                  label="First Name"
                  placeholder="John"
                  value={field.value}
                  onChangeText={field.onChange}
                  onBlur={field.onBlur}
                  error={errors.first_name?.message}
                  autoCapitalize="words"
                  autoComplete="given-name"
                  editable={!isSubmitting}
                  testID="sign-up-first-name"
                />
              )}
            />
          </View>
          <View style={styles.name}>
            <Controller
              control={control}
              name="last_name"
              render={({ field }) => (
                <TextField
                  label="Last Name"
                  placeholder="Doe"
                  value={field.value}
                  onChangeText={field.onChange}
                  onBlur={field.onBlur}
                  error={errors.last_name?.message}
                  autoCapitalize="words"
                  autoComplete="family-name"
                  editable={!isSubmitting}
                  testID="sign-up-last-name"
                />
              )}
            />
          </View>
        </View>

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
              autoComplete="new-password"
              textContentType="newPassword"
              editable={!isSubmitting}
              testID="sign-up-password"
            />
          )}
        />

        <Controller
          control={control}
          name="password_confirmation"
          render={({ field }) => (
            <TextField
              label="Confirm Password"
              placeholder="Confirm password"
              secret
              value={field.value}
              onChangeText={field.onChange}
              onBlur={field.onBlur}
              error={errors.password_confirmation?.message}
              autoComplete="new-password"
              textContentType="newPassword"
              editable={!isSubmitting}
              testID="sign-up-password-confirmation"
            />
          )}
        />

        <Controller
          control={control}
          name="terms"
          render={({ field }) => (
            <Checkbox checked={field.value} onChange={field.onChange} testID="sign-up-terms">
              <AppText family="display" size={12.5} color={colors.authMuted}>
                I agree to the Terms of Service and Privacy Policy
              </AppText>
            </Checkbox>
          )}
        />

        <AuthButton
          label="Sign Up"
          onPress={() => submit()}
          disabled={!isValid}
          loading={isSubmitting}
          testID="sign-up-submit"
        />

        <View style={styles.footer}>
          <PromptRow prompt="Already have an account?">
            <TextLink label="Sign in" onPress={() => router.replace("/sign-in")} testID="go-sign-in" />
          </PromptRow>
          <QuietLink label="Continue without an account" onPress={() => router.push("/menu")} />
        </View>
      </View>
    </AuthCard>
  );
}

const styles = StyleSheet.create({
  form: { gap: 16 },
  heading: { gap: 4, marginBottom: 2 },
  names: { flexDirection: "row", gap: 12 },
  name: { flex: 1 },
  footer: { alignItems: "center", gap: 10, marginTop: 4 },
});
