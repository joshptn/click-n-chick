import { StyleSheet, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";

import { AppText } from "../../components/AppText";
import { IconBell } from "../../components/icons";
import { AppHeader } from "../../components/menu/AppHeader";
import { colors } from "../../theme/colors";

/** Signed in only (the tabs layout guards it). The notification list arrives in phase 7. */
export default function Notifications() {
  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right"]}>
      <AppHeader />
      <View style={styles.body}>
        <IconBell size={44} color="#d9d3cb" strokeWidth={1.4} />
        <AppText family="display" weight="semibold" size={15} color={colors.ink500}>
          Notifications
        </AppText>
        <AppText family="display" size={13} color={colors.ink300} style={styles.center}>
          Order updates and store news will show here in a later update.
        </AppText>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  body: { flex: 1, alignItems: "center", justifyContent: "center", gap: 6, paddingHorizontal: 32, paddingBottom: 120 },
  center: { textAlign: "center" },
});
