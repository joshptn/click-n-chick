import type { ReactNode } from "react";
import { Pressable, StyleSheet, View } from "react-native";
import { router } from "expo-router";

import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { IconArrowLeft } from "../icons";

/**
 * The account screens' top bar: the round back button and a centred title, as
 * on the Notification screen. `right` keeps the title centred when a screen
 * has nothing to put there.
 */
export function ScreenHeader({ title, right, onBack }: { title: string; right?: ReactNode; onBack?: () => void }) {
  const back = () => {
    if (onBack) return onBack();
    if (router.canGoBack()) router.back();
    else router.navigate("/profile");
  };

  return (
    <View style={styles.header}>
      <Pressable accessibilityRole="button" accessibilityLabel="Go back" onPress={back} style={styles.back} hitSlop={6}>
        <IconArrowLeft size={18} color={colors.authInk} strokeWidth={2.2} />
      </Pressable>
      <AppText
        family="display"
        weight="semibold"
        size={19}
        color={colors.authInk}
        style={styles.title}
        numberOfLines={1}
        accessibilityRole="header"
      >
        {title}
      </AppText>
      <View style={styles.side}>{right}</View>
    </View>
  );
}

const styles = StyleSheet.create({
  header: { flexDirection: "row", alignItems: "center", gap: 12, paddingHorizontal: 16, paddingVertical: 10 },
  back: {
    width: 38,
    height: 38,
    borderRadius: 19,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
    elevation: 2,
    shadowColor: "#000",
    shadowOpacity: 0.08,
    shadowRadius: 4,
    shadowOffset: { width: 0, height: 1 },
  },
  title: { flex: 1, textAlign: "center" },
  side: { width: 38, alignItems: "flex-end" },
});

export default ScreenHeader;
