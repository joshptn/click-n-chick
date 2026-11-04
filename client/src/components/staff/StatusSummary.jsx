import { TONES } from "./tones";

/** The day at a glance; each card jumps to its column. */
function StatusSummary({ columns, onSelect, loading = false }) {
  return (
    <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-5">
      {columns.map((column) => {
        const tone = TONES[column.tone];

        return (
          <button
            key={column.key}
            type="button"
            onClick={() => onSelect(column.key)}
            data-testid={`summary-${column.key}`}
            className={`flex flex-col items-center justify-center rounded-[12px] px-3 py-2 ring-1 ring-inset transition-transform hover:-translate-y-0.5 ${tone.surface} ${tone.ring} last:col-span-2 sm:last:col-span-1`}
          >
            <span className={`font-display text-[18px] font-bold leading-none ${tone.text}`}>
              {loading ? "–" : column.total}
            </span>
            <span className={`mt-1 font-display text-[11px] font-medium ${tone.text}`}>{column.label}</span>
          </button>
        );
      })}
    </div>
  );
}

export default StatusSummary;
