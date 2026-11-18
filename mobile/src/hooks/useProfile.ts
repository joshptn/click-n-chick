import { useCallback } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";

import { PROFILE_KEY, accountApi, type AccountUser, type Profile } from "../api/account";
import { USER_KEY } from "./useUser";

/**
 * The account pages' one query (GET /api/profile), shared by Profile, Edit
 * Profile and the discount screen. `adopt` writes a change's answer back,
 * and refreshes the signed-in user that the rest of the app reads.
 */
export function useProfile() {
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: PROFILE_KEY,
    queryFn: ({ signal }) => accountApi.profile(signal),
    staleTime: 30 * 1000,
    refetchOnWindowFocus: true,
  });

  const adopt = useCallback(
    (payload: Partial<Profile>) => {
      queryClient.setQueryData<Profile>(PROFILE_KEY, (previous) => (previous ? { ...previous, ...payload } : (payload as Profile)));
      if (payload.user) adoptUser(queryClient, payload.user);
    },
    [queryClient]
  );

  return { ...query, adopt };
}

/** The signed-in user changed (name, photo, 2FA): every screen that reads it updates. */
export function adoptUser(queryClient: ReturnType<typeof useQueryClient>, user: AccountUser) {
  queryClient.setQueryData(USER_KEY, (previous: object | undefined) => ({ ...(previous ?? {}), ...user }));
  queryClient.setQueryData<Profile>(PROFILE_KEY, (previous) => (previous ? { ...previous, user: { ...previous.user, ...user } } : previous));
}

export default useProfile;
