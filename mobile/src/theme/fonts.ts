import {
  Fredoka_400Regular,
  Fredoka_500Medium,
  Fredoka_600SemiBold,
  Fredoka_700Bold,
} from "@expo-google-fonts/fredoka";
import {
  Poppins_300Light,
  Poppins_400Regular,
  Poppins_500Medium,
  Poppins_600SemiBold,
  Poppins_700Bold,
  Poppins_800ExtraBold,
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
  Poppins_300Light,
  Poppins_400Regular,
  Poppins_500Medium,
  Poppins_600SemiBold,
  Poppins_700Bold,
  Poppins_800ExtraBold,
};

export type FontFamily = "body" | "display";
export type FontWeight = "light" | "regular" | "medium" | "semibold" | "bold" | "extrabold";

export const fonts: Record<FontFamily, Record<FontWeight, string>> = {
  // Fredoka has no light or extra-bold cut; the nearest weights stand in.
  body: {
    light: "Fredoka_400Regular",
    regular: "Fredoka_400Regular",
    medium: "Fredoka_500Medium",
    semibold: "Fredoka_600SemiBold",
    bold: "Fredoka_700Bold",
    extrabold: "Fredoka_700Bold",
  },
  display: {
    light: "Poppins_300Light",
    regular: "Poppins_400Regular",
    medium: "Poppins_500Medium",
    semibold: "Poppins_600SemiBold",
    bold: "Poppins_700Bold",
    extrabold: "Poppins_800ExtraBold",
  },
};
