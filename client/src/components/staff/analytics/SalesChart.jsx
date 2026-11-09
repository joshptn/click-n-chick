import { useState } from "react";
import { useElementSize } from "@mantine/hooks";

import { formatPeso } from "../../../lib/menu";

const HEIGHT = 240;
const MARGIN = { top: 16, right: 16, bottom: 28, left: 48 };
const TICKS = 4;
const MIN_LABEL_WIDTH = 56;
const MAX_DOTS = 14;
const LINE = "#f27d1d";
const GRID = "#f3ede4";

function niceStep(max) {
  const rough = max / TICKS;
  const magnitude = 10 ** Math.floor(Math.log10(rough));
  const scaled = rough / magnitude;

  return (scaled <= 1 ? 1 : scaled <= 2 ? 2 : scaled <= 5 ? 5 : 10) * magnitude;
}

function shortAmount(amount) {
  if (amount < 1000) return `₱${amount}`;

  const thousands = amount / 1000;

  return `₱${Number.isInteger(thousands) ? thousands : thousands.toFixed(1)}K`;
}

const orderCount = (count) => `${count} ${count === 1 ? "order" : "orders"}`;

/**
 * One line of sales over time. Drawn by hand to avoid shipping a chart library
 * to the staff pages for a single chart.
 */
function SalesChart({ points }) {
  const { ref, width } = useElementSize();
  const [active, setActive] = useState(null);

  const values = points.map((point) => point.value);
  const highest = Math.max(0, ...values);
  const step = niceStep(highest || TICKS * 100);
  const ceiling = step * Math.max(1, Math.ceil(highest / step));
  const plotWidth = Math.max(0, width - MARGIN.left - MARGIN.right);
  const plotHeight = HEIGHT - MARGIN.top - MARGIN.bottom;
  const count = points.length;

  const x = (index) => MARGIN.left + (count > 1 ? (index / (count - 1)) * plotWidth : plotWidth / 2);
  const y = (value) => MARGIN.top + plotHeight - (value / ceiling) * plotHeight;

  const ticks = Array.from({ length: Math.round(ceiling / step) + 1 }, (_, index) => index * step);
  const labelEvery = Math.max(1, Math.ceil(count / Math.max(1, Math.floor(plotWidth / MIN_LABEL_WIDTH))));
  const path = values.map((value, index) => `${index === 0 ? "M" : "L"}${x(index).toFixed(1)} ${y(value).toFixed(1)}`).join(" ");

  const pick = (clientX, element) => {
    const offset = clientX - element.getBoundingClientRect().left - MARGIN.left;
    const index = count > 1 ? Math.round((offset / plotWidth) * (count - 1)) : 0;

    setActive(Math.min(count - 1, Math.max(0, index)));
  };

  const onKeyDown = (event) => {
    if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;

    event.preventDefault();

    const delta = event.key === "ArrowRight" ? 1 : -1;

    setActive((index) => Math.min(count - 1, Math.max(0, (index ?? (delta > 0 ? -1 : count)) + delta)));
  };

  const point = active === null ? null : points[active];
  const flip = point && x(active) > width / 2;

  return (
    <div
      ref={ref}
      className="relative outline-none focus-visible:rounded-[8px] focus-visible:ring-2 focus-visible:ring-brand-300"
      style={{ height: HEIGHT }}
      tabIndex={0}
      role="group"
      aria-label="Sales chart. Use the left and right arrow keys to read each value."
      data-testid="sales-chart"
      onKeyDown={onKeyDown}
      onBlur={() => setActive(null)}
    >
      {width > 0 && (
        <svg
          width={width}
          height={HEIGHT}
          className="block touch-pan-y"
          aria-hidden="true"
          onPointerMove={(event) => pick(event.clientX, event.currentTarget)}
          onPointerLeave={() => setActive(null)}
        >
          {ticks.map((tick) => (
            <g key={tick}>
              <line x1={MARGIN.left} x2={width - MARGIN.right} y1={y(tick)} y2={y(tick)} stroke={GRID} />
              <text x={MARGIN.left - 8} y={y(tick) + 3.5} textAnchor="end" className="fill-[#a39f9b] font-display text-[10px] tabular-nums">
                {shortAmount(tick)}
              </text>
            </g>
          ))}

          {points.map(
            (entry, index) =>
              index % labelEvery === 0 && (
                <text key={entry.id} x={x(index)} y={HEIGHT - 8} textAnchor={index === 0 ? "start" : index === count - 1 ? "end" : "middle"} className="fill-[#a39f9b] font-display text-[10px]">
                  {entry.axis}
                </text>
              )
          )}

          <path d={path} fill="none" stroke={LINE} strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />

          {count <= MAX_DOTS && values.map((value, index) => <circle key={points[index].id} cx={x(index)} cy={y(value)} r={3.5} fill={LINE} stroke="#fff" strokeWidth={1.5} />)}

          {point && (
            <>
              <line x1={x(active)} x2={x(active)} y1={MARGIN.top} y2={y(0)} stroke="#d9d3cb" />
              <circle cx={x(active)} cy={y(values[active])} r={5} fill={LINE} stroke="#fff" strokeWidth={2} />
            </>
          )}
        </svg>
      )}

      {point && (
        <div
          data-testid="sales-tooltip"
          className="pointer-events-none absolute top-2 z-10 flex flex-col rounded-[10px] bg-white px-3 py-2 font-display shadow-[0_6px_20px_-6px_rgba(60,40,20,0.3)] ring-1 ring-[#f0e9df]"
          style={{ left: x(active), transform: flip ? "translateX(calc(-100% - 12px))" : "translateX(12px)" }}
        >
          <span className="whitespace-nowrap text-[11px] text-[#8d8884]">{point.title}</span>
          <span className="whitespace-nowrap text-[13px] font-bold text-ink">{formatPeso(point.value)}</span>
          <span className="whitespace-nowrap text-[11px] text-[#8d8884]">{orderCount(point.orders)}</span>
        </div>
      )}

      <span className="sr-only" aria-live="polite">
        {point ? `${point.title}: ${formatPeso(point.value)}, ${orderCount(point.orders)}.` : ""}
      </span>
    </div>
  );
}

export default SalesChart;
