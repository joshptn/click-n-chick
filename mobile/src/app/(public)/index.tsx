import { Image, Pressable, ScrollView, StyleSheet, View, useWindowDimensions } from "react-native";
import { Link, Redirect, router } from "expo-router";
import { SafeAreaView } from "react-native-safe-area-context";

import { AppText } from "../../components/AppText";
import { useSession } from "../../hooks/useSession";
import { colors } from "../../theme/colors";

/**
 * The landing page (design of 2026-11-16), for signed-out customers. A
 * signed-in customer goes straight past it: to their account for now, to the
 * menu once it exists (phase 6).
 *
 * "Order now" and "Start your order" open Sign in; "Explore the menu" opens the
 * menu (a placeholder until phase 6).
 */
export default function Landing() {
  const { signedIn } = useSession();
  const { width } = useWindowDimensions();

  if (signedIn) return <Redirect href="/account" />;

  // The headline and the mascot scale down on narrow phones.
  const headline = Math.min(56, Math.round(width * 0.135));
  const mascot = Math.min(340, width - 64);

  return (
    <SafeAreaView style={styles.safe} edges={["top", "bottom", "left", "right"]}>
      <ScrollView contentContainerStyle={styles.content} bounces={false} showsVerticalScrollIndicator={false}>
        <View style={styles.header}>
          <View style={styles.brand}>
            <Image source={require("../../../assets/images/logo-icon.png")} style={styles.logo} />
            <AppText family="body" weight="bold" size={18} color={colors.orange}>
              Click n Chick
            </AppText>
          </View>

          <Pressable
            accessibilityRole="button"
            onPress={() => router.push("/sign-in")}
            style={({ pressed }) => [styles.orderNow, pressed && styles.pressed]}
            testID="landing-order-now"
          >
            <AppText family="display" weight="semibold" size={13} color={colors.white}>
              Order now
            </AppText>
          </Pressable>
        </View>

        <View style={styles.hero}>
          <View style={styles.eyebrow}>
            <View style={styles.eyebrowLine} />
            <AppText family="display" weight="semibold" size={11} color={colors.orange} style={styles.eyebrowText}>
              CHOOSE THE BEST, BESTIE
            </AppText>
          </View>

          <AppText
            family="display"
            weight="extrabold"
            size={headline}
            color={colors.espresso}
            style={[styles.headline, { lineHeight: Math.round(headline * 1.12) }]}
            accessibilityRole="header"
          >
            Basta BES
          </AppText>
          <AppText
            family="display"
            weight="extrabold"
            size={headline}
            color={colors.orange}
            style={[styles.headline, { lineHeight: Math.round(headline * 1.12) }]}
          >
            da BESt
          </AppText>

          <AppText family="display" size={14.5} color={colors.mocha} style={styles.body}>
            {"Your comfort food favorites, cooked fresh and delivered while they're still hot. One tap is all it takes."}
          </AppText>
        </View>

        <Image
          source={require("../../../assets/images/chicken-hero.png")}
          style={[styles.mascot, { width: mascot, height: mascot }]}
          resizeMode="contain"
          accessibilityLabel="The Click n Chick mascot riding a delivery scooter with a bucket of fried chicken"
        />

        <View style={styles.actions}>
          {/* Not a Link: Link's asChild drops a style given as a function. */}
          <Pressable
            accessibilityRole="button"
            onPress={() => router.push("/sign-in")}
            style={({ pressed }) => [styles.start, pressed && styles.pressed]}
            testID="landing-start-order"
          >
            <AppText family="display" weight="semibold" size={15} color={colors.white}>
              Start your order
            </AppText>
            <AppText family="display" weight="medium" size={18} color={colors.white} style={styles.arrow}>
              →
            </AppText>
          </Pressable>

          <Link href="/menu" asChild>
            <Pressable accessibilityRole="button" style={styles.explore} hitSlop={8} testID="landing-explore-menu">
              <AppText family="display" weight="semibold" size={13.5} color={colors.espresso}>
                Explore the menu
              </AppText>
            </Pressable>
          </Link>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  content: { flexGrow: 1, paddingHorizontal: 24, paddingBottom: 24 },
  header: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    paddingTop: 10,
  },
  brand: { flexDirection: "row", alignItems: "center", gap: 8 },
  logo: { width: 36, height: 36 },
  orderNow: {
    paddingHorizontal: 18,
    height: 40,
    borderRadius: 20,
    backgroundColor: colors.espressoButton,
    alignItems: "center",
    justifyContent: "center",
    elevation: 6,
    shadowColor: "#000",
    shadowOpacity: 0.2,
    shadowRadius: 10,
    shadowOffset: { width: 0, height: 4 },
  },
  hero: { alignItems: "center", paddingTop: 52 },
  eyebrow: { flexDirection: "row", alignItems: "center", gap: 10, marginBottom: 14 },
  eyebrowLine: { width: 26, height: 2, borderRadius: 1, backgroundColor: colors.orange },
  eyebrowText: { letterSpacing: 1.6 },
  headline: { textAlign: "center", letterSpacing: -1.5 },
  body: { textAlign: "center", lineHeight: 23, marginTop: 22, maxWidth: 340 },
  mascot: { alignSelf: "center", marginTop: 20 },
  actions: { marginTop: "auto", paddingTop: 16, gap: 18, alignItems: "stretch" },
  start: {
    height: 52,
    borderRadius: 14,
    backgroundColor: colors.orange,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 12,
    elevation: 8,
    shadowColor: colors.orange,
    shadowOpacity: 0.35,
    shadowRadius: 14,
    shadowOffset: { width: 0, height: 6 },
  },
  arrow: { marginTop: -2 },
  explore: { alignSelf: "center", paddingVertical: 4 },
  pressed: { opacity: 0.85 },
});
