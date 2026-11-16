import { Text, type TextProps } from "react-native";

import { colors } from "../theme/colors";
import { type FontFamily, type FontWeight, fonts } from "../theme/fonts";

type AppTextProps = TextProps & {
  family?: FontFamily;
  weight?: FontWeight;
  size?: number;
  color?: string;
};

/** Text in the brand faces: Fredoka by default, Poppins with family="display". */
export function AppText({
  family = "body",
  weight = "regular",
  size = 15,
  color = colors.ink,
  style,
  ...rest
}: AppTextProps) {
  return (
    <Text
      {...rest}
      style={[{ fontFamily: fonts[family][weight], fontSize: size, color, lineHeight: Math.round(size * 1.4) }, style]}
    />
  );
}

export default AppText;
