/**
 * The phone layout (decided 2026-11-26): below 768px the customer's pages take
 * the native app's design, a bottom tab bar and the app's tab screens, in a
 * phone browser and in the installed PWA alike. Wider screens keep the website.
 */
export const PHONE_QUERY = "(max-width: 767.98px)";

/** The app header's height (PhoneHeader); the category bar sticks under it. */
export const PHONE_HEADER_HEIGHT = 58;

/** Where the floating tab bar sits: 8px above the safe area, at least 16px up. */
export const TAB_BAR_BOTTOM = "calc(max(env(safe-area-inset-bottom, 0px), 8px) + 8px)";

/** Room a tab screen leaves under its content for the bar (the app's TAB_BAR_SPACE). */
export const TAB_BAR_SPACE = "calc(104px + env(safe-area-inset-bottom, 0px))";
