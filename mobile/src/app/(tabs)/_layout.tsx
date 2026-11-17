import { Tabs } from "expo-router/js-tabs";

import { TabBar } from "../../components/TabBar";
import { useSession } from "../../hooks/useSession";
import { colors } from "../../theme/colors";

/**
 * The app's main screens, for customers and guests alike. Notifications and
 * Profile need an account, so they exist only while signed in; a guest's bar
 * shows Sign in in their place (components/TabBar.tsx).
 */
export default function TabsLayout() {
  const { signedIn } = useSession();

  return (
    <Tabs
      tabBar={(props) => <TabBar {...props} />}
      screenOptions={{ headerShown: false, sceneStyle: { backgroundColor: colors.landing } }}
    >
      <Tabs.Screen name="home" options={{ title: "Home" }} />
      <Tabs.Screen name="cart" options={{ title: "Cart" }} />

      <Tabs.Protected guard={signedIn}>
        <Tabs.Screen name="notifications" options={{ title: "Notif" }} />
        <Tabs.Screen name="profile" options={{ title: "Profile" }} />
      </Tabs.Protected>
    </Tabs>
  );
}
