import { useState } from "react";
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { SafeAreaView } from "react-native-safe-area-context";

import { ApiError } from "../../../api/client";
import { DEVICES_KEY, accountApi, type Device } from "../../../api/account";
import { AppText } from "../../../components/AppText";
import { ScreenHeader } from "../../../components/account/ScreenHeader";
import { Sheet } from "../../../components/account/Sheet";
import { DeviceIcon, blockedReason, deviceName, useDevices } from "../../../components/account/devices";
import { AuthButton } from "../../../components/auth/AuthButton";
import { TextField } from "../../../components/auth/TextField";
import { IconShieldCheck, IconShieldOff } from "../../../components/icons";
import { endSession } from "../../../lib/session";
import { formatWhen } from "../../../lib/time";
import { toast } from "../../../lib/toast";
import { colors } from "../../../theme/colors";

function messageOf(error: unknown, fallback: string) {
  return error instanceof Error && error.message ? error.message : fallback;
}

/**
 * One device (from the user's "Logins on …" reference, 2026-11-18): where and
 * when it was last used, and its two actions. "Log out" ends its sign-ins;
 * "Trust this device" lets it manage the others, and asks for the password
 * first. The API refuses anything this phone may not do; the screen only
 * avoids offering a button that is sure to fail.
 */
export default function DeviceDetails() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const queryClient = useQueryClient();
  const { data, isLoading } = useDevices();

  const device = data?.devices.find((item) => String(item.id) === String(id)) ?? null;
  const currentTrusted = Boolean(data?.current_device_trusted);

  const [askPassword, setAskPassword] = useState(false);
  const [password, setPassword] = useState("");
  const [passwordError, setPasswordError] = useState<string | null>(null);

  const refresh = () => queryClient.invalidateQueries({ queryKey: DEVICES_KEY });

  const revoke = useMutation({
    mutationFn: (target: Device) => accountApi.revokeDevice(target.id),
    onSuccess: async (answer, target) => {
      // This phone's own sign-in has just ended.
      if (answer.current_device_revoked) {
        toast.success("Signed out", "You have been signed out on this phone.");
        await endSession();
        router.replace("/");
        return;
      }

      toast.success("Device signed out", `${deviceName(target)} will need your password to sign in again.`);
      await refresh();
      router.back();
    },
    onError: (error) => toast.error("Not signed out", messageOf(error, "That device could not be signed out.")),
  });

  const trust = useMutation({
    mutationFn: ({ target, secret }: { target: Device; secret?: string }) =>
      accountApi.setTrust(target.id, !target.is_trusted, secret),
    onSuccess: (answer) => {
      closePassword();
      toast.success("Trust updated", answer.message);
      void refresh();
    },
    onError: (error) => {
      // A wrong password is corrected in the open sheet, not in a toast.
      if (error instanceof ApiError && error.payload.code === "PASSWORD_REQUIRED") {
        setPassword("");
        setPasswordError(error.message);
        return;
      }

      closePassword();
      toast.error("Trust not changed", messageOf(error, "Trust could not be updated for that device."));
    },
  });

  function closePassword() {
    setAskPassword(false);
    setPassword("");
    setPasswordError(null);
  }

  if (isLoading || !device) {
    return (
      <SafeAreaView style={styles.safe} edges={["top", "left", "right", "bottom"]}>
        <ScreenHeader title="Device" />
        <View style={styles.center1}>
          {isLoading ? (
            <ActivityIndicator color={colors.orange} />
          ) : (
            <AppText family="display" size={14} color={colors.authMuted}>
              This device is no longer on your account.
            </AppText>
          )}
        </View>
      </SafeAreaView>
    );
  }

  const blocked = blockedReason(device, currentTrusted);
  const busy = revoke.isPending || trust.isPending;

  const logOut = () =>
    Alert.alert(
      device.is_current ? "Log out of this phone?" : "Log out of that device?",
      device.is_current
        ? "You will be signed out of the app straight away. Your other devices stay signed in."
        : `${deviceName(device)} will be signed out straight away and will need your password to sign in again.`,
      [
        { text: "Cancel", style: "cancel" },
        { text: "Log out", style: "destructive", onPress: () => revoke.mutate(device) },
      ]
    );

  // Granting trust asks for the password; removing it never does.
  const toggleTrust = () => (device.is_trusted ? trust.mutate({ target: device }) : setAskPassword(true));

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right", "bottom"]}>
      <ScreenHeader title="" />

      <ScrollView contentContainerStyle={styles.content}>
        <View style={styles.titleRow}>
          <View style={styles.iconCircle}>
            <DeviceIcon platform={device.platform} size={26} color={colors.authInk} />
          </View>
          <View style={styles.flex}>
            <AppText family="display" weight="semibold" size={22} color={colors.authInk}>
              {deviceName(device)}
            </AppText>
            <View style={styles.badges}>
              {device.is_current && <Badge text="This device" tone="green" />}
              {device.is_trusted && <Badge text="Trusted" tone="blue" />}
              {!device.is_active && <Badge text="Signed out" tone="grey" />}
            </View>
          </View>
        </View>

        <AppText family="display" size={13.5} color={colors.authMuted}>
          If you don&apos;t recognise this login, log it out. It will need your password to get back in.
        </AppText>

        <View style={styles.card}>
          <AppText family="display" weight="medium" size={18} color={colors.authInk}>
            {device.location ?? "Unknown location"}
          </AppText>
          <AppText family="display" size={14} color={colors.authMuted}>
            Last active: {formatWhen(device.last_seen_at) || "Unknown"}
          </AppText>
          <AppText family="display" size={12.5} color={colors.authHint}>
            First signed in: {formatWhen(device.first_seen_at) || "Unknown"}
            {device.is_active
              ? ` • ${device.active_session_count} active sign-in${device.active_session_count === 1 ? "" : "s"}`
              : ""}
          </AppText>

          <Pressable
            accessibilityRole="button"
            accessibilityState={{ disabled: !device.is_active || Boolean(blocked) || busy, busy: revoke.isPending }}
            disabled={!device.is_active || Boolean(blocked) || busy}
            onPress={logOut}
            style={({ pressed }) => [styles.logOut, (!device.is_active || blocked || pressed) && styles.dim]}
            testID="device-log-out"
          >
            {revoke.isPending ? (
              <ActivityIndicator color={colors.authInk} />
            ) : (
              <AppText family="display" weight="medium" size={15} color={colors.authInk}>
                {device.is_active ? "Log out" : "Already signed out"}
              </AppText>
            )}
          </Pressable>
        </View>

        <View style={styles.card}>
          <View style={styles.trustHead}>
            {device.is_trusted ? (
              <IconShieldCheck size={20} color="#1c7ed6" strokeWidth={2} />
            ) : (
              <IconShieldOff size={20} color={colors.authHint} strokeWidth={2} />
            )}
            <AppText family="display" weight="semibold" size={15} color={colors.authInk}>
              {device.is_trusted ? "Trusted device" : "Not trusted"}
            </AppText>
          </View>
          <AppText family="display" size={12.5} color={colors.authMuted}>
            A trusted device can sign your other devices out. Trust only phones and computers that are yours.
          </AppText>

          <Pressable
            accessibilityRole="button"
            accessibilityState={{ disabled: Boolean(blocked) || busy, busy: trust.isPending && !askPassword }}
            disabled={Boolean(blocked) || busy}
            onPress={toggleTrust}
            style={({ pressed }) => [styles.trust, device.is_trusted && styles.untrust, (blocked || pressed) && styles.dim]}
            testID="device-trust"
          >
            {trust.isPending && !askPassword ? (
              <ActivityIndicator color={device.is_trusted ? colors.authInk : colors.white} />
            ) : (
              <AppText family="display" weight="medium" size={15} color={device.is_trusted ? colors.authInk : colors.white}>
                {device.is_trusted ? "Remove trust" : "Trust this device"}
              </AppText>
            )}
          </Pressable>
        </View>

        {blocked ? (
          <AppText family="display" size={12} color={colors.authHint} style={styles.centerText}>
            {blocked}
          </AppText>
        ) : null}
      </ScrollView>

      <Sheet
        visible={askPassword}
        title="Trust this device?"
        subtitle="Confirm your password, so someone holding a stolen sign-in can't give themselves this power."
        onClose={closePassword}
        busy={trust.isPending}
        testID="trust-sheet"
      >
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
          testID="trust-password"
        />
        <AuthButton
          label="Trust this device"
          onPress={() => trust.mutate({ target: device, secret: password })}
          disabled={password === ""}
          loading={trust.isPending}
          testID="trust-confirm"
        />
      </Sheet>
    </SafeAreaView>
  );
}

