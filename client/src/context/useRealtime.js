import { useContext, useEffect, useState } from "react";

import RealtimeContext from "./RealtimeContext";
import { LastOrderEventContext, LiveNotificationsContext } from "./realtimeSlices";
import { getEcho, isRealtimeConfigured } from "../lib/echo";

/**
 * The socket's connection state and which channels are live.
 *
 * Live notifications and the last order broadcast have their own hooks below,
 * so a component that reads one of them is not re-rendered by the others.
 *
 * Kept out of RealtimeContext.jsx so that file exports only a component -
 * mixing hooks in breaks fast refresh for every consumer of the provider.
 */
export function useRealtime() {
  const context = useContext(RealtimeContext);

  if (!context) {
    throw new Error("useRealtime must be used inside a RealtimeProvider.");
  }

  return context;
}

/** Live notifications only; not re-rendered by connection or order events. */
export function useLiveNotifications() {
  return useContext(LiveNotificationsContext);
}

/** The last staff order broadcast only. */
export function useLastOrderEvent() {
  return useContext(LastOrderEventContext);
}

/**
 * Watch one order's status live.
 *
 * Joined on demand rather than up front: a customer may have many past orders
 * but is only ever looking at one. Subscribing is still gated by
 * routes/channels.php, which admits the owner or staff and nobody else - so
 * passing another customer's id here authorizes nothing, it just fails.
 *
 * @param {number|string|null} orderId
 * @returns {{event: string, order: object}|null} the most recent broadcast
 */
export function useOrderChannel(orderId) {
  const [lastEvent, setLastEvent] = useState(null);

  useEffect(() => {
    if (!orderId || !isRealtimeConfigured()) return undefined;

    const echo = getEcho();

    if (!echo) return undefined;

    const name = `orders.${orderId}`;

    echo.private(name).listen(".order", (payload) => setLastEvent(payload));

    return () => echo.leave(name);
  }, [orderId]);

  return lastEvent;
}

/**
 * Watch a guest's order live.
 *
 * Public rather than private, because a guest has no session to authorize one
 * with: /api/broadcasting/auth sits behind auth:sanctum, and loosening that would
 * hand every other channel a null user to guard against.
 *
 * Nothing is weakened by this. The name is derived one-way from a value only the
 * link holder can produce, the server hands it over on the same request that
 * already proved possession, and the payload is an id and a status - so knowing
 * the name is no more than holding the link already was.
 *
 * @param {string|null} name the channel the tracking response named
 */
export function usePublicOrderChannel(name) {
  const [lastEvent, setLastEvent] = useState(null);

  useEffect(() => {
    if (!name || !isRealtimeConfigured()) return undefined;

    const echo = getEcho();

    if (!echo) return undefined;

    echo.channel(name).listen(".order", (payload) => setLastEvent(payload));

    return () => echo.leave(name);
  }, [name]);

  return lastEvent;
}

export default useRealtime;
