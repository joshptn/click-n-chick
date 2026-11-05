import { useEffect, useRef, useState } from "react";
import { Menu, Tooltip } from "@mantine/core";
import {
  IconCheck,
  IconChefHat,
  IconDotsVertical,
  IconListNumbers,
  IconMinus,
  IconPackageOff,
  IconPlus,
  IconX,
} from "@tabler/icons-react";

/** What the agent reads under the count: why the number matters right now. */
function StockNote({ food, threshold }) {
  if (!food.is_tracked) {
    return <span className="text-[#2563eb]">Made to order</span>;
  }

  const left = food.available_quantity ?? 0;
  const held = food.held_quantity ?? 0;

  return (
    <span className="flex flex-wrap items-center justify-center gap-x-1.5">
      {left <= 0 ? (
        <span className="font-semibold text-[#e5322d]">Sold out</span>
      ) : left <= threshold ? (
        <span className="font-semibold text-[#c98500]">Low · {left} left</span>
      ) : (
        <span className="text-[#2f9e44]">In stock</span>
      )}
      {held > 0 && <span className="text-[#8d8884]">· {held} held for payment</span>}
    </span>
  );
}

const stepButton =
  "grid h-[28px] w-[28px] shrink-0 place-items-center rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-40";

/**
 * One dish's count: step it, type a recount, mark it sold out, or switch it
 * between counted and made to order. Read-only for anyone who may not manage
 * stock (the Store Manager, BR-29).
 */
