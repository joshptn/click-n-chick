import { useEffect } from "react";
import { useSearchParams } from "react-router-dom";
import { Drawer, Pagination, Skeleton } from "@mantine/core";
import { useMediaQuery } from "@mantine/hooks";
import {
  IconAlertTriangle,
  IconChartBar,
  IconChevronLeft,
  IconChevronRight,
  IconCircleCheck,
  IconClock,
  IconInfoCircle,
  IconRefresh,
  IconShieldCheck,
  IconTrendingUp,
  IconTruckDelivery,
} from "@tabler/icons-react";

import FilterSelect from "../../components/staff/FilterSelect";
import LivePill from "../../components/staff/LivePill";
import RecordDetailPanel from "../../components/staff/RecordDetailPanel";
import { DateInput, RecordCards, RecordTable } from "../../components/staff/RecordList";
import StaffLayout from "../../components/staff/StaffLayout";
import Button from "../../components/ui/Button";
import { useDailySales } from "../../hooks/useDailySales";
import { useOrderRecord, useOrderRecords, useRecordPrefetch } from "../../hooks/useOrderRecords";
import { formatPeso } from "../../lib/menu";
import { storeDate } from "../../lib/records";
import {
  REPORT_KINDS,
  REPORT_OUTCOMES,
  REPORT_TYPES,
  formatReportDate,
  methodLabel,
  readReportFilters,
  reportParams,
  reportRecordParams,
  shiftDate,
} from "../../lib/salesReport";

const PAGE_TITLE = "Daily Sales Report · Click n Chick";

const NOT_AVAILABLE = "Not available";

const orderCount = (count) => `${count} ${count === 1 ? "order" : "orders"}`;

const ICON_TONES = {
  green: "bg-[#ecf9f0] text-[#2f9e44]",
  orange: "bg-[#fff1e6] text-brand-600",
  blue: "bg-[#eef4ff] text-[#2563eb]",
  teal: "bg-[#e8f8f5] text-[#0f9d8a]",
  purple: "bg-[#f3ecff] text-[#7c3aed]",
};

function Stat({ icon, tone, value, label, note, muted = false, testId }) {
  const Icon = icon;

  return (
    <div
      data-testid={testId}
      className="flex min-w-0 flex-col rounded-[16px] bg-white p-4 ring-1 ring-[#f0e9df] last:col-span-2 sm:last:col-span-1"
    >
      <span className={`grid h-9 w-9 place-items-center rounded-[10px] ${ICON_TONES[tone]}`}>
        <Icon size={18} stroke={2} />
      </span>
      <span className={`mt-3 truncate font-display font-bold leading-tight ${muted ? "text-[15px] text-[#a39f9b]" : "text-[20px] text-ink"}`}>
        {value}
      </span>
      <span className="mt-0.5 font-display text-[12px] font-medium text-[#5f5b58]">{label}</span>
      <span className="mt-1 font-display text-[10.5px] leading-snug text-[#a39f9b]">{note}</span>
    </div>
  );
}

function Panel({ title, hint, children, testId }) {
  return (
    <section data-testid={testId} className="flex min-w-0 flex-col rounded-[16px] bg-white p-4 ring-1 ring-[#f0e9df] sm:p-5">
      <h2 className="m-0 font-display text-[14px] font-bold text-ink">{title}</h2>
      {hint && <p className="m-0 mt-0.5 font-display text-[11px] leading-snug text-[#8d8884]">{hint}</p>}
      <div className="mt-3 flex flex-1 flex-col gap-2">{children}</div>
    </section>
  );
}

