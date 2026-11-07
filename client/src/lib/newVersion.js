const STAMP = "chunk-reload-at";

/**
 * Pages load as separate files. After a deploy, a tab still running the old
 * version asks for files that no longer exist: reload once to pick up the new
 * version rather than leave a broken page. Returns false - so the caller shows
 * the error instead - when it reloaded moments ago, which means the files are
 * genuinely unreachable (offline) and another reload would only loop.
 */
export function reloadForNewVersion() {
  try {
    const last = Number(sessionStorage.getItem(STAMP) || 0);

    if (Date.now() - last < 10000) return false;

    sessionStorage.setItem(STAMP, String(Date.now()));
  } catch {
    // No session storage: one reload is still the best way out.
  }

  window.location.reload();

  return true;
}

/**
 * Wraps a lazy component's loader: a failed load reloads once (the page then
 * stays on its loading state until it does), or fails as before.
 */
export function loadOrReload(load) {
  return () =>
    load().catch((error) => {
      if (reloadForNewVersion()) return new Promise(() => {});

      throw error;
    });
}
