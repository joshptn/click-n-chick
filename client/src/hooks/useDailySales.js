import { keepPreviousData, useQuery } from "@tanstack/react-query";

import { dailySalesKey, fetchDailySales } from "../lib/salesReport";

export function useDailySales(params) {
  const query = useQuery({
    queryKey: dailySalesKey(params),
    queryFn: ({ signal }) => fetchDailySales(params, { signal }),
    placeholderData: keepPreviousData,
    staleTime: 15 * 1000,
    retry: (count, error) => ![403, 422].includes(error?.status) && count < 2,
  });

  return {
    report: query.data ?? null,
    isLoading: query.isPending,
    isPlaceholder: query.isPlaceholderData,
    isError: query.isError && !query.isPlaceholderData,
    error: query.error,
    refetch: query.refetch,
  };
}

export default useDailySales;
