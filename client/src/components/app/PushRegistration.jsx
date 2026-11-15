import { useContext, useEffect } from "react";
import { useNavigate } from "react-router-dom";

import AuthContext from "../../context/AuthContext";
import { ROLES } from "../../lib/roles";
import { OPEN_MESSAGE, resumePush } from "../../lib/push";

/**
 * Keeps push working without asking again (PRD §9). Renders nothing.
 *
 * - A customer who turned push on in this browser is registered again each time
 *   they are signed in here: the server dropped the token when the last sign-in
 *   ended, and Firebase may have rotated it.
 * - A tapped notification, while the app is already open, arrives as a message
 *   from the service worker and is opened here without reloading the page.
 */
function PushRegistration() {
  const { user } = useContext(AuthContext);
  const navigate = useNavigate();
  const customerId = user?.role === ROLES.CUSTOMER ? user.id : null;

  useEffect(() => {
    if (!customerId) return;

    resumePush(customerId).catch(() => {
      // Quiet: the bell still offers "Turn on" if this keeps failing.
    });
  }, [customerId]);

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
