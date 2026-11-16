import { useEffect, useMemo, useRef } from "react";
import { Pressable, ScrollView, StyleSheet, View } from "react-native";
import { useQuery } from "@tanstack/react-query";

import { ALL_CATEGORY, fetchCategories, type Category } from "../../api/menu";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";

/**
 * The category chips, "All" first, as on the web (CategoryTabs.jsx). The
 * menu filters by the category's name; if the selected category is renamed
 * the selection follows it, and if it is deleted it falls back to All.
 */
export function CategoryChips({ value, onChange }: { value: string; onChange: (next: string) => void }) {
  const { data, isLoading } = useQuery({
    queryKey: ["categories"],
    queryFn: ({ signal }) => fetchCategories(signal),
    staleTime: 5 * 60 * 1000,
  });

  const categories = useMemo<Category[]>(() => (Array.isArray(data) ? data : (data?.data ?? [])), [data]);

  const selectedId = useRef<number | null>(null);

  useEffect(() => {
    if (!data || value === ALL_CATEGORY) {
      selectedId.current = null;
      return;
    }

    const byName = categories.find((category) => category.name === value);

    if (byName) {
      selectedId.current = byName.id;
      return;
    }

    const byId = categories.find((category) => category.id === selectedId.current);

    onChange(byId ? byId.name : ALL_CATEGORY);
  }, [data, categories, value, onChange]);

  if (isLoading) {
    return (
      <View style={styles.row}>
        {Array.from({ length: 4 }, (_, index) => (
          <View key={index} style={styles.skeleton} />
        ))}
      </View>
    );
  }

  const chips = [{ key: ALL_CATEGORY, label: "All" }, ...categories.map((category) => ({ key: category.name, label: category.name }))];

  return (
    <ScrollView
      horizontal
      showsHorizontalScrollIndicator={false}
      contentContainerStyle={styles.row}
      accessibilityRole="tablist"
    >
      {chips.map((chip) => {
        const selected = chip.key === value;

        return (
          <Pressable
            key={chip.key}
            accessibilityRole="tab"
            accessibilityState={{ selected }}
            onPress={() => onChange(chip.key)}
            style={[styles.chip, selected && styles.selected]}
            testID={`category-${chip.label}`}
          >
            <AppText family="display" weight="medium" size={12.5} color={selected ? colors.white : colors.authInk}>
              {chip.label}
            </AppText>
          </Pressable>
        );
      })}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: "row", gap: 8, paddingHorizontal: 16 },
  chip: {
    height: 34,
    paddingHorizontal: 16,
    borderRadius: 17,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: "#e5e7eb",
  },
  selected: {
    backgroundColor: colors.orange,
    borderColor: colors.orange,
    elevation: 3,
    shadowColor: colors.orange,
    shadowOpacity: 0.35,
    shadowRadius: 6,
    shadowOffset: { width: 0, height: 3 },
  },
  skeleton: { width: 82, height: 34, borderRadius: 17, backgroundColor: "#f0e9df" },
});

export default CategoryChips;
