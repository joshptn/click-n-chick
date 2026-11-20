import { useCallback, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import toast from "../components/app/Toast";
import {
  ADVANCE_REQUESTS_KEY,
  acceptAdvanceRequest,
  advanceAgentOrder,
  advancedLocally,
  advanceOrders,
  cancelAgentOrder,
  fetchAdvanceRequest,
  fetchAdvanceRequests,
  maybeAdvanceEvent,
  rejectAdvanceRequest,
  remindAdvanceRequest,
} from "../lib/agent";
import { useOrderEvents } from "../lib/orderEvents";

const LIST_FOR = {
  submitted: "awaiting_decision",
  accepted: "awaiting_payment",
  awaiting_payment: "awaiting_payment",
  confirmed: "scheduled",
  scheduled: "scheduled",
  preparing: "in_kitchen",
  ready_for_pickup: "in_kitchen",
  completed: "closed",
  rejected: "closed",
  cancelled: "closed",
};

function expired(error) {
  if (error?.payload?.error_code !== "ADVANCE_EXPIRED") return false;

  toast.warning(error.message, "Request expired");

  return true;
}

const LISTS = ["awaiting_decision", "awaiting_payment", "scheduled", "due_today", "in_kitchen", "closed"];

const byCollection = (a, b) => new Date(a.scheduled_for) - new Date(b.scheduled_for);

/** The payload with one request moved to the list its status now puts it in. */
function withRequest(data, updated) {
  const next = { ...data };

  LISTS.forEach((key) => {
    next[key] = (data[key] ?? []).filter((order) => order.id !== updated.id);
  });

  const target = LIST_FOR[updated.status];

  if (target === "closed") {
    next.closed = [updated, ...next.closed];
  } else if (target) {
    next[target] = [...next[target], updated].sort(byCollection);
  }

  if (target === "scheduled" && updated.is_due_today) {
    next.due_today = [...next.due_today, updated].sort(byCollection);
  }

  // The counts move with the cards, since the list is no longer reloaded on
  // every change (decided 2026-11-20).
  next.summary = {
    ...data.summary,
    awaiting_decision: next.awaiting_decision.length,
    awaiting_payment: next.awaiting_payment.length,
    scheduled: next.scheduled.length,
    due_today: next.due_today.length,
    in_kitchen: next.in_kitchen.length,
  };

  return next;
}

/**
 * The advance list and everything a Store Agent does to it.
 *
 * Accept and reject answer with the request itself, so the cache takes the
 * server's copy. Kitchen moves and cancellation go through the shared order
 * endpoints, which answer in the board's shape, so those move the card locally
 * and let the refetch settle it. Every change also arrives over admin.orders,
 * which refetches this key for every agent with the page open.
 */
export function useAdvanceRequests() {
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
    queryKey: ADVANCE_REQUESTS_KEY,
    queryFn: ({ signal }) => fetchAdvanceRequests({ signal }),
    refetchInterval: 30 * 1000,
    refetchOnWindowFocus: true,
    staleTime: 5 * 1000,
  });

  // A full reload, only after something failed: the list may be out of date.
  const settle = useCallback(() => queryClient.invalidateQueries({ queryKey: ADVANCE_REQUESTS_KEY }), [queryClient]);

  const place = useCallback(
    (order) => queryClient.setQueryData(ADVANCE_REQUESTS_KEY, (current) => (current ? withRequest(current, order) : current)),
    [queryClient]
  );

  // Decided 2026-11-20: an order event, or the agent's own move, fetches just
  // that order and updates its card instead of reloading the list.
  const refreshOne = useCallback(
    async (id) => {
      if (!queryClient.getQueryData(ADVANCE_REQUESTS_KEY)) return;

      try {
        const answer = await fetchAdvanceRequest(id);

        if (answer?.order) place(answer.order);
      } catch {
        // Not an advance order, or gone: the interval reload catches up.
      }
    },
    [place, queryClient]
  );

  useOrderEvents((payload) => {
    if (payload?.order?.id && maybeAdvanceEvent(payload)) refreshOne(payload.order.id);
  });

  const failed = useCallback(
    (title) => (error) => {
      if (expired(error)) return;

      // Someone else answered it first; the refetch shows what they decided.
      if (error?.payload?.error_code === "ALREADY_ANSWERED") {
        toast.warning(error.message, "Already answered");

        return;
      }

      toast.error(error.message, title);
    },
    []
  );

  const accept = useMutation({
    mutationFn: ({ order, preparationTime }) => acceptAdvanceRequest(order.id, preparationTime),
    onMutate: ({ order }) => mark(order.id, true),
    onSuccess: (response) => {
      if (response?.order) place(response.order);
      toast.success(response?.message ?? "Request accepted.", "Request accepted");
    },
    onError: (error) => {
      failed("Could not accept the request")(error);
      settle();
    },
    onSettled: (_response, _error, { order }) => mark(order.id, false),
  });

  const reject = useMutation({
    mutationFn: ({ order, reason }) => rejectAdvanceRequest(order.id, reason),
    onMutate: ({ order }) => mark(order.id, true),
    onSuccess: (response) => {
      if (response?.order) place(response.order);
      toast.success(response?.message ?? "Request declined.", "Request declined");
    },
    onError: (error) => {
      failed("Could not decline the request")(error);
      settle();
    },
    onSettled: (_response, _error, { order }) => mark(order.id, false),
  });

  const remind = useMutation({
    mutationFn: (order) => remindAdvanceRequest(order.id),
    onMutate: (order) => mark(order.id, true),
    onSuccess: (response, order) => {
      toast.success(response?.message ?? "Reminder sent.", "Reminder sent");
      refreshOne(order.id);
    },
    onError: (error) => {
      if (expired(error)) return;

      if (error?.status === 429) {
        toast.warning(error.message, "Reminded recently");

        return;
      }

      toast.error(error.message, "Could not send the reminder");
      settle();
    },
    onSettled: (_response, _error, order) => mark(order.id, false),
  });

  const advance = useMutation({
    mutationFn: (order) => advanceAgentOrder(order.id),
    onMutate: async (order) => {
      mark(order.id, true);
      await queryClient.cancelQueries({ queryKey: ADVANCE_REQUESTS_KEY });

      const previous = queryClient.getQueryData(ADVANCE_REQUESTS_KEY);

      place({ ...advancedLocally(order), can_start_preparing: false, can_schedule: false });

      return { previous };
    },
    onError: (error, _order, context) => {
      if (context?.previous) queryClient.setQueryData(ADVANCE_REQUESTS_KEY, context.previous);

      settle();

      if (expired(error)) return;

      toast.error(error.message, "Could not move the order");
    },
    onSuccess: (_response, order) => refreshOne(order.id),
    onSettled: (_response, _error, order) => mark(order.id, false),
  });

  const cancel = useMutation({
    mutationFn: ({ order, reason, refund }) => cancelAgentOrder(order.id, { reason, refund }),
    onMutate: ({ order }) => mark(order.id, true),
    onSuccess: (response, { order, reason }) => {
      place({
        ...order,
        status: "cancelled",
        status_label: "Cancelled",
        cancelled_by_store: true,
        cancellation_reason: reason || null,
        refund_owed: response?.refund_owed ?? 0,
        closed_at: new Date().toISOString(),
      });
      toast.success(response?.message ?? "Order cancelled.", "Order cancelled");
      refreshOne(order.id);
    },
    onError: (error) => {
      settle();

      if (expired(error)) return;

      toast.error(error.message, "Could not cancel the order");
    },
    onSettled: (_response, _error, { order }) => mark(order.id, false),
  });

  const orders = useMemo(() => advanceOrders(query.data), [query.data]);

  return {
    data: query.data ?? null,
    orders,
    isLoading: query.isLoading,
    isError: query.isError && !query.data,
    error: query.error,
    refetch: query.refetch,
    isBusy: (id) => busy.has(id),
    accept: (order, preparationTime) => accept.mutateAsync({ order, preparationTime }),
    isAccepting: accept.isPending,
    reject: (order, reason) => reject.mutateAsync({ order, reason }),
    isRejecting: reject.isPending,
    remind: (order) => remind.mutate(order),
    advance: (order) => advance.mutate(order),
    cancel: (order, options) => cancel.mutateAsync({ order, ...options }),
    isCancelling: cancel.isPending,
  };
}

export default useAdvanceRequests;
