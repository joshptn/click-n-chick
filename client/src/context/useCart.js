import { useContext } from "react";

import CartContext from "./CartContext";
import CartActionsContext from "./CartActionsContext";
import { CART_MODE } from "../lib/cartModes";

function pick(context, mode, name) {
  if (!context) throw new Error(`${name} must be used inside a CartProvider.`);

  return context[mode] ?? context[CART_MODE.IMMEDIATE];
}

/** The cart's contents, selection and line actions; re-renders when they change. */
export function useCartState(mode = CART_MODE.IMMEDIATE) {
  return pick(useContext(CartContext), mode, "useCartState");
}

/** Adding to the cart only; does not re-render when the cart's contents change. */
export function useCartActions(mode = CART_MODE.IMMEDIATE) {
  return pick(useContext(CartActionsContext), mode, "useCartActions");
}
