import { useEffect, useMemo } from "react";
import { useLocalSearchParams } from "expo-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { ApiError } from "../../api/client";
import {
  guestOrderKey,
  guestOrdersApi,
  withLinkDeadline,
  type ContactChanges,
  type GuestOrderAnswer,
  type Order,
  type OrderAnswer,
  type TrackingLink,
} from "../../api/orders";
import { OrderTrackingView } from "../../components/orders/OrderTrackingView";
import { usePublicOrderChannel } from "../../hooks/useRealtime";
import { forgetGuestOrder, rememberGuestOrder } from "../../lib/guestOrders";
import { toast } from "../../lib/toast";


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
  });

  const order = query.data?.order ?? null;

  // Remembered for Home's "Track it live" while the order is open (decided
  // 2026-11-22, as the browser); forgotten once it closes, or once the API
  // says the link no longer works.
  useEffect(() => {
    if (!order) return;

    if (order.is_terminal) void forgetGuestOrder(token);
    else void rememberGuestOrder({ token, reference: order.reference, placedAt: order.placed_at, closedAt: null });
  }, [order, token]);

  useEffect(() => {
    const status = query.error instanceof ApiError ? query.error.status : 0;
    if (status === 403 || status === 404 || status === 410) void forgetGuestOrder(token);
  }, [query.error, token]);

  const lastEvent = usePublicOrderChannel(query.data?.channel ?? null);

  useEffect(() => {
    if (lastEvent) void queryClient.invalidateQueries({ queryKey });
  }, [lastEvent, queryClient, queryKey]);

  // Merged rather than replaced: the cached answer also carries the channel
  // name and the resend card's details.
  const settle = (payload: OrderAnswer) => {
    if (payload.order) {
      const next: Order = payload.order;
      queryClient.setQueryData<GuestOrderAnswer>(queryKey, (prev) => ({
        channel: prev?.channel ?? null,
        link: prev?.link ?? null,
        order: next,
      }));
    }
  };

  const keepLink = (next: TrackingLink | null | undefined) => {
    if (next === undefined) return;
    queryClient.setQueryData<GuestOrderAnswer>(queryKey, (prev) => (prev ? { ...prev, link: withLinkDeadline(next) } : prev));
  };

  // The card shows the result; a refusal (the wait, the limit, a failed send) is a toast.
  const resendLink = useMutation({
    mutationFn: () => guestOrdersApi.resendLink(token),
    onSuccess: (payload) => keepLink(payload.link),
    onError: (error) => {
      if (error instanceof ApiError) keepLink(error.payload.link as TrackingLink | null | undefined);
      toast.error("Link not sent", errorText(error, "We couldn't send it just now. Please try again in a moment."));
    },
  });

  // Saving the address sends nothing; the guest taps Resend link after.
  const changeLinkEmail = useMutation({
    mutationFn: (email: string) => guestOrdersApi.updateContact(token, { contact_email: email }),
    onSuccess: (payload, email) => {
      settle(payload);
      queryClient.setQueryData<GuestOrderAnswer>(queryKey, (prev) =>
        prev?.link ? { ...prev, link: { ...prev.link, email } } : prev
      );
      toast.success("Email updated", "Tap Resend link to send it there.");
    },
  });

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

  // The sheet shows a refusal itself; the order is asked again in case it closed meanwhile.
  const updateContact = useMutation({
    mutationFn: (changes: ContactChanges) => guestOrdersApi.updateContact(token, changes),
    onSuccess: (payload) => {
      settle(payload);
      toast.success("Contact updated", payload.message ?? "Your contact details for this order have been updated.");
    },
    onError: () => void queryClient.invalidateQueries({ queryKey }),
  });

  return (
    <OrderTrackingView
      onUpdateContact={(changes) => updateContact.mutateAsync(changes)}
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
      trackingLink={query.data?.link ?? null}
      onResendLink={() => resendLink.mutateAsync()}
      onChangeLinkEmail={(email) => changeLinkEmail.mutateAsync(email)}
    />
  );
}
