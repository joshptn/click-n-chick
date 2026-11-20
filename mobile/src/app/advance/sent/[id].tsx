import type { ComponentType } from "react";
import { ScrollView, StyleSheet, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { SafeAreaView } from "react-native-safe-area-context";

import { formatCollectionDay } from "../../../api/advance";
import { AppText } from "../../../components/AppText";
import { ScreenHeader } from "../../../components/account/ScreenHeader";
import { CheckoutButton } from "../../../components/checkout/CheckoutButton";
import { IconChecks, IconClockHour4, IconCreditCard, IconSend } from "../../../components/icons";
import { formatPeso } from "../../../lib/money";
import { formatTime } from "../../../lib/time";
import { colors } from "../../../theme/colors";

type IconProps = { size?: number; color?: string; strokeWidth?: number };

const TIMELINE: { Icon: ComponentType<IconProps>; title: string; body: string; done: boolean }[] = [
  { Icon: IconSend, title: "Request sent", body: "The store has your order and the date you want it.", done: true },
  {
    Icon: IconClockHour4,
    title: "Waiting for the store",
    body: "A Store Agent checks whether the kitchen can make this on your date, then accepts or declines.",
    done: false,
  },
  {
    Icon: IconCreditCard,
    title: "Pay to confirm",
    body: "Once it is accepted you are told when to pay by: for a collection tomorrow, before the kitchen has to start on it; otherwise within 24 hours. Nothing is charged before then.",
    done: false,
  },
];

/**
 * The request is with the store (phase 9; the web's AdvanceSubmitted). This
 * screen is where "Request sent" is shown: the order's tracking starts at
 * "To pay" (decided 2026-11-19).
 */
export default function AdvanceSent() {
  const params = useLocalSearchParams<{ id: string; scheduled_for?: string; total?: string; items?: string }>();
  const collectAt = params.scheduled_for ? `${formatCollectionDay(params.scheduled_for)}, ${formatTime(params.scheduled_for)}` : null;
  const items = Number(params.items ?? 0);

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right", "bottom"]}>
      <ScreenHeader title="Request Sent" onBack={() => router.navigate("/home")} />

      <ScrollView contentContainerStyle={styles.content}>
        <View style={styles.card}>
          <View style={styles.hero}>
            <View style={styles.heroIcon}>
              <IconChecks size={24} color={colors.white} strokeWidth={2.2} />
            </View>
            <AppText family="display" weight="bold" size={20} color={colors.white} style={styles.center}>
              Your request is with the store
            </AppText>
            <AppText family="display" size={13} color="rgba(255,255,255,0.88)" style={styles.center}>
              Request #{params.id}
              {collectAt ? ` · for ${collectAt}` : ""}
            </AppText>
          </View>

          <View style={styles.timeline}>
            {TIMELINE.map(({ Icon, title, body, done }, index) => (
              <View key={title} style={styles.entry}>
                <View style={styles.rail}>
                  <View style={[styles.dot, done ? styles.dotDone : styles.dotTodo]}>
                    <Icon size={17} color={done ? colors.success : colors.ink300} strokeWidth={2.2} />
                  </View>
                  {index < TIMELINE.length - 1 && <View style={styles.railLine} />}
                </View>
                <View style={[styles.flex, styles.entryText]}>
                  <AppText family="display" weight="semibold" size={14} color={colors.authInk}>
                    {title}
                  </AppText>
                  <AppText family="display" size={12.5} color={colors.authMuted}>
                    {body}
                  </AppText>
                </View>
              </View>
            ))}
          </View>

          {params.total ? (
            <View style={styles.totals}>
              <View style={styles.totalRow}>
                <AppText family="display" size={13} color={colors.authMuted}>
                  {items} item{items === 1 ? "" : "s"}
                </AppText>
                <AppText family="display" weight="bold" size={18} color={colors.orange}>
                  {formatPeso(params.total)}
                </AppText>
              </View>
              <AppText family="display" size={11} color={colors.authHint} style={styles.right}>
                Payable once accepted
              </AppText>
            </View>
          ) : null}

          <View style={styles.actions}>
            <CheckoutButton
              label="Track this request"
              onPress={() => router.replace({ pathname: "/orders/[id]", params: { id: params.id } })}
              testID="track-request"
            />
            <CheckoutButton label="Schedule another" variant="outline" onPress={() => router.replace("/advance")} testID="schedule-another" />
          </View>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  flex: { flex: 1 },
  center: { textAlign: "center" },
  right: { textAlign: "right" },
  content: { padding: 16 },
  card: { borderRadius: 18, borderWidth: 1, borderColor: "#f0e9df", backgroundColor: colors.white, overflow: "hidden" },
  hero: { alignItems: "center", gap: 6, paddingHorizontal: 20, paddingVertical: 24, backgroundColor: colors.brand600 },
  heroIcon: { width: 48, height: 48, borderRadius: 24, alignItems: "center", justifyContent: "center", backgroundColor: "rgba(255,255,255,0.2)" },
  timeline: { padding: 20 },
  entry: { flexDirection: "row", gap: 14 },
  entryText: { paddingTop: 6, paddingBottom: 18, gap: 2 },
  rail: { alignItems: "center" },
  dot: { width: 36, height: 36, borderRadius: 18, alignItems: "center", justifyContent: "center" },
  dotDone: { backgroundColor: "#e9f8ee" },
  dotTodo: { backgroundColor: "#f4f1ec" },
  railLine: { flex: 1, width: 1, marginTop: 4, backgroundColor: "#f0e9df" },
  totals: { paddingHorizontal: 20, paddingVertical: 14, borderTopWidth: 1, borderTopColor: "#f0e9df" },
  totalRow: { flexDirection: "row", alignItems: "center", justifyContent: "space-between" },
  actions: { gap: 10, padding: 20, borderTopWidth: 1, borderTopColor: "#f0e9df" },
});
