import { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Skeleton } from "@mantine/core";
import { useDebouncedValue } from "@mantine/hooks";
import {
  IconArrowRight,
  IconCalendarTime,
  IconCash,
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
import { CHART_VIEWS, chartPoints, reportLinkFor } from "../../lib/analytics";
import { formatPeso } from "../../lib/menu";
import { formatRecordDate, itemsPreview, storeDate, typeLabel } from "../../lib/records";

const PAGE_TITLE = "Analytics Dashboard · Click n Chick";
const DAILY_REPORT = "/superadmin/reports/daily";
const BOARD = "/admin";
const ADVANCE_ORDERS = "/admin/advance-orders";

const CARD_TONES = {
  orange: { icon: "bg-[#fff1e6] text-brand-600", text: "text-brand-600" },
  blue: { icon: "bg-[#eef4ff] text-[#2563eb]", text: "text-[#2563eb]" },
  indigo: { icon: "bg-[#eeeefe] text-[#5b5bd6]", text: "text-[#5b5bd6]" },
  green: { icon: "bg-[#ecf9f0] text-[#2f9e44]", text: "text-[#2f9e44]" },
  purple: { icon: "bg-[#f3ecff] text-[#7c3aed]", text: "text-[#7c3aed]" },
  amber: { icon: "bg-[#fff8e1] text-[#c98500]", text: "text-[#c98500]" },
};

const RANK_TONES = ["bg-[#f5b400] text-white", "bg-[#c9c5bf] text-white", "bg-[#f2a65a] text-white"];

function ErrorBlock({ message, onRetry, testId }) {
  return (
    <div className="flex flex-col items-center gap-3 rounded-[16px] bg-white px-6 py-10 text-center ring-1 ring-[#f0e9df]" data-testid={testId}>
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
  const colors = CARD_TONES[tone];

  return (
    <Link to={to} data-testid={testId} className="flex min-w-0 flex-col rounded-[16px] bg-white p-4 no-underline ring-1 ring-[#f0e9df] transition-transform hover:-translate-y-0.5 sm:p-5">
      <span className="flex items-start justify-between gap-3">
        <span className="font-display text-[11px] font-semibold uppercase tracking-[0.8px] text-[#6f6b68]">{label}</span>
        <span className={`grid h-8 w-8 shrink-0 place-items-center rounded-[10px] ${colors.icon}`}>
          <Icon size={16} stroke={2} />
        </span>
      </span>
      <span data-testid={`${testId}-value`} className={`font-display text-[30px] font-bold leading-none ${colors.text}`}>
        {value}
      </span>
      <span className="mt-3 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 font-display text-[#8d8884]">
        <span className="text-[12.5px]">{subtitle}</span>
        <span data-testid={`${testId}-details`} className="flex shrink-0 gap-2 text-[11.5px]">
          {details.map((detail) => (
            <span key={detail}>{detail}</span>
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
          <Skeleton key={key} height={124} radius={16} />
        ))}
      </div>
    );
  }

  const { new_orders: newOrders, preparing, delivery, pickup, advance } = data;

  return (
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-testid="stat-cards">
      <StatCard testId="card-new" icon={IconShoppingCart} tone="orange" label="New Orders" value={newOrders.today} subtitle="Orders placed today" details={[`${newOrders.completed} Completed`, `${newOrders.cancelled} Cancelled`]} />
      <StatCard testId="card-preparing" icon={IconToolsKitchen2} tone="blue" label="Now Preparing" value={preparing.count} subtitle="Orders in the Kitchen" details={[`${preparing.pending} Pending`]} />
      <StatCard testId="card-due" to={ADVANCE_ORDERS} icon={IconCalendarTime} tone="purple" label="Advance Orders Due Today" value={advance.due_today} subtitle="Scheduled Today" details={[`${advance.preparing} Preparing`, `${advance.completed} Completed`]} />
      <StatCard testId="card-delivery" icon={IconTruckDelivery} tone="indigo" label="Delivery Orders" value={delivery.open} subtitle="To Deliver Today" details={[`${delivery.delivered} Delivered`]} />
      <StatCard testId="card-pickup" icon={IconMapPin} tone="green" label="Pick Up Orders" value={pickup.open} subtitle="For Pickup Today" details={[`${pickup.completed} Completed`]} />
      <StatCard testId="card-to-pay" to={ADVANCE_ORDERS} icon={IconCash} tone="amber" label="Advance Orders To Pay" value={advance.to_pay} subtitle="Payment deadline today" details={[`${advance.accepted} Accepted`, `${advance.paid} Paid`]} />
    </div>
  );
}

function SalesPanel({ sales, view, onView }) {
  const current = CHART_VIEWS.find((option) => option.key === view);
  const points = chartPoints(sales, view);
  const empty = !points.some((point) => point.orders > 0);

  return (
    <section className="rounded-[16px] bg-white p-4 ring-1 ring-[#f0e9df] sm:p-5" data-testid="panel-sales">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="m-0 font-display text-[11px] font-semibold uppercase tracking-[0.8px] text-[#8d8884]">Sales Report (PHP)</p>
          <h2 className="m-0 mt-0.5 font-display text-[16px] font-bold text-ink" data-testid="sales-title">
            {current.title}
          </h2>
          <p className="m-0 mt-0.5 font-display text-[11px] text-[#8d8884]">{current.window}. Completed and delivered orders at checkout value, not confirmed payments.</p>
        </div>

        <div className="flex items-center gap-2">
          <div role="group" aria-label="Chart period" className="inline-flex rounded-[10px] bg-[#f8f5f0] p-0.5 ring-1 ring-inset ring-[#f0e9df]">
            {CHART_VIEWS.map((option) => (
              <button
                key={option.key}
                type="button"
                aria-pressed={view === option.key}
                data-testid={`chart-view-${option.key}`}
                onClick={() => onView(option.key)}
                className={`rounded-[8px] px-3 py-1 font-display transition-colors ${view === option.key ? "bg-white text-brand-600 shadow-[0_1px_4px_rgba(60,40,20,0.14)]" : "bg-transparent text-[#6f6b68] hover:text-ink"}`}
              >
                <span className="text-[11.5px] font-semibold">{option.label}</span>
              </button>
            ))}
          </div>
          <LivePill />
        </div>
      </div>

      <div className="mt-4">
        {empty ? (
          <div className="flex h-[240px] items-center justify-center rounded-[12px] border border-dashed border-[#e7dfd4]" data-testid="sales-empty">
            <p className="m-0 font-display text-[12.5px] text-[#8d8884]">No completed orders in this period yet.</p>
          </div>
        ) : (
          <SalesChart key={view} points={points} />
        )}
      </div>
    </section>
  );
}

function TopProducts({ products }) {
  const most = products[0]?.quantity ?? 0;

  return (
    <section className="rounded-[16px] bg-white p-4 ring-1 ring-[#f0e9df] sm:p-5" data-testid="panel-products">
      <p className="m-0 font-display text-[11px] font-semibold uppercase tracking-[0.8px] text-[#8d8884]">Product Performance</p>
      <h2 className="m-0 mt-0.5 font-display text-[16px] font-bold text-ink">Top Selling Products</h2>
      <p className="m-0 mt-0.5 font-display text-[11px] text-[#8d8884]">Last 7 days, by units on completed and delivered orders.</p>

      {products.length === 0 ? (
        <p className="m-0 mt-4 font-display text-[12.5px] text-[#8d8884]" data-testid="products-empty">
          No dishes sold in the last 7 days.
        </p>
      ) : (
        <ol className="m-0 mt-4 flex list-none flex-col gap-2.5 p-0">
          {products.map((product, index) => (
            <li key={product.food_id} data-testid="product-row" className="flex items-center gap-3 rounded-[12px] px-3 py-2.5 ring-1 ring-inset ring-[#f3ede4]">
              <span className={`grid h-7 w-7 shrink-0 place-items-center rounded-full font-display text-[12px] font-bold ${RANK_TONES[index] ?? "bg-[#f1eee9] text-[#6f6b68]"}`}>{index + 1}</span>
              <div className="min-w-0 flex-1">
                <div className="flex min-w-0 items-center gap-2">
                  <span className="truncate font-display text-[13.5px] font-semibold text-ink">{product.food_name}</span>
                  {product.category && <span className="shrink-0 rounded-full bg-[#fff1e6] px-2 py-[1px] font-display text-[9.5px] font-semibold uppercase tracking-[0.3px] text-brand-700">{product.category}</span>}
                </div>
                <div className="mt-1.5 h-[6px] overflow-hidden rounded-full bg-[#f6f1ea]" aria-hidden="true">
                  <div className="h-full rounded-full bg-brand-500" style={{ width: `${Math.max(4, (product.quantity / most) * 100)}%` }} />
                </div>
                <p className="m-0 mt-1 font-display text-[11px] text-[#8d8884]">
                  <strong className="font-semibold text-ink">{product.quantity}</strong> units sold <strong className="ml-2 font-semibold text-brand-600">{formatPeso(product.sales)}</strong> sales
                </p>
              </div>
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}

const PILLS = {
  delivery: "bg-[#eef4ff] text-[#2563eb] ring-[#c9dafd]",
  pickup: "bg-[#ecf9f0] text-[#2f9e44] ring-[#bfe8cb]",
  paid: "bg-[#ecf9f0] text-[#2f9e44] ring-[#bfe8cb]",
  unverified: "bg-[#f4f1ec] text-[#6f6b68] ring-[#e4ded5]",
};

function Pill({ tone, children }) {
  return <span className={`inline-flex whitespace-nowrap rounded-full px-2.5 py-[3px] font-display text-[10.5px] font-semibold ring-1 ring-inset ${PILLS[tone]}`}>{children}</span>;
}

function PaymentPill({ record }) {
  return record.payment_verified ? <Pill tone="paid">Cleared Paid</Pill> : <Pill tone="unverified">Not Verified</Pill>;
}

function FulfilmentPill({ record }) {
  return <Pill tone={record.order_type === "delivery" ? "delivery" : "pickup"}>{typeLabel(record)}</Pill>;
}

const LOG_GRID = "grid grid-cols-[minmax(84px,0.8fr)_minmax(110px,1fr)_minmax(150px,1.7fr)_88px_108px_96px_112px] items-center gap-x-3";

function SalesLog() {
  const navigate = useNavigate();
  const [search, setSearch] = useState("");
  const [debounced] = useDebouncedValue(search.trim(), 300);
  const log = useSalesLog({ date: storeDate(), search: debounced });
  const records = log.data?.data ?? [];

  return (
    <section className="overflow-hidden rounded-[16px] bg-white ring-1 ring-[#f0e9df]" data-testid="sales-log">
      <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-5">
        <div className="min-w-0">
          <h2 className="m-0 font-display text-[16px] font-bold text-ink">Daily Sales Report</h2>
          <p className="m-0 mt-0.5 font-display text-[11px] text-[#8d8884]">
            Today's latest completed orders.{" "}
            <Link to={DAILY_REPORT} data-testid="link-daily-report" className="inline-flex items-center gap-0.5 font-semibold text-brand-600 no-underline hover:text-brand-800">
              View full report
              <IconArrowRight size={12} stroke={2.2} />
            </Link>
          </p>
        </div>
        <StaffSearch value={search} onChange={setSearch} placeholder="Search by ticket ID or customer…" />
      </div>

      {log.isError ? (
        <div className="px-4 pb-4">
          <ErrorBlock message="The sales log could not be loaded." onRetry={() => log.refetch()} testId="log-error" />
        </div>
      ) : log.isLoading ? (
        <div className="flex flex-col gap-2 px-4 pb-4" aria-hidden="true">
          {[0, 1, 2].map((key) => (
            <Skeleton key={key} height={48} radius={8} />
          ))}
        </div>
      ) : records.length === 0 ? (
        <p className="m-0 px-6 pb-10 pt-4 text-center font-display text-[12.5px] text-[#8d8884]" data-testid="log-empty">
          {debounced ? "No completed orders today match that search." : "No orders completed yet today."}
        </p>
      ) : (
        <>
          <div className="hidden overflow-x-auto lg:block">
            <div className="min-w-[820px]">
              <div className={`${LOG_GRID} border-y border-[#f3ede4] bg-[#fbf9f5] px-5 py-2.5 font-display text-[10px] font-bold uppercase tracking-[0.8px] text-[#8d8884]`}>
                <span>Invoice Ref</span>
                <span>Customer</span>
                <span>Items</span>
                <span>Fulfillment</span>
                <span>Tax Exemption</span>
                <span className="text-right">Total</span>
                <span className="text-center">For Check</span>
              </div>
              {records.map((record) => (
                <button key={record.id} type="button" onClick={() => navigate(reportLinkFor(record))} data-testid="log-row" className={`${LOG_GRID} w-full border-b border-[#f6f1ea] bg-white px-5 py-3 text-left font-display transition-colors last:border-b-0 hover:bg-[#fffaf4]`}>
                  <span className="min-w-0">
                    <span className="block text-[13px] font-bold text-brand-600">{record.reference}</span>
                    <span className="block text-[10.5px] text-[#a39f9b]">{formatRecordDate(record.closed_at)}</span>
                  </span>
                  <span className="truncate text-[12.5px] font-medium text-ink">{record.customer?.name || "Customer"}</span>
                  <span className="truncate text-[12px] text-[#5f5b58]" title={itemsPreview(record)}>
                    {itemsPreview(record)}
                  </span>
                  <span>
                    <FulfilmentPill record={record} />
                  </span>
                  <span className={`text-[12px] font-medium ${record.discount_amount > 0 ? "text-[#e5322d]" : "text-[#a39f9b]"}`}>{record.discount_amount > 0 ? formatPeso(record.discount_amount) : "—"}</span>
                  <span className="text-right text-[13px] font-bold text-ink">{formatPeso(record.total)}</span>
                  <span className="text-center">
                    <PaymentPill record={record} />
                  </span>
                </button>
              ))}
            </div>
          </div>

          <div className="flex flex-col gap-2.5 px-3 pb-3 lg:hidden">
            {records.map((record) => (
              <button key={record.id} type="button" onClick={() => navigate(reportLinkFor(record))} data-testid="log-card" className="flex w-full flex-col gap-1.5 rounded-[12px] bg-white p-3 text-left font-display ring-1 ring-[#f0e9df]">
                <span className="flex w-full items-center justify-between gap-2">
                  <span className="text-[13.5px] font-bold text-brand-600">{record.reference}</span>
                  <PaymentPill record={record} />
                </span>
                <span className="text-[12.5px] font-semibold text-ink">{record.customer?.name || "Customer"}</span>
                <span className="truncate text-[12px] text-[#6f6b68]">{itemsPreview(record)}</span>
                <span className="flex w-full items-center justify-between gap-2 border-t border-dashed border-[#f0e9df] pt-2">
                  <FulfilmentPill record={record} />
                  <span className="text-[14px] font-bold text-ink">{formatPeso(record.total)}</span>
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
  const [view, setView] = useState("weekly");
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
        <header className="border-b border-[#f0e9df] bg-white px-4 py-4 sm:px-6 lg:px-7">
          <h1 className="m-0 font-display text-[18px] font-bold tracking-[-0.3px] text-ink">Analytics Dashboard</h1>
          <p className="m-0 mt-0.5 font-display text-[12px] text-[#8d8884]">Real-time overview of operations — Apalit, Pampanga</p>
        </header>

        <div className="flex flex-1 flex-col gap-3 px-4 py-4 sm:px-6 lg:px-7">
          {operations.isError ? (
            <ErrorBlock message="The order figures could not be loaded." onRetry={() => operations.refetch()} testId="cards-error" />
          ) : (
            <StatCards data={operations.data} />
          )}

          {analytics.isError ? (
            <ErrorBlock message="The sales figures could not be loaded." onRetry={() => analytics.refetch()} testId="sales-error" />
          ) : analytics.isLoading ? (
            <div className="flex flex-col gap-3" data-testid="sales-loading" aria-hidden="true">
              <Skeleton height={340} radius={16} />
              <Skeleton height={320} radius={16} />
            </div>
          ) : (
            <>
              <SalesPanel sales={analytics.data.sales} view={view} onView={setView} />
              <TopProducts products={analytics.data.products} />
            </>
          )}

          <SalesLog />
        </div>
      </main>
    </StaffLayout>
  );
}

export default SuperAdminDashboard;
