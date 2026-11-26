import { useContext, useEffect } from "react";
import { useQuery } from "@tanstack/react-query";

import AuthContext from "../../context/AuthContext";
import LiveOrderBanner from "./LiveOrderBanner";
import { forgetGuestOrder, useGuestOrders } from "../../lib/guestOrders";
import { fetchGuestOrder, guestOrderKey, withLinkDeadline } from "../../lib/orders";

/**
 * "Track it live" on Home for a guest (FR-02.13c, decided 2026-11-22): the
 * newest order kept in this browser, as the app shows it. Hidden when there is
 * none, once it closes (and forgotten then), and for a signed-in customer, who
 * has My Orders.
 */
function GuestOrderBanner({ className = "mb-5" }) {
  const { token: session } = useContext(AuthContext);
  const [newest] = useGuestOrders();
  const token = !session ? (newest?.token ?? null) : null;

  const { data, error } = useQuery({
    queryKey: guestOrderKey(token),
    // The same entry the tracking page reads, so it is stored the same way.
    queryFn: async ({ signal }) => {
      const payload = await fetchGuestOrder(token, { signal });

      return { ...payload, link: withLinkDeadline(payload?.link) };
    },
    enabled: token !== null,
    retry: false,
    staleTime: 15 * 1000,
    refetchOnWindowFocus: true,
  });

  const order = data?.order ?? null;
  const refused = error?.status === 404 || error?.status === 410;

  useEffect(() => {
    if (token && (refused || order?.is_terminal)) forgetGuestOrder(token);
  }, [token, refused, order?.is_terminal]);

  if (!token || !order || order.is_terminal) return null;

  return <LiveOrderBanner order={order} to={`/track#${token}`} className={className} />;
}

export default GuestOrderBanner;
