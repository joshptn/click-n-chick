import { useState } from "react";
import { Pressable, StyleSheet, View } from "react-native";
import { router } from "expo-router";
import { useQuery } from "@tanstack/react-query";

import { ApiError } from "../../api/client";
import type { Channel } from "../../api/auth";
import { CHANNELS_KEY, accountApi, type AccountUser, type CodeRequest } from "../../api/account";
import { useCooldown } from "../../hooks/useCooldown";
import { PASSWORD_RULES, passwordProblem } from "../../lib/passwordRules";
import { endSession } from "../../lib/session";
import { toast } from "../../lib/toast";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { AuthButton } from "../auth/AuthButton";
import { CodeInput } from "../auth/CodeInput";
import { FormAlert } from "../auth/FormAlert";
import { TextField } from "../auth/TextField";
import { IconCheck, IconLock } from "../icons";
import { Sheet } from "./Sheet";

const EMPTY = { current: "", next: "", confirm: "" };

function messageOf(error: unknown, fallback: string) {
  return error instanceof Error && error.message ? error.message : fallback;
}

/**
 * Change the password (BR-33), the web's PasswordChangeCard: the three fields,
 * then a code to a verified channel confirms it. Success ends every session,
 * this phone's included, so the customer signs in again with the new one.
 */
