import { useEffect, useState } from "react";
import { Animated, Easing, Image, StyleSheet, View, useWindowDimensions } from "react-native";
import { StatusBar } from "expo-status-bar";

import { colors } from "../theme/colors";
import { AppText } from "./AppText";

/** How long the bar takes to fill: the shortest time the splash is on screen. */
const FILL_MS = 1500;
const FADE_MS = 250;

/**
 * The branded splash (design of 2026-11-16): the drumstick in a white circle
 * with two soft rings, the name, the tagline and a progress bar.
 *
 * It takes over from the phone's own splash (app.json), which shows the same
 * orange and the same circle in the same place, so the hand-over is not
 * visible. It is mounted once the fonts and the stored session are ready,
 * fills its bar, then fades out to the screen underneath.
 *
 * The drumstick (assets/images/drumstick.png) is a placeholder drawing until
 * the real artwork exists; replacing the file is enough.
 */
export function BrandSplash({ onDone }: { onDone: () => void }) {
  const { height } = useWindowDimensions();
  // Created once and never replaced, so held in state rather than read from a ref.
  const [fill] = useState(() => new Animated.Value(0));
  const [opacity] = useState(() => new Animated.Value(1));

  useEffect(() => {
    const run = Animated.sequence([
      Animated.timing(fill, {
        toValue: 1,
        duration: FILL_MS,
        easing: Easing.out(Easing.cubic),
        useNativeDriver: true,
      }),
      Animated.timing(opacity, { toValue: 0, duration: FADE_MS, useNativeDriver: true }),
    ]);

    run.start(({ finished }) => {
      if (finished) onDone();
    });

    return () => run.stop();
  }, [fill, opacity, onDone]);

  return (
    <Animated.View
      style={[StyleSheet.absoluteFill, styles.root, { opacity }]}
      accessible
      accessibilityLabel="Click'n Chick is loading"
      testID="brand-splash"
    >
      <StatusBar style="light" />

      {/* Centred exactly, to sit where the phone's own splash drew the circle. */}
      <View style={[StyleSheet.absoluteFill, styles.center]} pointerEvents="none">
        <View style={styles.outerRing}>
          <View style={styles.innerRing}>
            <View style={styles.badge}>
              <Image source={require("../../assets/images/drumstick.png")} style={styles.drumstick} />
            </View>
          </View>
        </View>
      </View>

      <View style={[styles.below, { top: height / 2 + 66 }]} pointerEvents="none">
        <AppText family="display" weight="bold" size={30} color={colors.white} style={styles.title}>
          {"Click'n Chick"}
        </AppText>
        <AppText family="display" weight="light" size={14} color="rgba(255,255,255,0.85)" style={styles.tagline}>
          Basta BES, da BEST!
        </AppText>

        <View style={styles.track}>
          <Animated.View style={[styles.bar, { transform: [{ scaleX: fill }] }]} />
        </View>
      </View>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  root: { backgroundColor: colors.orange, zIndex: 100 },
  center: { alignItems: "center", justifyContent: "center" },
  outerRing: {
    width: 204,
    height: 204,
    borderRadius: 102,
    backgroundColor: "rgba(255,255,255,0.07)",
    alignItems: "center",
    justifyContent: "center",
  },
  innerRing: {
    width: 112,
    height: 112,
    borderRadius: 56,
    backgroundColor: "rgba(255,255,255,0.16)",
    alignItems: "center",
    justifyContent: "center",
  },
  badge: {
    width: 80,
    height: 80,
    borderRadius: 40,
    backgroundColor: colors.white,
    alignItems: "center",
    justifyContent: "center",
    elevation: 10,
    shadowColor: "#7a3a00",
    shadowOpacity: 0.25,
    shadowRadius: 14,
    shadowOffset: { width: 0, height: 6 },
  },
  drumstick: { width: 40, height: 40 },
  below: { position: "absolute", left: 0, right: 0, alignItems: "center" },
  title: { letterSpacing: -0.6, lineHeight: 40 },
  tagline: { marginTop: 2, letterSpacing: 0.4 },
  track: {
    marginTop: 30,
    width: 160,
    height: 3,
    borderRadius: 2,
    backgroundColor: "rgba(255,255,255,0.3)",
    overflow: "hidden",
  },
  bar: {
    width: 160,
    height: 3,
    borderRadius: 2,
    backgroundColor: colors.white,
    // Grows from the left edge as the scale goes from 0 to 1.
    transformOrigin: "left",
  },
});

export default BrandSplash;
