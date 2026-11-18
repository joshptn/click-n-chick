import { useState } from "react";
import { ActivityIndicator, Pressable, StyleSheet, View } from "react-native";
import { useQuery, useQueryClient } from "@tanstack/react-query";

import type { Channel } from "../../api/auth";
import { CHANNELS_KEY, accountApi, type AccountUser, type CodeRequest } from "../../api/account";
import { useCooldown } from "../../hooks/useCooldown";
import { adoptUser } from "../../hooks/useProfile";
import { toast } from "../../lib/toast";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { AuthButton } from "../auth/AuthButton";
import { CodeInput } from "../auth/CodeInput";
import { FormAlert } from "../auth/FormAlert";
import { TextField } from "../auth/TextField";
import { IconAlertTriangle, IconCheck, IconDeviceMobileMessage, IconMail, IconShieldCheck, IconShieldLock } from "../icons";
import { Sheet } from "./Sheet";

const META: Record<Channel, { label: string; describe: string; noun: string; icon: typeof IconMail }> = {
  sms: { label: "Text message", describe: "We text you a 6-digit code each time you sign in.", noun: "phone", icon: IconDeviceMobileMessage },
  email: { label: "Email", describe: "We email you a 6-digit code each time you sign in.", noun: "email", icon: IconMail },
};

function messageOf(error: unknown, fallback: string) {
  return error instanceof Error && error.message ? error.message : fallback;
}

/**
 * Two-step verification (UC-PROF-006 / BR-31), the web's TwoFactorCard:
 * choose where codes go, prove the code arrived to turn it on (or move it),
 * and the account password to turn it off. The answer to each change carries
 * the account, so the screen shows the server's state, never a guess.
 */
