import { useState } from "react";
import { useElementSize } from "@mantine/hooks";

import { formatPeso } from "../../../lib/menu";

export const CHART_HEIGHT = 264;

const MARGIN = { top: 14, right: 12, bottom: 28, left: 44 };
const TICKS = 4;
const MIN_LABEL_WIDTH = 56;
const LINE = "#f27d1d";
const GRID = "#f4efe8";

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
 * A curve through every point that never swings past a neighbouring value, so
 * it reads smoothly without suggesting sales that did not happen.
 */
function curvePath(points) {
  if (points.length < 3) return points.map(([x, y], index) => `${index === 0 ? "M" : "L"}${x.toFixed(1)} ${y.toFixed(1)}`).join(" ");

  const slopes = points.slice(1).map(([x, y], index) => (y - points[index][1]) / (x - points[index][0]));
  const tangents = points.map((_, index) => {
    if (index === 0) return slopes[0];
    if (index === points.length - 1) return slopes[index - 1];

    const [before, after] = [slopes[index - 1], slopes[index]];

    return before * after <= 0 ? 0 : (2 * before * after) / (before + after);
  });

  return points.reduce((path, [x, y], index) => {
    if (index === 0) return `M${x.toFixed(1)} ${y.toFixed(1)}`;

    const [fromX, fromY] = points[index - 1];
    const third = (x - fromX) / 3;

    return `${path} C${(fromX + third).toFixed(1)} ${(fromY + tangents[index - 1] * third).toFixed(1)} ${(x - third).toFixed(1)} ${(y - tangents[index] * third).toFixed(1)} ${x.toFixed(1)} ${y.toFixed(1)}`;
  }, "");
}

/**
 * One line of sales over time. Drawn by hand to avoid shipping a chart library
 * to the staff pages for a single chart.
 */
function SalesChart({ points, onPick, drillHint }) {
  const { ref, width } = useElementSize();
  const [active, setActive] = useState(null);

  const values = points.map((point) => point.value);
  const highest = Math.max(0, ...values);
  const step = niceStep(highest || TICKS * 100);
  const ceiling = step * Math.max(1, Math.ceil(highest / step));
  const plotWidth = Math.max(0, width - MARGIN.left - MARGIN.right);
  const plotHeight = CHART_HEIGHT - MARGIN.top - MARGIN.bottom;
  const count = points.length;

  const x = (index) => MARGIN.left + (count > 1 ? (index / (count - 1)) * plotWidth : plotWidth / 2);
  const y = (value) => MARGIN.top + plotHeight - (value / ceiling) * plotHeight;

  const ticks = Array.from({ length: Math.round(ceiling / step) + 1 }, (_, index) => index * step);
  const labelEvery = Math.max(1, Math.ceil(count / Math.max(1, Math.floor(plotWidth / MIN_LABEL_WIDTH))));
  const line = curvePath(values.map((value, index) => [x(index), y(value)]));

  const nearest = (clientX, element) => {
    const offset = clientX - element.getBoundingClientRect().left - MARGIN.left;
    const index = count > 1 ? Math.round((offset / plotWidth) * (count - 1)) : 0;

    return Math.min(count - 1, Math.max(0, index));
  };

  const pick = (clientX, element) => setActive(nearest(clientX, element));

  const onKeyDown = (event) => {
    if (event.key === "Enter" && active !== null) {
      onPick(points[active]);

      return;
    }

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
      style={{ height: CHART_HEIGHT }}
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
          height={CHART_HEIGHT}
          className={`block touch-pan-y ${drillHint ? "cursor-pointer" : ""}`}
          aria-hidden="true"
          onPointerMove={(event) => pick(event.clientX, event.currentTarget)}
          onClick={(event) => onPick(points[nearest(event.clientX, event.currentTarget)])}
          onPointerLeave={() => setActive(null)}
        >
          {ticks.map((tick) => (
            <g key={tick}>
              <line x1={MARGIN.left} x2={width - MARGIN.right} y1={y(tick)} y2={y(tick)} stroke={GRID} strokeDasharray="3 3" />
              <text x={MARGIN.left - 10} y={y(tick) + 3.5} textAnchor="end" className="fill-[#9a9590] font-display text-[10.5px] tabular-nums">
                {shortAmount(tick)}
              </text>
            </g>
          ))}

          {points.map((entry, index) => (
            <line key={entry.id} x1={x(index)} x2={x(index)} y1={MARGIN.top} y2={y(0)} stroke={GRID} strokeDasharray="3 3" />
          ))}

          {points.map(
            (entry, index) =>
              index % labelEvery === 0 && (
                <text key={entry.id} x={x(index)} y={CHART_HEIGHT - 6} textAnchor={index === 0 ? "start" : index === count - 1 ? "end" : "middle"} className="fill-[#9a9590] font-display text-[10.5px]">
                  {entry.axis}
                </text>
              )
          )}

          <path d={line} fill="none" stroke={LINE} strokeWidth={2.25} strokeLinejoin="round" strokeLinecap="round" />

          {values.map((value, index) => (
            <circle key={points[index].id} cx={x(index)} cy={y(value)} r={3.5} fill={LINE} stroke="#fff" strokeWidth={1.5} />
          ))}

          {point && (
            <>
              <line x1={x(active)} x2={x(active)} y1={MARGIN.top} y2={y(0)} stroke="#e2dcd3" />
              <circle cx={x(active)} cy={y(values[active])} r={5} fill={LINE} stroke="#fff" strokeWidth={2} />
            </>
          )}
        </svg>
      )}

      {point && (
        <div
          data-testid="sales-tooltip"
          className="pointer-events-none absolute top-1 z-10 flex flex-col gap-0.5 rounded-[10px] bg-white px-3 py-2 font-display shadow-[0_8px_24px_-8px_rgba(60,40,20,0.28)] ring-1 ring-[#f0e9df]"
          style={{ left: x(active), transform: flip ? "translateX(calc(-100% - 12px))" : "translateX(12px)" }}
        >
          <span className="whitespace-nowrap text-[11px] text-[#8d8884]">{point.title}</span>
          <span className="whitespace-nowrap text-[14px] font-semibold tabular-nums text-ink">{formatPeso(point.value)}</span>
          <span className="whitespace-nowrap text-[11px] text-[#8d8884]">{orderCount(point.orders)}</span>
          {drillHint && <span className="mt-0.5 whitespace-nowrap text-[10.5px] font-medium text-brand-600">{drillHint}</span>}
        </div>
      )}

      <span className="sr-only" aria-live="polite">
        {point ? `${point.title}: ${formatPeso(point.value)}, ${orderCount(point.orders)}.` : ""}
      </span>
    </div>
  );
}

export default SalesChart;
