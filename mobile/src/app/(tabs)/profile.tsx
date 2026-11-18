import { useState } from "react";
import { Pressable, RefreshControl, ScrollView, StyleSheet, View } from "react-native";
import { router } from "expo-router";
import { SafeAreaView } from "react-native-safe-area-context";

import { authApi } from "../../api/auth";
import { AppText } from "../../components/AppText";
import { TAB_BAR_SPACE } from "../../components/TabBar";
import { Avatar } from "../../components/account/Avatar";
import { DiscountCard } from "../../components/account/DiscountCard";
import { MenuRow } from "../../components/account/MenuRow";
import { ScreenHeader } from "../../components/account/ScreenHeader";
import { IconDevices, IconLock, IconLogout, IconStarFilled, IconUserEdit, IconWallet } from "../../components/icons";
import { useProfile } from "../../hooks/useProfile";
import { useUser } from "../../hooks/useUser";
import { currentToken, endSession } from "../../lib/session";
import { colors } from "../../theme/colors";

/**
 * The account's home (phase 8, from the user's reference design of
 * 2026-11-18): who is signed in, loyalty points, discount eligibility, and the
 * way into each account page. Saved addresses are not here: in the app an
 * address only exists at checkout (decided 2026-11-18).
 */
export default function Profile() {
  const signedInUser = useUser();
  const { data, isLoading, isError, isRefetching, refetch } = useProfile();
  const [signingOut, setSigningOut] = useState(false);

  const user = data?.user ?? signedInUser;
  const name = [user?.first_name, user?.last_name].filter(Boolean).join(" ") || "My account";
  const email = data?.user.email ?? null;

  const signOut = async () => {
    setSigningOut(true);

    const token = currentToken();

    // Ends this sign-in on the server too (which also drops its push token).
    // The phone signs out either way: a failed request must not keep it in.
    if (token) await authApi.logout(token).catch(() => undefined);

    await endSession();
    // Back to the landing page, as a signed-out start.
    router.replace("/");
  };

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right"]}>
      <ScreenHeader title="Profile" showBack={false} />

      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={() => void refetch()} colors={[colors.orange]} />}
      >
        <View style={styles.identity}>
          <Avatar uri={data?.user.avatar} firstName={user?.first_name} lastName={user?.last_name} size={58} />
          <View style={styles.identityText}>
            <AppText family="display" weight="semibold" size={16} color={colors.authInk} numberOfLines={1}>
              {name}
            </AppText>
            {email ? (
              <AppText family="display" size={12.5} color={colors.authHint} numberOfLines={1}>
                {email}
              </AppText>
            ) : null}
          </View>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={`${data?.loyalty_points ?? 0} loyalty points. Open BES Wallet`}
            onPress={() => router.push("/account/wallet")}
            style={styles.points}
            testID="points-pill"
          >
            <IconStarFilled size={15} color="#f5b301" />
            <AppText family="display" weight="semibold" size={13.5} color={colors.authInk}>
              {(data?.loyalty_points ?? 0).toLocaleString("en-PH")}
            </AppText>
          </Pressable>
        </View>

        {isLoading && <View style={styles.skeleton} />}

        {isError && !data && (
          <Pressable accessibilityRole="button" onPress={() => void refetch()} style={styles.failed}>
            <AppText family="display" size={13} color={colors.danger}>
              Your profile could not be loaded. Tap to try again.
            </AppText>
          </Pressable>
        )}

        {data?.discount && <DiscountCard discount={data.discount} onApply={() => router.push("/account/discount")} />}

        <SectionLabel>Account settings</SectionLabel>
        <MenuRow
          icon={IconUserEdit}
          title="Edit Profile"
          subtitle="Name, phone number and photo"
          onPress={() => router.push("/account/edit")}
          testID="menu-edit-profile"
        />
        <MenuRow
          icon={IconWallet}
          title="BES Wallet"
          subtitle="Redeem loyalty points"
          onPress={() => router.push("/account/wallet")}
          testID="menu-wallet"
        />

        <SectionLabel>Account security</SectionLabel>
        <MenuRow
          icon={IconLock}
          title="Password"
          subtitle="Change password, enable 2FA"
          onPress={() => router.push("/account/security")}
          testID="menu-security"
        />
        <MenuRow
          icon={IconDevices}
          title="Manage Devices"
          subtitle="Sign out unknown devices"
          onPress={() => router.push("/account/devices")}
          testID="menu-devices"
        />

        <Pressable
          accessibilityRole="button"
          accessibilityState={{ busy: signingOut }}
          disabled={signingOut}
          onPress={signOut}
          style={({ pressed }) => [styles.signOut, (pressed || signingOut) && styles.pressed]}
          testID="sign-out"
        >
          <IconLogout size={19} color={colors.danger} strokeWidth={2} />
          <AppText family="display" weight="medium" size={15} color={colors.danger}>
            {signingOut ? "Signing out..." : "Sign Out"}
          </AppText>
        </Pressable>
      </ScrollView>
    </SafeAreaView>
  );
}

function SectionLabel({ children }: { children: string }) {
  return (
    <AppText family="display" weight="semibold" size={12.5} color={colors.authHint} style={styles.section}>
      {children.toUpperCase()}
    </AppText>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  content: { paddingHorizontal: 16, paddingTop: 8, paddingBottom: TAB_BAR_SPACE + 16, gap: 6 },
  identity: { flexDirection: "row", alignItems: "center", gap: 14, paddingBottom: 12 },
  identityText: { flex: 1, gap: 1 },
  points: {
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 999,
    borderWidth: 1,
    borderColor: colors.fieldBorder,
    backgroundColor: colors.white,
  },
  skeleton: { height: 92, borderRadius: 16, backgroundColor: "#f0e9df" },
  failed: { padding: 14, borderRadius: 14, backgroundColor: colors.dangerSurface },
  section: { letterSpacing: 0.6, marginTop: 22, marginBottom: 2, marginLeft: 6 },
  signOut: {
    marginTop: 26,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 10,
    minHeight: 50,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: "#fbd5d4",
    backgroundColor: colors.white,
  },
  pressed: { opacity: 0.7 },
});
