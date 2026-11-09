import { useContext, useEffect, useMemo, useRef, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { IconAlertTriangle, IconRefresh } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import CancelOrderModal from "../../components/staff/CancelOrderModal";
import FilterBar, { FilterMeta } from "../../components/staff/FilterBar";
import LivePill from "../../components/staff/LivePill";
import OrderBoard from "../../components/staff/OrderBoard";
import OrderDetailDrawer from "../../components/staff/OrderDetailDrawer";
import ReadOnlyNotice from "../../components/staff/ReadOnlyNotice";
import StaffLayout from "../../components/staff/StaffLayout";
import StatusSummary from "../../components/staff/StatusSummary";
import Button from "../../components/ui/Button";
import { useAgentBoard } from "../../hooks/useAgentBoard";
import { BOARD_COLUMNS, columnFor, matchesSearch } from "../../lib/agent";
import { canOperate } from "../../lib/roles";
import { formatStoreTime, useStoreStatus } from "../../lib/store";

const PAGE_TITLE = "Orders · Click n Chick";

function boardDate(date) {
  if (!date) return "";

  return new Date(`${date}T00:00:00`).toLocaleDateString("en-PH", {
    weekday: "long",
    month: "long",
    day: "numeric",
  });
}

function StoreNotice({ store }) {
  if (!store) return null;

  if (!store.accepting_orders) {
    const reason =
      store.ordering_override === "closed"
        ? "Ordering is paused from the sidebar."
        : `Online ordering runs ${formatStoreTime(store.opens_at)} – ${formatStoreTime(store.closes_at)}.`;

    return (
      <p className="m-0 flex items-start gap-2 rounded-[10px] bg-[#fff8e1] px-3.5 py-2.5 font-display text-[12px] text-[#8a5d00] ring-1 ring-inset ring-[#ffe59a]">
        <IconAlertTriangle size={15} stroke={2} className="mt-[1px] shrink-0" />
        <span>
          <strong className="font-semibold">Customers cannot check out right now.</strong> {reason} Orders already
          placed stay on the board.
        </span>
      </p>
    );
  }

  if (!store.accepting_delivery) {
    return (
      <p className="m-0 rounded-[10px] bg-white px-3.5 py-2.5 font-display text-[12px] text-[#6f6b68] ring-1 ring-inset ring-[#f0e9df]">
        Delivery is off - new orders are pickup only.
      </p>
    );
  }

  return null;
}

/**
 * The Store Agent's board: every order in today's line, from arrival to handover.
 *
 * Moves go through /api/agent/orders/{id}/advance, which walks the order's own
 * lifecycle, so the board never decides a target status. Another agent's moves
 * arrive over admin.orders. The Store Manager gets the same board, read-only.
 */
function AdminDashboard() {
  const { user } = useContext(AuthContext);
  const board = useAgentBoard();
  // The live copy: the store broadcast updates it the moment staff anywhere flip a switch.
  const { status: storeStatus } = useStoreStatus();
  const columnRefs = useRef({});

  const [search, setSearch] = useState("");
  const [activeColumn, setActiveColumn] = useState("new");
  const [cancelling, setCancelling] = useState(null);
  const [searchParams, setSearchParams] = useSearchParams();

  const selectedId = searchParams.get("order");
  const canAct = canOperate(user);
  const searching = search.trim() !== "";

  const columns = useMemo(() => {
    return BOARD_COLUMNS.map((column) => {
      const all = board.orders.filter((order) => columnFor(order.status) === column.key);
      const orders = searching ? all.filter((order) => matchesSearch(order, search)) : all;
      const total =
        column.key === "completed" ? Math.max(board.queue?.summary?.completed_today ?? 0, all.length) : all.length;

      return { ...column, orders, total };
    });
  }, [board.orders, board.queue, search, searching]);

  const newCount = columns[0].total;
  const shown = columns.reduce((count, column) => count + column.orders.length, 0);

  useEffect(() => {
    document.title = newCount > 0 ? `(${newCount}) New order${newCount === 1 ? "" : "s"} · Click n Chick` : PAGE_TITLE;
  }, [newCount]);

  useEffect(() => () => {
    document.title = "Chick N' Click";
  }, []);

  const selected = selectedId ? board.orders.find((order) => String(order.id) === selectedId) ?? null : null;

  const openOrder = (order) => {
    setSearchParams((params) => {
      params.set("order", String(order.id));

      return params;
    });
  };

  const closeOrder = () => {
    setSearchParams((params) => {
      params.delete("order");

      return params;
    });
  };

  const jumpTo = (key) => {
    setActiveColumn(key);
    columnRefs.current[key]?.scrollIntoView({ behavior: "smooth", block: "nearest", inline: "center" });
  };

  const confirmCancel = async (options) => {
    try {
      await board.cancel(cancelling.order, options);
      setCancelling(null);

      if (selected?.id === cancelling.order.id) closeOrder();
    } catch {
      // The hook has already said why.
    }
  };

  const cardProps = {
    isBusy: board.isBusy,
    canAct,
    onAdvance: board.advance,
    onReject: (order) => setCancelling({ order, mode: "reject" }),
    onOpen: openOrder,
  };

  const summary = board.queue?.summary;

  return (
    <StaffLayout>
      <main className="flex flex-1 flex-col">
        <header className="border-b border-[#f0e9df] bg-white px-4 py-4 sm:px-6 lg:px-7">
          <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2.5">
                <h1 className="m-0 font-display text-[18px] font-bold tracking-[-0.3px] text-ink">Today&rsquo;s Orders</h1>
                <LivePill showLastOrder />
              </div>
              <p className="m-0 mt-0.5 font-display text-[12px] text-[#8d8884]">
                {boardDate(board.queue?.date)}
                {summary?.longest_wait_minutes != null && ` · longest wait ${summary.longest_wait_minutes} min`}
                {summary?.cancelled_today > 0 && ` · ${summary.cancelled_today} cancelled`}
              </p>
            </div>
          </div>

          <div className="mt-4">
            <StatusSummary columns={columns} onSelect={jumpTo} loading={board.isLoading} />
          </div>
        </header>

        <div className="flex flex-1 flex-col gap-3 px-4 py-4 sm:px-6 lg:px-7">
          {!canAct && <ReadOnlyNotice>Orders are moved by the Store Agent. You are viewing this board read-only.</ReadOnlyNotice>}

          <StoreNotice store={storeStatus ?? board.queue?.store} />

          <FilterBar
            search={{
              value: search,
              onChange: setSearch,
              placeholder: "Search orders…",
              title: "Search by order number, customer, phone or item",
              label: "Search orders",
            }}
          >
            {!board.isLoading && (
              <FilterMeta>
                {searching && `${shown} of `}
                {board.orders.length} {board.orders.length === 1 ? "order" : "orders"}
              </FilterMeta>
            )}
          </FilterBar>

          {board.isError ? (
            <div className="flex flex-col items-center gap-3 rounded-[16px] bg-white px-6 py-12 text-center ring-1 ring-[#f0e9df]">
              <p className="m-0 font-display text-[15px] font-semibold text-ink">The board could not be loaded.</p>
              <p className="m-0 font-display text-[13px] text-[#8d8884]">{board.error?.message}</p>
              <Button size="sm" variant="outline" onClick={() => board.refetch()}>
                <IconRefresh size={16} stroke={2} />
                Try again
              </Button>
            </div>
          ) : (
            <OrderBoard
              columns={columns}
              activeColumn={activeColumn}
              onActiveColumnChange={setActiveColumn}
              loading={board.isLoading}
              searching={searching}
              columnRefs={columnRefs}
              cardProps={cardProps}
            />
          )}
        </div>
      </main>

      <OrderDetailDrawer
        order={selected}
        opened={Boolean(selectedId) && !board.isLoading}
        onClose={closeOrder}
        busy={selected ? board.isBusy(selected.id) : false}
        canAct={canAct}
        onAdvance={board.advance}
        onReject={(order) => setCancelling({ order, mode: "reject" })}
        onCancel={(order) => setCancelling({ order, mode: "cancel" })}
      />

      <CancelOrderModal
        order={cancelling?.order}
        mode={cancelling?.mode}
        opened={Boolean(cancelling)}
        onClose={() => setCancelling(null)}
        onConfirm={confirmCancel}
        loading={board.isCancelling}
      />
    </StaffLayout>
  );
}

export default AdminDashboard;
