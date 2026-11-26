import { useEffect } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { useInfiniteQuery, useQueryClient } from "@tanstack/react-query";
import { IconArrowLeft, IconReceipt, IconToolsKitchen2 } from "@tabler/icons-react";

import AppHeader from "../../components/app/AppHeader";
import Button from "../../components/ui/Button";
import OrderCard from "../../components/orders/OrderCard";
import ScreenTitle from "../../components/phone/ScreenTitle";
import TabScreen from "../../components/phone/TabScreen";
import { ORDER_TABS, ORDERS_KEY, fetchOrders } from "../../lib/orders";
import { useLastOrderEvent } from "../../context/useRealtime";
import { usePhoneLayout } from "../../hooks/usePhoneLayout";

const EMPTY = {
  today: {
    title: "No orders today",
    body: "What you order today stays here until the day ends, even once it is done.",
    link: { to: "/home", label: "Browse the menu" },
  },
  advance: {
    title: "No advance orders",
    body: "Orders booked ahead stay here until their collection day has passed.",
    link: { to: "/advance-order", label: "Order in advance" },
  },
  past: {
    title: "No past orders yet",
    body: "Orders you have collected or received move here once their day has passed.",
  },
  cancelled: {
    title: "Nothing cancelled",
    body: "Orders that are cancelled, declined or rejected are kept here.",
  },
};

function OrdersSkeleton() {
  return (
    <div className="flex flex-col gap-3">
      {Array.from({ length: 2 }).map((_, index) => (
        <div key={index} className="h-[260px] animate-pulse rounded-[18px] bg-[#f4f1ec]" />
      ))}
    </div>
  );
}

/**
 * My Orders (redesigned 2026-11-26, the user's design): Today, Advance, Past
 * and Cancelled. Today and Advance keep an order until its day has passed,
 * whatever became of it; a cancelled one shows in Cancelled at once as well.
 * The tab is in the address (?tab=advance), so other pages can link to it.
 * Dishes are open on Today and Advance and folded on Past and Cancelled.
 *
 * On a phone it is the app's Orders tab (2026-11-26): the centred title and
 * the app's chips, no back button, and the tab bar.
 */
