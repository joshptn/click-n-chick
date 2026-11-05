import { forwardRef } from "react";
import { Skeleton } from "@mantine/core";
import { useMediaQuery } from "@mantine/hooks";
import { AnimatePresence, LayoutGroup } from "framer-motion";
import { IconInbox } from "@tabler/icons-react";

import AgentOrderCard from "./AgentOrderCard";
import { TONES } from "./tones";

function ColumnHeader({ column }) {
  const tone = TONES[column.tone];

  return (
    <div className="mb-2.5 flex items-center gap-1.5 px-1">
      <span className={`h-2 w-2 rounded-full ${tone.dot}`} aria-hidden="true" />
      <h2 className={`m-0 font-display text-[13.5px] font-semibold ${tone.text}`}>{column.label}</h2>
      <span
        className={`grid h-[18px] min-w-[18px] place-items-center rounded-full px-1.5 font-display text-[10px] font-bold text-white ${tone.pill}`}
      >
        {column.orders.length}
      </span>
    </div>
  );
}

function EmptyColumn({ column, searching }) {
  const tone = TONES[column.tone];

  return (
    <div
      className={`flex flex-col items-center justify-center gap-1.5 rounded-[12px] border-2 border-dashed border-current px-4 py-6 opacity-75 ${tone.surface} ${tone.text}`}
    >
      <IconInbox size={18} stroke={1.6} />
      <span className="font-display text-[11px]">{searching ? "No matches" : "No orders"}</span>
    </div>
  );
}

const defaultCard = (order, cardProps) => (
  <AgentOrderCard key={order.id} order={order} busy={cardProps.isBusy(order.id)} {...cardProps} />
);

const Column = forwardRef(function Column({ column, loading, searching, cardProps, renderCard = defaultCard }, ref) {
  return (
    <section ref={ref} aria-label={`${column.label} orders`} className="flex min-w-0 flex-col" data-column={column.key}>
      <ColumnHeader column={column} />

      <div className="flex flex-col gap-2.5">
        {loading ? (
          [0, 1].map((index) => <Skeleton key={index} height={130} radius={12} />)
        ) : column.orders.length === 0 ? (
          <EmptyColumn column={column} searching={searching} />
        ) : (
          <AnimatePresence mode="popLayout" initial={false}>
            {column.orders.map((order) => renderCard(order, cardProps))}
          </AnimatePresence>
        )}
      </div>
    </section>
  );
});

/**
 * Five columns side by side from `lg`; below that, one column at a time behind
 * a tab row, since five 280px columns cannot share a phone. `renderCard` lets a
 * page with its own kind of order (the advance list) keep the same board.
 */
function OrderBoard({
  columns,
  activeColumn,
  onActiveColumnChange,
  loading,
  searching,
  columnRefs,
  cardProps,
  renderCard,
  label = "Order stages",
}) {
  // One layout mounted at a time: hiding the other with CSS would render every
  // card twice under the same layoutId.
  const wide = useMediaQuery("(min-width: 64em)", true);
  const active = columns.find((column) => column.key === activeColumn) ?? columns[0];

  if (wide) {
    return (
      <LayoutGroup>
        <div className="overflow-x-auto pb-2">
          <div className="grid min-w-[1040px] grid-cols-5 gap-3">
            {columns.map((column) => (
              <Column
                key={column.key}
                ref={(node) => {
                  columnRefs.current[column.key] = node;
                }}
                column={column}
                loading={loading}
                searching={searching}
                cardProps={cardProps}
                renderCard={renderCard}
              />
            ))}
          </div>
        </div>
      </LayoutGroup>
    );
  }

  return (
    <LayoutGroup>
      <div>
        <div role="tablist" aria-label={label} className="-mx-4 mb-3 flex gap-1.5 overflow-x-auto px-4 pb-1 text-[12px]">
          {columns.map((column) => {
            const selected = column.key === active.key;
            const tone = TONES[column.tone];

            return (
              <button
                key={column.key}
                type="button"
                role="tab"
                aria-selected={selected}
                onClick={() => onActiveColumnChange(column.key)}
                className={`flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 font-display font-semibold transition-colors ${
                  selected ? `border-transparent bg-white shadow-sm ${tone.text}` : "border-[#ece7e0] bg-transparent text-[#8d8884]"
                }`}
              >
                <span className={`h-2 w-2 rounded-full ${tone.dot}`} aria-hidden="true" />
                {column.label}
                <span className="text-[10.5px] opacity-80">{column.orders.length}</span>
              </button>
            );
          })}
        </div>

        <Column column={active} loading={loading} searching={searching} cardProps={cardProps} renderCard={renderCard} />
      </div>
    </LayoutGroup>
  );
}

export default OrderBoard;