function Row({ label, detail, value, strong = false, muted = false, tone = "" }) {
  return (
    <div className={`flex items-baseline justify-between gap-3 font-display ${strong ? "border-t border-dashed border-[#ece7e0] pt-2" : ""}`}>
      <span className={`min-w-0 ${strong ? "text-[13px] font-bold text-ink" : "text-[12.5px] text-[#5f5b58]"}`}>
        {label}
        {detail && <span className="ml-1.5 text-[11px] text-[#a39f9b]">{detail}</span>}
      </span>
      <span
        className={`shrink-0 tabular-nums ${muted ? "text-[11.5px] text-[#a39f9b]" : strong ? "text-[14px] font-bold text-ink" : `text-[13px] font-semibold ${tone || "text-ink"}`}`}
      >
        {value}
      </span>
    </div>
  );
}

function Notice({ tone = "amber", children, testId }) {
  const styles =
    tone === "red"
      ? "bg-[#fdecec] text-[#b42318] ring-[#f8c9c7]"
      : "bg-[#fff8e1] text-[#8a5d00] ring-[#ffe59a]";
  const Icon = tone === "red" ? IconAlertTriangle : IconInfoCircle;

  return (
    <p data-testid={testId} className={`m-0 flex items-start gap-2 rounded-[10px] px-3.5 py-2.5 font-display text-[12px] ring-1 ring-inset ${styles}`}>
      <Icon size={15} stroke={2} className="mt-[1px] shrink-0" />
      <span>{children}</span>
    </p>
  );
}

function ReportSkeleton() {
  return (
    <div className="flex flex-col gap-3" data-testid="report-loading" aria-hidden="true">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
        {[0, 1, 2, 3, 4].map((key) => (
          <Skeleton key={key} height={146} radius={16} className="last:col-span-2 sm:last:col-span-1" />
        ))}
      </div>
      <div className="grid gap-3 lg:grid-cols-2">
        <Skeleton height={230} radius={16} />
        <Skeleton height={230} radius={16} />
      </div>
      <Skeleton height={260} radius={16} />
    </div>
  );
}

function FulfilmentRows({ report }) {
  const rows = [
    ["Pickup", report.breakdown.pickup],
    ["Delivery", report.breakdown.delivery],
    ["Regular", report.breakdown.regular],
    ["Advance", report.breakdown.advance],
  ];

  return rows.map(([label, totals], index) => (
    <div key={label} className={index === 2 ? "border-t border-dashed border-[#ece7e0] pt-2" : ""}>
      <Row label={label} detail={orderCount(totals.orders)} value={formatPeso(totals.total)} />
    </div>
  ));
}

