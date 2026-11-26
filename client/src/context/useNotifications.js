import { useCallback, useContext, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import AuthContext from "./AuthContext";
import { useLiveNotifications } from "./useRealtime";
import {
  NOTIFICATIONS_KEY,
  fetchNotifications,
  markAllNotificationsRead,
  markNotificationRead,
} from "../lib/notifications";

export function useNotifications() {
  const { notifications: live, markAllRead: clearLive } = useLiveNotifications();
  const { token } = useContext(AuthContext);
  const queryClient = useQueryClient();

  // Opened here and not yet confirmed by a refetch; a notice that only arrived
  // over the live connection has no stored copy to update yet.
  const [readIds, setReadIds] = useState(() => new Set());

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: NOTIFICATIONS_KEY,
    queryFn: ({ signal }) => fetchNotifications({ signal }),
    // An inbox needs an account. Without this a guest browsing the menu would
    // ask for one and be refused on every page.
    enabled: Boolean(token),
    staleTime: 30 * 1000,
    retry: false,
  });

  const items = useMemo(() => {
    const stored = data?.data ?? [];
    const storedIds = new Set(stored.map((item) => item?.id).filter((id) => id != null));
    const seen = new Set();
    const merged = [];

    // The stored copy wins over the live one: it knows whether it was read.
    [...live.filter((item) => item?.id == null || !storedIds.has(item.id)), ...stored].forEach((item) => {
      const key = item?.id ?? null;

      if (key !== null && seen.has(key)) return;
      if (key !== null) seen.add(key);

      merged.push(key !== null && readIds.has(key) && !item.is_read ? { ...item, is_read: true } : item);
    });

    return merged;
  }, [live, data, readIds]);

  const unreadCount = useMemo(
    () => items.filter((item) => !item?.is_read).length,
    [items]
  );

  const marking = useMutation({
    mutationFn: markAllNotificationsRead,
    onSettled: () => {
      clearLive();
      queryClient.invalidateQueries({ queryKey: NOTIFICATIONS_KEY });
    },
  });

  const markAllRead = useCallback(() => {
    if (unreadCount === 0) return;

    marking.mutate();
  }, [marking, unreadCount]);

  const { mutate: markOne } = useMutation({
    mutationFn: markNotificationRead,
    onSettled: () => queryClient.invalidateQueries({ queryKey: NOTIFICATIONS_KEY }),
  });

  /** Mark one notice read as it is opened, as the app does. */
  const markRead = useCallback(
    (id) => {
      if (id == null) return;

      setReadIds((prev) => (prev.has(id) ? prev : new Set(prev).add(id)));
      markOne(id);
    },
    [markOne]
  );

  return {
    items,
    unreadCount,
    isLoading,
    isError,
    refetch,
    markAllRead,
    markRead,
    isMarking: marking.isPending,
  };
}

export default useNotifications;
