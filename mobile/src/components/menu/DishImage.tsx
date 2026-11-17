import { useState } from "react";
import { StyleSheet, View, type StyleProp, type ViewStyle } from "react-native";
import { Image } from "expo-image";

import { sizedImage } from "../../lib/images";
import { IconPhoto, IconPhotoOff } from "../icons";

/**
 * A dish photo filling a sized box: a quiet photo icon while it loads, a quick
 * fade when it arrives, and a "no photo" icon if it fails or there is none, as
 * on the web. Fetched at the width it is drawn (lib/images.ts) and kept in
 * Expo Image's memory and disk cache.
 */
export function DishImage({
  url,
  width,
  style,
  dimmed = false,
  iconSize = 24,
  priority = "normal",
}: {
  url: string | null | undefined;
  /** Drawn width in points, to pick the Cloudinary size. */
  width: number;
  style?: StyleProp<ViewStyle>;
  /** Faded, for a dish that cannot be ordered. */
  dimmed?: boolean;
  iconSize?: number;
  priority?: "low" | "normal" | "high";
}) {
  const source = sizedImage(url, width);
  const [state, setState] = useState<{ source: string | null; status: "loading" | "loaded" | "failed" }>({
    source,
    status: source ? "loading" : "failed",
  });

  // A new photo (the dish's picture changed) starts over from its placeholder.
  if (state.source !== source) setState({ source, status: source ? "loading" : "failed" });

  const status = state.source === source ? state.status : "loading";
  const setStatus = (next: "loaded" | "failed") => setState({ source, status: next });

  return (
    <View style={[styles.box, style]}>
      {status !== "loaded" && (
        <View style={styles.placeholder}>
          {status === "failed" ? (
            <IconPhotoOff size={iconSize} color="#c9c2b8" />
          ) : (
            <IconPhoto size={iconSize} color="#c9c2b8" />
          )}
        </View>
      )}

      {source && status !== "failed" ? (
        <Image
          source={source}
          style={StyleSheet.absoluteFill}
          contentFit="cover"
          transition={200}
          cachePolicy="memory-disk"
          priority={priority}
          recyclingKey={source}
          onLoad={() => setStatus("loaded")}
          onError={() => setStatus("failed")}
        />
      ) : null}

      {/* A wash rather than a label: the reason is spelled out under the price. */}
      {dimmed && <View style={styles.dim} />}
    </View>
  );
}

const styles = StyleSheet.create({
  box: { overflow: "hidden", backgroundColor: "#f4f1ec" },
  placeholder: { position: "absolute", top: 0, left: 0, right: 0, bottom: 0, alignItems: "center", justifyContent: "center" },
  dim: { position: "absolute", top: 0, left: 0, right: 0, bottom: 0, backgroundColor: "rgba(255,255,255,0.5)" },
});

export default DishImage;
