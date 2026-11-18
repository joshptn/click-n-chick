import { Image, Pressable, StyleSheet, TextInput, View } from "react-native";
import { router, usePathname } from "expo-router";

import { useStoreStatus } from "../../hooks/useStoreStatus";
import { setMenuSearch, useMenuSearch } from "../../lib/menuSearch";
import { colors } from "../../theme/colors";
import { fonts } from "../../theme/fonts";
import { AppText } from "../AppText";
import { IconSearch, IconX } from "../icons";

export function AppHeader() {
  const search = useMenuSearch();
  const pathname = usePathname();
  const { isOpen } = useStoreStatus();

  const onSearch = (text: string) => {
    setMenuSearch(text);
    if (text !== "" && pathname !== "/home") router.navigate("/home");
  };

  return (
    <View style={styles.bar}>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel="Click n Chick home page"
        onPress={() => router.navigate({ pathname: "/", params: { visit: "1" } })}
        style={styles.badge}
        testID="header-logo"
      >
        <Image source={require("../../../assets/images/logo-icon.png")} style={styles.logo} />
      </Pressable>

      <View style={styles.search}>
        <IconSearch size={16} color={colors.authHint} />
        <TextInput
          value={search}
          onChangeText={onSearch}
          placeholder="Search food..."
          placeholderTextColor={colors.authHint}
          accessibilityLabel="Search the menu"
          returnKeyType="search"
          style={styles.input}
          testID="menu-search"
        />
        {search ? (
          <Pressable accessibilityRole="button" accessibilityLabel="Clear search" onPress={() => setMenuSearch("")} hitSlop={10}>
            <IconX size={15} color={colors.authHint} />
          </Pressable>
        ) : null}
      </View>

      <View style={[styles.status, isOpen ? styles.open : styles.closed]} testID="store-status">
        <View style={[styles.dot, { backgroundColor: isOpen ? colors.success : colors.ink300 }]} />
        <AppText family="display" weight="semibold" size={11.5} color={isOpen ? colors.success : colors.ink500}>
          {isOpen ? "Open" : "Closed"}
        </AppText>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  bar: {
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
    paddingHorizontal: 16,
    paddingVertical: 10,
    backgroundColor: colors.white,
    borderBottomWidth: 1,
    borderBottomColor: "#f0e9df",
  },
  badge: {
    width: 36,
    height: 36,
    borderRadius: 10,
    backgroundColor: colors.orange,
    alignItems: "center",
    justifyContent: "center",
    overflow: "hidden",
  },
  logo: { width: 30, height: 30, resizeMode: "contain" },
  search: {
    flex: 1,
    height: 38,
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    paddingHorizontal: 12,
    borderRadius: 19,
    backgroundColor: "#f3f4f6",
  },
  input: {
    flex: 1,
    paddingVertical: 0,
    fontFamily: fonts.display.regular,
    fontSize: 13.5,
    color: colors.authInk,
  },
  status: {
    flexDirection: "row",
    alignItems: "center",
    gap: 5,
    paddingHorizontal: 10,
    paddingVertical: 5,
    borderRadius: 999,
    borderWidth: 1,
  },
  open: { backgroundColor: "#ecfdf3", borderColor: "#b7ebc6" },
  closed: { backgroundColor: "#f4f1ec", borderColor: "#e6ded4" },
  dot: { width: 6, height: 6, borderRadius: 3 },
});

export default AppHeader;
