import { Skeleton } from "@mantine/core";

import { formatPeso } from "../../lib/menu";
import {
  OUTCOME_PILL,
  formatRecordDate,
  formatRecordTime,
  itemsPreview,
  recordOutcome,
  typeLabel,
} from "../../lib/records";

/** Only a fulfilled order has a sale to verify; a payment record is what verifies it. */
function PaymentNote({ record }) {
  if (record.outcome !== "completed") return null;

  return (
    <span className={`font-display text-[10px] font-medium ${record.payment_verified ? "text-[#2f9e44]" : "text-[#a39f9b]"}`}>
      {record.payment_verified ? "Payment verified" : "Not verified"}
    </span>
  );
}

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

export function RecordTable({ records, loading, selectedId, onSelect, intent, showPayment = false }) {
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
                  <span className="flex flex-col items-center gap-0.5">
                    <StatusPill record={record} />
                    {showPayment && <PaymentNote record={record} />}
                  </span>
                </button>
              );
            })}
      </div>
    </div>
  );
}

export function RecordCards({ records, loading, selectedId, onSelect, intent, showPayment = false }) {
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
            <span className="flex flex-col items-end">
              <span className="font-display text-[14px] font-bold text-ink">{formatPeso(record.total)}</span>
              {showPayment && <PaymentNote record={record} />}
            </span>
          </span>
        </button>
      ))}
    </div>
  );
}

export function DateInput({ label, value, onChange, min, max }) {
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
