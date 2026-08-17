import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";

import AuthContext from "./AuthContext";
import toast from "../components/app/Toast";
import { ROLES } from "../lib/roles";
import { disconnectEcho, getEcho, isRealtimeConfigured } from "../lib/echo";
import { endSession, sessionDeviceId } from "../lib/session";

const RealtimeContext = createContext(null);

/**
 * Subscribes the signed-in user to the channels the backend already
 * broadcasts on, and turns those broadcasts into UI state.
 *
 * Which channels a client joins is decided here, but whether it MAY join is
 * decided entirely by routes/channels.php - every private subscription goes
 * through /api/broadcasting/auth first. Nothing in this file can widen access:
 * subscribing to a channel the server refuses simply fails.
 *
 *   private-notifications.{id}  every signed-in user, their own only
 *   private-admin.orders        staff firehose (admin, super_admin)
 *   menu                        public; live availability and stock
 *
 * Per-order channels (private-orders.{id}) are joined on demand by
 * useOrderChannel below, since a customer only watches an order while it is
 * actually on screen.
 */
export function RealtimeProvider({ children }) {
  const { user, token } = useContext(AuthContext);
  const queryClient = useQueryClient();

  const [status, setStatus] = useState("idle");
  const [notifications, setNotifications] = useState([]);
  const [lastOrderEvent, setLastOrderEvent] = useState(null);
  // Channels whose subscription has actually been acknowledged by the server.
  // Connection state is NOT the same thing: a private channel needs an auth
  // round trip after the socket opens, and anything broadcast in that window
  // is missed outright - Reverb does not replay. Anything that must not miss
  // an early event should wait on this, not on `isConnected`.
  const [subscribed, setSubscribed] = useState([]);

  // Read inside subscription callbacks without making them a dependency,
  // which would tear the subscription down on every notification.
  const userRef = useRef(user);
  userRef.current = user;

  useEffect(() => {
    if (!token || !isRealtimeConfigured()) {
      setStatus(isRealtimeConfigured() ? "idle" : "unconfigured");
      disconnectEcho();
      return undefined;
    }

    const echo = getEcho();

    if (!echo) return undefined;

    const connection = echo.connector?.pusher?.connection;

    const onState = ({ current }) => setStatus(current);
    connection?.bind("state_change", onState);
    setStatus(connection?.state ?? "connecting");

    const currentUser = userRef.current;
    const channels = [];

    // --- The user's own notifications -------------------------------
    if (currentUser?.id) {
      const name = `notifications.${currentUser.id}`;

      echo.private(name)
        .subscribed(() => setSubscribed((prev) => (prev.includes(name) ? prev : [...prev, name])))
        .listen(".notification", (payload) => {
        const notification = payload?.notification;

        if (!notification) return;

        setNotifications((prev) => [notification, ...prev].slice(0, 30));
        toast.info(notification.body, notification.title ?? "Update");

        // The bell reads the same endpoint; let it refetch rather than
        // trusting this payload to be the whole story.
        queryClient.invalidateQueries({ queryKey: ["notifications"] });
      })
        // Another device signed THIS one out (FR-01.13). The event goes to the
        // whole account, so act only if it names this device. The token is
        // already dead server-side either way; this just gets the browser off
        // a signed-in screen immediately instead of at the next request.
        .listen(".session.revoked", (payload) => {
          const mine = sessionDeviceId();

          if (mine === null || Number(payload?.device_id) !== Number(mine)) return;

          endSession({ reason: "signed_out_remotely" });
        });

      channels.push(name);
    }

    // --- Staff order firehose ---------------------------------------
    const isStaff = currentUser?.role === ROLES.ADMIN || currentUser?.role === ROLES.SUPER_ADMIN;

    if (isStaff) {
      echo.private("admin.orders")
        .subscribed(() => setSubscribed((prev) => (prev.includes("admin.orders") ? prev : [...prev, "admin.orders"])))
        .listen(".order", (payload) => {
        setLastOrderEvent(payload);
        queryClient.invalidateQueries({ queryKey: ["orders"] });

        if (payload?.event === "create") {
          toast.info(`Order #${payload?.order?.id} just came in.`, "New order");
        }
      });

      channels.push("admin.orders");
    }

    // --- Live menu availability (public) ----------------------------
    // Backs the low-stock indicator on the home page: when the Store Agent
    // changes stock, every open menu updates without a refresh.
    echo.channel("menu")
      .subscribed(() => setSubscribed((prev) => (prev.includes("menu") ? prev : [...prev, "menu"])))
      .listen(".food", () => {
      queryClient.invalidateQueries({ queryKey: ["foods"] });
    });

    channels.push("menu");

    return () => {
      connection?.unbind("state_change", onState);
      setSubscribed([]);

      channels.forEach((name) => {
        // leave() drops the private- prefixed variant too.
        echo.leave(name);
      });
    };
  }, [token, user?.id, user?.role, queryClient]);

  // Sign-out must drop the socket: the authorizer holds the old token.
  useEffect(() => {
    if (!token) {
      setNotifications([]);
      setLastOrderEvent(null);
    }
  }, [token]);

  // Mirrors the subscription list onto window purely so end-to-end tests can
  // wait for a channel to be live. Read-only; nothing reads it at runtime.
  useEffect(() => {
    window.__rtSubscribed = subscribed;
  }, [subscribed]);

  const markAllRead = useCallback(() => setNotifications([]), []);

  const value = useMemo(
    () => ({
      status,
      isConnected: status === "connected",
      isConfigured: isRealtimeConfigured(),
      notifications,
      unreadCount: notifications.length,
      lastOrderEvent,
      markAllRead,
      subscribed,
      isSubscribed: (name) => subscribed.includes(name),
      // The user's own channel is live, so no notification can be missed.
      isReady: user?.id ? subscribed.includes(`notifications.${user.id}`) : false,
    }),
    [status, notifications, lastOrderEvent, markAllRead, subscribed, user?.id]
  );

  return <RealtimeContext.Provider value={value}>{children}</RealtimeContext.Provider>;
}

export default RealtimeContext;
