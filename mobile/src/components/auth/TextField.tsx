import { useState } from "react";
import { Pressable, StyleSheet, TextInput, View, type TextInputProps } from "react-native";

import { colors } from "../../theme/colors";
import { fonts } from "../../theme/fonts";
import { AppText } from "../AppText";
import { IconEye, IconEyeOff } from "../icons";

type TextFieldProps = TextInputProps & {
  label: string;
  error?: string;
  /** A password field, with the eye button to show what was typed. */
  secret?: boolean;
};

/** A labelled input in the auth design: rounded, light border, an error line under it. */
export function TextField({ label, error, secret = false, style, onFocus, onBlur, ...rest }: TextFieldProps) {
  const [hidden, setHidden] = useState(true);
  const [focused, setFocused] = useState(false);

  return (
    <View style={styles.wrap}>
      <AppText family="display" weight="medium" size={13} color={colors.authLabel}>
        {label}
      </AppText>

      <View
        style={[
          styles.box,
          focused && styles.boxFocused,
          Boolean(error) && styles.boxError,
        ]}
      >
        <TextInput
          {...rest}
          accessibilityLabel={label}
          secureTextEntry={secret && hidden}
          placeholderTextColor={colors.authHint}
          onFocus={(event) => {
            setFocused(true);
            onFocus?.(event);
          }}
          onBlur={(event) => {
            setFocused(false);
            onBlur?.(event);
          }}
          style={[styles.input, style]}
        />

        {secret && (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={hidden ? "Show password" : "Hide password"}
            onPress={() => setHidden((value) => !value)}
            hitSlop={10}
            style={styles.eye}
          >
            {hidden ? <IconEye size={19} color={colors.authHint} /> : <IconEyeOff size={19} color={colors.authHint} />}
          </Pressable>
        )}
      </View>

      {error ? (
        <AppText family="display" size={12} color={colors.danger} accessibilityLiveRegion="polite">
          {error}
        </AppText>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { gap: 7 },
  box: {
    minHeight: 48,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: colors.fieldBorder,
    backgroundColor: colors.white,
    flexDirection: "row",
    alignItems: "center",
  },
  boxFocused: { borderColor: colors.orangeSoft },
  boxError: { borderColor: "#f5b4b2" },
  input: {
    flex: 1,
    paddingHorizontal: 16,
    paddingVertical: 12,
    fontFamily: fonts.display.regular,
    fontSize: 14.5,
    color: colors.authInk,
  },
  eye: { paddingHorizontal: 14, alignSelf: "stretch", justifyContent: "center" },
});

export default TextField;
