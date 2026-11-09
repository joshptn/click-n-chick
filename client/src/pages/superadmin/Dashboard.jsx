import { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Skeleton } from "@mantine/core";
import { useDebouncedValue } from "@mantine/hooks";
import {
  IconArrowRight,
  IconCalendarTime,
  IconCash,
  IconChevronLeft,
  IconChevronRight,
  IconMapPin,
  IconRefresh,
  IconShoppingCart,
  IconToolsKitchen2,
  IconTruckDelivery,
} from "@tabler/icons-react";

import SalesChart from "../../components/staff/analytics/SalesChart";
import LivePill from "../../components/staff/LivePill";
import StaffLayout from "../../components/staff/StaffLayout";
import StaffSearch from "../../components/staff/StaffSearch";
import Button from "../../components/ui/Button";
import { useOperations, useSalesAnalytics, useSalesLog } from "../../hooks/useAnalytics";
import { CHART_VIEWS, chartState, reportLinkFor } from "../../lib/analytics";
import { formatPeso } from "../../lib/menu";
import { formatRecordDate, itemsPreview, storeDate, typeLabel } from "../../lib/records";

const PAGE_TITLE = "Analytics Dashboard · Click n Chick";
const DAILY_REPORT = "/superadmin/reports/daily";
const BOARD = "/admin";
const ADVANCE_ORDERS = "/admin/advance-orders";

const SURFACE = "rounded-[14px] bg-white ring-1 ring-[#f0e9df] shadow-[0_1px_2px_rgba(60,40,20,0.03)]";
const PANEL_TITLE = "m-0 font-display text-[15px] font-semibold tracking-[-0.1px] text-ink";
const PANEL_HINT = "m-0 mt-0.5 font-display text-[11.5px] leading-snug text-[#8d8884]";

const ICON_TONES = {
  orange: "bg-[#fff1e6] text-brand-600",
  blue: "bg-[#eef4ff] text-[#2563eb]",
  indigo: "bg-[#eeeefe] text-[#5b5bd6]",
  green: "bg-[#ecf9f0] text-[#2f9e44]",
  purple: "bg-[#f3ecff] text-[#7c3aed]",
  amber: "bg-[#fff8e1] text-[#c98500]",
};

function ErrorBlock({ message, onRetry, testId }) {
  return (
    <div className={`${SURFACE} flex flex-col items-center gap-3 px-6 py-10 text-center`} data-testid={testId}>
      <p className="m-0 font-display text-[14px] font-semibold text-ink">{message}</p>
      <Button size="sm" variant="outline" onClick={onRetry}>
        <IconRefresh size={16} stroke={2} />
        Try again
      </Button>
    </div>
  );
}

function StatCard({ testId, to = BOARD, icon, tone, label, value, subtitle, details }) {
  const Icon = icon;

  return (
    <Link to={to} data-testid={testId} className={`${SURFACE} flex min-w-0 flex-col px-4 py-3.5 no-underline transition-[box-shadow,transform] hover:-translate-y-px hover:shadow-[0_6px_16px_-8px_rgba(60,40,20,0.2)]`}>
      <span className="flex items-center justify-between gap-3">
        <span className="font-display text-[10.5px] font-semibold uppercase tracking-[0.7px] text-[#7a7571]">{label}</span>
        <span className={`grid h-7 w-7 shrink-0 place-items-center rounded-[8px] ${ICON_TONES[tone]}`}>
          <Icon size={15} stroke={2} />
        </span>
      </span>
      <span data-testid={`${testId}-value`} className="mt-1 font-display text-[26px] font-semibold leading-none tracking-[-0.5px] text-ink">
        {value}
      </span>
      <span className="mt-2.5 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 font-display">
        <span className="text-[12px] text-[#6f6b68]">{subtitle}</span>
        <span data-testid={`${testId}-details`} className="flex shrink-0 gap-2.5 text-[11px] text-[#8d8884]">
          {details.map(([count, name]) => (
            <span key={name}>
              <span className="font-semibold tabular-nums text-[#5f5b58]">{count}</span> {name}
            </span>
          ))}
        </span>
      </span>
    </Link>
  );
}

