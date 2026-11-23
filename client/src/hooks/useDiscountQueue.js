import { useCallback, useMemo, useState } from "react";
import { useInfiniteQuery, useMutation, useQueryClient } from "@tanstack/react-query";

import toast from "../components/app/Toast";
import {
  approveDiscountClaim,
  discountQueueKey,
  fetchDiscountClaims,
  rejectDiscountClaim,
  undoDiscountClaim,
} from "../lib/agent";

/** A wrong or throttled password is shown in the prompt, not as a toast. */
const PASSWORD_RETRY = ["PASSWORD_CONFIRMATION_INVALID", "PASSWORD_CONFIRMATION_THROTTLED"];

const PER_PAGE = 30;

/**
 * One tab of the discount review queue, the two decisions on it, and undo.
 *
 * The server settles each claim once (a second decision is a 409), so nothing
 * is optimistic here: the panel waits for the answer and keeps the settled
 * claim it returns, which is what the agent sees after the claim has left the
 * Pending list. The admin.discounts broadcast refetches every tab, so another
 * agent's decision removes the claim from this queue too.
 */
export function useDiscountQueue(status) {
  const queryClient = useQueryClient();
  const [decided, setDecided] = useState({});

  const query = useInfiniteQuery({
    queryKey: discountQueueKey(status),
    queryFn: ({ pageParam, signal }) => fetchDiscountClaims({ status, page: pageParam, perPage: PER_PAGE, signal }),
    initialPageParam: 1,
    getNextPageParam: (last) => (last?.current_page < last?.last_page ? last.current_page + 1 : undefined),
    refetchInterval: 60 * 1000,
    refetchOnWindowFocus: true,
    staleTime: 5 * 1000,
  });

  const settle = useCallback(() => queryClient.invalidateQueries({ queryKey: ["discount-claims"] }), [queryClient]);

  // The decision's response carries the settled claim with a narrower user
  // (no phone), so it is laid over the copy the panel was already showing.
  const remember = useCallback((settled, shown) => {
    if (!settled?.id) return;

    setDecided((previous) => ({
      ...previous,
      [settled.id]: { ...shown, ...settled, user: { ...shown?.user, ...settled.user } },
    }));
  }, []);

  const onError = useCallback(
    (error) => {
      if (error?.payload?.code === "ALREADY_REVIEWED") {
        toast.warning("Another agent has already reviewed this application.", "Already reviewed");
        settle();

        return;
      }

      toast.error(error.message, "Could not save the decision");
    },
    [settle]
  );

  const approve = useMutation({
    mutationFn: (claim) => approveDiscountClaim(claim.id),
    onSuccess: (response, claim) => {
      remember(response?.claim, claim);
      toast.success(response?.message ?? "Discount approved.", "Discount approved");
    },
    onError,
    onSettled: settle,
  });

  const reject = useMutation({
    mutationFn: ({ claim, reason }) => rejectDiscountClaim(claim.id, reason),
    onSuccess: (response, { claim }) => {
      remember(response?.claim, claim);
      toast.success(response?.message ?? "Discount application rejected.", "Application rejected");
    },
    onError,
    onSettled: settle,
  });

  // Undo puts the claim back in Pending; the reopened claim is kept like a
  // decision, so the panel shows it ready to decide again.
  const undo = useMutation({
    mutationFn: ({ claim, password }) => undoDiscountClaim(claim.id, password),
    onSuccess: (response, { claim }) => {
      remember(response?.claim, claim);
      toast.success(response?.message ?? "Decision undone.", "Back in Pending");
    },
    onError: (error) => {
      if (PASSWORD_RETRY.includes(error?.payload?.error_code)) return;

      if (["NOT_DECIDED", "REPLACED"].includes(error?.payload?.code)) {
        toast.warning(error.message, "Cannot undo");
        settle();

        return;
      }

      toast.error(error.message, "Could not undo the decision");
    },
    onSettled: settle,
  });

  const pages = query.data?.pages;
  const claims = useMemo(() => (pages ?? []).flatMap((page) => page?.data ?? []), [pages]);
  const head = pages?.[0];

  const pendingId = approve.isPending
    ? approve.variables?.id
    : reject.isPending
      ? reject.variables?.claim?.id
      : undo.isPending
        ? undo.variables?.claim?.id
        : null;

  return {
    claims,
    counts: head?.counts ?? null,
    percentage: head?.percentage ?? null,
    isLoading: query.isLoading,
    isError: query.isError && !query.data,
    error: query.error,
    refetch: query.refetch,
    hasMore: Boolean(query.hasNextPage),
    loadMore: () => query.fetchNextPage(),
    isLoadingMore: query.isFetchingNextPage,
    decided,
    approve: (claim) => approve.mutateAsync(claim),
    reject: (claim, reason) => reject.mutateAsync({ claim, reason }),
    undo: (claim, password) => undo.mutateAsync({ claim, password }),
    isApproving: approve.isPending,
    isRejecting: reject.isPending,
    isUndoing: undo.isPending,
    pendingId,
  };
}

export default useDiscountQueue;
