import { useContext, useEffect, useRef, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { Skeleton } from "@mantine/core";
import { IconArrowRight, IconChevronRight, IconCircleCheck, IconRefresh } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import CancelOrderModal from "../../components/staff/CancelOrderModal";
import LivePill from "../../components/staff/LivePill";
import OrderDetailDrawer from "../../components/staff/OrderDetailDrawer";
import ReadOnlyNotice from "../../components/staff/ReadOnlyNotice";
import StaffLayout from "../../components/staff/StaffLayout";
import StatusSummary from "../../components/staff/StatusSummary";
import Button from "../../components/ui/Button";
import { usePendingOrders } from "../../hooks/usePendingOrders";
import { advanceLabel, kitchenSummary } from "../../lib/agent";
import { formatRecordDate, formatRecordTime, typeLabel } from "../../lib/records";
import { canOperate } from "../../lib/roles";

const PAGE_TITLE = "Pending Orders · Click n Chick";

const SURFACE = "rounded-[14px] bg-white ring-1 ring-[#f0e9df] shadow-[0_1px_2px_rgba(60,40,20,0.03)]";

const AGENT_GRID = "md:grid md:grid-cols-[96px_minmax(130px,0.8fr)_minmax(0,2fr)_minmax(100px,0.6fr)_150px] md:items-center md:gap-x-4";
const MANAGER_GRID = "md:grid md:grid-cols-[96px_minmax(130px,0.8fr)_minmax(0,2fr)_minmax(100px,0.6fr)_16px] md:items-center md:gap-x-4";

function waited(minutes) {
  if (minutes === null || minutes === undefined) return null;

  return minutes < 60 ? `Waiting ${minutes} min` : `Waiting ${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

function TimeCell({ order, withDate }) {
  if (withDate) {
    const moment = order.needed_at ?? order.queued_at ?? order.created_at;

    return (
      <span className="block">
        <span className="block text-[13px] font-semibold text-ink">{formatRecordDate(moment).replace(/, \d{4}$/, "")}</span>
        {order.needed_at && <span className="block text-[11px] text-[#8d8884]">{formatRecordTime(order.needed_at)}</span>}
      </span>
    );
  }

  const late = order.status === "confirmed" && order.needed_at && new Date(order.needed_at) < new Date();

  return (
    <span className="block">
      <span className="block text-[15px] font-semibold tracking-[-0.2px] text-ink">{order.needed_at ? formatRecordTime(order.needed_at) : "ASAP"}</span>
      {late && <span className="block text-[10.5px] font-semibold text-[#b45309]">Past due</span>}
    </span>
  );
}

function AdvanceTag({ order }) {
  if (!order.is_advance) return null;

  return <span className="rounded bg-[#f3ecff] px-1.5 text-[9.5px] font-semibold uppercase tracking-[0.3px] text-[#7c3aed]">Advance</span>;
}

function PendingRow({ order, withDate, canAct, busy, onOpen, onMove }) {
  const addons = (order.items ?? []).flatMap((item) => item.addons ?? []);
  const wait = order.is_advance ? null : waited(order.waiting_minutes);
  const move = canAct ? advanceLabel(order) : null;

  return (
    <div
      role="button"
      tabIndex={0}
      onClick={() => onOpen(order)}
      onKeyDown={(event) => {
        if (event.target === event.currentTarget && (event.key === "Enter" || event.key === " ")) {
          event.preventDefault();
          onOpen(order);
        }
      }}
      data-testid="pending-row"
      data-order={order.id}
      className={`${canAct ? AGENT_GRID : MANAGER_GRID} flex w-full cursor-pointer flex-wrap items-start gap-3 border-b border-[#f7f3ed] bg-white px-4 py-3 text-left font-display outline-none transition-colors last:border-b-0 hover:bg-[#fdfaf6] focus-visible:bg-[#fdfaf6] sm:px-5`}
    >
      <span className="w-[76px] shrink-0 md:w-auto">
        <TimeCell order={order} withDate={withDate} />
      </span>

      <span className="hidden min-w-0 md:block">
        <span className="flex items-center gap-1.5">
          <span className="text-[12.5px] font-semibold text-ink">{order.reference}</span>
          <AdvanceTag order={order} />
        </span>
        <span className="block truncate text-[11.5px] text-[#8d8884]">{order.customer?.name || "Customer"}</span>
      </span>

      <span className="min-w-0 flex-1 basis-[200px]">
        <span className="flex items-center gap-1.5 md:hidden">
          <span className="text-[12.5px] font-semibold text-ink">{order.reference}</span>
          <AdvanceTag order={order} />
          <span className="truncate text-[11.5px] text-[#8d8884]">{order.customer?.name || "Customer"}</span>
        </span>
        <span className="mt-0.5 block text-[12.5px] leading-snug text-[#3f3b38] md:mt-0">{kitchenSummary(order.items)}</span>
        {addons.length > 0 && <span className="block text-[11px] leading-snug text-[#8d8884]">+ {addons.join(", ")}</span>}
        <span className="mt-1 flex flex-wrap gap-x-2 text-[11px] text-[#8d8884] md:hidden">
          <span>{typeLabel(order)}</span>
          {withDate && <span>{order.status_label}</span>}
          {wait && !withDate && <span>{wait}</span>}
        </span>
      </span>

      <span className="hidden min-w-0 md:block">
        <span className="block text-[12px] text-[#5f5b58]">{typeLabel(order)}</span>
        <span className="block truncate text-[11px] text-[#8d8884]">{withDate ? order.status_label : wait}</span>
      </span>

      {canAct ? (
        move ? (
          <button
            type="button"
            disabled={busy}
            onClick={(event) => {
              event.stopPropagation();
              onMove(order);
            }}
            data-testid="pending-move"
            className="inline-flex h-[32px] w-full items-center justify-center gap-1 rounded-[8px] bg-brand-500 px-2.5 font-display font-semibold text-white transition-colors hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-55 md:w-full"
          >
            <span className="truncate text-[12px]">{move}</span>
            <IconArrowRight size={13} stroke={2.4} className="shrink-0" />
          </button>
        ) : (
          <span className="hidden md:block" />
        )
      ) : (
        <IconChevronRight size={15} stroke={2} className="hidden shrink-0 text-[#c9c3bb] md:block" aria-hidden="true" />
      )}
    </div>
  );
}

const Group = ({ testId, title, tone, orders, withDate = false, innerRef, rowProps }) => (
  <section ref={innerRef} className="flex scroll-mt-20 flex-col gap-2" data-testid={testId}>
    <div className="flex items-center gap-2 px-1">
      <span className={`h-2 w-2 shrink-0 rounded-full ${tone}`} aria-hidden="true" />
      <h2 className="m-0 font-display text-[11.5px] font-semibold uppercase tracking-[0.9px] text-[#7a7571]">{title}</h2>
      <span data-testid={`${testId}-count`} className="font-display text-[11.5px] font-semibold tabular-nums text-[#a39f9b]">
        {orders.length}
      </span>
    </div>

    <div className={`${SURFACE} overflow-hidden`}>
      {orders.length === 0 ? (
        <p className="m-0 px-5 py-5 text-center font-display text-[12.5px] text-[#a39f9b]">None right now.</p>
      ) : (
        orders.map((order) => <PendingRow key={order.id} order={order} withDate={withDate} busy={rowProps.isBusy(order.id)} canAct={rowProps.canAct} onOpen={rowProps.onOpen} onMove={rowProps.onMove} />)
      )}
    </div>
  </section>
);

/**
 * Pending Orders: what one shift hands to the next.
 *
 * Confirmed orders the kitchen has not started, by when each is needed, with
 * today's advance orders alongside and what the kitchen is working on. A row
 * moves on one step, as a card on the board does; the server walks the order's
 * own chain. The Store Manager sees it read-only.
 */
function PendingOrders() {
  const { user } = useContext(AuthContext);
  const pending = usePendingOrders();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const [cancelling, setCancelling] = useState(null);
  const groupRefs = useRef({});

  const canAct = canOperate(user);
  const selectedId = searchParams.get("order");
  const groups = pending.data?.groups;
  const summary = pending.data?.summary;
  const orders = groups ? [...groups.now, ...groups.later, ...groups.preparing, ...groups.unsettled] : [];
  const selected = selectedId ? orders.find((order) => String(order.id) === selectedId) ?? null : null;

  useEffect(() => {
    document.title = PAGE_TITLE;

    return () => {
      document.title = "Chick N' Click";
    };
  }, []);

  const closeOrder = () => {
    setSearchParams(
      (params) => {
        params.delete("order");

        return params;
      },
      { replace: true }
    );
  };

  // Closed, or out of the kitchen: it is no longer here, so nothing is left to show.
  const orderLeft = Boolean(selectedId && pending.data && !selected);

  useEffect(() => {
    if (!orderLeft) return;

    setSearchParams(
      (params) => {
        params.delete("order");

        return params;
      },
      { replace: true }
    );
  }, [orderLeft, setSearchParams]);

  const openOrder = (order) => {
    if (order.is_advance) {
      navigate(`/admin/advance-orders?order=${order.id}`);

      return;
    }

    setSearchParams((params) => {
      params.set("order", String(order.id));

      return params;
    });
  };

  const confirmCancel = async (options) => {
    try {
      await pending.cancel(cancelling.order, options);
      setCancelling(null);
    } catch {
      // The hook has already said why.
    }
  };

  const cards = [
    { key: "later", label: "Due Later", tone: "orange", total: summary?.later ?? 0 },
    { key: "now", label: "Due Now", tone: "slate", total: summary?.now ?? 0 },
    { key: "total", label: "Total Pending", tone: "amber", total: (summary?.now ?? 0) + (summary?.later ?? 0) },
  ];

  const jumpTo = (key) => groupRefs.current[key === "total" ? "now" : key]?.scrollIntoView({ behavior: "smooth", block: "start" });

  const rowProps = { canAct, isBusy: pending.isBusy, onOpen: openOrder, onMove: pending.advance };
  const empty = summary && summary.now + summary.later + summary.preparing + summary.unsettled === 0;

  return (
    <StaffLayout>
      <main className="flex min-w-0 flex-1 flex-col">
        <header className="border-b border-[#f0e9df] bg-white px-4 py-3.5 sm:px-6 lg:px-7">
          <div className="flex flex-wrap items-center gap-2.5">
            <h1 className="m-0 font-display text-[18px] font-semibold tracking-[-0.3px] text-ink">Pending Orders</h1>
            <LivePill />
          </div>
          <p className="m-0 font-display text-[12px] text-[#8d8884]">Confirmed orders awaiting kitchen action</p>

          <div className="mt-3.5">
            <StatusSummary columns={cards} onSelect={jumpTo} loading={pending.isLoading} gridClass="sm:grid-cols-3" />
          </div>
        </header>

        <div className="flex flex-1 flex-col gap-4 px-4 py-4 sm:px-6 sm:py-5 lg:px-7">
          {!canAct && <ReadOnlyNotice>Orders are moved by the Store Agent. You are viewing this list read-only.</ReadOnlyNotice>}

          {pending.isError ? (
            <div className={`${SURFACE} flex flex-col items-center gap-3 px-6 py-12 text-center`} data-testid="pending-error">
              <p className="m-0 font-display text-[15px] font-semibold text-ink">The pending orders could not be loaded.</p>
              <p className="m-0 font-display text-[13px] text-[#8d8884]">{pending.error?.message}</p>
              <Button size="sm" variant="outline" onClick={() => pending.refetch()}>
                <IconRefresh size={16} stroke={2} />
                Try again
              </Button>
            </div>
          ) : pending.isLoading ? (
            <div className="flex flex-col gap-3" data-testid="pending-loading" aria-hidden="true">
              <Skeleton height={190} radius={14} />
              <Skeleton height={190} radius={14} />
            </div>
          ) : empty ? (
            <div className={`${SURFACE} flex flex-col items-center gap-2 px-6 py-16 text-center`} data-testid="pending-empty">
              <IconCircleCheck size={30} stroke={1.6} className="text-[#46b46a]" />
              <p className="m-0 font-display text-[15px] font-semibold text-ink">All caught up</p>
              <p className="m-0 font-display text-[12.5px] text-[#8d8884]">No confirmed orders are waiting for the kitchen.</p>
            </div>
          ) : (
            <>
              <Group testId="pending-now" title="Due Now" tone="bg-brand-500" orders={groups.now} rowProps={rowProps} innerRef={(node) => (groupRefs.current.now = node)} />
              <Group testId="pending-later" title="Due Later" tone="bg-[#8d8884]" orders={groups.later} rowProps={rowProps} innerRef={(node) => (groupRefs.current.later = node)} />
              <Group testId="pending-preparing" title="Preparing" tone="bg-[#5b8def]" orders={groups.preparing} rowProps={rowProps} />
              {groups.unsettled.length > 0 && <Group testId="pending-unsettled" title="Unsettled Orders" tone="bg-[#d92d20]" orders={groups.unsettled} withDate rowProps={rowProps} />}
            </>
          )}
        </div>
      </main>

      <OrderDetailDrawer
        order={selected}
        opened={Boolean(selected)}
        onClose={closeOrder}
        busy={selected ? pending.isBusy(selected.id) : false}
        canAct={canAct}
        onAdvance={pending.advance}
        onReject={(order) => setCancelling({ order, mode: "reject" })}
        onCancel={(order) => setCancelling({ order, mode: "cancel" })}
      />

      <CancelOrderModal order={cancelling?.order} mode={cancelling?.mode} opened={Boolean(cancelling)} onClose={() => setCancelling(null)} onConfirm={confirmCancel} loading={pending.isCancelling} />
    </StaffLayout>
  );
}

export default PendingOrders;
