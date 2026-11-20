import { useCallback, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import toast from "../components/app/Toast";
import {
  AGENT_QUEUE_KEY,
  advanceAgentOrder,
  advancedLocally,
  boardOrders,
  cancelAgentOrder,
  fetchAgentOrder,
  fetchAgentQueue,
  maybeAdvanceEvent,
} from "../lib/agent";
import { useOrderEvents } from "../lib/orderEvents";

const LINE = ["placed", "confirmed", "preparing"];
const HANDOVER = ["ready_for_pickup", "on_the_way"];
const DONE = ["completed", "delivered"];

/** The day's number, then the id: numbers may repeat (decided 2026-11-20). */
const byQueueNumber = (a, b) => Number(a.order_number ?? 0) - Number(b.order_number ?? 0) || a.id - b.id;

const strip = (list = [], id) => list.filter((order) => order.id !== id);

/**
 * The board with one order put wherever its status now puts it, or taken off
 * when it no longer belongs (`onBoard` false). The day's finished and
 * cancelled counts move with it, counted only when the card was on the board
 * before, so the same change arriving twice counts once.
 */
function patchBoard(queue, updated, onBoard = true) {
  const before = boardOrders(queue).find((order) => order.id === updated.id) ?? null;

  const next = {
    ...queue,
    line: strip(queue.line, updated.id),
    handover: strip(queue.handover, updated.id),
    completed: strip(queue.completed, updated.id),
  };

  if (!onBoard) return next;

  if (LINE.includes(updated.status)) next.line = [...next.line, updated].sort(byQueueNumber);
  else if (HANDOVER.includes(updated.status)) next.handover = [...next.handover, updated].sort(byQueueNumber);
  else if (DONE.includes(updated.status)) next.completed = [updated, ...next.completed];

  if (before) {
    const summary = { ...queue.summary };

    if (!DONE.includes(before.status) && DONE.includes(updated.status)) {
      summary.completed_today = (summary.completed_today ?? 0) + 1;
    }

    if (updated.status === "cancelled") {
      summary.cancelled_today = (summary.cancelled_today ?? 0) + 1;
    }

    next.summary = summary;
  }

  return next;
}

/**
 * Today's board, and the two things an agent does to it.
 *
 * Both actions update the cache optimistically - a card that waits a round trip
 * to move feels broken at a busy counter - then take the server's answer.
 * Decided 2026-11-20: an order event (another agent's move, a new payment)
 * fetches just that order and updates its card; the whole board reloads only
 * on the 30-second interval, on focus, and when the live connection returns.
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

  const patch = useCallback(
    (order, onBoard = true) =>
      queryClient.setQueryData(AGENT_QUEUE_KEY, (current) => (current ? patchBoard(current, order, onBoard) : current)),
    [queryClient]
  );

  // One card at a time. An advance order never sits on this board, so an
  // event that can only be one is skipped without a request.
  const refreshOne = useCallback(
    async (id) => {
      if (!queryClient.getQueryData(AGENT_QUEUE_KEY)) return;

      try {
        const answer = await fetchAgentOrder(id);

        if (answer?.order) patch(answer.order, Boolean(answer.on_board));
      } catch {
        // The interval reload catches up.
      }
    },
    [patch, queryClient]
  );

  useOrderEvents((payload) => {
    if (payload?.order?.id && !maybeAdvanceEvent(payload)) refreshOne(payload.order.id);
  });

  const reload = useCallback(() => queryClient.invalidateQueries({ queryKey: AGENT_QUEUE_KEY }), [queryClient]);

  const advance = useMutation({
    mutationFn: (order) => advanceAgentOrder(order.id),
    onMutate: async (order) => {
      mark(order.id, true);
      await queryClient.cancelQueries({ queryKey: AGENT_QUEUE_KEY });

      const previous = queryClient.getQueryData(AGENT_QUEUE_KEY);

      if (previous) queryClient.setQueryData(AGENT_QUEUE_KEY, patchBoard(previous, advancedLocally(order)));

      return { previous };
    },
    onError: (error, _order, context) => {
      if (context?.previous) queryClient.setQueryData(AGENT_QUEUE_KEY, context.previous);

      toast.error(error.message, "Could not move the order");
      reload();
    },
    onSuccess: (response) => {
      if (response?.order) patch(response.order);
    },
    onSettled: (_response, _error, order) => mark(order.id, false),
  });

  const cancel = useMutation({
    mutationFn: ({ order, reason, refund }) => cancelAgentOrder(order.id, { reason, refund }),
    onMutate: ({ order }) => mark(order.id, true),
    onSuccess: (response, { order }) => {
      patch({ ...order, status: "cancelled" });
      toast.success(response?.message ?? "Order cancelled.", "Order cancelled");
    },
    onError: (error) => {
      toast.error(error.message, "Could not cancel the order");
      reload();
    },
    onSettled: (_response, _error, { order }) => mark(order.id, false),
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
