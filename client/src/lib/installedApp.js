/**
 * True when the page runs as the installed PWA (opened from the home screen),
 * not in a browser tab. `navigator.standalone` is iOS Safari's older signal for
 * the same thing. It does not change while the page is open.
 */
export function isInstalledApp() {
  if (typeof window === "undefined") return false;

  return (
    window.matchMedia?.("(display-mode: standalone)").matches === true ||
    window.navigator.standalone === true
  );
}

export default isInstalledApp;