export function PasswordSection({ user }: { user: AccountUser }) {
  const [form, setForm] = useState(EMPTY);
  const [error, setError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);

  const [pending, setPending] = useState<CodeRequest | null>(null);
  const [code, setCode] = useState("");
  const [codeError, setCodeError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const cooldown = useCooldown(0);

  const channels = useQuery({
    queryKey: CHANNELS_KEY,
    queryFn: ({ signal }) => accountApi.channels(signal),
    staleTime: 5 * 60 * 1000,
  });

  /** A channel this account has verified and the server can deliver on. */
  const usableChannel = (): Channel | null => {
    const deliverable = (channels.data ?? []).filter((entry) => entry.available).map((entry) => entry.channel);

    if (user.email_verified_at && deliverable.includes("email")) return "email";
    if (user.phone_verified_at && deliverable.includes("sms")) return "sms";

    return null;
  };

  const set = (key: keyof typeof EMPTY) => (value: string) => {
    setForm((previous) => ({ ...previous, [key]: value }));
    setError(null);
  };

  const sendCode = async (channel: Channel) => {
    const answer = await accountApi.requestPasswordCode(channel);
    setPending(answer);
    cooldown.restart(answer.resend_available_in ?? 60);
  };

  const submit = async () => {
    if (form.next !== form.confirm) {
      setError("The new passwords do not match.");
      return;
    }

    const problem = passwordProblem(form.next);
    if (problem) {
      setError(problem);
      return;
    }

    const channel = usableChannel();
    if (!channel) {
      setError("We have no verified way to reach you, so the password can't be changed here yet.");
      return;
    }

    setSending(true);

    try {
      setCode("");
      setCodeError(null);
      await sendCode(channel);
    } catch (failure) {
      setError(messageOf(failure, "We could not send a confirmation code."));
    } finally {
      setSending(false);
    }
  };

  const resend = async () => {
    if (!pending) return;

    try {
      await sendCode(pending.channel);
      toast.info("A new code is on its way");
    } catch (failure) {
      setCodeError(messageOf(failure, "We could not send a new code."));
    }
  };

  const confirm = async () => {
    setSaving(true);
    setCodeError(null);

    try {
      await accountApi.changePassword({
        current_password: form.current,
        password: form.next,
        password_confirmation: form.confirm,
        code,
      });

      setPending(null);
      setForm(EMPTY);
      toast.success("Password updated", "Please sign in again with your new password.");

      // Every sign-in was ended on the server, this one included.
      await endSession();
      router.replace("/sign-in");
    } catch (failure) {
      const fields = failure instanceof ApiError ? (failure.payload.errors as Record<string, string[]> | undefined) : undefined;

      // A wrong current password is fixed in the form, not in the code.
      if (fields?.current_password || fields?.password) {
        setPending(null);
        setError(fields.current_password?.[0] ?? fields.password?.[0] ?? null);
        return;
      }

      setCodeError(messageOf(failure, "That code is not correct."));
      setCode("");
    } finally {
      setSaving(false);
    }
  };

  const met = PASSWORD_RULES.filter((rule) => rule.test(form.next)).length;
  const filled = form.current !== "" && form.next !== "" && form.confirm !== "";

  return (
    <View style={styles.section}>
      <TextField
        label="Current Password"
        secret
        value={form.current}
        onChangeText={set("current")}
        placeholder="Enter current password"
        autoComplete="current-password"
        textContentType="password"
        editable={!sending}
        testID="current-password"
      />

      <View style={styles.group}>
        <TextField
          label="New Password"
          secret
          value={form.next}
          onChangeText={set("next")}
          placeholder="Enter new password"
          autoComplete="new-password"
          textContentType="newPassword"
          editable={!sending}
          testID="new-password"
        />

        <View style={styles.meter} accessibilityLabel={`${met} of ${PASSWORD_RULES.length} password rules met`}>
          {PASSWORD_RULES.map((rule, index) => (
            <View
              key={rule.label}
              style={[styles.bar, index < met && { backgroundColor: met === PASSWORD_RULES.length ? colors.success : colors.orange }]}
            />
          ))}
        </View>

        <AppText family="display" size={12.5} color={colors.authLabel}>
          Your password must contain:
        </AppText>
        {PASSWORD_RULES.map((rule) => {
          const ok = rule.test(form.next);

          return (
            <View key={rule.label} style={styles.rule}>
              <View style={[styles.tick, ok && styles.tickOn]}>{ok && <IconCheck size={11} color={colors.white} strokeWidth={3} />}</View>
              <AppText family="display" size={12.5} color={ok ? colors.success : colors.authHint}>
                {rule.label.charAt(0).toUpperCase() + rule.label.slice(1)}
              </AppText>
            </View>
          );
        })}
      </View>

      <TextField
        label="Confirm Password"
        secret
        value={form.confirm}
        onChangeText={set("confirm")}
        placeholder="Repeat new password"
        autoComplete="new-password"
        textContentType="newPassword"
        editable={!sending}
        testID="confirm-password"
      />

      <FormAlert message={error} />

      <AuthButton label="Update Password" onPress={submit} disabled={!filled} loading={sending} testID="update-password" />
      <AppText family="display" size={11.5} color={colors.authHint} style={styles.center}>
        We&apos;ll send a confirmation code before the change is applied.
      </AppText>

      <Sheet
        visible={pending !== null}
        title="Confirm it's you"
        subtitle={`We sent a 6-digit code to your ${pending?.channel === "email" ? "email" : "phone"}${pending?.identifier ? ` ${pending.identifier}` : ""}.`}
        onClose={() => setPending(null)}
        busy={saving}
        testID="password-code-sheet"
      >
        <CodeInput value={code} onChange={(value) => {
          setCode(value);
          setCodeError(null);
        }} testID="password-code" />
        <FormAlert message={codeError} />
        <View style={styles.warning}>
          <IconLock size={15} color="#b8820a" strokeWidth={2} />
          <AppText family="display" size={12} color="#7a5620" style={styles.flex}>
            Changing your password signs you out everywhere, including this phone.
          </AppText>
        </View>
        <AuthButton label="Update Password" onPress={confirm} disabled={code.length < 6} loading={saving} testID="confirm-password-code" />
        <Pressable accessibilityRole="button" disabled={cooldown.seconds > 0 || saving} onPress={resend} style={styles.resend}>
          <AppText family="display" weight="medium" size={13} color={cooldown.seconds > 0 ? colors.authHint : colors.brand600}>
            {cooldown.seconds > 0 ? `Send a new code in ${cooldown.seconds}s` : "Send a new code"}
          </AppText>
        </Pressable>
      </Sheet>
    </View>
  );
}

const styles = StyleSheet.create({
  section: { gap: 16 },
  group: { gap: 8 },
  flex: { flex: 1 },
  center: { textAlign: "center" },
  meter: { flexDirection: "row", gap: 8, marginTop: 4 },
  bar: { flex: 1, height: 5, borderRadius: 3, backgroundColor: "#e5e7eb" },
  rule: { flexDirection: "row", alignItems: "center", gap: 8 },
  tick: {
    width: 16,
    height: 16,
    borderRadius: 8,
    borderWidth: 1.5,
    borderColor: "#d1d5db",
    alignItems: "center",
    justifyContent: "center",
  },
  tickOn: { backgroundColor: colors.success, borderColor: colors.success },
  warning: {
    flexDirection: "row",
    gap: 8,
    padding: 12,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: "#ffe6a8",
    backgroundColor: "#fff9e8",
  },
  resend: { alignSelf: "center", paddingVertical: 6 },
});

export default PasswordSection;
