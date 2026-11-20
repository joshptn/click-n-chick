import type { ReactNode } from "react";
import { Modal, Pressable, StyleSheet, View } from "react-native";

import { colors } from "../../theme/colors";
import { AppText } from "../AppText";

type Props = {
  visible: boolean;
  onClose: () => void;
  onConfirm: () => void;
  confirmDisabled?: boolean;
  children: ReactNode;
  testID?: string;
};

/**
 * A centred dialog for the date and time pickers (decided 2026-11-20, the
 * user's design): the backdrop fades, the card holds the picker, and Cancel
 * and OK sit at the bottom right.
 */
export function PickerDialog({ visible, onClose, onConfirm, confirmDisabled = false, children, testID }: Props) {
  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={onClose} statusBarTranslucent>
      <View style={styles.root}>
        <Pressable style={StyleSheet.absoluteFill} onPress={onClose} accessibilityLabel="Close" accessibilityRole="button" />
        <View style={styles.card} testID={testID}>
          {children}
          <View style={styles.actions}>
            <Pressable accessibilityRole="button" onPress={onClose} hitSlop={8} testID={testID ? `${testID}-cancel` : undefined}>
              <AppText family="display" weight="semibold" size={14} color={colors.brand600}>
                Cancel
              </AppText>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              accessibilityState={{ disabled: confirmDisabled }}
              disabled={confirmDisabled}
              onPress={onConfirm}
              hitSlop={8}
              testID={testID ? `${testID}-ok` : undefined}
            >
              <AppText family="display" weight="semibold" size={14} color={confirmDisabled ? DISABLED : colors.brand600}>
                OK
              </AppText>
            </Pressable>
          </View>
        </View>
      </View>
    </Modal>
  );
}

export const DISABLED = "#cfc8bf";

const styles = StyleSheet.create({
  root: { flex: 1, alignItems: "center", justifyContent: "center", padding: 16, backgroundColor: "rgba(17, 24, 39, 0.45)" },
  card: { width: "100%", maxWidth: 360, borderRadius: 24, backgroundColor: colors.white, paddingTop: 20, paddingBottom: 18, overflow: "hidden" },
  actions: { flexDirection: "row", justifyContent: "flex-end", gap: 28, paddingHorizontal: 24, paddingTop: 10 },
});
