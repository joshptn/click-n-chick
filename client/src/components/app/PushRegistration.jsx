import { useContext, useEffect } from "react";
import { useNavigate } from "react-router-dom";

import AuthContext from "../../context/AuthContext";
import { OPEN_MESSAGE, resumePush } from "../../lib/push";

/**
 * Keeps push working without asking again (PRD §9). Renders nothing.
 *
 * - An account (customer or staff) that turned push on in this browser is
 *   registered again each time it is signed in here: the server dropped the
 *   token when the last sign-in ended, and Firebase may have rotated it.
 * - A tapped notification, while the app is already open, arrives as a message
 *   from the service worker and is opened here without reloading the page.
 */
function PushRegistration() {
  const { user } = useContext(AuthContext);
  const navigate = useNavigate();
  const userId = user?.id ?? null;

  useEffect(() => {
    if (!userId) return;

    resumePush(userId).catch(() => {
      // Quiet: the bell's "⋯" menu still offers "Turn on" if this keeps failing.
    });
  }, [userId]);

  useEffect(() => {
    if (typeof navigator === "undefined" || !("serviceWorker" in navigator)) return undefined;

    const onMessage = (event) => {
      const url = event.data?.type === OPEN_MESSAGE ? event.data.url : null;

      // Our own paths only.
      if (typeof url === "string" && url.startsWith("/") && !url.startsWith("//")) navigate(url);
    };

    navigator.serviceWorker.addEventListener("message", onMessage);

    return () => navigator.serviceWorker.removeEventListener("message", onMessage);
  }, [navigate]);

  return null;
}

export default PushRegistration;
