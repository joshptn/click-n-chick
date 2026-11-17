import { useEffect, useState } from "react";
import { Animated, Pressable, StyleSheet, View } from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";

import { dismissToast, useToast } from "../lib/toast";
import { colors } from "../theme/colors";
import { AppText } from "./AppText";
import { IconAlertCircle, IconCircleCheck, IconShoppingCart } from "./icons";

const TONES = {
  success: { icon: IconCircleCheck, color: colors.success },
  error: { icon: IconAlertCircle, color: colors.danger },
  info: { icon: IconShoppingCart, color: colors.orange },
} as const;

/** Draws the current toast (lib/toast.ts) at the top of the screen; a tap dismisses it. */
export function ToastHost() {
  const current = useToast();
  const insets = useSafeAreaInsets();
  const [shown] = useState(() => new Animated.Value(0));

  useEffect(() => {
    if (!current) return;

    shown.setValue(0);
    Animated.timing(shown, { toValue: 1, duration: 180, useNativeDriver: true }).start();
  }, [current, shown]);

  if (!current) return null;

  const tone = TONES[current.tone];
  const Icon = tone.icon;

  return (
    <Animated.View
      pointerEvents="box-none"
      style={[
        styles.wrap,
        {
          top: insets.top + 8,
          opacity: shown,
          transform: [{ translateY: shown.interpolate({ inputRange: [0, 1], outputRange: [-12, 0] }) }],
        },
      ]}
    >
      <Pressable
        accessibilityRole="alert"
        accessibilityLiveRegion="polite"
        onPress={dismissToast}
        style={[styles.card, { borderLeftColor: tone.color }]}
        testID="toast"
      >
        <Icon size={20} color={tone.color} />
        <View style={styles.text}>
          <AppText family="display" weight="semibold" size={13.5} color={colors.authInk}>
            {current.title}
          </AppText>
          {current.message ? (
            <AppText family="display" size={12.5} color={colors.authMuted}>
              {current.message}
            </AppText>
          ) : null}
        </View>
      </Pressable>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  wrap: { position: "absolute", left: 16, right: 16, zIndex: 50 },
  card: {
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
    paddingVertical: 12,
    paddingHorizontal: 14,
    borderRadius: 14,
    borderLeftWidth: 4,
    backgroundColor: colors.white,
    elevation: 6,
    shadowColor: "#000",
    shadowOpacity: 0.12,
    shadowRadius: 10,
    shadowOffset: { width: 0, height: 3 },
  },
  text: { flex: 1 },
});

export default ToastHost;
