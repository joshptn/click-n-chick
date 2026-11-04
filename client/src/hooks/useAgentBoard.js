import { useCallback, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import toast from "../components/app/Toast";
import {
  AGENT_QUEUE_KEY,
  advanceAgentOrder,
  advancedLocally,
  boardOrders,
  cancelAgentOrder,
  fetchAgentQueue,
} from "../lib/agent";

const LINE = ["placed", "confirmed", "preparing"];
const HANDOVER = ["ready_for_pickup", "on_the_way"];
const DONE = ["completed", "delivered"];

const byQueueNumber = (a, b) => (a.queue_number ?? 0) - (b.queue_number ?? 0);

/** The queue payload with one order moved to wherever its status now puts it. */
function withOrder(queue, updated) {
  const strip = (list = []) => list.filter((order) => order.id !== updated.id);

  let line = strip(queue.line);
  let handover = strip(queue.handover);
  let completed = strip(queue.completed);

  if (LINE.includes(updated.status)) line = [...line, updated].sort(byQueueNumber);
  else if (HANDOVER.includes(updated.status)) handover = [...handover, updated].sort(byQueueNumber);
  else if (DONE.includes(updated.status)) completed = [updated, ...completed];

  return { ...queue, line, handover, completed };
}

function withoutOrder(queue, id) {
  const strip = (list = []) => list.filter((order) => order.id !== id);

  return {
    ...queue,
    line: strip(queue.line),
    handover: strip(queue.handover),
    completed: strip(queue.completed),
    summary: { ...queue.summary, cancelled_today: (queue.summary?.cancelled_today ?? 0) + 1 },
  };
}

/**
 * Today's board, and the two things an agent does to it.
 *
 * Both actions update the cache optimistically - a card that waits a round trip
 * to move feels broken at a busy counter - then settle on the server's answer.
 * The admin.orders broadcast refetches the same key, so another agent's moves
 * arrive here without a reload; the interval is only a floor for a dropped
 * socket.
 */
export function useAgentBoard() {
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState(() => new Set());

  const mark = useCallback((id, on) => {
    setBusy((previous) => {
      const next = new Set(previous);

      if (on) next.add(id);
      else next.delete(id);

      return next;
    });
  }, []);

  const query = useQuery({
    queryKey: AGENT_QUEUE_KEY,
    queryFn: ({ signal }) => fetchAgentQueue({ signal }),
    refetchInterval: 30 * 1000,
    refetchOnWindowFocus: true,
    staleTime: 5 * 1000,
  });

  const settle = useCallback(() => queryClient.invalidateQueries({ queryKey: AGENT_QUEUE_KEY }), [queryClient]);

  const advance = useMutation({
    mutationFn: (order) => advanceAgentOrder(order.id),
    onMutate: async (order) => {
      mark(order.id, true);
      await queryClient.cancelQueries({ queryKey: AGENT_QUEUE_KEY });

      const previous = queryClient.getQueryData(AGENT_QUEUE_KEY);

      if (previous) {
        queryClient.setQueryData(AGENT_QUEUE_KEY, withOrder(previous, advancedLocally(order)));
      }

      return { previous };
    },
    onError: (error, _order, context) => {
      if (context?.previous) queryClient.setQueryData(AGENT_QUEUE_KEY, context.previous);

      toast.error(error.message, "Could not move the order");
    },
    onSuccess: (response) => {
      if (response?.order) {
        queryClient.setQueryData(AGENT_QUEUE_KEY, (current) => (current ? withOrder(current, response.order) : current));
      }
    },
    onSettled: (_response, _error, order) => {
      mark(order.id, false);
      settle();
    },
  });

  const cancel = useMutation({
    mutationFn: ({ order, reason, refund }) => cancelAgentOrder(order.id, { reason, refund }),
    onMutate: ({ order }) => mark(order.id, true),
    onSuccess: (response, { order }) => {
      queryClient.setQueryData(AGENT_QUEUE_KEY, (current) => (current ? withoutOrder(current, order.id) : current));
      toast.success(response?.message ?? "Order cancelled.", "Order cancelled");
    },
    onError: (error) => toast.error(error.message, "Could not cancel the order"),
    onSettled: (_response, _error, { order }) => {
      mark(order.id, false);
      settle();
    },
  });

  const orders = useMemo(() => boardOrders(query.data), [query.data]);

  return {
    queue: query.data ?? null,
    orders,
    isLoading: query.isLoading,
    isError: query.isError && !query.data,
    error: query.error,
    refetch: query.refetch,
    isBusy: (id) => busy.has(id),
    advance: (order) => advance.mutate(order),
    cancel: (order, options) => cancel.mutateAsync({ order, ...options }),
    isCancelling: cancel.isPending,
  };
}

export default useAgentBoard;
