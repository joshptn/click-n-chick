import { useContext, useEffect, useMemo, useRef, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { IconInfoCircle, IconRefresh } from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import AdvanceDetailDrawer from "../../components/staff/AdvanceDetailDrawer";
import AdvanceOrderCard from "../../components/staff/AdvanceOrderCard";
import CancelOrderModal from "../../components/staff/CancelOrderModal";
import LivePill from "../../components/staff/LivePill";
import OrderBoard from "../../components/staff/OrderBoard";
import ReasonModal from "../../components/staff/ReasonModal";
import StaffLayout from "../../components/staff/StaffLayout";
import StaffSearch from "../../components/staff/StaffSearch";
import StatusSummary from "../../components/staff/StatusSummary";
import Button from "../../components/ui/Button";
import { useAdvanceRequests } from "../../hooks/useAdvanceRequests";
import {
  ADVANCE_CANCEL_REASONS,
  ADVANCE_COLUMNS,
  ADVANCE_REJECTION_REASONS,
  advanceColumnFor,
  advanceStage,
  collectionLabel,
  matchesSearch,
} from "../../lib/agent";
import { ROLES } from "../../lib/roles";

const PAGE_TITLE = "Advance requests · Click n Chick";

function cancelDescription(order) {
  if (!order) return undefined;

  if (advanceStage(order) === "payment") {
    return "The request is withdrawn before the customer pays. Nothing has been charged.";
  }

  return "The customer is told the store cancelled their advance order.";
}

/**
 * Advance Order Requests: every advance order from request to pickup.
 *
 * Not a queue (BR-22c): each column is ordered by collection date and nothing
 * carries a queue number. A Store Agent decides requests on their own judgement
 * of the kitchen (BR-16e); only the customer's payment moves an accepted
 * request on (BR-16g); a paid order is prepared on its collection date
 * (UC-ADV-012) and then runs the ordinary pickup chain. Every change arrives
 * over admin.orders, so other agents' decisions land here too.
 */
function AdvanceOrders() {
  const { user } = useContext(AuthContext);
  const advance = useAdvanceRequests();
  const columnRefs = useRef({});

  const [search, setSearch] = useState("");
  const [activeColumn, setActiveColumn] = useState("requests");
  const [declining, setDeclining] = useState(null);
  const [cancelling, setCancelling] = useState(null);
  const [searchParams, setSearchParams] = useSearchParams();

  const selectedId = searchParams.get("order");
  // OrderPolicy::decideAdvance: deciding and chasing payment are the Store
  // Agent's; the Store Manager sees the list.
  const canDecide = user?.role === ROLES.ADMIN;
  const searching = search.trim() !== "";
  const summary = advance.data?.summary;

  const columns = useMemo(() => {
    return ADVANCE_COLUMNS.map((column) => {
      const all = advance.orders.filter((order) => advanceColumnFor(order.status) === column.key);
      const orders = searching ? all.filter((order) => matchesSearch(order, search)) : all;
      const total = column.key === "closed" ? Math.max(summary?.closed_recently ?? 0, all.length) : all.length;

      return { ...column, orders, total };
    });
  }, [advance.orders, search, searching, summary]);

  const awaiting = columns[0].total;
  const dueToday = summary?.due_today ?? 0;

  useEffect(() => {
    document.title = awaiting > 0 ? `(${awaiting}) Advance request${awaiting === 1 ? "" : "s"} · Click n Chick` : PAGE_TITLE;
  }, [awaiting]);

  useEffect(() => () => {
    document.title = "Chick N' Click";
  }, []);

  const selected = selectedId ? advance.orders.find((order) => String(order.id) === selectedId) ?? null : null;

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

  const confirmDecline = async (reason) => {
    try {
      await advance.reject(declining, reason);
    } catch {
      // The hook has already said why.
    }

    setDeclining(null);
  };

  const confirmCancel = async (options) => {
    try {
      await advance.cancel(cancelling, options);
      setCancelling(null);
    } catch {
      // The hook has already said why.
    }
  };

  const handlers = {
    canDecide,
    onAccept: advance.accept,
    onReject: setDeclining,
    onRemind: advance.remind,
    onAdvance: advance.advance,
  };

  const cardProps = { ...handlers, isBusy: advance.isBusy, onOpen: openOrder };

  const renderCard = (order, props) => (
    <AdvanceOrderCard key={order.id} order={order} busy={props.isBusy(order.id)} {...props} />
  );

  return (
    <StaffLayout>
      <main className="flex flex-1 flex-col">
        <header className="border-b border-[#f0e9df] bg-white px-4 py-4 sm:px-6 lg:px-7">
          <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2.5">
                <h1 className="m-0 font-display text-[18px] font-bold tracking-[-0.3px] text-ink">Advance Order Requests</h1>
                <LivePill />
              </div>
              <p className="m-0 mt-0.5 font-display text-[12px] text-[#8d8884]">
                Ordered by collection date
                {dueToday > 0 && ` · ${dueToday} due today`}
                {summary?.awaiting_payment > 0 && ` · ${summary.awaiting_payment} awaiting payment`}
              </p>
            </div>

            <StaffSearch
              value={search}
              onChange={setSearch}
              placeholder="Search requests…"
              title="Search by order number, customer, phone or item"
              label="Search advance requests"
            />
          </div>

          <div className="mt-4">
            <StatusSummary columns={columns} onSelect={jumpTo} loading={advance.isLoading} />
          </div>
        </header>

        <div className="flex flex-1 flex-col gap-3 px-4 py-4 sm:px-6 lg:px-7">
          {dueToday > 0 && (
            <p className="m-0 flex items-start gap-2 rounded-[10px] bg-[#fff1e6] px-3.5 py-2.5 font-display text-[12px] text-brand-800 ring-1 ring-inset ring-[#ffd6b3]">
              <IconInfoCircle size={15} stroke={2} className="mt-[1px] shrink-0" />
              <span>
                <strong className="font-semibold">
                  {dueToday} advance order{dueToday === 1 ? " is" : "s are"} due today.
                </strong>{" "}
                Start preparing from the Scheduled column; from then on they follow the ordinary pickup steps here.
              </span>
            </p>
          )}

          {advance.isError ? (
            <div className="flex flex-col items-center gap-3 rounded-[16px] bg-white px-6 py-12 text-center ring-1 ring-[#f0e9df]">
              <p className="m-0 font-display text-[15px] font-semibold text-ink">The advance requests could not be loaded.</p>
              <p className="m-0 font-display text-[13px] text-[#8d8884]">{advance.error?.message}</p>
              <Button size="sm" variant="outline" onClick={() => advance.refetch()}>
                <IconRefresh size={16} stroke={2} />
                Try again
              </Button>
            </div>
          ) : (
            <OrderBoard
              columns={columns}
              activeColumn={activeColumn}
              onActiveColumnChange={setActiveColumn}
              loading={advance.isLoading}
              searching={searching}
              columnRefs={columnRefs}
              cardProps={cardProps}
              renderCard={renderCard}
              label="Advance order stages"
            />
          )}

          {summary?.closed_days && (
            <p className="m-0 text-center font-display text-[11px] text-[#a39f9b]">
              Closed shows the last {summary.closed_days} days.
            </p>
          )}
        </div>
      </main>

      <AdvanceDetailDrawer
        order={selected}
        opened={Boolean(selectedId) && !advance.isLoading}
        onClose={closeOrder}
        busy={selected ? advance.isBusy(selected.id) : false}
        {...handlers}
        onCancel={setCancelling}
      />

      <ReasonModal
        opened={Boolean(declining)}
        onClose={() => setDeclining(null)}
        onConfirm={confirmDecline}
        loading={advance.isRejecting}
        resetKey={declining?.id}
        title={declining ? `Decline request ${declining.reference ?? `#${declining.id}`}?` : ""}
        description={
          declining
            ? `${declining.customer?.name || "The customer"} asked to collect ${collectionLabel(declining)}. They are told why; nothing has been charged.`
            : undefined
        }
        presets={ADVANCE_REJECTION_REASONS}
        hint="Required. It is the customer's notification."
        minLength={3}
        maxLength={255}
        keepLabel="Keep request"
        confirmLabel="Decline request"
        busyLabel="Declining…"
        testId="advance-decline"
      />

      <CancelOrderModal
        order={cancelling}
        mode="cancel"
        opened={Boolean(cancelling)}
        onClose={() => setCancelling(null)}
        onConfirm={confirmCancel}
        loading={advance.isCancelling}
        reasons={ADVANCE_CANCEL_REASONS}
        description={cancelDescription(cancelling)}
        // BR-20: refundable while it rests in scheduled whoever asks; once the
        // kitchen has started, a customer's own cancellation is not refunded.
        allowWaiver={cancelling ? advanceStage(cancelling) === "kitchen" : false}
      />
    </StaffLayout>
  );
}

export default AdvanceOrders;
