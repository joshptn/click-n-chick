import type { ReactNode } from "react";
import { KeyboardAvoidingView, Modal, Pressable, ScrollView, StyleSheet, View } from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";

import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { IconX } from "../icons";

type SheetProps = {
  visible: boolean;
  title: string;
  subtitle?: string;
  onClose: () => void;
  /** While something is being sent the sheet cannot be dismissed. */
  busy?: boolean;
  children: ReactNode;
  testID?: string;
};

/**
 * A panel from the bottom of the screen, for the short forms the web shows in
 * a dialog: a password before trusting a device or turning 2FA off, and the
 * code that confirms a password change or 2FA.
 */
export function Sheet({ visible, title, subtitle, onClose, busy = false, children, testID }: SheetProps) {
  const insets = useSafeAreaInsets();
  const close = () => {
    if (!busy) onClose();
  };

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={close} statusBarTranslucent>
      <KeyboardAvoidingView behavior="padding" style={styles.root}>
        <Pressable style={styles.backdrop} onPress={close} accessibilityLabel="Close" accessibilityRole="button" />
        <View style={[styles.panel, { paddingBottom: Math.max(insets.bottom, 16) + 8 }]} testID={testID}>
          <View style={styles.grip} />
          <View style={styles.head}>
            <View style={styles.headText}>
              <AppText family="display" weight="semibold" size={17} color={colors.authInk} accessibilityRole="header">
                {title}
              </AppText>
              {subtitle ? (
                <AppText family="display" size={13} color={colors.authMuted}>
                  {subtitle}
                </AppText>
              ) : null}
            </View>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Close"
              onPress={close}
              disabled={busy}
              hitSlop={8}
              style={styles.close}
            >
              <IconX size={18} color={colors.authMuted} />
            </Pressable>
          </View>
          <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={styles.body} bounces={false}>
            {children}
          </ScrollView>
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, justifyContent: "flex-end" },
  backdrop: { ...StyleSheet.absoluteFill, backgroundColor: "rgba(17, 24, 39, 0.45)" },
  panel: {
    maxHeight: "88%",
    backgroundColor: colors.white,
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    paddingTop: 8,
  },
  grip: { alignSelf: "center", width: 40, height: 4, borderRadius: 2, backgroundColor: "#e5e7eb", marginBottom: 10 },
  head: { flexDirection: "row", alignItems: "flex-start", gap: 12, paddingHorizontal: 20 },
  headText: { flex: 1, gap: 2 },
  close: { width: 30, height: 30, borderRadius: 15, alignItems: "center", justifyContent: "center", backgroundColor: "#f3f4f6" },
  body: { paddingHorizontal: 20, paddingTop: 16, gap: 14 },
});

export default Sheet;
