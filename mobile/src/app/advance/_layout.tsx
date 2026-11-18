import { Stack } from "expo-router";

import { colors } from "../../theme/colors";

/**
 * Advance orders (phase 9): the advance menu, scheduling and the request-sent
 * screen. The root layout keeps the group signed-in only (accounts only, as
 * on the API).
 */
export default function AdvanceLayout() {
  return <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.landing } }} />;
}
