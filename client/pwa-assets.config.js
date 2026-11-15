import { createAppleSplashScreens, defineConfig } from "@vite-pwa/assets-generator/config";

/**
 * App icons and iPhone splash screens for the installed PWA, generated at build
 * time from one image (vite-plugin-pwa reads this file).
 *
 * public/pwa-source.png is a PLACEHOLDER: an orange tile with "CnC". Replace
 * that one file with the real artwork (1024x1024 PNG, key content inside the
 * middle 80% so Android's round crop keeps it) and every size regenerates.
 *
 * The browser-tab favicon is deliberately not generated: it stays the logo in
 * public/logo-icon.png, linked from index.html.
 */
const IPHONES = [
  "iPhone 17 Pro Max", "iPhone 17 Pro", "iPhone Air", "iPhone 17",
  "iPhone 16 Pro Max", "iPhone 16 Pro", "iPhone 16 Plus", "iPhone 16", "iPhone 16e",
  "iPhone 15 Pro Max", "iPhone 15 Pro", "iPhone 15 Plus", "iPhone 15",
  "iPhone 14 Pro Max", "iPhone 14 Pro", "iPhone 14 Plus", "iPhone 14",
  "iPhone 13 Pro Max", "iPhone 13 Pro", "iPhone 13", "iPhone 13 mini",
  "iPhone 12 Pro Max", "iPhone 12 Pro", "iPhone 12", "iPhone 12 mini",
  "iPhone 11 Pro Max", "iPhone 11 Pro", "iPhone 11",
  "iPhone XS Max", "iPhone XS", "iPhone XR", "iPhone X",
  "iPhone 8 Plus", "iPhone 8", 'iPhone SE 4.7"',
];

export default defineConfig({
  headLinkOptions: { preset: "2023" },
  preset: {
    transparent: { sizes: [64, 192, 512], favicons: [] },
    maskable: { sizes: [512], resizeOptions: { background: "#f58c2f" } },
    apple: { sizes: [180], resizeOptions: { background: "#f58c2f" } },
    appleSplashScreens: createAppleSplashScreens(
      {
        padding: 0.35,
        // No dark variant: the app's pages are cream in dark mode too. iOS still
        // gets a landscape size for each device (the generator always makes one).
        resizeOptions: { background: "#fff7ed", fit: "contain" },
        linkMediaOptions: { log: false, addMediaScreen: true, basePath: "/", xhtml: false },
      },
      IPHONES,
    ),
  },
  images: ["public/pwa-source.png"],
});
