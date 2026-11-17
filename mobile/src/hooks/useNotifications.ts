import { useCallback, useMemo } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { NOTIFICATIONS_KEY, notificationsApi, type AppNotification } from "../api/notifications";
import { useSession } from "./useSession";

/**
 * The signed-in customer's notifications (the web's bell), newest first.
 * Live notices arrive through useRealtimeConnection, which marks this stale.
 */
export function useNotifications() {
  const { signedIn } = useSession();
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: NOTIFICATIONS_KEY,
    queryFn: ({ signal }) => notificationsApi.list(signal),
    enabled: signedIn,
    staleTime: 30 * 1000,
    refetchOnWindowFocus: true,
  });

  const notifications = useMemo(() => (signedIn ? (query.data?.data ?? []) : []), [signedIn, query.data]);
  const unreadCount = useMemo(() => notifications.filter((item) => !item.is_read).length, [notifications]);

  const setRead = useCallback(
    (ids: number[] | "all") =>
      queryClient.setQueryData<{ data: AppNotification[] }>(NOTIFICATIONS_KEY, (prev) =>
        prev
          ? {
              ...prev,
              data: prev.data.map((item) => (ids === "all" || ids.includes(item.id) ? { ...item, is_read: true } : item)),
            }
          : prev
      ),
    [queryClient]
  );

  const markRead = useMutation({
    mutationFn: (id: number) => notificationsApi.markRead(id),
    onMutate: (id) => setRead([id]),
    onError: () => queryClient.invalidateQueries({ queryKey: NOTIFICATIONS_KEY }),
  });

  const markAllRead = useMutation({
    mutationFn: () => notificationsApi.markAllRead(),
    onMutate: () => setRead("all"),
    onError: () => queryClient.invalidateQueries({ queryKey: NOTIFICATIONS_KEY }),
  });

  return {
    notifications,
    unreadCount,
    isLoading: signedIn && query.isLoading,
    isError: query.isError,
    isRefetching: query.isRefetching,
    refetch: query.refetch,
    markRead: markRead.mutate,
    markAllRead: markAllRead.mutate,
  };
}

export default useNotifications;
