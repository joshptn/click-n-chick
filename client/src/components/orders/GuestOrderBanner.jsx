import { useContext, useEffect } from "react";
import { Link } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { IconChevronRight, IconShoppingBag } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import { forgetGuestOrder, useGuestOrders } from "../../lib/guestOrders";
import { fetchGuestOrder, guestOrderKey, withLinkDeadline } from "../../lib/orders";

/**
 * "Track it live" on Home for a guest (FR-02.13c, decided 2026-11-22): the
 * newest order kept in this browser, as the app shows it. Hidden when there is
 * none, once it closes (and forgotten then), and for a signed-in customer, who
 * has My Orders.
 */
function GuestOrderBanner() {
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

  return (
    <Link
      to={`/track#${token}`}
      className="mb-5 flex items-center gap-3 rounded-[16px] bg-brand-500 px-4 py-3.5 text-white no-underline shadow-[0_6px_16px_-4px_rgba(255,139,43,0.5)] transition-colors hover:bg-brand-600"
    >
      <span className="grid h-10 w-10 shrink-0 place-items-center rounded-[12px] bg-white/20">
        <IconShoppingBag size={20} stroke={2} aria-hidden="true" />
      </span>

      <span className="min-w-0 flex-1">
        <span className="block font-display text-[14px] font-semibold">{order.title}</span>
        <span className="block truncate font-display text-[12px] text-white/90">
          {order.status_label}. Track it live.
        </span>
      </span>

      <span className="flex shrink-0 items-center gap-0.5 rounded-[10px] bg-white/20 px-3 py-1.5 font-display text-[12.5px] font-semibold">
        Track
        <IconChevronRight size={14} stroke={2.4} aria-hidden="true" />
      </span>
    </Link>
  );
}

export default GuestOrderBanner;