function ReportBody({ report }) {
  const live = report.payments.recording;
  const { fulfilled, unverified_sales: unverified, verified_sales: verified, money, refunds, not_sold: notSold } = report;
  const deducted = (amount) => `${amount > 0 ? "−" : ""}${formatPeso(amount)}`;
  const moneyValue = (amount, deduction = false) => (!live ? NOT_AVAILABLE : deduction ? deducted(amount) : formatPeso(amount));

  return (
    <>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
        <Stat
          testId="stat-verified"
          icon={IconShieldCheck}
          tone="green"
          value={live ? formatPeso(verified.total) : NOT_AVAILABLE}
          muted={!live}
          label="Verified Sales"
          note={live ? `${orderCount(verified.orders)} fulfilled and paid` : "Payments not connected yet"}
        />
        <Stat
          testId="stat-fulfilled"
          icon={IconTrendingUp}
          tone="orange"
          value={formatPeso(fulfilled.total)}
          label="Fulfilled Order Value"
          note={unverified.orders > 0 ? `${orderCount(unverified.orders)} without verified payment` : "All with verified payment"}
        />
        <Stat
          testId="stat-delivery"
          icon={IconTruckDelivery}
          tone="blue"
          value={formatPeso(fulfilled.delivery_fees)}
          label="Delivery Fees"
          note="Charged on fulfilled orders"
        />
        <Stat
          testId="stat-completed"
          icon={IconCircleCheck}
          tone="teal"
          value={fulfilled.orders}
          label="Orders Completed"
          note={fulfilled.orders > 0 ? `Average ${formatPeso(fulfilled.average_order)}` : "None closed this day"}
        />
        <Stat
          testId="stat-active"
          icon={IconClock}
          tone="purple"
          value={report.open.orders}
          label="Active Orders"
          note={report.is_today ? "Placed today, still open" : "Placed this day, still open"}
        />
      </div>

      <div className="grid gap-3 lg:grid-cols-2">
        <Panel
          testId="panel-fulfilled"
          title="Fulfilled order value"
          hint="What completed and delivered orders came to at checkout. This is not confirmed revenue."
        >
          <Row label="Food sales" detail="with add-ons" value={formatPeso(fulfilled.food_sales)} />
          <Row
            label="SC/PWD discounts"
            detail={fulfilled.discounted_orders > 0 ? orderCount(fulfilled.discounted_orders) : undefined}
            value={deducted(fulfilled.discounts)}
            tone={fulfilled.discounts > 0 ? "text-[#e5322d]" : ""}
          />
          <Row label="Delivery fees" value={formatPeso(fulfilled.delivery_fees)} />
          <Row label="Fulfilled order value" value={formatPeso(fulfilled.total)} strong />
          <div className="mt-1 flex flex-col gap-2 rounded-[10px] bg-[#faf6f0] px-3 py-2.5">
            <Row label="Payment verified" detail={orderCount(verified.orders)} value={formatPeso(verified.total)} tone="text-[#2f9e44]" />
            <Row label="Not verified" detail={orderCount(unverified.orders)} value={formatPeso(unverified.total)} />
          </div>
        </Panel>

        <Panel
          testId="panel-money"
          title="Money received"
          hint={
            live
              ? "From payment records, dated when the money moved. An advance order can be paid on a different day from its collection."
              : "Filled in from payment records once online payment is connected."
          }
        >
          <Row label="Payments received" detail={live ? orderCount(money.received.orders) : undefined} value={moneyValue(money.received.amount)} muted={!live} />
          <Row label="Refunds paid out" value={moneyValue(money.refunds_settled.amount, true)} muted={!live} tone={money.refunds_settled.amount > 0 ? "text-[#e5322d]" : ""} />
          <Row label="Net received" value={moneyValue(money.net_received)} strong={live} muted={!live} />
          <Row label="Kept on cancelled orders" detail={live && money.kept_on_cancelled.orders > 0 ? orderCount(money.kept_on_cancelled.orders) : undefined} value={moneyValue(money.kept_on_cancelled.amount)} muted={!live} />

          {live && money.by_method.length > 0 && (
            <div className="mt-1 flex flex-col gap-2 rounded-[10px] bg-[#faf6f0] px-3 py-2.5">
              {money.by_method.map((method) => (
                <Row key={method.method ?? "none"} label={methodLabel(method.method)} detail={orderCount(method.orders)} value={formatPeso(method.amount)} />
              ))}
            </div>
          )}

          <div className="mt-auto flex flex-col gap-2 border-t border-dashed border-[#ece7e0] pt-2">
            <Row label="Refunds owed" detail={orderCount(refunds.owed.orders)} value={formatPeso(refunds.owed.amount)} />
            <Row label="Still to be paid out" detail={orderCount(refunds.awaiting.orders)} value={formatPeso(refunds.awaiting.amount)} />
          </div>
        </Panel>
      </div>

      <div className="grid gap-3 lg:grid-cols-3">
        <Panel testId="panel-breakdown" title="By order type" hint="Fulfilled orders. Each pair adds up to the same total.">
          <FulfilmentRows report={report} />
        </Panel>

        <Panel testId="panel-not-sold" title="Cancelled and declined" hint="Not counted as sales.">
          <Row label="Cancelled" detail={orderCount(notSold.cancelled.orders)} value={formatPeso(notSold.cancelled.value)} />
          <div className="flex flex-col gap-1.5 border-l-2 border-[#f0e9df] pl-3">
            <Row label="By the store" value={notSold.cancelled.by_store} />
            <Row label="By the customer" value={notSold.cancelled.by_customer} />
            <Row label="Expired unpaid" value={notSold.cancelled.expired_unpaid} />
          </div>
          <Row label="Declined requests" detail={orderCount(notSold.rejected.orders)} value={formatPeso(notSold.rejected.value)} />
          <Row label="Still open" detail={orderCount(report.open.orders)} value={formatPeso(report.open.value)} />
        </Panel>

        <Panel testId="panel-top-items" title="Top dishes" hint="By quantity on fulfilled orders, at the price sold.">
          {report.top_items.length === 0 ? (
            <p className="m-0 font-display text-[12px] text-[#a39f9b]">No dishes were sold on this day.</p>
          ) : (
            report.top_items.map((item) => (
              <Row key={item.food_id} label={item.food_name} detail={`×${item.quantity}`} value={formatPeso(item.sales)} />
            ))
          )}
        </Panel>
      </div>
    </>
  );
}