function StatCards({ data }) {
  if (!data) {
    return (
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-testid="cards-loading" aria-hidden="true">
        {[0, 1, 2, 3, 4, 5].map((key) => (
          <Skeleton key={key} height={104} radius={14} />
        ))}
      </div>
    );
  }

  const { new_orders: newOrders, preparing, delivery, pickup, advance } = data;

  return (
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-testid="stat-cards">
      <StatCard testId="card-new" icon={IconShoppingCart} tone="orange" label="New Orders" value={newOrders.today} subtitle="Orders placed today" details={[[newOrders.completed, "Completed"], [newOrders.cancelled, "Cancelled"]]} />
      <StatCard testId="card-preparing" icon={IconToolsKitchen2} tone="blue" label="Now Preparing" value={preparing.count} subtitle="Orders in the Kitchen" details={[[preparing.pending, "Pending"]]} />
      <StatCard testId="card-due" to={ADVANCE_ORDERS} icon={IconCalendarTime} tone="purple" label="Advance Orders Due Today" value={advance.due_today} subtitle="Scheduled Today" details={[[advance.preparing, "Preparing"], [advance.completed, "Completed"]]} />
      <StatCard testId="card-delivery" icon={IconTruckDelivery} tone="indigo" label="Delivery Orders" value={delivery.open} subtitle="To Deliver Today" details={[[delivery.delivered, "Delivered"]]} />
      <StatCard testId="card-pickup" icon={IconMapPin} tone="green" label="Pick Up Orders" value={pickup.open} subtitle="For Pickup Today" details={[[pickup.completed, "Completed"]]} />
      <StatCard testId="card-to-pay" to={ADVANCE_ORDERS} icon={IconCash} tone="amber" label="Advance Orders To Pay" value={advance.to_pay} subtitle="Payment deadline today" details={[[advance.accepted, "Accepted"], [advance.paid, "Paid"]]} />
    </div>
  );
}

