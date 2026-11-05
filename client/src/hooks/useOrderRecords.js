import { keepPreviousData, useQuery } from "@tanstack/react-query";

import { fetchRecord, fetchRecords, recordKey, recordsKey } from "../lib/records";

/**
 * One page of Order Records.
 *
 * The previous page stays on screen while the next one loads, so paging and
 * filtering do not flash the table empty. Nothing here writes: records are
 * read-only, and an order closing elsewhere reaches this list through the
 * admin.orders broadcast, which invalidates every key under "orders".
 */
export function useOrderRecords(params) {
  const query = useQuery({
    queryKey: recordsKey(params),
    queryFn: ({ signal }) => fetchRecords(params, { signal }),
    placeholderData: keepPreviousData,
    staleTime: 15 * 1000,
  });

  return {
    records: query.data?.data ?? [],
    meta: query.data?.meta ?? null,
    summary: query.data?.summary ?? null,
    isLoading: query.isPending,
    isFetching: query.isFetching,
    isPlaceholder: query.isPlaceholderData,
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
  };
}

/** One record in full, for the details panel. */
export function useOrderRecord(id) {
  const query = useQuery({
    queryKey: recordKey(id),
    queryFn: ({ signal }) => fetchRecord(id, { signal }),
    enabled: Boolean(id),
    staleTime: 60 * 1000,
    // A missing or still-open order will not appear by asking again.
    retry: (count, error) => error?.status !== 404 && error?.status !== 403 && count < 2,
  });

  return {
    record: query.data?.data ?? null,
    isLoading: query.isPending && Boolean(id),
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
  };
}
