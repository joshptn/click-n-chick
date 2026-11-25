import { useEffect, useRef, useState } from "react";
import { IconCheck, IconMinus, IconPlus, IconTrash, IconX } from "@tabler/icons-react";

/** The most of one line an advance order takes: four digits, as the API allows (Cart::ADVANCE_MAX_QUANTITY). */
const ADVANCE_MAX = 9999;

const round = "grid h-[26px] w-[26px] shrink-0 place-items-center rounded-full transition-colors disabled:cursor-not-allowed";

/**
 * A cart line's quantity: − count +, the minus becoming a bin at 1. On the
 * advance cart (`typable`) the count can also be typed, as on the Store
 * Agent's stock control (decided 2026-11-26): tap it, type 1 to 9999, then
 * Enter or ✓ to save, Esc or ✕ to cancel.
 */
function CartQuantity({ line, busy, typable = false, onSet, onRemove }) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState("");
  const inputRef = useRef(null);

  useEffect(() => {
    if (editing) inputRef.current?.select();
  }, [editing]);

  const max = typable ? ADVANCE_MAX : line.stock_quantity;
  const atMax = max !== null && max !== undefined && line.quantity >= max;

  const startEdit = () => {
    setDraft(String(line.quantity));
    setEditing(true);
  };

  const save = () => {
    setEditing(false);

    const wanted = Number.parseInt(draft, 10);

    // Nothing typed, or 0: left as it was (the bin removes a line).
    if (!Number.isFinite(wanted) || wanted < 1) return;

    const quantity = Math.min(wanted, ADVANCE_MAX);

    if (quantity !== line.quantity) onSet(quantity);
  };

  if (editing) {
    return (
      <div className="flex shrink-0 flex-col items-center gap-1">
        <div className="flex items-center gap-1.5">
          <button
            type="button"
            onClick={() => setEditing(false)}
            aria-label="Cancel"
            data-testid="cart-quantity-cancel"
            className={`${round} bg-[#f4f1ec] text-[#6f6b68] hover:bg-[#ece7e0]`}
          >
            <IconX size={13} stroke={2.4} />
          </button>
          <input
            ref={inputRef}
            type="text"
            inputMode="numeric"
            value={draft}
            onChange={(event) => setDraft(event.target.value.replace(/[^\d]/g, "").slice(0, 4))}
            onKeyDown={(event) => {
              if (event.key === "Enter") save();
              if (event.key === "Escape") setEditing(false);
            }}
            aria-label={`How many ${line.food_name}`}
            data-testid="cart-quantity-input"
            className="h-[28px] w-[58px] rounded-[8px] border border-brand-300 bg-white px-1 text-center font-display text-[13px] font-bold text-ink outline-none focus:border-brand-500"
          />
          <button
            type="button"
            onClick={save}
            aria-label="Save quantity"
            data-testid="cart-quantity-save"
            className={`${round} bg-brand-500 text-white hover:bg-brand-600`}
          >
            <IconCheck size={14} stroke={2.6} />
          </button>
        </div>
        <span className="whitespace-nowrap font-display text-[9.5px] text-[#a39f9b]">Enter to save · Esc to cancel</span>
      </div>
    );
  }

  return (
    <div className="flex shrink-0 items-center gap-1.5">
      <button
        type="button"
        disabled={busy}
        onClick={() => (line.quantity <= 1 ? onRemove() : onSet(line.quantity - 1))}
        aria-label={line.quantity <= 1 ? `Remove ${line.food_name}` : `Decrease ${line.food_name}`}
        // The pale fill lost its edge against white and read smaller than the
        // orange +; the inset ring draws the same 26px circle.
        className={`${round} bg-[#fdf0e4] text-brand-700 ring-1 ring-inset ring-[#f6d9bf] hover:bg-[#fbe2cd] disabled:opacity-50`}
      >
        {/* The trash glyph reads small beside + and -, so it is drawn a size up. */}
        {line.quantity <= 1 ? <IconTrash size={15} stroke={2.2} /> : <IconMinus size={13} stroke={3} />}
      </button>

      {typable ? (
        <button
          type="button"
          disabled={busy}
          onClick={startEdit}
          aria-label={`${line.quantity} ${line.food_name}. Type a quantity`}
          title="Type a quantity"
          data-testid="cart-quantity"
          className="h-[26px] min-w-[34px] rounded-[7px] border border-[#f3e3d3] bg-[#fffaf5] px-1.5 font-display text-ink transition-colors hover:border-brand-300 disabled:cursor-not-allowed"
        >
          {/* On the span: Mantine resets type on <button> itself. */}
          <span className="text-[13px] font-bold">{line.quantity}</span>
        </button>
      ) : (
        <span className="min-w-[22px] text-center font-display text-[13px] font-bold text-ink">{line.quantity}</span>
      )}

      <button
        type="button"
        disabled={busy || atMax}
        onClick={() => onSet(line.quantity + 1)}
        aria-label={`Increase ${line.food_name}`}
        className={`${round} bg-brand-500 text-white hover:bg-brand-600 disabled:bg-brand-200`}
      >
        <IconPlus size={13} stroke={3} />
      </button>
    </div>
  );
}

export default CartQuantity;
