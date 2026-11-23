import { useEffect, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";

import { NOTIFICATIONS_KEY, type AppNotification } from "../api/notifications";
import { ORDERS_KEY, orderKey } from "../api/orders";
import { STORE_STATUS_KEY } from "../api/store";
import { disconnectEcho, getEcho, isRealtimeConfigured } from "../lib/echo";
import { toast } from "../lib/toast";
import { useSession } from "./useSession";
import { useUser } from "./useUser";

/**
 * Live updates (Laravel Reverb), as the web's RealtimeProvider:
 *
 *   menu                      public: availability, stock, prices, add-ons, categories
 *   store                     public: ordering paused, delivery, hours
 *   private-notifications.id  the signed-in customer's own notices
 *
 * Each broadcast only marks cached data stale; the screens then ask the API
 * again, which stays the authority. Reverb does not replay what a dropped
 * connection missed, so a reconnect refreshes everything on screen.
 *
 * Mounted once, in the root layout.
 */
export function useRealtimeConnection() {
  const queryClient = useQueryClient();
  const { signedIn } = useSession();
  const user = useUser();
  const userId = user?.id ?? null;

  // A session ending drops the connection: its private subscriptions were
  // authorised as whoever was signed in. The public channels then reconnect.
  const hadSession = useRef(false);
  const [generation, setGeneration] = useState(0);

  useEffect(() => {
    if (signedIn) {
      hadSession.current = true;
      return;
    }

    if (hadSession.current) {
      hadSession.current = false;
      disconnectEcho();
      setGeneration((value) => value + 1);
    }
  }, [signedIn]);

  // --- The connection and the public channels, signed in or not ---
  useEffect(() => {
    const echo = getEcho();

    if (!echo) return undefined;

    const connection = echo.connector.pusher.connection;
    let connectedBefore = connection.state === "connected";

    const onState = ({ current }: { current: string }) => {
      if (current !== "connected") return;

      // Anything broadcast while the connection was down is lost.
      if (connectedBefore) void queryClient.invalidateQueries();

      connectedBefore = true;
    };

    connection.bind("state_change", onState);

    echo
      .channel("menu")
      .listen(".food", () => queryClient.invalidateQueries({ queryKey: ["foods"] }))
      // Prices changed, one dish or a bulk change (FR-02.5). Carts are priced
      // on every read, so refetching is what makes their totals float.
      .listen(".prices", () => {
        void queryClient.invalidateQueries({ queryKey: ["foods"] });
        void queryClient.invalidateQueries({ queryKey: ["cart"] });
        void queryClient.invalidateQueries({ queryKey: ["checkout", "quote"] });
        void queryClient.invalidateQueries({ queryKey: ["advance", "quote"] });
      })
      // An add-on, or one of its sizes, changed or switched (decided 2026-11-24).
      .listen(".addon", () => {
        void queryClient.invalidateQueries({ queryKey: ["foods"] });
        void queryClient.invalidateQueries({ queryKey: ["food"] });
        void queryClient.invalidateQueries({ queryKey: ["cart"] });
        void queryClient.invalidateQueries({ queryKey: ["checkout", "quote"] });
        void queryClient.invalidateQueries({ queryKey: ["advance", "quote"] });
      })
      .listen(".category", (payload: { event?: string }) => {
        void queryClient.invalidateQueries({ queryKey: ["categories"] });
        if (payload?.event === "renamed") void queryClient.invalidateQueries({ queryKey: ["foods"] });
      });

    echo.channel("store").listen(".status", (payload: { store?: unknown }) => {
      if (payload?.store) queryClient.setQueryData(STORE_STATUS_KEY, payload.store);
      else void queryClient.invalidateQueries({ queryKey: STORE_STATUS_KEY });
    });

    return () => {
      connection.unbind("state_change", onState);
      echo.leave("menu");
      echo.leave("store");
    };
  }, [queryClient, generation]);

  // --- The customer's own notifications ---
  useEffect(() => {
    if (!signedIn || userId === null) return undefined;

    const echo = getEcho();

    if (!echo) return undefined;

    const name = `notifications.${userId}`;

    echo.private(name).listen(".notification", (payload: { notification?: AppNotification }) => {
      const notification = payload?.notification;

      if (!notification) return;

      toast.info(notification.title ?? "Update", notification.body);
      void queryClient.invalidateQueries({ queryKey: NOTIFICATIONS_KEY });

      // Order notices come with a status change: the lists and the open order follow.
      if (notification.order_id) {
        void queryClient.invalidateQueries({ queryKey: ORDERS_KEY });
        void queryClient.invalidateQueries({ queryKey: orderKey(notification.order_id) });
      }
    });

    return () => echo.leave(name);
  }, [signedIn, userId, queryClient, generation]);
}

/**
 * The last broadcast about one order, while it is on screen. The API admits
 * its owner only (routes/channels.php), so another id here just fails.
 */
export function useOrderChannel(orderId: number | null) {
  const [lastEvent, setLastEvent] = useState<unknown>(null);

  useEffect(() => {
    if (orderId === null || !isRealtimeConfigured()) return undefined;

    const echo = getEcho();

    if (!echo) return undefined;

    const name = `orders.${orderId}`;

    echo.private(name).listen(".order", (payload: unknown) => setLastEvent(payload));

    return () => echo.leave(name);
  }, [orderId]);

  return lastEvent;
}

/**
 * The last broadcast about a guest's order. Public, because a guest has no
 * session to authorise with; the name is derived one-way from the link's
 * token and handed over only to whoever holds it.
 */
export function usePublicOrderChannel(name: string | null) {
  const [lastEvent, setLastEvent] = useState<unknown>(null);

  useEffect(() => {
    if (!name || !isRealtimeConfigured()) return undefined;

    const echo = getEcho();

    if (!echo) return undefined;

    echo.channel(name).listen(".order", (payload: unknown) => setLastEvent(payload));

    return () => echo.leave(name);
  }, [name]);

  return lastEvent;
}
