import { useSyncExternalStore } from "react";
import { IconWifiOff } from "@tabler/icons-react";

function subscribe(onChange) {
  window.addEventListener("online", onChange);
  window.addEventListener("offline", onChange);

  return () => {
    window.removeEventListener("online", onChange);
    window.removeEventListener("offline", onChange);
  };
}

const isOnline = () => navigator.onLine;

/**
 * Shown while the device has no connection (NFR-05). The app has no offline
 * features: this only says why nothing loads, instead of leaving a spinner.
 * It goes away by itself when the connection returns.
 */
function OfflineNotice() {
  const online = useSyncExternalStore(subscribe, isOnline, () => true);

  if (online) return null;

  return (
    <div
      role="status"
      data-testid="offline-notice"
      className="fixed inset-x-0 bottom-0 z-[400] flex items-center justify-center gap-2.5 bg-[#2f2f2f] px-4 py-3 text-white shadow-[0_-4px_16px_rgba(0,0,0,0.18)]"
    >
      <IconWifiOff size={18} stroke={2} aria-hidden="true" className="shrink-0" />
      <p className="m-0 font-display text-[13px] leading-snug">
        You are offline. Check your connection; Click&nbsp;n&nbsp;Chick needs it to load the menu and your orders.
      </p>
    </div>
  );
}

export default OfflineNotice;
