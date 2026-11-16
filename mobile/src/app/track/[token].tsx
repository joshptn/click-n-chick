import { useEffect, useMemo } from "react";
import { useLocalSearchParams } from "expo-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { ApiError } from "../../api/client";
import { guestOrderKey, guestOrdersApi, type Order, type OrderAnswer } from "../../api/orders";
import { OrderTrackingView } from "../../components/orders/OrderTrackingView";
import { usePublicOrderChannel } from "../../hooks/useRealtime";
import { forgetGuestOrder, rememberGuestOrder } from "../../lib/guestOrders";
import { toast } from "../../lib/toast";

const IN_LINE_POLL_MS = 30 * 1000;

function errorText(error: unknown, fallback: string) {
  return error instanceof Error && error.message ? error.message : fallback;
}

/**
 * A guest's order, opened from its tracking link (clicknchick://track/<token>,
 * decided 2026-11-17). Open to everyone: the token in the link is what grants
 * access, and the phone keeps it only while the link works.
 */
export default function GuestOrder() {
  const { token } = useLocalSearchParams<{ token: string }>();
  const queryClient = useQueryClient();
  // Built once per token: the effects below depend on it, and a new array
  // each render would refetch on every render after the first broadcast.
  const queryKey = useMemo(() => guestOrderKey(token), [token]);

  const query = useQuery({
    queryKey,
    queryFn: ({ signal }) => guestOrdersApi.show(token, signal),
    enabled: Boolean(token),
    retry: false,
    refetchOnWindowFocus: true,
    refetchInterval: (current) => (current.state.data?.order.queue?.in_line ? IN_LINE_POLL_MS : false),
  });

  const order = query.data?.order ?? null;

  // Remembered for Home's "Track it live" while the link works; forgotten
  // once the API says it no longer does.
  useEffect(() => {
    if (order) {
      void rememberGuestOrder({ token, reference: order.reference, placedAt: order.placed_at, closedAt: order.closed_at });
    }
  }, [order, token]);

  useEffect(() => {
    const status = query.error instanceof ApiError ? query.error.status : 0;
    if (status === 403 || status === 404 || status === 410) void forgetGuestOrder(token);
  }, [query.error, token]);

  const lastEvent = usePublicOrderChannel(query.data?.channel ?? null);

  useEffect(() => {
    if (lastEvent) void queryClient.invalidateQueries({ queryKey });
  }, [lastEvent, queryClient, queryKey]);

  // Merged rather than replaced: the cached answer also carries the channel name.
  const settle = (payload: OrderAnswer) => {
    if (payload.order) {
      const next: Order = payload.order;
      queryClient.setQueryData<{ order: Order; channel: string | null }>(queryKey, (prev) => ({
        channel: prev?.channel ?? null,
        order: next,
      }));
    }
  };

  const cancel = useMutation({
    mutationFn: () => guestOrdersApi.cancel(token),
    onSuccess: (payload) => {
      settle(payload);
      toast.success("Cancelled", payload.message ?? "Your order has been cancelled.");
    },
    onError: (error) => {
      toast.error("Cancellation failed", errorText(error, "That order could not be cancelled."));
      void queryClient.invalidateQueries({ queryKey });
    },
  });

  const confirmReceipt = useMutation({
    mutationFn: () => guestOrdersApi.confirmReceipt(token),
    onSuccess: (payload) => {
      settle(payload);
      toast.success("Order received", "Thanks for confirming. Enjoy your meal!");
    },
    onError: (error) => {
      toast.error("Something went wrong", errorText(error, "That could not be confirmed."));
      void queryClient.invalidateQueries({ queryKey });
    },
  });

  return (
    <OrderTrackingView
      guest
      order={order}
      isLoading={query.isLoading}
      error={query.error}
      refreshing={query.isRefetching}
      onRefresh={() => void query.refetch()}
      onCancel={() => cancel.mutateAsync()}
      onConfirmReceipt={() => confirmReceipt.mutate()}
      cancelling={cancel.isPending}
      confirmingReceipt={confirmReceipt.isPending}
    />
  );
}
