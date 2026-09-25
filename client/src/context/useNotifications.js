import { useCallback, useMemo } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { useRealtime } from "./useRealtime";
import {
  NOTIFICATIONS_KEY,
  fetchNotifications,
  markAllNotificationsRead,
} from "../lib/notifications";

export function useNotifications() {
  const { notifications: live, markAllRead: clearLive } = useRealtime();
  const queryClient = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: NOTIFICATIONS_KEY,
    queryFn: ({ signal }) => fetchNotifications({ signal }),
    staleTime: 30 * 1000,
    retry: false,
  });

  const items = useMemo(() => {
    const stored = data?.data ?? [];
    const seen = new Set();
    const merged = [];

    [...live, ...stored].forEach((item) => {
      const key = item?.id ?? null;

      if (key !== null && seen.has(key)) return;
      if (key !== null) seen.add(key);

      merged.push(item);
    });

    return merged;
  }, [live, data]);

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

  return {
    items,
    unreadCount,
    isLoading,
    markAllRead,
    isMarking: marking.isPending,
  };
}

export default useNotifications;
