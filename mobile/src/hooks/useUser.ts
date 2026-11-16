import { useQuery } from "@tanstack/react-query";

import { api } from "../api/client";
import type { AuthUser } from "../api/auth";
import { useSession } from "./useSession";

export const USER_KEY = ["user"] as const;

/** The signed-in account (GET /api/user), or null for a guest. */
export function useUser(): AuthUser | null {
  const { signedIn } = useSession();

  const query = useQuery({
    queryKey: USER_KEY,
    queryFn: ({ signal }) => api.get<AuthUser>("/api/user", { signal }),
    enabled: signedIn,
    staleTime: 5 * 60 * 1000,
  });

  return signedIn ? (query.data ?? null) : null;
}

export default useUser;
