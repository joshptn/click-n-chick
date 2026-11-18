import { Stack } from "expo-router";

import { colors } from "../../theme/colors";

/**
 * The account pages opened from the Profile tab (phase 8). The root layout
 * keeps the whole group signed-in only.
 */
export default function AccountLayout() {
  return <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.landing } }} />;
}
