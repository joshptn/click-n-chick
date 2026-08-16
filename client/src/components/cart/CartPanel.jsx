import { IconAlertTriangle, IconMinus, IconPhotoOff, IconPlus, IconShoppingCart, IconTrash } from "@tabler/icons-react";
import { AnimatePresence, motion } from "framer-motion";

import { formatPeso } from "../../lib/menu";
import { useCart } from "../../context/useCart";

const MotionLi = motion.li;

/**
 * "My Orders" - the cart body.
 *
 * One component for both presentations: docked in the home page grid on wide
 * screens, and inside CartModal everywhere else. Splitting them would mean
 * maintaining the quantity controls twice.
 */
function CartPanel({ onCheckout, className = "" }) {
  const {
    cart,
    item_count: itemCount,
    subtotal,
    total,
    has_unavailable_items: hasUnavailable,
    isLoading,
    setQuantity,
    removeItem,
    clearCart,
    pendingLineId,
    isMutating,
  } = useCart();

  const isEmpty = !isLoading && cart.length === 0;

  return (
    <section
      aria-label="My orders"
      className={`flex min-h-0 flex-col overflow-hidden rounded-[16px] border border-[#f0e9df] bg-white ${className}`}
    >
      <header className="flex shrink-0 items-center justify-between gap-3 border-b border-[#f0e9df] px-5 py-4">
        <h2 className="m-0 font-display text-[16px] font-extrabold text-ink">My Orders</h2>

        <span className="rounded-full bg-brand-500 px-3 py-1 font-display text-[11px] font-bold text-white">
          {itemCount} item{itemCount === 1 ? "" : "s"}
        </span>
      </header>

      {cart.length > 0 && (
        <div className="flex shrink-0 items-center justify-between border-b border-[#f5f0e9] bg-[#faf7f3] px-5 py-2.5">
          <span className="font-display text-[12.5px] font-semibold text-[#6f6b68]">
            {cart.length} line{cart.length === 1 ? "" : "s"}
          </span>

          <button
            type="button"
            onClick={() => clearCart()}
            disabled={isMutating}
            className="bg-transparent font-display text-[12.5px] font-bold text-[#e5322d] transition-opacity hover:underline disabled:cursor-not-allowed disabled:opacity-50"
          >
            Clear all
          </button>
        </div>
      )}

      {hasUnavailable && (
        <p className="m-0 flex shrink-0 items-start gap-2 border-b border-[#ffe6a8] bg-[#fff9e8] px-5 py-3 font-display text-[12.5px] leading-snug text-[#8a6206]">
          <IconAlertTriangle size={15} stroke={2.2} aria-hidden="true" className="mt-px shrink-0" />
          Something in your order sold out. Remove it before checking out.
        </p>
      )}

      <div className="min-h-0 flex-1 overflow-y-auto">
        {isLoading && (
          <ul className="m-0 list-none space-y-3 p-5">
            {Array.from({ length: 2 }).map((_, i) => (
              <li key={i} className="h-[62px] animate-pulse rounded-[12px] bg-[#f4f1ec]" />
            ))}
          </ul>
        )}

        {isEmpty && (
          <div className="grid h-full min-h-[260px] place-items-center px-6 py-10 text-center">
            <div>
              <IconShoppingCart size={40} stroke={1.4} aria-hidden="true" className="mx-auto text-[#d9d3cb]" />
              <p className="mt-3 font-display text-[14px] font-semibold text-[#8d8884]">Your cart is empty</p>
              <p className="m-0 font-display text-[12.5px] text-[#b3aca4]">Add items to get started</p>
            </div>
          </div>
        )}

        <ul className="m-0 list-none space-y-1 p-3">
          <AnimatePresence initial={false}>
            {cart.map((line) => {
              const busy = pendingLineId === line.id;

              return (
                <MotionLi
                  key={line.id}
                  layout
                  initial={{ opacity: 0, height: 0 }}
                  animate={{ opacity: 1, height: "auto" }}
                  exit={{ opacity: 0, height: 0 }}
                  transition={{ duration: 0.2 }}
                  className={`flex items-start gap-3 rounded-[12px] px-2 py-2.5 transition-colors hover:bg-[#faf7f3] ${busy ? "opacity-60" : ""}`}
                >
                  <span className="h-11 w-11 shrink-0 overflow-hidden rounded-[9px] bg-[#f4f1ec]">
                    {line.thumbnail ? (
                      <img
                        src={line.thumbnail}
                        alt=""
                        className={`h-full w-full object-cover ${line.is_orderable ? "" : "grayscale"}`}
                      />
                    ) : (
                      <span className="grid h-full w-full place-items-center text-[#c9c2b8]">
                        <IconPhotoOff size={15} stroke={1.8} aria-hidden="true" />
                      </span>
                    )}
                  </span>

                  <div className="min-w-0 flex-1">
                    <p className="m-0 truncate font-display text-[13px] font-semibold text-ink">
                      {line.food_name}
                    </p>

                    {line.addons.length > 0 && (
                      <p className="m-0 truncate font-display text-[11px] text-[#8d8884]">
                        {line.addons.map((addon) => addon.addon_name).join(", ")}
                      </p>
                    )}

                    <p className="m-0 font-display text-[13px] font-bold text-brand-600">
                      {formatPeso(line.subtotal)}
                    </p>

                    {!line.is_orderable && (
                      <p className="m-0 font-display text-[11px] font-semibold text-[#e5322d]">Sold out</p>
                    )}
                  </div>

                  <div className="flex shrink-0 items-center gap-1.5">
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() =>
                        line.quantity <= 1
                          ? removeItem({ cartItemId: line.id })
                          : setQuantity({ cartItemId: line.id, quantity: line.quantity - 1 })
                      }
                      aria-label={line.quantity <= 1 ? `Remove ${line.food_name}` : `Decrease ${line.food_name}`}
                      className="grid h-[26px] w-[26px] place-items-center rounded-full bg-[#fdf0e4] text-brand-700 transition-colors hover:bg-[#fbe2cd] disabled:cursor-not-allowed disabled:opacity-50"
                    >
                      {line.quantity <= 1 ? <IconTrash size={12} stroke={2.2} /> : <IconMinus size={12} stroke={3} />}
                    </button>

                    <span className="min-w-[22px] text-center font-display text-[13px] font-bold text-ink">
                      {line.quantity}
                    </span>

                    <button
                      type="button"
                      disabled={busy || !line.is_orderable || (line.stock_quantity !== null && line.quantity >= line.stock_quantity)}
                      onClick={() => setQuantity({ cartItemId: line.id, quantity: line.quantity + 1 })}
                      aria-label={`Increase ${line.food_name}`}
                      className="grid h-[26px] w-[26px] place-items-center rounded-full bg-brand-500 text-white transition-colors hover:bg-brand-600 disabled:cursor-not-allowed disabled:bg-brand-200"
                    >
                      <IconPlus size={12} stroke={3} />
                    </button>
                  </div>
                </MotionLi>
              );
            })}
          </AnimatePresence>
        </ul>
      </div>

      <footer className="shrink-0 border-t border-[#f0e9df] px-5 pb-5 pt-4">
        <div className="flex items-center justify-between font-display text-[13px] text-[#6f6b68]">
          <span>Subtotal</span>
          <span className="font-semibold text-ink">{formatPeso(subtotal)}</span>
        </div>

        <div className="mt-1.5 flex items-center justify-between">
          <span className="font-display text-[15px] font-extrabold text-ink">Total</span>
          <span className="font-display text-[19px] font-extrabold leading-none text-brand-600">
            {formatPeso(total)}
          </span>
        </div>

        <button
          type="button"
          onClick={onCheckout}
          disabled={cart.length === 0 || hasUnavailable || isMutating}
          className="mt-4 inline-flex h-[50px] w-full items-center justify-center gap-2 rounded-[12px] bg-brand-500 font-display text-[15px] font-bold text-white transition-all duration-150 hover:bg-brand-600 active:translate-y-px disabled:cursor-not-allowed disabled:bg-brand-200"
        >
          <IconShoppingCart size={18} stroke={2} aria-hidden="true" />
          Checkout
        </button>

        <p className="m-0 mt-2.5 text-center font-display text-[10.5px] leading-snug text-[#a39f9b]">
          🔒 Secure checkout &middot; Orders can&rsquo;t be cancelled once confirmed
        </p>
      </footer>
    </section>
  );
}

export default CartPanel;