function Badge({ text, tone }: { text: string; tone: "green" | "blue" | "grey" }) {
  const palette = {
    green: { bg: "#e9f8ee", fg: colors.success },
    blue: { bg: "#f0f7ff", fg: "#1c7ed6" },
    grey: { bg: "#f4f1ec", fg: colors.authMuted },
  }[tone];

  return (
    <View style={[styles.badge, { backgroundColor: palette.bg }]}>
      <AppText family="display" weight="semibold" size={11} color={palette.fg}>
        {text}
      </AppText>
    </View>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  flex: { flex: 1 },
  center1: { flex: 1, alignItems: "center", justifyContent: "center", paddingHorizontal: 32 },
  centerText: { textAlign: "center" },
  content: { paddingHorizontal: 20, paddingTop: 4, paddingBottom: 32, gap: 16 },
  titleRow: { flexDirection: "row", alignItems: "center", gap: 14 },
  iconCircle: {
    width: 54,
    height: 54,
    borderRadius: 27,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: "#e5e7eb",
  },
  badges: { flexDirection: "row", flexWrap: "wrap", gap: 6, marginTop: 4 },
  badge: { paddingHorizontal: 10, paddingVertical: 2, borderRadius: 999 },
  card: {
    gap: 6,
    padding: 18,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: "#e5e7eb",
    backgroundColor: colors.white,
  },
  logOut: {
    marginTop: 12,
    minHeight: 48,
    borderRadius: 999,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "#f3f4f6",
  },
  trustHead: { flexDirection: "row", alignItems: "center", gap: 8 },
  trust: {
    marginTop: 10,
    minHeight: 48,
    borderRadius: 999,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.orange,
  },
  untrust: { backgroundColor: "#f3f4f6" },
  dim: { opacity: 0.6 },
});
