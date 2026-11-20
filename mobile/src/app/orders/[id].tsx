import { useEffect, useMemo, useRef } from "react";
import { AppState } from "react-native";
import { useLocalSearchParams } from "expo-router";
import * as WebBrowser from "expo-web-browser";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { paymentApi } from "../../api/checkout";
import { ORDERS_KEY, orderKey, ordersApi, type ContactChanges, type OrderAnswer } from "../../api/orders";
import { OrderTrackingView } from "../../components/orders/OrderTrackingView";
import { useOrderChannel } from "../../hooks/useRealtime";
import { toast } from "../../lib/toast";


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

  // Paying for an accepted advance order: Xendit's page in the in-app browser,
  // then, back in the app, the API checks with Xendit (as the payment screen).
  const awaitingReturn = useRef(false);

  const pay = useMutation({
    // Only reached through Review Advance Order.
    mutationFn: () => paymentApi.pay({ orderId: id }, true),
    onSuccess: async (payload) => {
      if (payload.paid || !payload.payment?.url) {
        settle({ order: payload.order });
        return;
      }

      awaitingReturn.current = true;
      await WebBrowser.openBrowserAsync(payload.payment.url, { showTitle: true, toolbarColor: "#ffffff" });
    },
    onError: (error) => toast.error("Payment unavailable", errorText(error, "Payment could not be opened. Please try again.")),
  });

  useEffect(() => {
    const subscription = AppState.addEventListener("change", (state) => {
      if (state !== "active" || !awaitingReturn.current) return;

      paymentApi
        .status({ orderId: id })
        .then((payload) => {
          settle({ order: payload.order });
          if (payload.paid) {
            awaitingReturn.current = false;
            toast.success("Payment received", "Your collection date is booked.");
          }
        })
        .catch(() => void query.refetch());
    });

    return () => subscription.remove();
    // settle and query are stable enough for a listener that only reads them on return.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  // The sheet shows a refusal itself; the order is asked again in case it closed meanwhile.
  const updateContact = useMutation({
    mutationFn: (changes: ContactChanges) => ordersApi.updateContact(id, changes),
    onSuccess: (payload) => {
      settle(payload);
      toast.success("Contact updated", payload.message ?? "Your contact details for this order have been updated.");
    },
    onError: () => void queryClient.invalidateQueries({ queryKey }),
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
      onPay={() => pay.mutate()}
      paying={pay.isPending}
      onUpdateContact={(changes) => updateContact.mutateAsync(changes)}
    />
  );
}
