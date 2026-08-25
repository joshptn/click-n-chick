import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { IconReceipt, IconToolsKitchen2 } from "@tabler/icons-react";

import AppHeader from "../../components/app/AppHeader";
import OrderCard from "../../components/orders/OrderCard";
import { ORDER_FILTERS, ORDERS_KEY, fetchOrders } from "../../lib/orders";
import { useRealtime } from "../../context/useRealtime";


const EMPTY = {
  active: {
    title: "Nothing cooking right now",
    body: "When you place an order it will appear here, and you can follow it from the moment it is confirmed.",
  },
  past: {
    title: "No past orders yet",
    body: "Orders you have collected or received will be kept here.",
  },
  all: {
    title: "You haven't ordered yet",
    body: "Once you place your first order it will show up here, live from the kitchen.",
  },
};

function OrdersSkeleton() {
  return (
    <div className="flex flex-col gap-3">
      {Array.from({ length: 3 }).map((_, index) => (
        <div key={index} className="h-[104px] animate-pulse rounded-[16px] bg-[#f4f1ec]" />
      ))}
    </div>
  );
}

function Orders() {
  const queryClient = useQueryClient();
  const { lastOrderEvent } = useRealtime();

  const [filter, setFilter] = useState("active");

  const [autoSwitched, setAutoSwitched] = useState(false);

  const { data, isLoading, isError } = useQuery({
    queryKey: [...ORDERS_KEY, filter],
    queryFn: ({ signal }) => fetchOrders({ filter, signal }),
    staleTime: 15 * 1000,
  });

  const orders = data?.data ?? [];
  const activeCount = data?.counts?.active ?? 0;

  useEffect(() => {
    if (autoSwitched || isLoading) return;
    if (filter !== "active" || orders.length > 0) return;

    setAutoSwitched(true);
    setFilter("all");
  }, [autoSwitched, filter, isLoading, orders.length]);

  useEffect(() => {
    if (!lastOrderEvent) return;

    queryClient.invalidateQueries({ queryKey: ORDERS_KEY });
  }, [lastOrderEvent, queryClient]);

  const empty = EMPTY[filter] ?? EMPTY.all;

  return (
    <div className="min-h-dvh bg-[#fdfaf6] font-display text-ink">
      <AppHeader />

      <main className="mx-auto w-full max-w-[950px] px-4 py-6 sm:px-6 lg:px-8">
        <div className="mb-5">
          <h1 className="m-0 font-display text-[22px] font-extrabold leading-tight text-ink sm:text-[25px]">
            My orders
          </h1>
          <p className="m-0 mt-1 font-display text-[13px] text-[#6f6b68]">
            {activeCount > 0
              ? `You have ${activeCount} order${activeCount === 1 ? "" : "s"} in progress.`
              : "Everything you have ordered from us."}
          </p>
        </div>

        <div
          role="tablist"
          aria-label="Filter orders"
          className="mb-4 inline-flex rounded-full border border-[#f0e9df] bg-white p-1"
        >
          {ORDER_FILTERS.map((tab) => {
            const selected = filter === tab.id;

            return (
              <button
                key={tab.id}
                type="button"
                role="tab"
                aria-selected={selected}
                onClick={() => {
                  setAutoSwitched(true);
                  setFilter(tab.id);
                }}
                className={`rounded-full px-4 py-1.5 font-display text-[13px] font-semibold transition-colors ${
                  selected ? "bg-brand-500 text-white" : "bg-transparent text-[#6f6b68] hover:text-ink"
                }`}
              >
                {tab.label}
                {tab.id === "active" && activeCount > 0 && (
                  <span
                    className={`ml-1.5 rounded-full px-1.5 py-0.5 text-[10.5px] font-bold ${
                      selected ? "bg-white/25 text-white" : "bg-[#fff4e8] text-brand-600"
                    }`}
                  >
                    {activeCount}
                  </span>
                )}
              </button>
            );
          })}
        </div>

        {isLoading && <OrdersSkeleton />}

        {isError && (
          <section className="rounded-[16px] border border-[#f0e9df] bg-white px-6 py-12 text-center">
            <h2 className="m-0 font-display text-[16px] font-extrabold text-ink">
              Your orders could not be loaded
            </h2>
            <p className="m-0 mt-1.5 font-display text-[13px] text-[#6f6b68]">
              Please check your connection and try again.
            </p>
          </section>
        )}

        {!isLoading && !isError && orders.length === 0 && (
          <section className="rounded-[16px] border border-[#f0e9df] bg-white px-6 py-14 text-center">
            <span className="mx-auto grid h-14 w-14 place-items-center rounded-full bg-[#faf7f3] text-[#c9c3bb]">
              <IconReceipt size={26} stroke={1.8} aria-hidden="true" />
            </span>

            <h2 className="m-0 mt-4 font-display text-[17px] font-extrabold text-ink">{empty.title}</h2>
            <p className="m-0 mx-auto mt-1.5 max-w-[380px] font-display text-[13px] leading-relaxed text-[#6f6b68]">
              {empty.body}
            </p>

            <Link
              to="/home"
              className="mt-5 inline-flex h-[46px] items-center justify-center gap-2 rounded-[10px] bg-brand-500 px-6 font-display text-[14px] font-semibold text-white no-underline shadow-[0_6px_16px_-4px_rgba(255,139,43,0.5)] transition-colors hover:bg-brand-600"
            >
              <IconToolsKitchen2 size={17} stroke={2.2} aria-hidden="true" />
              Browse the menu
            </Link>
          </section>
        )}

        {orders.length > 0 && (
          <div className="flex flex-col gap-3">
            {orders.map((order) => (
              <OrderCard key={order.id} order={order} />
            ))}
          </div>
        )}
      </main>
    </div>
  );
}

export default Orders;