function ListTotal({ outcome, report }) {
  if (!report) return null;

  const [label, value] =
    outcome === "completed"
      ? ["Fulfilled order value", report.fulfilled.total]
      : outcome === "cancelled"
        ? ["Cancelled, not counted as sales", report.not_sold.cancelled.value]
        : ["Declined, not counted as sales", report.not_sold.rejected.value];

  return (
    <div className="flex items-center justify-between gap-3 border-t border-[#f3ede4] bg-[#fdfaf6] px-4 py-3">
      <span className="font-display text-[12px] font-semibold text-[#5f5b58]">{label}</span>
      <span data-testid="list-total" className="font-display text-[15px] font-bold text-brand-600">
        {formatPeso(value)}
      </span>
    </div>
  );
}

/**
 * The Daily Sales Report (UC-REP-002): one store day, for the Store Manager.
 *
 * Every figure is the server's; this page only formats them. Fulfilled order
 * value and verified money are shown apart, so an order that was handed over is
 * never presented as revenue until a payment record backs it. The day's orders
 * come from Order Records under the same day and filters.
 */
function DailySalesReport() {
  const wide = useMediaQuery("(min-width: 64em)", true);
  const [searchParams, setSearchParams] = useSearchParams();
  const filters = readReportFilters(searchParams);
  const selectedId = searchParams.get("order");
  const today = storeDate();

  const { report, isLoading, isPlaceholder, isError, error, refetch } = useDailySales(reportParams(filters));
  const list = useOrderRecords(reportRecordParams(filters));
  const detail = useOrderRecord(selectedId);
  const intent = useRecordPrefetch();

  useEffect(() => {
    document.title = PAGE_TITLE;

    return () => {
      document.title = "Chick N' Click";
    };
  }, []);

  const write = (changes, { keepPage = false, push = false } = {}) => {
    const defaults = { date: today, type: "all", kind: "all", outcome: "completed", page: "1" };

    setSearchParams(
      (current) => {
        const next = new URLSearchParams(current);

        Object.entries(changes).forEach(([key, value]) => {
          if (value === null || String(value) === defaults[key]) next.delete(key);
          else next.set(key, value);
        });

        if (!keepPage && !("page" in changes)) next.delete("page");

        return next;
      },
      { replace: !push }
    );
  };

  const setDate = (date) => {
    if (date && date <= today) write({ date, order: null });
  };

  const select = (record) => write({ order: record ? String(record.id) : null }, { keepPage: true });

  const filtered = filters.type !== "all" || filters.kind !== "all";
  const live = report?.payments.recording;
  const marked = report?.unverified_sales.marked_paid_orders ?? 0;
  const mismatched = report?.fulfilled.mismatched_orders ?? 0;
  const meta = list.meta;
  const preview = list.records.find((record) => String(record.id) === selectedId) ?? null;

  const listProps = {
    records: list.records,
    loading: list.isLoading,
    selectedId,
    onSelect: select,
    intent,
    showPayment: true,
  };

  return (
    <StaffLayout>
      <main className="flex min-w-0 flex-1 flex-col">
        <header className="border-b border-[#f0e9df] bg-white px-4 py-4 sm:px-6 lg:px-7">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2.5">
                <h1 className="m-0 font-display text-[18px] font-bold tracking-[-0.3px] text-ink">Daily Sales Report</h1>
                <LivePill />
              </div>
              <p className="m-0 mt-0.5 font-display text-[12px] text-[#8d8884]" data-testid="report-date">
                {formatReportDate(filters.date)} · store time
              </p>
            </div>

            <div className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-1.5 sm:flex sm:flex-wrap">
              <button
                type="button"
                aria-label="Previous day"
                data-testid="date-previous"
                onClick={() => setDate(shiftDate(filters.date, -1))}
                className="grid h-[34px] w-[34px] place-items-center rounded-full border border-[#ece7e0] bg-white text-[#5f5b58] transition-colors hover:border-brand-300"
              >
                <IconChevronLeft size={16} stroke={2} />
              </button>
              <DateInput label="Report date" value={filters.date} max={today} onChange={setDate} />
              <button
                type="button"
                aria-label="Next day"
                data-testid="date-next"
                disabled={filters.date >= today}
                onClick={() => setDate(shiftDate(filters.date, 1))}
                className="grid h-[34px] w-[34px] place-items-center rounded-full border border-[#ece7e0] bg-white text-[#5f5b58] transition-colors hover:border-brand-300 disabled:cursor-not-allowed disabled:opacity-40"
              >
                <IconChevronRight size={16} stroke={2} />
              </button>
              {filters.date !== today && (
                <Button size="sm" variant="ghost" className="col-span-3" onClick={() => setDate(today)}>
                  Today
                </Button>
              )}
            </div>
          </div>

          <div className="mt-3 grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
            <FilterSelect label="Order type" value={filters.type} onChange={(type) => write({ type })} options={REPORT_TYPES} active={filters.type !== "all"} testId="filter-type" />
            <FilterSelect label="Regular or advance" value={filters.kind} onChange={(kind) => write({ kind })} options={REPORT_KINDS} active={filters.kind !== "all"} testId="filter-kind" />
            {filtered && (
              <button type="button" onClick={() => write({ type: "all", kind: "all" })} className="bg-transparent font-semibold text-brand-600 hover:text-brand-800">
                <span className="text-[11.5px]">Clear filters</span>
              </button>
            )}
          </div>
        </header>

        <div className="flex flex-1 flex-col gap-3 px-4 py-4 sm:px-6 lg:px-7">
          {isError ? (
            <div className="flex flex-col items-center gap-3 rounded-[16px] bg-white px-6 py-12 text-center ring-1 ring-[#f0e9df]" data-testid="report-error">
              <p className="m-0 font-display text-[15px] font-semibold text-ink">The report could not be loaded.</p>
              <p className="m-0 font-display text-[13px] text-[#8d8884]">{error?.message}</p>
              <Button size="sm" variant="outline" onClick={() => refetch()}>
                <IconRefresh size={16} stroke={2} />
                Try again
              </Button>
            </div>
          ) : isLoading ? (
            <ReportSkeleton />
          ) : (
            <div className={`flex flex-col gap-3 transition-opacity ${isPlaceholder ? "opacity-60" : ""}`} aria-busy={isPlaceholder}>
              {!live && (
                <Notice testId="payments-notice">
                  <strong className="font-semibold">Online payment is not connected yet, so no money is confirmed as received.</strong>{" "}
                  Figures below show orders as recorded at checkout.
                  {marked > 0 && ` ${orderCount(marked)} marked paid without a payment record ${marked === 1 ? "is" : "are"} not counted as verified.`}
                </Notice>
              )}
              {mismatched > 0 && (
                <Notice tone="red" testId="mismatch-notice">
                  {orderCount(mismatched)} on this day {mismatched === 1 ? "has" : "have"} stored amounts that do not add up. Totals use the amount
                  recorded on each order.
                </Notice>
              )}

              {!report.has_activity ? (
                <div data-testid="report-empty" className="flex flex-col items-center gap-2 rounded-[16px] border border-dashed border-[#e7dfd4] bg-white/60 px-6 py-14 text-center">
                  <IconChartBar size={28} stroke={1.6} className="text-[#cfc7bd]" />
                  <p className="m-0 font-display text-[14px] font-semibold text-ink">
                    {filtered ? "No orders match these filters on this day." : "No orders on this day."}
                  </p>
                  <p className="m-0 font-display text-[12px] text-[#8d8884]">
                    {filtered ? "Try clearing the filters." : "Pick another date to see its report."}
                  </p>
                  {filtered && (
                    <Button size="sm" variant="ghost" onClick={() => write({ type: "all", kind: "all" })}>
                      Clear filters
                    </Button>
                  )}
                </div>
              ) : (
                <>
                  <ReportBody report={report} />

                  <section className="overflow-hidden rounded-[16px] bg-white ring-1 ring-[#f0e9df]" data-testid="report-orders">
                    <div className="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                      <div>
                        <h2 className="m-0 font-display text-[14px] font-bold text-ink">Orders closed this day</h2>
                        {meta && (
                          <p className="m-0 font-display text-[11px] text-[#8d8884]" data-testid="list-count">
                            {orderCount(meta.total)}
                          </p>
                        )}
                      </div>
                      <FilterSelect label="Outcome" value={filters.outcome} onChange={(outcome) => write({ outcome })} options={REPORT_OUTCOMES} active={false} testId="filter-outcome" />
                    </div>

                    {list.isError && !meta ? (
                      <div className="flex flex-col items-center gap-2 px-6 py-10 text-center">
                        <p className="m-0 font-display text-[13px] font-semibold text-ink">The orders could not be loaded.</p>
                        <Button size="sm" variant="outline" onClick={() => list.refetch()}>
                          <IconRefresh size={16} stroke={2} />
                          Try again
                        </Button>
                      </div>
                    ) : !list.isLoading && list.records.length === 0 ? (
                      <p className="m-0 px-6 py-10 text-center font-display text-[12.5px] text-[#8d8884]" data-testid="list-empty">
                        No {REPORT_OUTCOMES.find((option) => option.key === filters.outcome).label.toLowerCase()} orders on this day.
                      </p>
                    ) : (
                      <div className={`transition-opacity ${list.isPlaceholder ? "opacity-60" : ""} ${wide ? "" : "px-3 pb-3"}`}>
                        {wide ? <RecordTable {...listProps} /> : <RecordCards {...listProps} />}
                      </div>
                    )}

                    {meta?.last_page > 1 && (
                      <div className="flex justify-center px-4 py-3">
                        <Pagination
                          total={meta.last_page}
                          value={Math.min(filters.page, meta.last_page)}
                          onChange={(page) => write({ page: String(page) }, { keepPage: true, push: true })}
                          size="sm"
                          radius="xl"
                          color="orange"
                          siblings={wide ? 1 : 0}
                          boundaries={1}
                        />
                      </div>
                    )}

                    <ListTotal outcome={filters.outcome} report={report} />
                  </section>
                </>
              )}
            </div>
          )}
        </div>
      </main>

      <Drawer
        opened={Boolean(selectedId)}
        onClose={() => select(null)}
        position="right"
        size="min(100%, 380px)"
        padding={0}
        radius={0}
        aria-label="Order details"
      >
        <RecordDetailPanel
          preview={preview}
          record={detail.record}
          isLoading={detail.isLoading}
          isError={detail.isError}
          error={detail.error}
          onRetry={() => detail.refetch()}
        />
      </Drawer>
    </StaffLayout>
  );
}

export default DailySalesReport;
