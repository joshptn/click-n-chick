import { useContext } from "react";

import CartContext from "./CartContext";
import { CART_MODE } from "../lib/cartModes";

export function useCart(mode = CART_MODE.IMMEDIATE) {
  const context = useContext(CartContext);

  if (!context) {
    throw new Error("useCart must be used inside a CartProvider.");
  }

  return context[mode] ?? context[CART_MODE.IMMEDIATE];
}

export default useCart;
