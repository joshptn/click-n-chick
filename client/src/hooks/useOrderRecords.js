import { useCallback, useEffect, useRef } from "react";
import { keepPreviousData, useQuery, useQueryClient } from "@tanstack/react-query";

import { fetchRecord, fetchRecords, recordKey, recordsKey } from "../lib/records";

const RECORD_STALE_MS = 60 * 1000;

/** How long a pointer or focus rests on a row before its details are fetched. */
const INTENT_DELAY_MS = 150;

/**
 * One page of Order Records.
 *
 * The previous page stays on screen while the next one loads, so paging and
 * filtering do not flash the table empty. Nothing here writes: records are
 * read-only, and an order closing elsewhere reaches this list through the
 * admin.orders broadcast, which invalidates every key under "orders".
 */
export function useOrderRecords(params, { enabled = true } = {}) {
  const query = useQuery({
    queryKey: recordsKey(params),
    queryFn: ({ signal }) => fetchRecords(params, { signal }),
    placeholderData: keepPreviousData,
    staleTime: 15 * 1000,
    enabled,
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
    staleTime: RECORD_STALE_MS,
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

/**
 * Fetch a record's details when a row is about to be picked: the pointer or
 * focus has rested on it for a moment. Details are still only fetched for
 * records someone is reaching for, never for the whole page, and a record
 * already fetched in the last minute is not asked for again.
 */
export function useRecordPrefetch() {
  const queryClient = useQueryClient();
  const timer = useRef(null);

  const cancel = useCallback(() => {
    window.clearTimeout(timer.current);
    timer.current = null;
  }, []);

  const intend = useCallback(
    (id) => {
      cancel();

      timer.current = window.setTimeout(() => {
        queryClient.prefetchQuery({
          queryKey: recordKey(id),
          queryFn: ({ signal }) => fetchRecord(id, { signal }),
          staleTime: RECORD_STALE_MS,
        });
      }, INTENT_DELAY_MS);
    },
    [queryClient, cancel]
  );

  useEffect(() => cancel, [cancel]);

  return { intend, cancel };
}
