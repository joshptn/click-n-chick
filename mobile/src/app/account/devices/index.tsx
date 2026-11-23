import { Alert, Pressable, RefreshControl, ScrollView, StyleSheet, View } from "react-native";
import { router } from "expo-router";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { SafeAreaView } from "react-native-safe-area-context";

import { DEVICES_KEY, accountApi, type Device } from "../../../api/account";
import { AppText } from "../../../components/AppText";
import { ScreenHeader } from "../../../components/account/ScreenHeader";
import { DeviceIcon, deviceLine, deviceName, useDevices } from "../../../components/account/devices";
import { IconChevronRight, IconLogout, IconShieldCheck } from "../../../components/icons";
import { toast } from "../../../lib/toast";
import { colors } from "../../../theme/colors";

/**
 * Manage Devices (FR-01.13), from the user's "login activity" reference of
 * 2026-11-18: every device signed in to the account with its approximate
 * place and when it was last used. Tapping one opens its page, where it can
 * be signed out or trusted.
 */
export default function Devices() {
  const queryClient = useQueryClient();
  const { data, isLoading, isError, isRefetching, refetch } = useDevices();

  const devices = data?.devices ?? [];
  const current = devices.find((device) => device.is_current) ?? null;
  const others = devices.filter((device) => !device.is_current);
  const trusted = Boolean(data?.current_device_trusted);
  const otherActive = others.filter((device) => device.is_active).length;

  const sweep = useMutation({
    mutationFn: () => accountApi.signOutOthers(),
    onSuccess: (answer) => {
      toast.success("Other devices signed out", answer.message);
      void queryClient.invalidateQueries({ queryKey: DEVICES_KEY });
    },
    onError: (error) =>
      toast.error("Not signed out", error instanceof Error ? error.message : "Your other devices could not be signed out."),
  });

  const confirmSweep = () =>
    Alert.alert(
      "Sign out all other devices?",
      "Every device except this phone will be signed out straight away, including trusted ones. Each will need your password to sign in again.",
      [
        { text: "Cancel", style: "cancel" },
        { text: "Sign out all", style: "destructive", onPress: () => sweep.mutate() },
      ]
    );

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right", "bottom"]}>
      <ScreenHeader title="Manage Devices" />

      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={() => void refetch()} colors={[colors.orange]} />}
      >
        <AppText family="display" weight="semibold" size={22} color={colors.authInk}>
          Login activity
        </AppText>
        <AppText family="display" size={13.5} color={colors.authMuted}>
          Your account is signed in on these devices. If you don&apos;t recognise one, sign it out — it will need your
          password to get back in.
        </AppText>

        {isLoading && Array.from({ length: 3 }, (_, index) => <View key={index} style={styles.skeleton} />)}

        {isError && !data && (
          <Pressable accessibilityRole="button" onPress={() => void refetch()} style={styles.failed}>
            <AppText family="display" size={13} color={colors.danger}>
              Your devices could not be loaded. Tap to try again.
            </AppText>
          </Pressable>
        )}

        {data && !trusted && devices.length > 1 && (
          <View style={styles.banner} testID="untrusted-banner">
            <IconShieldCheck size={18} color="#e8890c" strokeWidth={1.9} />
            <AppText family="display" size={12.5} color="#7a5620" style={styles.flex}>
              <AppText family="display" weight="semibold" size={12.5} color="#7a5620">
                This phone is not trusted yet.
              </AppText>{" "}
              Open it below and trust it to manage your other devices from here.
            </AppText>
          </View>
        )}

        {current && (
          <>
            <AppText family="display" weight="semibold" size={15} color={colors.authInk} style={styles.heading}>
              This device
            </AppText>
            <View style={styles.list}>
              <DeviceRow device={current} last />
            </View>
          </>
        )}

        {others.length > 0 && (
          <>
            <AppText family="display" weight="semibold" size={15} color={colors.authInk} style={styles.heading}>
              Logins on other devices
            </AppText>
            <View style={styles.list}>
              {others.map((device, index) => (
                <DeviceRow key={device.id} device={device} last={index === others.length - 1} />
              ))}
            </View>
          </>
        )}

        {data && devices.length === 0 && (
          <AppText family="display" size={13.5} color={colors.authMuted} style={styles.center}>
            No devices recorded yet.
          </AppText>
        )}

        {otherActive > 0 && (
          <View style={styles.sweepBox}>
            <Pressable
              accessibilityRole="button"
              accessibilityState={{ disabled: !trusted || sweep.isPending, busy: sweep.isPending }}
              disabled={!trusted || sweep.isPending}
              onPress={confirmSweep}
              style={({ pressed }) => [styles.sweep, (!trusted || pressed || sweep.isPending) && styles.dim]}
              testID="sign-out-others"
            >
              <IconLogout size={18} color={colors.danger} strokeWidth={2} />
              <AppText family="display" weight="medium" size={14} color={colors.danger}>
                {sweep.isPending ? "Signing out..." : "Sign out all other devices"}
              </AppText>
            </Pressable>
            {!trusted && (
              <AppText family="display" size={11.5} color={colors.authHint} style={styles.center}>
                Only a trusted device can do this. Trust this phone first.
              </AppText>
            )}
          </View>
        )}

        {data && (
          <AppText family="display" size={10.5} color={colors.authHint} style={styles.credit}>
            Locations are approximate. This product includes GeoLite2 data created by MaxMind, available from
            https://www.maxmind.com.
          </AppText>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

function DeviceRow({ device, last }: { device: Device; last: boolean }) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityHint="Opens this device's details"
      onPress={() => router.push(`/account/devices/${device.id}`)}
      style={({ pressed }) => [styles.row, !last && styles.rowBorder, pressed && styles.pressed]}
      testID={`device-${device.id}`}
    >
      <DeviceIcon platform={device.platform} size={30} color={device.is_active ? colors.authInk : colors.authHint} />
      <View style={styles.flex}>
        <View style={styles.nameRow}>
          <AppText family="display" weight="medium" size={15.5} color={colors.authInk} numberOfLines={1} style={styles.shrink}>
            {deviceName(device)}
          </AppText>
          {device.is_trusted && <IconShieldCheck size={15} color="#1c7ed6" strokeWidth={2.2} />}
        </View>
        <AppText family="display" size={13} color={colors.authMuted}>
          {deviceLine(device)}
        </AppText>
        {!device.is_active && (
          <AppText family="display" weight="medium" size={11.5} color={colors.authHint}>
            Signed out
          </AppText>
        )}
      </View>
      <IconChevronRight size={20} color={colors.authMuted} strokeWidth={2} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  flex: { flex: 1 },
  shrink: { flexShrink: 1 },
  center: { textAlign: "center" },
  content: { paddingHorizontal: 20, paddingTop: 6, paddingBottom: 32, gap: 10 },
  skeleton: { height: 74, borderRadius: 16, backgroundColor: "#f0e9df" },
  failed: { padding: 14, borderRadius: 14, backgroundColor: colors.dangerSurface },
  banner: {
    flexDirection: "row",
    gap: 10,
    padding: 12,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: "#ffe3bf",
    backgroundColor: "#fff8ef",
  },
  heading: { marginTop: 12 },
  list: { borderRadius: 18, borderWidth: 1, borderColor: "#e5e7eb", backgroundColor: colors.white, overflow: "hidden" },
  row: { flexDirection: "row", alignItems: "center", gap: 14, paddingHorizontal: 16, paddingVertical: 14 },
  rowBorder: { borderBottomWidth: 1, borderBottomColor: "#f0f0f0" },
  pressed: { backgroundColor: "#fafafa" },
  nameRow: { flexDirection: "row", alignItems: "center", gap: 6 },
  sweepBox: { gap: 6, marginTop: 14 },
  sweep: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 10,
    minHeight: 48,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: "#fbd5d4",
    backgroundColor: colors.white,
  },
  dim: { opacity: 0.55 },
  credit: { textAlign: "center", marginTop: 18, lineHeight: 15 },
});