export function TwoFactorSection({ user }: { user: AccountUser }) {
  const queryClient = useQueryClient();
  const enabled = Boolean(user.two_factor_enabled);
  const active = user.two_factor_channel ?? null;

  const [selected, setSelected] = useState<Channel | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);

  const [pending, setPending] = useState<CodeRequest | null>(null);
  const [code, setCode] = useState("");
  const [codeError, setCodeError] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);
  const cooldown = useCooldown(0);

  const [disabling, setDisabling] = useState(false);
  const [password, setPassword] = useState("");
  const [passwordError, setPasswordError] = useState<string | null>(null);
  const [disableBusy, setDisableBusy] = useState(false);

  const channels = useQuery({
    queryKey: CHANNELS_KEY,
    queryFn: ({ signal }) => accountApi.channels(signal),
    staleTime: 5 * 60 * 1000,
  });

  const requestCode = async (channel: Channel, resend = false) => {
    setSending(true);
    setError(null);
    setCodeError(null);

    try {
      const answer = await accountApi.requestTwoFactorCode(channel);
      setPending(answer);
      setCode("");
      cooldown.restart(answer.resend_available_in ?? 60);
      if (resend) toast.info("A new code is on its way");
    } catch (failure) {
      if (resend) setCodeError(messageOf(failure, "We could not send a new code."));
      else setError(messageOf(failure, "We could not send a code. Try again."));
    } finally {
      setSending(false);
    }
  };

  const confirm = async () => {
    setConfirming(true);
    setCodeError(null);

    try {
      const answer = await accountApi.confirmTwoFactor(code);
      if (answer.user) adoptUser(queryClient, answer.user);

      setPending(null);
      setSelected(null);
      toast.success("Two-step verification is on", answer.message);
    } catch (failure) {
      setCodeError(messageOf(failure, "That code is not correct."));
      setCode("");
    } finally {
      setConfirming(false);
    }
  };

  const closeDisable = () => {
    setDisabling(false);
    setPassword("");
    setPasswordError(null);
  };

  const disable = async () => {
    setDisableBusy(true);
    setPasswordError(null);

    try {
      const answer = await accountApi.disableTwoFactor(password);
      if (answer.user) adoptUser(queryClient, answer.user);

      closeDisable();
      toast.success("Two-step verification is off", answer.message);
    } catch (failure) {
      setPassword("");
      setPasswordError(messageOf(failure, "Could not turn two-step verification off."));
    } finally {
      setDisableBusy(false);
    }
  };

  const activeMeta = active ? META[active] : null;

  return (
    <View style={styles.section}>
      <View style={styles.head}>
        <View style={[styles.shield, enabled && styles.shieldOn]}>
          <IconShieldLock size={20} color={enabled ? colors.success : colors.authHint} strokeWidth={1.9} />
        </View>
        <View style={styles.flex}>
          <View style={styles.titleRow}>
            <AppText family="display" weight="semibold" size={15} color={colors.authInk}>
              Two-step verification
            </AppText>
            {enabled && (
              <View style={styles.on}>
                <IconShieldCheck size={12} color={colors.success} strokeWidth={2.4} />
                <AppText family="display" weight="semibold" size={11} color={colors.success}>
                  On
                </AppText>
              </View>
            )}
          </View>
          <AppText family="display" size={12.5} color={colors.authMuted}>
            {enabled
              ? `Codes go to your ${activeMeta?.noun ?? "chosen channel"} every time you sign in. Pick the other one below to move them.`
              : "Add a second check at sign-in, so your password alone is not enough to get in."}
          </AppText>
        </View>
      </View>

      {channels.isLoading ? (
        <View style={styles.loading}>
          <ActivityIndicator color={colors.orange} size="small" />
          <AppText family="display" size={12.5} color={colors.authMuted}>
            Checking what we can send...
          </AppText>
        </View>
      ) : (
        <View style={styles.options}>
          {(channels.data ?? []).map((entry) => {
            const meta = META[entry.channel];
            if (!meta) return null;

            const Icon = meta.icon;
            const isSelected = selected === entry.channel;

            return (
              <Pressable
                key={entry.channel}
                accessibilityRole="radio"
                accessibilityState={{ selected: isSelected, disabled: !entry.available }}
                disabled={!entry.available || sending}
                onPress={() => setSelected(entry.channel)}
                style={[styles.option, isSelected && styles.optionSelected, !entry.available && styles.optionOff]}
                testID={`two-factor-${entry.channel}`}
              >
                <View style={[styles.optionIcon, isSelected && styles.optionIconSelected]}>
                  <Icon size={17} color={isSelected ? colors.white : colors.authMuted} strokeWidth={1.9} />
                </View>
                <View style={styles.flex}>
                  <AppText family="display" weight="semibold" size={13} color={colors.authInk}>
                    {meta.label}
                  </AppText>
                  <AppText family="display" size={11.5} color={colors.authMuted}>
                    {entry.available ? meta.describe : (entry.reason ?? "Not available right now.")}
                  </AppText>
                </View>
                {isSelected && <IconCheck size={17} color={colors.orange} strokeWidth={2.4} />}
              </Pressable>
            );
          })}
        </View>
      )}

      <FormAlert message={error} />

      {enabled && selected !== null && selected === active ? (
        <AppText family="display" size={12} color={colors.authMuted}>
          This is already where your codes go.
        </AppText>
      ) : null}

      <View style={styles.actions}>
        <Pressable
          accessibilityRole="button"
          accessibilityState={{ disabled: !selected || sending, busy: sending }}
          disabled={!selected || sending}
          onPress={() => selected && requestCode(selected)}
          style={[styles.send, (!selected || sending) && styles.sendOff]}
          testID="two-factor-send"
        >
          {sending && !pending ? (
            <ActivityIndicator color={colors.white} size="small" />
          ) : (
            <AppText family="display" weight="semibold" size={13} color={colors.white}>
              Send me a code
            </AppText>
          )}
        </Pressable>

        {enabled && (
          <Pressable accessibilityRole="button" onPress={() => setDisabling(true)} style={styles.turnOff} testID="two-factor-off">
            <AppText family="display" weight="semibold" size={13} color={colors.danger}>
              Turn off
            </AppText>
          </Pressable>
        )}
      </View>

      <Sheet
        visible={pending !== null}
        title="Enter your code"
        subtitle={`We sent a 6-digit code to your ${pending ? META[pending.channel].noun : "phone"}${pending?.identifier ? ` ${pending.identifier}` : ""}. It expires in ${pending?.expires_in_minutes ?? 10} minutes.`}
        onClose={() => setPending(null)}
        busy={confirming}
        testID="two-factor-code-sheet"
      >
        <CodeInput
          value={code}
          onChange={(value) => {
            setCode(value);
            setCodeError(null);
          }}
          testID="two-factor-code"
        />
        <FormAlert message={codeError} />
        <AuthButton label={enabled ? "Confirm" : "Confirm and turn on"} onPress={confirm} disabled={code.length < 6} loading={confirming} testID="two-factor-confirm" />
        <Pressable
          accessibilityRole="button"
          disabled={cooldown.seconds > 0 || sending || confirming}
          onPress={() => pending && requestCode(pending.channel, true)}
          style={styles.resend}
        >
          <AppText family="display" weight="medium" size={13} color={cooldown.seconds > 0 ? colors.authHint : colors.brand600}>
            {cooldown.seconds > 0 ? `Send a new code in ${cooldown.seconds}s` : "Send a new code"}
          </AppText>
        </Pressable>
      </Sheet>

      <Sheet
        visible={disabling}
        title="Turn off two-step verification?"
        onClose={closeDisable}
        busy={disableBusy}
        testID="two-factor-off-sheet"
      >
        <View style={styles.warning}>
          <IconAlertTriangle size={17} color="#e8890c" strokeWidth={1.9} />
          <AppText family="display" size={12.5} color="#7a5620" style={styles.flex}>
            Your password will be the only thing protecting your account. Anyone who learns it can sign in.
          </AppText>
        </View>
        <TextField
          label="Account password"
          secret
          value={password}
          onChangeText={(value) => {
            setPassword(value);
            setPasswordError(null);
          }}
          error={passwordError ?? undefined}
          autoComplete="current-password"
          textContentType="password"
          autoFocus
          testID="two-factor-off-password"
        />
        <AuthButton label="Turn it off" onPress={disable} disabled={password === ""} loading={disableBusy} testID="two-factor-off-confirm" />
      </Sheet>
    </View>
  );
}