function Orders() {
  const phone = usePhoneLayout();
  const queryClient = useQueryClient();
  const lastOrderEvent = useLastOrderEvent();
  const [params, setParams] = useSearchParams();
  const navigate = useNavigate();

  const tab = ORDER_TABS.some((entry) => entry.id === params.get("tab")) ? params.get("tab") : "today";

  const { data, isLoading, isError, fetchNextPage, hasNextPage, isFetchingNextPage } = useInfiniteQuery({
    queryKey: [...ORDERS_KEY, "tab", tab],
    queryFn: ({ pageParam, signal }) => fetchOrders({ filter: tab, page: pageParam, signal }),
    initialPageParam: 1,
    getNextPageParam: (last) => (last?.meta && last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
    staleTime: 15 * 1000,
  });

  const orders = data?.pages.flatMap((page) => page?.data ?? []) ?? [];
  const first = data?.pages[0];
  const total = first?.meta?.total ?? orders.length;
  const counts = first?.counts ?? {};

  useEffect(() => {
    if (!lastOrderEvent) return;

    queryClient.invalidateQueries({ queryKey: ORDERS_KEY });
  }, [lastOrderEvent, queryClient]);

  const empty = EMPTY[tab];
  const folded = tab === "past" || tab === "cancelled";

  const choose = (id) => setParams(id === "today" ? {} : { tab: id }, { replace: true });

  // Back to wherever the customer came from; opened directly, to the menu.
  const goBack = () => (window.history.state?.idx > 0 ? navigate(-1) : navigate("/home"));

  // Below the tabs: loading, a failure, nothing yet, or the orders.
  const content = (
    <>
      {isLoading && <OrdersSkeleton />}

      {isError && (
        <section className="rounded-[18px] border border-[#f0e9df] bg-white px-6 py-12 text-center">
          <h2 className="m-0 font-display text-[16px] font-extrabold text-ink">Your orders could not be loaded</h2>
          <p className="m-0 mt-1.5 font-display text-[13px] text-[#6f6b68]">Please check your connection and try again.</p>
        </section>
      )}

      {!isLoading && !isError && orders.length === 0 && (
        <section className="rounded-[18px] border border-[#f0e9df] bg-white px-6 py-14 text-center" data-testid="orders-empty">
          <span className="mx-auto grid h-14 w-14 place-items-center rounded-full bg-[#faf7f3] text-[#c9c3bb]">
            <IconReceipt size={26} stroke={1.8} aria-hidden="true" />
          </span>

          <h2 className="m-0 mt-4 font-display text-[17px] font-extrabold text-ink">{empty.title}</h2>
          <p className="m-0 mx-auto mt-1.5 max-w-[380px] font-display text-[13px] leading-relaxed text-[#6f6b68]">{empty.body}</p>

          {empty.link && (
            <Link
              to={empty.link.to}
              className="mt-5 inline-flex h-[46px] items-center justify-center gap-2 rounded-[10px] bg-brand-500 px-6 font-display text-[14px] font-semibold text-white no-underline shadow-[0_6px_16px_-4px_rgba(255,139,43,0.5)] transition-colors hover:bg-brand-600"
            >
              <IconToolsKitchen2 size={17} stroke={2.2} aria-hidden="true" />
              {empty.link.label}
            </Link>
          )}
        </section>
      )}

      {orders.length > 0 && (
        <div className="flex flex-col gap-4" data-testid="orders-list">
          {orders.map((order) => (
            <OrderCard key={`${tab}-${order.id}`} order={order} defaultOpen={!folded} />
          ))}

          {hasNextPage && (
            <div className="flex justify-center pt-1">
              <Button variant="outline" size="sm" onClick={() => fetchNextPage()} loading={isFetchingNextPage} loadingLabel="Loading…">
                Show more
              </Button>
            </div>
          )}
        </div>
      )}
    </>
  );

  if (phone) {
    return (
      <TabScreen className="min-h-dvh bg-[#fffaf6]">
        <div className="sticky top-0 z-30 bg-[#fffaf6]">
          <ScreenTitle
            title="My Orders"
            right={
              !isLoading && !isError ? (
                <span className="font-display text-[12px] text-[#a39f9b]" data-testid="orders-total">
                  {total} order{total === 1 ? "" : "s"}
                </span>
              ) : null
            }
          />

          <div role="tablist" aria-label="Orders" className="flex gap-2 overflow-x-auto px-4 py-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            {ORDER_TABS.map((entry) => {
              const selected = entry.id === tab;
              const count = counts[entry.id];

              return (
                <button
                  key={entry.id}
                  type="button"
                  role="tab"
                  aria-selected={selected}
                  onClick={() => choose(entry.id)}
                  data-testid={`orders-tab-${entry.id}`}
                  className={`inline-flex h-[30px] shrink-0 items-center gap-1.5 rounded-[15px] border border-solid px-4 ${
                    selected ? "border-[#ff8904] bg-[#ff8904] text-white" : "border-[#e5e7eb] bg-white text-[#4b5563]"
                  }`}
                >
                  <span className="font-display text-[12.5px] font-medium">{entry.label}</span>
                  {count > 0 && (
                    <span
                      className={`grid h-[17px] min-w-[17px] place-items-center rounded-full px-1 font-display text-[10px] font-bold ${
                        selected ? "bg-white/30 text-white" : "bg-[#fff1e3] text-brand-600"
                      }`}
                      data-testid={`orders-count-${entry.id}`}
                    >
                      {count}
                    </span>
                  )}
                </button>
              );
            })}
          </div>
        </div>

        <main className="px-4 pt-1.5">{content}</main>
      </TabScreen>
    );
  }

  return (
    <div className="min-h-dvh bg-[#fdfaf6] font-display text-ink">
      <AppHeader />

      <main className="mx-auto w-full max-w-[1120px] px-4 py-5 sm:px-6 sm:py-7 lg:px-8">
        <div className="mb-4 flex items-center gap-3">
          <button
            type="button"
            onClick={goBack}
            aria-label="Go back"
            data-testid="orders-back"
            className="grid h-9 w-9 shrink-0 place-items-center rounded-full text-ink transition-colors hover:bg-[#f3ede4]"
          >
            <IconArrowLeft size={20} stroke={2.2} />
          </button>
          <h1 className="m-0 font-display text-[22px] font-extrabold tracking-[-0.3px] text-brand-600 sm:text-[24px]">My Orders</h1>
        </div>

        <div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-[18px] border border-[#f0e9df] bg-white p-2 sm:p-2.5">
          {/* One row; on a phone it scrolls sideways rather than wrapping. */}
          <div role="tablist" aria-label="Orders" className="-mx-1 flex min-w-0 max-w-full gap-1 overflow-x-auto px-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            {ORDER_TABS.map((entry) => {
              const selected = entry.id === tab;
              const count = counts[entry.id];

              return (
                <button
                  key={entry.id}
                  type="button"
                  role="tab"
                  aria-selected={selected}
                  onClick={() => choose(entry.id)}
                  data-testid={`orders-tab-${entry.id}`}
                  className={`inline-flex h-[42px] shrink-0 items-center gap-2 rounded-[12px] px-3.5 font-display transition-colors sm:px-5 ${
                    selected
                      ? "bg-brand-500 text-white shadow-[0_6px_14px_-6px_rgba(255,139,43,0.6)]"
                      : "bg-transparent text-[#6f6b68] hover:bg-[#faf6f0] hover:text-ink"
                  }`}
                >
                  {/* On the span: Mantine resets type on <button> itself. */}
                  <span className={`text-[14px] ${selected ? "font-bold" : "font-medium"}`}>{entry.label}</span>
                  {count > 0 && (
                    <span
                      className={`grid h-[20px] min-w-[20px] place-items-center rounded-full px-1.5 font-display text-[10.5px] font-bold ${
                        selected ? "bg-white/30 text-white" : "bg-[#fff1e3] text-brand-600"
                      }`}
                      data-testid={`orders-count-${entry.id}`}
                    >
                      {count}
                    </span>
                  )}
                </button>
              );
            })}
          </div>

          {!isLoading && !isError && (
            <span className="px-3 font-display text-[12px] text-[#a39f9b]" data-testid="orders-total">
              {total} order{total === 1 ? "" : "s"}
            </span>
          )}
        </div>

        {content}
      </main>
    </div>
  );
}

export default Orders;
