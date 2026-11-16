import {
  Fredoka_400Regular,
  Fredoka_500Medium,
  Fredoka_600SemiBold,
  Fredoka_700Bold,
} from "@expo-google-fonts/fredoka";
import {
  Poppins_400Regular,
  Poppins_500Medium,
  Poppins_600SemiBold,
  Poppins_700Bold,
} from "@expo-google-fonts/poppins";

/**
 * The web app's two faces: Fredoka for the body, Poppins for display text.
 * React Native picks a weight by family name, so each weight is its own font.
 */
export const fontFiles = {
  Fredoka_400Regular,
  Fredoka_500Medium,
  Fredoka_600SemiBold,
  Fredoka_700Bold,
  Poppins_400Regular,
  Poppins_500Medium,
  Poppins_600SemiBold,
  Poppins_700Bold,
};

export type FontFamily = "body" | "display";
export type FontWeight = "regular" | "medium" | "semibold" | "bold";

export const fonts: Record<FontFamily, Record<FontWeight, string>> = {
  body: {
    regular: "Fredoka_400Regular",
    medium: "Fredoka_500Medium",
    semibold: "Fredoka_600SemiBold",
    bold: "Fredoka_700Bold",
  },
  display: {
    regular: "Poppins_400Regular",
    medium: "Poppins_500Medium",
    semibold: "Poppins_600SemiBold",
    bold: "Poppins_700Bold",
  },
};