function StockControl({ food, threshold, canManage, busy, onStep, onRecount }) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState("");
  const [basis, setBasis] = useState(food);
  const inputRef = useRef(null);

  useEffect(() => {
    if (editing) inputRef.current?.select();
  }, [editing]);

  const startEdit = () => {
    // The count the agent is correcting; the server refuses the recount if it
    // has moved before they save (STOCK_CHANGED).
    setBasis(food);
    setDraft(food.stock_quantity == null ? "" : String(food.stock_quantity));
    setEditing(true);
  };

  const save = async () => {
    const count = Number.parseInt(draft, 10);

    if (!Number.isFinite(count) || count < 0) {
      setEditing(false);

      return;
    }

    setEditing(false);

    if (count === basis.stock_quantity) return;

    try {
      await onRecount(basis, Math.min(count, 9999));
    } catch {
      // The hook has already said why.
    }
  };

  const label = food.food_name;

  if (editing) {
    return (
      <div className="flex flex-col items-center gap-1">
        <div className="flex items-center gap-1 text-[12px]">
          <input
            ref={inputRef}
            type="number"
            inputMode="numeric"
            min={0}
            max={9999}
            value={draft}
            onChange={(event) => setDraft(event.target.value.replace(/[^\d]/g, "").slice(0, 4))}
            onKeyDown={(event) => {
              if (event.key === "Enter") save();
              if (event.key === "Escape") setEditing(false);
            }}
            aria-label={`New count for ${label}`}
            data-testid="stock-input"
            className="h-[30px] w-[64px] rounded-[8px] border border-brand-300 bg-white px-2 text-center font-display text-[13px] font-semibold text-ink outline-none focus:border-brand-500 [&::-webkit-inner-spin-button]:appearance-none"
          />
          <button
            type="button"
            onClick={save}
            aria-label="Save count"
            data-testid="stock-save"
            className={`${stepButton} bg-brand-500 text-white hover:bg-brand-600`}
          >
            <IconCheck size={15} stroke={2.6} />
          </button>
          <button
            type="button"
            onClick={() => setEditing(false)}
            aria-label="Cancel"
            className={`${stepButton} bg-[#f4f1ec] text-[#6f6b68] hover:bg-[#ece7e0]`}
          >
            <IconX size={14} stroke={2.4} />
          </button>
        </div>
        <span className="font-display text-[10px] text-[#a39f9b]">Enter to save · Esc to cancel</span>
      </div>
    );
  }

  const left = food.available_quantity ?? 0;

  return (
    <div className="flex flex-col items-center gap-1" data-testid="stock-control" data-food={food.id}>
      <div className="flex items-center gap-1.5 text-[12px]">
        {food.is_tracked ? (
          <>
            {canManage && (
              <button
                type="button"
                onClick={() => onStep(food, -1)}
                disabled={busy || (food.stock_quantity ?? 0) <= 0}
                aria-label={`One fewer ${label}`}
                data-testid="stock-minus"
                className={`${stepButton} border border-[#ffd6b3] bg-white text-brand-600 hover:bg-brand-50`}
              >
                <IconMinus size={14} stroke={2.6} />
              </button>
            )}
            <Tooltip label="Type a new count" disabled={!canManage} withArrow openDelay={400}>
              <button
                type="button"
                onClick={canManage ? startEdit : undefined}
                disabled={busy}
                aria-label={canManage ? `${food.stock_quantity} ${label} - type a new count` : `${food.stock_quantity} ${label}`}
                data-testid="stock-count"
                className={`h-[28px] min-w-[44px] rounded-full px-2.5 font-display font-bold tabular-nums transition-colors ${
                  left <= 0
                    ? "bg-[#fdecec] text-[#e5322d]"
                    : left <= threshold
                      ? "bg-[#fff8e1] text-[#c98500]"
                      : "bg-[#f4f1ec] text-ink"
                } ${canManage ? "cursor-text hover:ring-1 hover:ring-brand-300" : "cursor-default"}`}
              >
                <span className="text-[13px]">{food.stock_quantity}</span>
              </button>
            </Tooltip>
            {canManage && (
              <button
                type="button"
                onClick={() => onStep(food, 1)}
                disabled={busy || food.stock_quantity >= 9999}
                aria-label={`One more ${label}`}
                data-testid="stock-plus"
                className={`${stepButton} bg-brand-500 text-white shadow-[0_4px_10px_-4px_rgba(255,139,43,0.7)] hover:bg-brand-600`}
              >
                <IconPlus size={14} stroke={2.6} />
              </button>
            )}
          </>
        ) : (
          <span className="inline-flex h-[28px] items-center gap-1 rounded-full bg-[#eef4ff] px-3 font-display font-semibold text-[#2563eb]">
            <IconChefHat size={14} stroke={2} />
            <span className="text-[12px]">Made to order</span>
          </span>
        )}

        {canManage && (
          <Menu position="bottom-end" width={220} shadow="md" radius="md" withinPortal>
            <Menu.Target>
              <button
                type="button"
                disabled={busy}
                aria-label={`More stock actions for ${label}`}
                data-testid="stock-menu"
                className={`${stepButton} bg-transparent text-[#a39f9b] hover:bg-[#f4f1ec] hover:text-ink`}
              >
                <IconDotsVertical size={15} stroke={2} />
              </button>
            </Menu.Target>
            <Menu.Dropdown>
              {food.is_tracked ? (
                <>
                  <Menu.Item leftSection={<IconListNumbers size={16} stroke={1.9} />} onClick={startEdit}>
                    Type a new count
                  </Menu.Item>
                  <Menu.Item
                    leftSection={<IconPackageOff size={16} stroke={1.9} />}
                    disabled={food.stock_quantity === 0}
                    onClick={() => onRecount(food, 0).catch(() => {})}
                  >
                    Mark sold out (set to 0)
                  </Menu.Item>
                  <Menu.Item leftSection={<IconChefHat size={16} stroke={1.9} />} onClick={() => onRecount(food, null).catch(() => {})}>
                    Stop counting · made to order
                  </Menu.Item>
                </>
              ) : (
                <Menu.Item leftSection={<IconListNumbers size={16} stroke={1.9} />} onClick={startEdit}>
                  Start counting this dish
                </Menu.Item>
              )}
            </Menu.Dropdown>
          </Menu>
        )}
      </div>

      <span className="font-display text-[10.5px]">
        <StockNote food={food} threshold={threshold} />
      </span>
    </div>
  );
}

export default StockControl;
