import { useCallback, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import toast from "../components/app/Toast";
import { advanceAgentOrder, cancelAgentOrder } from "../lib/agent";
import { api } from "../lib/api";

/** Keyed under "orders" so the admin.orders broadcast refreshes the list. */
const PENDING_ORDERS_KEY = ["orders", "pending"];

const fetchPendingOrders = ({ signal } = {}) => api.get("/api/agent/pending-orders", { signal });

/**
 * The orders still to prepare, and the two things the order drawer can do.
 *
 * The interval is what moves an order into "needs starting now" as its time
 * approaches, since no broadcast marks that moment.
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

  const settle = useCallback(() => {
    setBusy(null);

    return queryClient.invalidateQueries({ queryKey: ["orders"] });
  }, [queryClient]);

  const advance = useMutation({
    mutationFn: (order) => advanceAgentOrder(order.id),
    onMutate: (order) => setBusy(order.id),
    onError: (error) => toast.error(error.message, "Could not move the order"),
    onSettled: settle,
  });

  const cancel = useMutation({
    mutationFn: ({ order, reason, refund }) => cancelAgentOrder(order.id, { reason, refund }),
    onMutate: ({ order }) => setBusy(order.id),
    onSuccess: (response) => toast.success(response?.message ?? "Order cancelled.", "Order cancelled"),
    onError: (error) => toast.error(error.message, "Could not cancel the order"),
    onSettled: settle,
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
