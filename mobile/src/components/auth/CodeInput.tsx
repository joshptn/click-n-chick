import { type ComponentRef, useRef, useState } from "react";
import { Pressable, StyleSheet, TextInput, View } from "react-native";

import { colors } from "../../theme/colors";
import { fonts } from "../../theme/fonts";
import { AppText } from "../AppText";

export const CODE_LENGTH = 6;

type CodeInputProps = {
  value: string;
  onChange: (code: string) => void;
  /** Six boxes (the reset and two-step designs) or one wide field (the sign-up design). */
  variant?: "boxes" | "single";
  autoFocus?: boolean;
  editable?: boolean;
  testID?: string;
};

/**
 * A 6-digit code. One real input takes the typing, pasting and the keyboard's
 * code suggestion from SMS or email; the boxes only draw what it holds.
 */
export function CodeInput({ value, onChange, variant = "boxes", autoFocus = true, editable = true, testID }: CodeInputProps) {
  const inputRef = useRef<ComponentRef<typeof TextInput>>(null);
  const [focused, setFocused] = useState(false);

  const input = (
    <TextInput
      ref={inputRef}
      value={value}
      onChangeText={(text) => onChange(text.replace(/\D/g, "").slice(0, CODE_LENGTH))}
      keyboardType="number-pad"
      inputMode="numeric"
      autoComplete="one-time-code"
      textContentType="oneTimeCode"
      maxLength={CODE_LENGTH}
      autoFocus={autoFocus}
      editable={editable}
      onFocus={() => setFocused(true)}
      onBlur={() => setFocused(false)}
      accessibilityLabel="Verification code"
      testID={testID}
      placeholder={variant === "single" ? "1 2 3 4 5 6" : undefined}
      placeholderTextColor={colors.authHint}
      style={variant === "single" ? styles.single : styles.hidden}
      caretHidden={variant === "boxes"}
    />
  );

  if (variant === "single") return input;

  const digits = value.split("");
  const active = Math.min(digits.length, CODE_LENGTH - 1);

  return (
    <Pressable onPress={() => inputRef.current?.focus()} style={styles.row} accessible={false}>
      {Array.from({ length: CODE_LENGTH }, (_, index) => (
        <View
          key={index}
          style={[styles.box, focused && index === active && styles.boxActive]}
          pointerEvents="none"
        >
          <AppText family="display" weight="semibold" size={20} color={colors.authInk}>
            {digits[index] ?? ""}
          </AppText>
        </View>
      ))}
      {input}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: "row", justifyContent: "space-between", gap: 8 },
  box: {
    flex: 1,
    maxWidth: 48,
    aspectRatio: 0.92,
    borderRadius: 12,
    borderWidth: 1.5,
    borderColor: colors.orangeSoft,
    backgroundColor: colors.white,
    alignItems: "center",
    justifyContent: "center",
    elevation: 2,
    shadowColor: colors.orange,
    shadowOpacity: 0.18,
    shadowRadius: 5,
    shadowOffset: { width: 0, height: 1 },
  },
  boxActive: { borderColor: colors.orange, borderWidth: 2 },
  // Covers the boxes so a tap anywhere lands in it, but draws nothing itself.
  // Not opacity 0: Android would drop it from the accessibility tree.
  hidden: { position: "absolute", top: 0, left: 0, right: 0, bottom: 0, opacity: 0.011, color: "transparent", fontSize: 1 },
  single: {
    height: 58,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: colors.fieldBorder,
    backgroundColor: colors.fieldFill,
    textAlign: "center",
    fontFamily: fonts.display.semibold,
    fontSize: 22,
    letterSpacing: 8,
    color: colors.authInk,
  },
});

export default CodeInput;
