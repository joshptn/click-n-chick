import { StyleSheet, View } from "react-native";
import { Image } from "expo-image";

import { colors } from "../../theme/colors";
import { AppText } from "../AppText";

/** The account's photo, or its initials on the brand orange (as the web's avatar). */
export function Avatar({
  uri,
  firstName,
  lastName,
  size = 56,
}: {
  uri?: string | null;
  firstName?: string | null;
  lastName?: string | null;
  size?: number;
}) {
  const initials = `${firstName?.charAt(0) ?? ""}${lastName?.charAt(0) ?? ""}`.toUpperCase() || "?";
  const round = { width: size, height: size, borderRadius: size / 2 };

  if (uri) {
    return <Image source={{ uri }} style={[round, styles.photo]} contentFit="cover" transition={150} accessibilityIgnoresInvertColors />;
  }

  return (
    <View style={[round, styles.initials]}>
      <AppText family="display" weight="semibold" size={Math.round(size * 0.34)} color={colors.white}>
        {initials}
      </AppText>
    </View>
  );
}

const styles = StyleSheet.create({
  photo: { backgroundColor: colors.cream100 },
  initials: {
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.orange,
    elevation: 3,
    shadowColor: colors.orange,
    shadowOpacity: 0.3,
    shadowRadius: 6,
    shadowOffset: { width: 0, height: 3 },
  },
});

export default Avatar;