const styles = StyleSheet.create({
  section: { gap: 14 },
  flex: { flex: 1 },
  head: { flexDirection: "row", gap: 12, alignItems: "flex-start" },
  shield: {
    width: 42,
    height: 42,
    borderRadius: 21,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "#f4f1ec",
  },
  shieldOn: { backgroundColor: "#e9f8ee" },
  titleRow: { flexDirection: "row", alignItems: "center", gap: 8 },
  on: {
    flexDirection: "row",
    alignItems: "center",
    gap: 3,
    paddingHorizontal: 8,
    paddingVertical: 1,
    borderRadius: 999,
    backgroundColor: "#e9f8ee",
  },
  loading: { flexDirection: "row", alignItems: "center", gap: 8 },
  options: { flexDirection: "row", gap: 10 },
  option: {
    flex: 1,
    gap: 8,
    padding: 12,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: colors.fieldBorder,
    backgroundColor: colors.white,
  },
  optionSelected: { borderColor: colors.orange, backgroundColor: colors.brand50 },
  optionOff: { opacity: 0.55 },
  optionIcon: {
    width: 32,
    height: 32,
    borderRadius: 16,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "#f4f1ec",
  },
  optionIconSelected: { backgroundColor: colors.orange },
  actions: { flexDirection: "row", alignItems: "center", gap: 10 },
  send: {
    minWidth: 140,
    height: 38,
    paddingHorizontal: 16,
    borderRadius: 10,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.orange,
  },
  sendOff: { backgroundColor: colors.orangeSoft },
  turnOff: {
    height: 38,
    paddingHorizontal: 16,
    borderRadius: 10,
    alignItems: "center",
    justifyContent: "center",
    borderWidth: 1,
    borderColor: "#fbd5d4",
    backgroundColor: colors.white,
  },
  resend: { alignSelf: "center", paddingVertical: 6 },
  warning: {
    flexDirection: "row",
    gap: 8,
    padding: 12,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: "#ffe3bf",
    backgroundColor: "#fff8ef",
  },
});

export default TwoFactorSection;
