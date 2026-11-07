import { useEffect, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { Drawer, Pagination, Skeleton } from "@mantine/core";
import { useMediaQuery } from "@mantine/hooks";
import { IconArchive, IconRefresh } from "@tabler/icons-react";

import FilterSelect from "../../components/staff/FilterSelect";
import LivePill from "../../components/staff/LivePill";
import RecordDetailPanel from "../../components/staff/RecordDetailPanel";
import StaffLayout from "../../components/staff/StaffLayout";
import StaffSearch from "../../components/staff/StaffSearch";
import StatusSummary from "../../components/staff/StatusSummary";
import Button from "../../components/ui/Button";
import { useOrderRecord, useOrderRecords, useRecordPrefetch } from "../../hooks/useOrderRecords";
import { formatPeso } from "../../lib/menu";
import {
  DATE_RANGES,
  ORDER_KINDS,
  ORDER_TYPES,
  OUTCOMES,
  OUTCOME_PILL,
  PAYMENT_FILTERS,
  RECORD_DEFAULTS,
  RECORD_SORTS,
  formatRecordDate,
  formatRecordTime,
  itemsPreview,
  readRecordFilters,
  recordOutcome,
  recordQueryParams,
  storeDate,
  typeLabel,
} from "../../lib/records";

const PAGE_TITLE = "Order Records · Click n Chick";

/** The summary cards double as the outcome filter. */
const SUMMARY = [
  { key: "all", label: "All Closed", tone: "slate", field: "total" },
  { key: "completed", label: "Completed", tone: "green", field: "completed" },
  { key: "cancelled", label: "Cancelled", tone: "orange", field: "cancelled" },
  { key: "rejected", label: "Declined", tone: "amber", field: "rejected" },
  { key: "value", label: "Completed Sales", tone: "blue", field: "completed_value", money: true },
];

const FILTER_KEYS = ["q", "outcome", "range", "type", "kind", "payment"];

function StatusPill({ record }) {
  const outcome = recordOutcome(record);

  return (
    <span
      className={`inline-flex rounded-full px-2.5 py-[3px] font-display text-[10.5px] font-semibold ring-1 ring-inset ${OUTCOME_PILL[outcome.tone]}`}
      title={outcome.detail}
    >
      {outcome.label}
    </span>
  );
}

function KindTag({ record }) {
  if (!record.is_advance) return null;

  return (
    <span className="rounded bg-[#f3ecff] px-1.5 font-display text-[9.5px] font-bold uppercase tracking-[0.3px] text-[#7c3aed]">
      Advance
    </span>
  );
}

const GRID =
  "grid grid-cols-[minmax(92px,0.9fr)_minmax(120px,1.3fr)_minmax(120px,1.6fr)_minmax(64px,0.6fr)_minmax(80px,0.8fr)_minmax(88px,0.7fr)] items-center gap-x-3";

function TableView({ records, loading, selectedId, onSelect, intent }) {
  return (
    <div className="overflow-x-auto rounded-[16px] bg-white ring-1 ring-[#f0e9df]">
      <div className="min-w-[640px]">
        <div
          className={`${GRID} border-b border-[#f3ede4] px-4 py-3 font-display text-[10px] font-bold uppercase tracking-[0.8px] text-[#8d8884]`}
        >
          <span>Order</span>
          <span>Customer</span>
          <span>Items</span>
          <span>Type</span>
          <span className="text-right">Total</span>
          <span className="text-center">Status</span>
        </div>

        {loading
          ? [0, 1, 2, 3, 4, 5].map((key) => (
              <div key={key} className={`${GRID} border-b border-[#f6f1ea] px-4 py-3.5 last:border-b-0`}>
                <Skeleton height={28} width={70} />
                <Skeleton height={14} width="75%" />
                <Skeleton height={14} width="85%" />
                <Skeleton height={14} width={50} />
                <Skeleton height={14} width={64} className="justify-self-end" />
                <Skeleton height={20} width={76} radius="xl" className="justify-self-center" />
              </div>
            ))
          : records.map((record) => {
              const selected = String(record.id) === selectedId;

              return (
                <button
                  key={record.id}
                  type="button"
                  onClick={() => onSelect(record)}
                  onPointerEnter={() => intent.intend(record.id)}
                  onPointerLeave={intent.cancel}
                  onFocus={() => intent.intend(record.id)}
                  aria-pressed={selected}
                  data-testid="record-row"
                  data-record={record.id}
                  className={`${GRID} w-full border-b border-l-[3px] border-b-[#f6f1ea] px-4 py-3 text-left transition-colors last:border-b-0 ${
                    selected ? "border-l-brand-500 bg-[#fff6ec]" : "border-l-transparent bg-white hover:bg-[#fffaf4]"
                  }`}
                >
                  <span className="min-w-0">
                    <span className="block font-display text-[13px] font-bold text-brand-600">{record.reference}</span>
                    <span className="block font-display text-[10.5px] text-[#a39f9b]">
                      {formatRecordDate(record.closed_at)} · {formatRecordTime(record.closed_at)}
                    </span>
                  </span>
                  <span className="min-w-0">
                    <span className="block truncate font-display text-[12.5px] font-medium text-ink">
                      {record.customer?.name || "Customer"}
                    </span>
                    {record.customer?.is_guest && (
                      <span className="block font-display text-[10.5px] text-[#a39f9b]">Guest</span>
                    )}
                  </span>
                  <span className="min-w-0">
                    <span className="block truncate font-display text-[12px] text-[#5f5b58]" title={itemsPreview(record)}>
                      {itemsPreview(record)}
                    </span>
                    <span className="block font-display text-[10.5px] text-[#a39f9b]">
                      {record.item_count} {record.item_count === 1 ? "item" : "items"}
                    </span>
                  </span>
                  <span className="flex flex-col items-start gap-0.5">
                    <span className="font-display text-[12px] text-[#5f5b58]">{typeLabel(record)}</span>
                    <KindTag record={record} />
                  </span>
                  <span className="text-right font-display text-[13px] font-bold text-ink">{formatPeso(record.total)}</span>
                  <span className="text-center">
                    <StatusPill record={record} />
                  </span>
                </button>
              );
            })}
      </div>
    </div>
  );
}

function CardView({ records, loading, selectedId, onSelect, intent }) {
  if (loading) {
    return (
      <div className="flex flex-col gap-2.5">
        {[0, 1, 2, 3].map((key) => (
          <Skeleton key={key} height={104} radius={14} />
        ))}
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-2.5">
      {records.map((record) => (
        <button
          key={record.id}
          type="button"
          onClick={() => onSelect(record)}
          onPointerEnter={() => intent.intend(record.id)}
          onPointerLeave={intent.cancel}
          onFocus={() => intent.intend(record.id)}
          aria-pressed={String(record.id) === selectedId}
          data-testid="record-row"
          data-record={record.id}
          className="flex w-full flex-col gap-1.5 rounded-[14px] bg-white p-3.5 text-left ring-1 ring-[#f0e9df] transition-colors hover:bg-[#fffaf4]"
        >
          <span className="flex w-full items-center justify-between gap-2">
            <span className="flex items-center gap-1.5">
              <span className="font-display text-[14px] font-bold text-brand-600">{record.reference}</span>
              <KindTag record={record} />
            </span>
            <StatusPill record={record} />
          </span>
          <span className="block font-display text-[13px] font-semibold text-ink">
            {record.customer?.name || "Customer"}
            {record.customer?.is_guest && <span className="ml-1.5 text-[10.5px] font-medium text-[#a39f9b]">Guest</span>}
          </span>
          <span className="block truncate font-display text-[12px] text-[#6f6b68]">{itemsPreview(record)}</span>
          <span className="mt-0.5 flex w-full items-center justify-between gap-2 border-t border-dashed border-[#f0e9df] pt-2">
            <span className="font-display text-[11px] text-[#8d8884]">
              {typeLabel(record)} · {formatRecordDate(record.closed_at)}, {formatRecordTime(record.closed_at)}
            </span>
            <span className="font-display text-[14px] font-bold text-ink">{formatPeso(record.total)}</span>
          </span>
        </button>
      ))}
    </div>
  );
}

function DateInput({ label, value, onChange, min, max }) {
  return (
    <label className="relative inline-flex min-w-0 items-center text-[12px]">
      <span className="sr-only">{label}</span>
      <input
        type="date"
        value={value}
        min={min}
        max={max}
        onChange={(event) => onChange(event.target.value)}
        className="h-[34px] w-full rounded-full border border-brand-400 bg-white px-3.5 font-display text-[12px] font-medium text-brand-700 outline-none"
      />
    </label>
  );
}

/**
 * Order Records: every order that has ended, for a Store Agent looking back
 * (UC-OPS-007).
 *
 * Read-only. Completed, delivered, cancelled and declined orders are listed by
 * when they closed, filtered and paged on the server; the panel shows one in
 * full. Filters and the open record live in the URL, so a record can be linked
 * to and a reload lands on the same results.
 */
function OrderRecords() {
  const wide = useMediaQuery("(min-width: 64em)", true);
  // The side panel needs the room a 1280px screen leaves beside the table.
  const paneled = useMediaQuery("(min-width: 80em)", true);
  const [searchParams, setSearchParams] = useSearchParams();
  const filters = readRecordFilters(searchParams);
  const selectedId = searchParams.get("order");

  const [searchText, setSearchText] = useState(filters.q);
  // A record the page picked by itself must not pop a drawer open on a
  // narrower screen; the drawer follows the agent's own taps and links.
  const [autoPicked, setAutoPicked] = useState(false);

  const params = recordQueryParams(filters);
  const list = useOrderRecords(params);
  const detail = useOrderRecord(selectedId);
  // Details for a row the pointer or focus rests on, so picking it is instant.
  const intent = useRecordPrefetch();

  // Paging is a step the Back button should undo; filtering and selecting
  // replace the entry so Back leaves the page rather than replaying each tap.
  const write = (changes, { resetPage = true, push = false } = {}) => {
    setSearchParams(
      (current) => {
        const next = new URLSearchParams(current);

        Object.entries(changes).forEach(([key, value]) => {
          if (value === undefined || value === null || value === "" || value === RECORD_DEFAULTS[key]) next.delete(key);
          else next.set(key, value);
        });

        if (resetPage && !("page" in changes)) next.delete("page");

        return next;
      },
      { replace: !push }
    );
  };

  const set = (key) => (value) => write({ [key]: value });

  // Search is sent once typing pauses, not on every key.
  useEffect(() => {
    if (searchText.trim() === filters.q.trim()) return undefined;

    const timer = setTimeout(() => write({ q: searchText.trim() }), 300);

    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchText]);

  const select = (record, { auto = false } = {}) => {
    setAutoPicked(auto);
    write({ order: record ? String(record.id) : null }, { resetPage: false });
  };

  // A page past the end, after a filter narrows the results or records leave.
  const lastPage = list.meta?.last_page ?? 1;
  const page = Number(filters.page);

  useEffect(() => {
    if (list.meta && !list.isPlaceholder && page > lastPage) write({ page: String(lastPage) }, { resetPage: false });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, lastPage, list.meta, list.isPlaceholder]);

  // With the panel always on screen, it opens on the latest record rather
  // than an empty card.
  const first = list.records[0];

  useEffect(() => {
    if (paneled && !selectedId && first && !list.isPlaceholder) select(first, { auto: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [paneled, selectedId, first?.id, list.isPlaceholder]);

  useEffect(() => {
    document.title = PAGE_TITLE;

    return () => {
      document.title = "Chick N' Click";
    };
  }, []);

  const filtered = FILTER_KEYS.some((key) => filters[key] !== RECORD_DEFAULTS[key]);

  const clear = () => {
    setSearchText("");
    write({ q: "", outcome: "all", range: "all", from: "", to: "", type: "all", kind: "all", payment: "all" });
  };

  const summary = list.summary;
  const summaryCards = SUMMARY.map((card) => ({
    ...card,
    total: card.money ? formatPeso(summary?.[card.field] ?? 0) : summary?.[card.field] ?? 0,
  }));

  const preview = list.records.find((record) => String(record.id) === selectedId) ?? null;

  const panel = (
    <RecordDetailPanel
      preview={preview}
      record={detail.record}
      isLoading={detail.isLoading}
      isError={detail.isError}
      error={detail.error}
      onRetry={() => detail.refetch()}
    />
  );

  const today = storeDate();
  const meta = list.meta;
  const outcomeLabel = OUTCOMES.find((option) => option.key === filters.outcome)?.label;

  return (
    <StaffLayout>
      <div className="flex flex-1">
        <main className="flex min-w-0 flex-1 flex-col">
          <header className="border-b border-[#f0e9df] bg-white px-4 py-4 sm:px-6 lg:px-7">
            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2.5">
                  <h1 className="m-0 font-display text-[18px] font-bold tracking-[-0.3px] text-ink">Order Records</h1>
                  <LivePill />
                </div>
                <p className="m-0 mt-0.5 font-display text-[12px] text-[#8d8884]">
                  Completed, cancelled and declined orders. Records are read-only.
                </p>
              </div>

              <StaffSearch
                value={searchText}
                onChange={setSearchText}
                placeholder="Search orders…"
                title="Search by ticket or order number, customer name, email, phone or dish"
                label="Search orders"
              />
            </div>

            <div className="mt-4">
              <StatusSummary
                columns={summaryCards}
                loading={list.isLoading}
                onSelect={(key) => {
                  const outcome = key === "value" ? "completed" : key;
                  write({ outcome: filters.outcome === outcome && key !== "all" ? "all" : outcome });
                }}
              />
            </div>
          </header>

          <div className="flex flex-1 flex-col gap-3 px-4 py-4 sm:px-6 lg:px-7">
            <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
              <FilterSelect
                label="Date closed"
                value={filters.range}
                onChange={(range) =>
                  write(range === "custom" ? { range, from: filters.from || today, to: filters.to || today } : { range, from: "", to: "" })
                }
                options={DATE_RANGES}
                active={filters.range !== "all"}
                testId="filter-range"
              />
              {filters.range === "custom" && (
                <div className="col-span-2 flex items-center gap-1.5 sm:col-span-1">
                  <DateInput label="From" value={filters.from} max={filters.to || today} onChange={set("from")} />
                  <span className="font-display text-[11px] text-[#a39f9b]">to</span>
                  <DateInput label="To" value={filters.to} min={filters.from} max={today} onChange={set("to")} />
                </div>
              )}
              <FilterSelect
                label="Outcome"
                value={filters.outcome}
                onChange={set("outcome")}
                options={OUTCOMES}
                active={filters.outcome !== "all"}
                testId="filter-outcome"
              />
              <FilterSelect
                label="Order type"
                value={filters.type}
                onChange={set("type")}
                options={ORDER_TYPES}
                active={filters.type !== "all"}
                testId="filter-type"
              />
              <FilterSelect
                label="Regular or advance"
                value={filters.kind}
                onChange={set("kind")}
                options={ORDER_KINDS}
                active={filters.kind !== "all"}
                testId="filter-kind"
              />
              <FilterSelect
                label="Payment"
                value={filters.payment}
                onChange={set("payment")}
                options={PAYMENT_FILTERS}
                active={filters.payment !== "all"}
                testId="filter-payment"
              />
              <FilterSelect
                label="Sort"
                value={filters.sort}
                onChange={set("sort")}
                options={RECORD_SORTS}
                active={false}
                testId="filter-sort"
              />

              <div className="col-span-2 flex items-center gap-3 font-display text-[11.5px] text-[#8d8884] sm:ml-auto">
                {meta && (
                  <span data-testid="result-count">
                    {meta.total} {filters.outcome !== "all" ? outcomeLabel.toLowerCase() : "closed"}{" "}
                    {meta.total === 1 ? "order" : "orders"}
                  </span>
                )}
                {filtered && (
                  <button
                    type="button"
                    onClick={clear}
                    className="bg-transparent font-semibold text-brand-600 hover:text-brand-800"
                  >
                    <span className="text-[11.5px]">Clear filters</span>
                  </button>
                )}
              </div>
            </div>

            {list.isError && !list.meta ? (
              <div className="flex flex-col items-center gap-3 rounded-[16px] bg-white px-6 py-12 text-center ring-1 ring-[#f0e9df]">
                <p className="m-0 font-display text-[15px] font-semibold text-ink">The order records could not be loaded.</p>
                <p className="m-0 font-display text-[13px] text-[#8d8884]">{list.error?.message}</p>
                <Button size="sm" variant="outline" onClick={() => list.refetch()}>
                  <IconRefresh size={16} stroke={2} />
                  Try again
                </Button>
              </div>
            ) : !list.isLoading && list.records.length === 0 ? (
              <div
                data-testid="records-empty"
                className="flex flex-col items-center gap-2 rounded-[16px] border border-dashed border-[#e7dfd4] bg-white/60 px-6 py-12 text-center"
              >
                <IconArchive size={28} stroke={1.6} className="text-[#cfc7bd]" />
                <p className="m-0 font-display text-[14px] font-semibold text-ink">
                  {filtered ? "No order matches these filters." : "No closed orders yet."}
                </p>
                <p className="m-0 font-display text-[12px] text-[#8d8884]">
                  {filtered
                    ? "Try a wider date range or fewer filters."
                    : "Orders are filed here once they are completed, cancelled or declined."}
                </p>
                {filtered && (
                  <Button size="sm" variant="ghost" onClick={clear}>
                    Clear filters
                  </Button>
                )}
              </div>
            ) : (
              <div className={`transition-opacity ${list.isPlaceholder ? "opacity-60" : ""}`} aria-busy={list.isFetching}>
                {wide ? (
                  <TableView records={list.records} loading={list.isLoading} selectedId={selectedId} onSelect={select} intent={intent} />
                ) : (
                  <CardView records={list.records} loading={list.isLoading} selectedId={selectedId} onSelect={select} intent={intent} />
                )}
              </div>
            )}

            {meta && meta.total > 0 && (
              <div className="flex flex-col items-center justify-between gap-2.5 sm:flex-row">
                <p className="m-0 font-display text-[11.5px] text-[#8d8884]" data-testid="page-range">
                  Showing {meta.from}–{meta.to} of {meta.total}
                </p>
                {meta.last_page > 1 && (
                  <Pagination
                    total={meta.last_page}
                    value={Math.min(page, meta.last_page)}
                    onChange={(next) => write({ page: String(next) }, { resetPage: false, push: true })}
                    size="sm"
                    radius="xl"
                    color="orange"
                    siblings={wide ? 1 : 0}
                    boundaries={1}
                    getControlProps={(control) => ({ "aria-label": `${control} page` })}
                  />
                )}
              </div>
            )}
          </div>
        </main>

        {paneled && (
          <aside
            aria-label="Order details"
            className="sticky top-0 h-dvh w-[340px] shrink-0 overflow-y-auto border-l border-[#f0e9df] bg-white"
          >
            {panel}
          </aside>
        )}
      </div>

      {!paneled && (
        <Drawer
          opened={Boolean(selectedId) && !autoPicked}
          onClose={() => select(null)}
          position="right"
          size="min(100%, 380px)"
          padding={0}
          radius={0}
          aria-label="Order details"
        >
          {panel}
        </Drawer>
      )}
    </StaffLayout>
  );
}

export default OrderRecords;
