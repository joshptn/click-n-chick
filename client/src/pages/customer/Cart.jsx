import { IconShoppingCart } from "@tabler/icons-react";

import AppHeader from "../../components/app/AppHeader";
import CartPanel from "../../components/cart/CartPanel";
import PhoneHeader from "../../components/phone/PhoneHeader";
import TabScreen from "../../components/phone/TabScreen";
import { useCartState } from "../../context/useCart";
import { usePhoneLayout } from "../../hooks/usePhoneLayout";
import { useStartCheckout } from "../../hooks/useStartCheckout";

/**
 * My Cart, the phone's Cart tab (the app's design, 2026-11-26): today's cart
 * only, as in the app; the advance cart stays on the Advance Order pages.
 * The page fills the screen, so the list scrolls between the title and the
 * checkout button, which stays in reach above the tab bar.
 *
 * Opened on a wider screen it is the same cart under the website's header.
 */
function Cart() {
  const phone = usePhoneLayout();
  const startCheckout = useStartCheckout();
  const { cart, item_count: itemCount } = useCartState();
  const lineCount = cart.length;

  if (!phone) {
    return (
      <div className="min-h-dvh bg-[#fdfaf6] font-display text-ink">
        <AppHeader />

        <main className="mx-auto w-full max-w-[560px] px-4 py-6">
          <CartPanel className="max-h-[calc(100dvh-120px)]" onCheckout={startCheckout} />
        </main>
      </div>
    );
  }

  return (
    <TabScreen className="flex h-dvh flex-col bg-white">
      <PhoneHeader />

      <div className="flex shrink-0 items-center gap-3 px-5 py-3">
        <IconShoppingCart size={24} stroke={1.9} aria-hidden="true" className="text-[#ff8904]" />
        <div className="min-w-0 flex-1">
          <h1 className="m-0 font-display text-[18px] font-semibold text-[#111827]">My Cart</h1>
          <p className="m-0 font-display text-[12px] text-[#6b7280]">
            {itemCount} item{itemCount === 1 ? "" : "s"}
          </p>
        </div>
        {lineCount > 0 && (
          <span className="grid h-6 min-w-6 place-items-center rounded-full bg-[#ff8904] px-1.5 font-display text-[12px] font-bold text-white">
            {lineCount}
          </span>
        )}
      </div>

      <CartPanel bare className="flex-1 border-t border-[#f0e9df]" onCheckout={startCheckout} />
    </TabScreen>
  );
}

export default Cart;
