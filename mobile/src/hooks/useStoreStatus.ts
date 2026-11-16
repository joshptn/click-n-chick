import { useQuery } from "@tanstack/react-query";

import { STORE_STATUS_KEY, fetchStoreStatus } from "../api/store";

/**
 * Trading status, refreshed every minute and on returning to the app: the
 * store closes on time whether or not anyone taps anything. As on the web.
 */
export function useStoreStatus() {
  const query = useQuery({
    queryKey: STORE_STATUS_KEY,
    queryFn: ({ signal }) => fetchStoreStatus({ signal }),
    staleTime: 30 * 1000,
    refetchInterval: 60 * 1000,
    refetchOnWindowFocus: true,
  });

  return {
    status: query.data ?? null,
    isLoading: query.isLoading,
    error: query.error,
    refetch: query.refetch,
    isRefetching: query.isRefetching,
    // Optimistic while loading, so the badge does not flash "Closed".
    isOpen: query.data ? query.data.accepting_orders : true,
  };
}

export default useStoreStatus;
