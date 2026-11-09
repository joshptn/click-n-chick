import { keepPreviousData, useQuery } from "@tanstack/react-query";

import { fetchUser, fetchUsers, userKey, usersKey } from "../lib/users";

const noRetryOn = (count, error) => ![403, 404, 422].includes(error?.status) && count < 2;

export function useUsers(params) {
  const query = useQuery({
    queryKey: usersKey(params),
    queryFn: ({ signal }) => fetchUsers(params, { signal }),
    placeholderData: keepPreviousData,
    staleTime: 15 * 1000,
    retry: noRetryOn,
  });

  return {
    users: query.data?.data ?? [],
    meta: query.data?.meta ?? null,
    summary: query.data?.summary ?? null,
    isLoading: query.isPending,
    isPlaceholder: query.isPlaceholderData,
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
  };
}

export function useUser(id) {
  const query = useQuery({
    queryKey: userKey(id),
    queryFn: ({ signal }) => fetchUser(id, { signal }),
    enabled: Boolean(id),
    retry: noRetryOn,
  });

  return {
    user: query.data?.data ?? null,
    isLoading: query.isPending && Boolean(id),
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
  };
}
