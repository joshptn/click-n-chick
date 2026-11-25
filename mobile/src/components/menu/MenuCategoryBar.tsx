import { useEffect, useRef } from "react";
import { Pressable, ScrollView, StyleSheet, View, type ScrollViewInstance } from "react-native";

import type { MenuSection } from "../../lib/menuSections";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";

export const MENU_BAR_HEIGHT = 46;

/**
 * The category bar of the home menu, as on the web (MenuCategoryBar.jsx):
 * it sticks under the header (the Home ScrollView's sticky header), underlines
 * the section being read, and a tab scrolls the menu to its section. One row
 * that scrolls sideways, keeping the active tab in view.
 */
export function MenuCategoryBar({
  sections,
  active,
  onPick,
}: {
  sections: MenuSection[];
  active: string | null;
  onPick: (key: string) => void;
}) {
  const row = useRef<ScrollViewInstance>(null);
  const tabs = useRef<Record<string, { x: number; width: number }>>({});
  const rowWidth = useRef(0);

  useEffect(() => {
    const tab = active ? tabs.current[active] : undefined;

    if (!tab || rowWidth.current === 0) return;

    row.current?.scrollTo({ x: Math.max(0, tab.x - (rowWidth.current - tab.width) / 2), animated: true });
  }, [active]);

  return (
    <View style={styles.bar} testID="menu-category-bar">
      <ScrollView
        ref={row}
        horizontal
        showsHorizontalScrollIndicator={false}
        contentContainerStyle={styles.row}
        onLayout={(event) => {
          rowWidth.current = event.nativeEvent.layout.width;
        }}
        accessibilityRole="tablist"
      >
        {sections.map((section) => {
          const current = section.key === active;

          return (
            <Pressable
              key={section.key}
              accessibilityRole="tab"
              accessibilityState={{ selected: current }}
              onPress={() => onPick(section.key)}
              onLayout={(event) => {
                tabs.current[section.key] = { x: event.nativeEvent.layout.x, width: event.nativeEvent.layout.width };
              }}
              style={styles.tab}
              testID={`menu-tab-${section.key}`}
            >
              <AppText family="display" weight="bold" size={11} color={current ? colors.authInk : colors.ink300} style={styles.label}>
                {section.label.toUpperCase()}
              </AppText>
              <View style={[styles.underline, current && styles.underlineOn]} />
            </Pressable>
          );
        })}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  bar: {
    height: MENU_BAR_HEIGHT,
    backgroundColor: colors.white,
    borderBottomWidth: 1,
    borderBottomColor: "#f0e9df",
  },
  row: { paddingHorizontal: 6 },
  tab: { height: MENU_BAR_HEIGHT - 1, justifyContent: "center", paddingHorizontal: 12 },
  label: { letterSpacing: 1.3 },
  underline: { position: "absolute", left: 12, right: 12, bottom: 0, height: 2.5, borderRadius: 2, backgroundColor: "transparent" },
  underlineOn: { backgroundColor: colors.orange },
});

export default MenuCategoryBar;
