import { useEffect, useMemo } from "react";
import { useLocalSearchParams } from "expo-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { ORDERS_KEY, orderKey, ordersApi, type OrderAnswer } from "../../api/orders";
import { OrderTrackingView } from "../../components/orders/OrderTrackingView";
import { useOrderChannel } from "../../hooks/useRealtime";
import { toast } from "../../lib/toast";

/** While an order waits in line its place moves with others' orders, which no broadcast announces. */
const IN_LINE_POLL_MS = 30 * 1000;

function errorText(error: unknown, fallback: string) {
  return error instanceof Error && error.message ? error.message : fallback;
}

/** A signed-in customer's order (the root layout keeps this signed-in only). */
export default function CustomerOrder() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const queryClient = useQueryClient();
  const queryKey = useMemo(() => orderKey(id), [id]);

  const query = useQuery({
    queryKey,
    queryFn: ({ signal }) => ordersApi.show(id, signal),
    retry: false,
    refetchOnWindowFocus: true,
    refetchInterval: (current) => (current.state.data?.order.queue?.in_line ? IN_LINE_POLL_MS : false),
  });

  const order = query.data?.order ?? null;

  // The order's own channel, joined once the API has shown it is ours.
  const lastEvent = useOrderChannel(order ? order.id : null);

  useEffect(() => {
    if (!lastEvent) return;

    void queryClient.invalidateQueries({ queryKey: ORDERS_KEY });
  }, [lastEvent, queryClient]);

  const settle = (payload: OrderAnswer) => {
    if (payload.order) queryClient.setQueryData(queryKey, { order: payload.order });
    void queryClient.invalidateQueries({ queryKey: ORDERS_KEY });
  };

  const cancel = useMutation({
    mutationFn: () => ordersApi.cancel(id),
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
    mutationFn: () => ordersApi.confirmReceipt(id),
    onSuccess: (payload) => {
      settle(payload);
      toast.success("Order received", "Thanks for confirming. Enjoy your meal!");
    },
    onError: (error) => {
      toast.error("Something went wrong", errorText(error, "That could not be confirmed."));
      void queryClient.invalidateQueries({ queryKey });
    },
  });

  const confirmDetails = useMutation({
    mutationFn: () => ordersApi.confirmDetails(id),
    onSuccess: (payload) => {
      settle(payload);
      toast.success("Details confirmed", "Thanks, we'll get started.");
    },
    onError: (error) => toast.error("Something went wrong", errorText(error, "That could not be sent.")),
  });

  return (
    <OrderTrackingView
      order={order}
      isLoading={query.isLoading}
      error={query.error}
      refreshing={query.isRefetching}
      onRefresh={() => void query.refetch()}
      onCancel={() => cancel.mutateAsync()}
      onConfirmReceipt={() => confirmReceipt.mutate()}
      onConfirmDetails={() => confirmDetails.mutate()}
      cancelling={cancel.isPending}
      confirmingReceipt={confirmReceipt.isPending}
      confirmingDetails={confirmDetails.isPending}
    />
  );
}
