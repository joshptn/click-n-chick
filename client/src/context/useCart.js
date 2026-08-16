import { useContext } from "react";

import CartContext from "./CartContext";

/**
 * Read the cart.
 *
 * Lives apart from CartContext.jsx so that file exports only a component -
 * mixing a hook in breaks fast refresh for every consumer of the provider.
 */
export function useCart() {
  const context = useContext(CartContext);

  if (!context) {
    throw new Error("useCart must be used inside a CartProvider.");
  }

  return context;
}

export default useCart;