function StepButton({ icon, label, testId, target, onSelect }) {
  const Icon = icon;

  return (
    <button
      type="button"
      aria-label={label}
      data-testid={testId}
      disabled={!target}
      onClick={() => onSelect(target)}
      className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-transparent text-brand-600 transition-colors hover:bg-brand-50 disabled:cursor-not-allowed disabled:text-[#d9d3cb] disabled:hover:bg-transparent"
    >
      <Icon size={19} stroke={2.4} />
    </button>
  );
}

function SalesPanel({ sales, selection, onSelect }) {
  const chart = chartState(sales, selection);
  const empty = !chart.points.some((point) => point.orders > 0);
  const stepping = chart.view !== "weekly";
  const unit = chart.view === "daily" ? "day" : "hour";

  return (
    <section className={`${SURFACE} flex min-w-0 flex-col p-4 sm:p-5`} data-testid="panel-sales">
      <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2.5">
        <div className="min-h-[62px] min-w-0 flex-1 basis-[220px]">
          <p className="m-0 font-display text-[10.5px] font-semibold uppercase tracking-[0.7px] text-[#8d8884]">Sales Report</p>
          <div className={`mt-0.5 flex items-center gap-1 ${stepping ? "-ml-1.5" : ""}`}>
            {stepping && <StepButton icon={IconChevronLeft} label={`Previous ${unit}`} testId="chart-previous" target={chart.previous} onSelect={onSelect} />}
            <h2 className={PANEL_TITLE} data-testid="sales-title">
              {chart.title}
            </h2>
            {stepping && <StepButton icon={IconChevronRight} label={`Next ${unit}`} testId="chart-next" target={chart.following} onSelect={onSelect} />}
          </div>
          {chart.subtitle && <p className="m-0 mt-0.5 font-display text-[10.5px] font-semibold uppercase tracking-[0.7px] text-[#a39f9b]">{chart.subtitle}</p>}
        </div>

        <div className="flex shrink-0 items-center gap-2">
          <div role="group" aria-label="Chart period" className="inline-flex rounded-[9px] bg-[#f6f2ec] p-[3px]">
            {CHART_VIEWS.map((option) => (
              <button
                key={option.key}
                type="button"
                aria-pressed={chart.view === option.key}
                data-testid={`chart-view-${option.key}`}
                onClick={() => onSelect({ view: option.key, date: chart.date, hour: chart.hour })}
                className={`rounded-[7px] px-3 py-1 font-display transition-colors ${chart.view === option.key ? "bg-white text-brand-600 shadow-[0_1px_3px_rgba(60,40,20,0.12)]" : "bg-transparent text-[#7a7571] hover:text-ink"}`}
              >
                <span className="text-[11.5px] font-semibold">{option.label}</span>
              </button>
            ))}
          </div>
          <LivePill />
        </div>
      </div>

      <div className="mt-3 flex-1">
        {empty ? (
          <div className="flex h-[264px] items-center justify-center rounded-[10px] bg-[#fbf9f5]" data-testid="sales-empty">
            <p className="m-0 font-display text-[12.5px] text-[#8d8884]">No completed orders in this period.</p>
          </div>
        ) : (
          <SalesChart key={`${chart.view}-${chart.date}-${chart.hour}`} points={chart.points} drillHint={chart.drillHint} onPick={(point) => point.next && onSelect(point.next)} />
        )}
      </div>

      <p className="m-0 mt-2 font-display text-[11px] text-[#a39f9b]">Checkout value of completed orders, not confirmed payments.</p>
    </section>
  );
}

function TopProducts({ products }) {
  const most = products[0]?.quantity ?? 0;

  return (
    <section className={`${SURFACE} flex min-w-0 flex-col p-4 sm:p-5`} data-testid="panel-products">
      <h2 className={PANEL_TITLE}>Top Selling Products</h2>
      <p className={PANEL_HINT}>Last 7 days · By units on completed and delivered orders</p>

      {products.length === 0 ? (
        <div className="mt-4 flex flex-1 items-center justify-center rounded-[10px] bg-[#fbf9f5] py-12" data-testid="products-empty">
          <p className="m-0 font-display text-[12.5px] text-[#8d8884]">No dishes sold in the last 7 days.</p>
        </div>
      ) : (
        <ol className="m-0 mt-4 flex flex-1 list-none flex-col justify-between gap-3 p-0">
          {products.map((product, index) => (
            <li key={product.food_id} data-testid="product-row" className="grid grid-cols-[18px_minmax(0,1fr)_auto] items-baseline gap-x-2.5 gap-y-1 font-display">
              <span className="text-[12px] font-semibold tabular-nums text-[#a39f9b]">{index + 1}</span>
              <span className="flex min-w-0 items-baseline gap-2">
                <span className="truncate text-[13px] font-semibold text-ink">{product.food_name}</span>
                {product.category && <span className="hidden shrink-0 text-[10.5px] text-[#a39f9b] sm:inline">{product.category}</span>}
              </span>
              <span className="text-right text-[13px] font-semibold tabular-nums text-ink">{formatPeso(product.sales)}</span>
              <span className="col-start-2 h-[5px] self-center overflow-hidden rounded-full bg-[#f6f2ec]" aria-hidden="true">
                <span className="block h-full rounded-full bg-brand-400" style={{ width: `${Math.max(4, (product.quantity / most) * 100)}%` }} />
              </span>
              <span className="text-right text-[11px] tabular-nums text-[#8d8884]">{product.quantity} {product.quantity === 1 ? "unit" : "units"} sold</span>
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}

function FulfilmentTag({ record }) {
  const delivery = record.order_type === "delivery";

  return (
    <span className="inline-flex items-center gap-1.5 whitespace-nowrap font-display text-[12px] text-[#5f5b58]">
      <span className={`h-1.5 w-1.5 rounded-full ${delivery ? "bg-[#5b8def]" : "bg-[#46b46a]"}`} aria-hidden="true" />
      {typeLabel(record)}
    </span>
  );
}

function PaymentPill({ record }) {
  return record.payment_verified ? (
    <span className="inline-flex whitespace-nowrap rounded-full bg-[#ecf9f0] px-2.5 py-[3px] font-display text-[10.5px] font-semibold text-[#2f9e44]">Cleared Paid</span>
  ) : (
    <span className="inline-flex whitespace-nowrap rounded-full bg-[#f4f1ec] px-2.5 py-[3px] font-display text-[10.5px] font-semibold text-[#6f6b68]">Not Verified</span>
  );
}

const LOG_GRID = "grid grid-cols-[minmax(84px,0.8fr)_minmax(110px,1fr)_minmax(150px,1.7fr)_88px_100px_96px_104px] items-center gap-x-4";

function SalesLog() {
  const navigate = useNavigate();
  const [search, setSearch] = useState("");
  const [debounced] = useDebouncedValue(search.trim(), 300);
  const log = useSalesLog({ date: storeDate(), search: debounced });
  const records = log.data?.data ?? [];

  return (
    <section className={`${SURFACE} overflow-hidden`} data-testid="sales-log">
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 p-4 sm:px-5">
        <div className="min-w-0">
          <h2 className={PANEL_TITLE}>Daily Sales Report</h2>
          <p className={PANEL_HINT}>Today's latest completed orders</p>
        </div>
        <div className="flex w-full flex-wrap items-center gap-x-4 gap-y-2 md:w-auto">
          <StaffSearch value={search} onChange={setSearch} placeholder="Search ticket or customer…" className="md:w-[260px]" />
          <Link to={DAILY_REPORT} data-testid="link-daily-report" className="inline-flex shrink-0 items-center gap-1 font-display text-[12.5px] font-semibold text-brand-600 no-underline hover:text-brand-800">
            View full report
            <IconArrowRight size={14} stroke={2} />
          </Link>
        </div>
      </div>

      {log.isError ? (
        <div className="px-4 pb-4">
          <ErrorBlock message="The sales log could not be loaded." onRetry={() => log.refetch()} testId="log-error" />
        </div>
      ) : log.isLoading ? (
        <div className="flex flex-col gap-2 px-4 pb-4" aria-hidden="true">
          {[0, 1, 2].map((key) => (
            <Skeleton key={key} height={44} radius={8} />
          ))}
        </div>
      ) : records.length === 0 ? (
        <p className="m-0 border-t border-[#f4efe8] px-6 py-10 text-center font-display text-[12.5px] text-[#8d8884]" data-testid="log-empty">
          {debounced ? "No completed orders today match that search." : "No orders completed yet today."}
        </p>
      ) : (
        <>
          <div className="hidden overflow-x-auto xl:block">
            <div className="min-w-[820px]">
              <div className={`${LOG_GRID} border-y border-[#f4efe8] bg-[#fcfaf7] px-5 py-2 font-display text-[10px] font-semibold uppercase tracking-[0.7px] text-[#8d8884]`}>
                <span>Invoice Ref</span>
                <span>Customer</span>
                <span>Items</span>
                <span>Fulfillment</span>
                <span className="whitespace-nowrap">Tax Exemption</span>
                <span className="text-right">Total</span>
                <span className="text-center">For Check</span>
              </div>
              {records.map((record) => (
                <button key={record.id} type="button" onClick={() => navigate(reportLinkFor(record))} data-testid="log-row" className={`${LOG_GRID} w-full border-b border-[#f7f3ed] bg-white px-5 py-2.5 text-left font-display transition-colors last:border-b-0 hover:bg-[#fdfaf6]`}>
                  <span className="min-w-0">
                    <span className="block text-[12.5px] font-semibold text-ink">{record.reference}</span>
                    <span className="block text-[10.5px] text-[#a39f9b]">{formatRecordDate(record.closed_at)}</span>
                  </span>
                  <span className="truncate text-[12.5px] text-ink">{record.customer?.name || "Customer"}</span>
                  <span className="truncate text-[12px] text-[#6f6b68]" title={itemsPreview(record)}>
                    {itemsPreview(record)}
                  </span>
                  <span>
                    <FulfilmentTag record={record} />
                  </span>
                  <span className={`text-[12px] tabular-nums ${record.discount_amount > 0 ? "text-[#5f5b58]" : "text-[#c4beb6]"}`}>{record.discount_amount > 0 ? formatPeso(record.discount_amount) : "—"}</span>
                  <span className="text-right text-[12.5px] font-semibold tabular-nums text-ink">{formatPeso(record.total)}</span>
                  <span className="text-center">
                    <PaymentPill record={record} />
                  </span>
                </button>
              ))}
            </div>
          </div>

          <div className="flex flex-col border-t border-[#f4efe8] xl:hidden">
            {records.map((record) => (
              <button key={record.id} type="button" onClick={() => navigate(reportLinkFor(record))} data-testid="log-card" className="flex w-full flex-col gap-1 border-b border-[#f7f3ed] bg-white p-4 text-left font-display last:border-b-0">
                <span className="flex w-full items-center justify-between gap-2">
                  <span className="text-[13px] font-semibold text-ink">{record.reference}</span>
                  <span className="text-[13px] font-semibold tabular-nums text-ink">{formatPeso(record.total)}</span>
                </span>
                <span className="text-[12px] text-[#5f5b58]">{record.customer?.name || "Customer"}</span>
                <span className="truncate text-[12px] text-[#8d8884]">{itemsPreview(record)}</span>
                <span className="mt-1 flex w-full items-center justify-between gap-2">
                  <FulfilmentTag record={record} />
                  <PaymentPill record={record} />
                </span>
              </button>
            ))}
          </div>
        </>
      )}
    </section>
  );
}

/**
 * The Store Manager's Analytics Dashboard (UC-REP-001): today's order cards,
 * the sales chart, the week's top products, and today's completed orders.
 *
 * Every figure is the server's, and nothing here changes an order (BR-36).
 */
function SuperAdminDashboard() {
  const [selection, setSelection] = useState({ view: "weekly", date: null, hour: null });
  const operations = useOperations();
  const analytics = useSalesAnalytics();

  useEffect(() => {
    document.title = PAGE_TITLE;

    return () => {
      document.title = "Chick N' Click";
    };
  }, []);

  return (
    <StaffLayout>
      <main className="flex min-w-0 flex-1 flex-col">
        <header className="border-b border-[#f0e9df] bg-white px-4 py-3.5 sm:px-6 lg:px-7">
          <h1 className="m-0 font-display text-[18px] font-semibold tracking-[-0.3px] text-ink">Analytics Dashboard</h1>
          <p className="m-0 font-display text-[12px] text-[#8d8884]">Real-time overview of operations — Apalit, Pampanga</p>
        </header>

        <div className="flex flex-1 flex-col gap-4 px-4 py-4 sm:px-6 sm:py-5 lg:px-7">
          {operations.isError ? (
            <ErrorBlock message="The order figures could not be loaded." onRetry={() => operations.refetch()} testId="cards-error" />
          ) : (
            <StatCards data={operations.data} />
          )}

          {analytics.isError ? (
            <ErrorBlock message="The sales figures could not be loaded." onRetry={() => analytics.refetch()} testId="sales-error" />
          ) : analytics.isLoading ? (
            <div className="flex flex-col gap-4" data-testid="sales-loading" aria-hidden="true">
              <Skeleton height={356} radius={14} />
              <Skeleton height={356} radius={14} />
            </div>
          ) : (
            <div className="flex flex-col gap-4">
              <SalesPanel sales={analytics.data.sales} selection={selection} onSelect={setSelection} />
              <TopProducts products={analytics.data.products} />
            </div>
          )}

          <SalesLog />
        </div>
      </main>
    </StaffLayout>
  );
}

export default SuperAdminDashboard;
