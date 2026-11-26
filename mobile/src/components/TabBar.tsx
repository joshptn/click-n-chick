import type { ComponentType } from "react";
import { Pressable, StyleSheet, View } from "react-native";
import { router } from "expo-router";
import type { BottomTabBarProps } from "expo-router/js-tabs";
import { useSafeAreaInsets } from "react-native-safe-area-context";

import { useCart } from "../hooks/useCart";
import { useNotifications } from "../hooks/useNotifications";
import { useSession } from "../hooks/useSession";
import { colors } from "../theme/colors";
import { AppText } from "./AppText";
import { IconBell, IconHomeFilled, IconLogin2, IconReceipt, IconShoppingCart, IconUser } from "./icons";

type IconProps = { size?: number; color?: string; strokeWidth?: number };

type Item = {
  /** The tab's route name, or null for "Sign in", which leaves the tabs. */
  route: string | null;
  label: string;
  Icon: ComponentType<IconProps>;
  badge?: number;
};

/** Space the floating bar takes at the bottom of a tab's content. */
export const TAB_BAR_SPACE = 104;

/**
 * The floating bottom bar (design of 2026-11-17). A signed-in customer has
 * Home, Cart, Orders (2026-11-26), Notif and Profile; a guest has Home, Cart
 * and Sign in.
 */
export function TabBar({ state, navigateToTab, emitter }: BottomTabBarProps) {
  const { signedIn } = useSession();
  const { item_count: itemCount } = useCart();
  const { unreadCount } = useNotifications();
  const insets = useSafeAreaInsets();

  const items: Item[] = [
    { route: "home", label: "Home", Icon: IconHomeFilled },
    { route: "cart", label: "Cart", Icon: IconShoppingCart, badge: itemCount },
    ...(signedIn
      ? [
          { route: "orders", label: "Orders", Icon: IconReceipt },
          { route: "notifications", label: "Notif", Icon: IconBell, badge: unreadCount },
          { route: "profile", label: "Profile", Icon: IconUser },
        ]
      : [{ route: null, label: "Sign in", Icon: IconLogin2 }]),
  ];

  const focusedName = state.routes[state.index]?.name;

  return (
    <View style={[styles.bar, { bottom: Math.max(insets.bottom, 8) + 8 }]} accessibilityRole="tablist">
      {items.map(({ route: name, label, Icon, badge }) => {
        const route = name ? state.routes.find((candidate) => candidate.name === name) : undefined;
        const focused = Boolean(name) && name === focusedName;
        const tint = focused ? colors.orange : colors.authInk;

        const onPress = () => {
          if (!name) {
            router.push("/sign-in");
            return;
          }

          if (!route) return;

          const event = emitter.emit({ type: "tabPress", target: route.key, canPreventDefault: true });

          if (!focused && !event.defaultPrevented) navigateToTab(route.key);
        };

        return (
          <Pressable
            key={label}
            accessibilityRole="tab"
            accessibilityState={{ selected: focused }}
            accessibilityLabel={badge ? `${label}, ${badge} ${name === "cart" ? "item" : "unread"}${name === "cart" && badge !== 1 ? "s" : ""}` : label}
            onPress={onPress}
            style={styles.item}
            testID={`tab-${label.toLowerCase().replace(" ", "-")}`}
          >
            <View>
              <Icon size={23} color={tint} strokeWidth={1.8} />
              {badge ? (
                <View style={styles.badge}>
                  <AppText family="display" weight="bold" size={9} color={colors.white} style={styles.badgeText}>
                    {badge > 99 ? "99+" : badge}
                  </AppText>
                </View>
              ) : null}
            </View>
            <AppText family="display" weight={focused ? "semibold" : "medium"} size={11} color={tint}>
              {label}
            </AppText>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  bar: {
    position: "absolute",
    left: 20,
    right: 20,
    height: 66,
    flexDirection: "row",
    alignItems: "center",
    borderRadius: 33,
    backgroundColor: colors.white,
    elevation: 10,
    shadowColor: "#000",
    shadowOpacity: 0.12,
    shadowRadius: 16,
    shadowOffset: { width: 0, height: 6 },
  },
  item: { flex: 1, alignItems: "center", justifyContent: "center", gap: 2, height: "100%" },
  badge: {
    position: "absolute",
    top: -5,
    right: -9,
    minWidth: 16,
    height: 16,
    paddingHorizontal: 3,
    borderRadius: 8,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.orange,
    borderWidth: 1.5,
    borderColor: colors.white,
  },
  badgeText: { lineHeight: 12 },
});

export default TabBar;
