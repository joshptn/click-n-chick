import { keepPreviousData, useQuery } from "@tanstack/react-query";

import { useRealtime } from "../context/useRealtime";
import { OPERATIONS_KEY, SALES_ANALYTICS_KEY, fetchOperations, fetchSalesAnalytics, fetchSalesLog, salesLogKey } from "../lib/analytics";

const POLL_WITHOUT_SOCKET_MS = 60 * 1000;

const retry = (count, error) => ![401, 403, 422].includes(error?.status) && count < 2;

/** Broadcasts keep these current, so a timer runs only while the socket is down. */
function useLiveOptions() {
  const { isConnected } = useRealtime();

  return {
    staleTime: 30 * 1000,
    refetchOnWindowFocus: true,
    refetchInterval: isConnected ? false : POLL_WITHOUT_SOCKET_MS,
    retry,
  };
}

function shape(query) {
  return {
    data: query.data ?? null,
    isLoading: query.isPending,
    isError: query.isError && !query.data,
    error: query.error,
    refetch: query.refetch,
  };
}

export function useOperations() {
  return shape(
    useQuery({
      queryKey: OPERATIONS_KEY,
      queryFn: ({ signal }) => fetchOperations({ signal }),
      ...useLiveOptions(),
    })
  );
}

export function useSalesAnalytics() {
  return shape(
    useQuery({
      queryKey: SALES_ANALYTICS_KEY,
      queryFn: ({ signal }) => fetchSalesAnalytics({ signal }),
      ...useLiveOptions(),
    })
  );
}

export function useSalesLog(params) {
  return shape(
    useQuery({
      queryKey: salesLogKey(params),
      queryFn: ({ signal }) => fetchSalesLog(params, { signal }),
      placeholderData: keepPreviousData,
      ...useLiveOptions(),
    })
  );
}
