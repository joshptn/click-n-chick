import { useQuery } from "@tanstack/react-query";

import { api } from "./api";

/**
 * Trading status: hours, the manual override, delivery availability.
 *
 * Public, so it is fetched with auth:false - the landing page and the header
 * both show the Open/Closed badge before anyone signs in.
 *
 * Refetched on an interval because this is one of the few things that changes
 * without the user doing anything: the store closes at 8pm whether or not
 * anybody clicks. A minute is well inside the window where someone would
 * otherwise be typing into a checkout form the server has already stopped
 * accepting.
 */
export const STORE_STATUS_KEY = ["store", "status"];

export function fetchStoreStatus() {
  return api.get("/api/store/status", { auth: false });
}

export function useStoreStatus() {
  const { data, isLoading, isError } = useQuery({
    queryKey: STORE_STATUS_KEY,
    queryFn: fetchStoreStatus,
    staleTime: 30 * 1000,
    refetchInterval: 60 * 1000,
    refetchOnWindowFocus: true,
  });

  return {
    status: data ?? null,
    isLoading,
    isError,
    // Optimistic while loading: the header should not flash "Closed" on every
    // page load, and every action this gates is re-checked server-side anyway.
    isOpen: data ? Boolean(data.accepting_orders) : true,
    acceptsDelivery: data ? Boolean(data.accepting_delivery) : true,
    acceptsPickup: data ? Boolean(data.accepting_pickup) : true,
  };
}

/** "7:00 AM" from the "07:00" the API returns. */
export function formatStoreTime(value) {
  if (typeof value !== "string" || !value.includes(":")) return "";

  const [rawHour, rawMinute] = value.split(":");
  const hour = Number(rawHour);
  const suffix = hour >= 12 ? "PM" : "AM";
  const display = hour % 12 === 0 ? 12 : hour % 12;

  return `${display}:${rawMinute} ${suffix}`;
}
