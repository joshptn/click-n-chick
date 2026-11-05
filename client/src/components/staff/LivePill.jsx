import { useLastOrderEvent, useRealtime } from "../../context/useRealtime";

/** Whether the page is hearing broadcasts, so staff know a quiet page is a quiet store. */
function LivePill({ showLastOrder = false }) {
  const { isConfigured, isConnected } = useRealtime();
  const lastOrderEvent = useLastOrderEvent();

  if (!isConfigured) return null;

  return (
    <span className="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-0.5 font-display text-[10.5px] text-[#6f6b68] ring-1 ring-[#f0e9df]">
      <span
        className={`h-1.5 w-1.5 rounded-full ${isConnected ? "animate-pulse bg-[#2f9e44]" : "bg-[#d9d3cb]"}`}
        aria-hidden="true"
      />
      {isConnected ? "Live" : "Reconnecting…"}
      {showLastOrder && lastOrderEvent?.order && (
        <span data-testid="last-order-event" className="hidden text-[#a39f9b] sm:inline">
          · order #{lastOrderEvent.order.id} {String(lastOrderEvent.order.status ?? "").replaceAll("_", " ")}
        </span>
      )}
    </span>
  );
}

export default LivePill;
