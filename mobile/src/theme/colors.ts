/**
 * The web app's palette (client/src/index.css), so both read as one brand.
 * `brand` is the orange, `cocoa` the dark brown, `cream` the light surfaces.
 */
export const colors = {
  brand50: "#fff6ec",
  brand100: "#ffe8d1",
  brand500: "#ff8b2b",
  brand600: "#f27d1d",
  brand700: "#ff7900",
  brand800: "#d96a10",

  /** The PWA's theme colour: status bar and icon background. */
  theme: "#f58c2f",

  cocoa900: "#2b150a",
  cream: "#faf6f0",
  cream100: "#f5eee3",
  /** The page background the PWA opens on. */
  canvas: "#fff7ed",

  ink: "#292929",
  ink500: "#6f6b68",
  ink300: "#a39f9b",

  white: "#ffffff",
  border: "#ede6dd",

  success: "#2f9e44",
  successSurface: "#f2fbf5",
  danger: "#e5322d",
  dangerSurface: "#fff1f1",

  /** The offline notice, as on the web. */
  notice: "#2f2f2f",
} as const;

export default colors;
