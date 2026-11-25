import { useEffect, useRef, useState } from "react";
import { FlatList, StyleSheet, View, type NativeScrollEvent, type NativeSyntheticEvent } from "react-native";
import { useQuery } from "@tanstack/react-query";

import { fetchPosters, type Poster } from "../../api/menu";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { IconPhoto } from "../icons";
import { DishImage } from "./DishImage";

const ROTATE_MS = 6000;
/** The banner's shape, the same as on the web: posters are cropped to 3:1 when added. */
const RATIO = 3;

/**
 * The store's posters (GET /api/posters), as the web's PosterBanner: one at a
 * time, swiped or turning every 6 seconds. A poster is only an image: not
 * tappable, no caption.
 */
export function PosterBanner({ width }: { width: number }) {
  const { data, isLoading } = useQuery({
    queryKey: ["posters"],
    queryFn: ({ signal }) => fetchPosters(signal),
    staleTime: 5 * 60 * 1000,
  });

  const posters = data?.data ?? [];
  const [position, setIndex] = useState(0);
  const list = useRef<FlatList<Poster>>(null);
  const count = posters.length;
  const height = Math.round(width / RATIO);
  // A poster taken down must not leave the strip pointing past the end.
  const index = position < count ? position : 0;

  useEffect(() => {
    if (count < 2) return undefined;

    const timer = setInterval(() => {
      setIndex((prev) => {
        const next = (Math.min(prev, count - 1) + 1) % count;
        list.current?.scrollToIndex({ index: next, animated: true });
        return next;
      });
    }, ROTATE_MS);

    return () => clearInterval(timer);
  }, [count]);

  const onSwiped = (event: NativeSyntheticEvent<NativeScrollEvent>) => {
    setIndex(Math.round(event.nativeEvent.contentOffset.x / width));
  };

  if (isLoading) return <View style={[styles.frame, styles.skeleton, { width, height }]} />;

  if (count === 0) {
    return (
      <View style={[styles.frame, styles.empty, { width, height }]}>
        <IconPhoto size={24} color="#c9c2b8" />
        <AppText family="display" weight="semibold" size={13} color={colors.ink500}>
          No promotions right now
        </AppText>
        <AppText family="display" size={12} color={colors.ink300}>
          Posters added by the store appear here.
        </AppText>
      </View>
    );
  }

  return (
    <View style={[styles.frame, { width, height }]} accessibilityLabel="Promotions">
      <FlatList
        ref={list}
        data={posters}
        keyExtractor={(poster) => String(poster.id)}
        horizontal
        pagingEnabled
        showsHorizontalScrollIndicator={false}
        onMomentumScrollEnd={onSwiped}
        getItemLayout={(_, item) => ({ length: width, offset: width * item, index: item })}
        renderItem={({ item, index: position }) => (
          <View style={{ width, height }} accessible accessibilityRole="image" accessibilityLabel="Store promotion">
            <DishImage url={item.image} width={width} style={StyleSheet.absoluteFill} priority={position === 0 ? "high" : "normal"} />
          </View>
        )}
      />

      {count > 1 && (
        <View style={styles.dots} pointerEvents="none">
          {posters.map((poster, dot) => (
            <View key={poster.id} style={[styles.dot, dot === index && styles.dotActive]} />
          ))}
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  frame: { borderRadius: 18, overflow: "hidden", alignSelf: "center" },
  skeleton: { backgroundColor: "#f0e9df" },
  empty: {
    alignItems: "center",
    justifyContent: "center",
    gap: 2,
    borderWidth: 2,
    borderStyle: "dashed",
    borderColor: "#e6ded4",
    backgroundColor: "#faf7f3",
  },
  dots: { position: "absolute", right: 14, bottom: 16, flexDirection: "row", gap: 5 },
  dot: { width: 6, height: 6, borderRadius: 3, backgroundColor: "rgba(255,255,255,0.55)" },
  dotActive: { width: 18, backgroundColor: colors.white },
});

export default PosterBanner;
