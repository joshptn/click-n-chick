import { useCallback, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import toast from "../components/app/Toast";
import { PENDING_ORDERS_KEY, advanceAgentOrder, cancelAgentOrder, fetchPendingOrder } from "../lib/agent";
import { api } from "../lib/api";
import { useOrderEvents } from "../lib/orderEvents";

const fetchPendingOrders = ({ signal } = {}) => api.get("/api/agent/pending-orders", { signal });

const GROUPS = ["now", "later", "preparing", "unsettled"];

const neededAt = (row) => new Date(row.needed_at ?? row.created_at).getTime();

/** The page with one row moved to its group (or off the page), and the counts redone. */
function patchPending(data, id, group, row) {
  const groups = {};

  GROUPS.forEach((key) => {
    groups[key] = (data.groups?.[key] ?? []).filter((item) => item.id !== id);
  });

  if (group && row && groups[group]) {
    groups[group] = [...groups[group], row].sort((a, b) => neededAt(a) - neededAt(b));
  }

  const summary = { ...data.summary };
  GROUPS.forEach((key) => {
    summary[key] = groups[key].length;
  });

  return { ...data, groups, summary };
}

/**
 * The orders still to prepare, and the two things the order drawer can do.
 *
 * The interval is what moves an order into "needs starting now" as its time
 * approaches, since no broadcast marks that moment. Decided 2026-11-20: an
 * order event, or one of the agent's own moves, fetches just that order and
 * updates its row instead of reloading the page.
 */
export function usePendingOrders() {
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState(null);

  const query = useQuery({
    queryKey: PENDING_ORDERS_KEY,
    queryFn: ({ signal }) => fetchPendingOrders({ signal }),
    refetchInterval: 60 * 1000,
    refetchOnWindowFocus: true,
    staleTime: 15 * 1000,
  });

  const refreshOne = useCallback(
    async (id) => {
      if (!queryClient.getQueryData(PENDING_ORDERS_KEY)) return;

      try {
        const answer = await fetchPendingOrder(id);

        queryClient.setQueryData(PENDING_ORDERS_KEY, (current) =>
          current ? patchPending(current, id, answer?.group ?? null, answer?.row ?? null) : current
        );
      } catch {
        // The interval reload catches up.
      }
    },
    [queryClient]
  );

  useOrderEvents((payload) => {
    if (payload?.order?.id) refreshOne(payload.order.id);
  });

  const failed = useCallback(
    (title) => (error) => {
      toast.error(error.message, title);
      queryClient.invalidateQueries({ queryKey: PENDING_ORDERS_KEY });
    },
    [queryClient]
  );

  const advance = useMutation({
    mutationFn: (order) => advanceAgentOrder(order.id),
    onMutate: (order) => setBusy(order.id),
    onSuccess: (_response, order) => refreshOne(order.id),
    onError: failed("Could not move the order"),
    onSettled: () => setBusy(null),
  });

  const cancel = useMutation({
    mutationFn: ({ order, reason, refund }) => cancelAgentOrder(order.id, { reason, refund }),
    onMutate: ({ order }) => setBusy(order.id),
    onSuccess: (response, { order }) => {
      refreshOne(order.id);
      toast.success(response?.message ?? "Order cancelled.", "Order cancelled");
    },
    onError: failed("Could not cancel the order"),
    onSettled: () => setBusy(null),
  });

  return {
    data: query.data ?? null,
    isLoading: query.isPending,
    isError: query.isError && !query.data,
    error: query.error,
    refetch: query.refetch,
    isBusy: (id) => busy === id,
    advance: (order) => advance.mutate(order),
    cancel: (order, options) => cancel.mutateAsync({ order, ...options }),
    isCancelling: cancel.isPending,
  };
}

export default usePendingOrders;
