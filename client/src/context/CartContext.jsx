import { createContext, useCallback, useContext, useMemo } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import AuthContext from "./AuthContext";
import { api } from "../lib/api";
import toast from "../components/app/Toast";

const CartContext = createContext(null);

const CART_QUERY_KEY = ["cart"];

const EMPTY = {
  cart: [],
  item_count: 0,
  line_count: 0,
  subtotal: 0,
  total: 0,
  has_unavailable_items: false,
};

/**
 * The cart, with the server as the source of truth.
 *
 * Every mutation returns the whole recalculated cart and that response is
 * written straight into the query cache, so prices and stock always come from
 * the server rather than being re-derived here. Totals shown to a customer are
 * never computed on the client.
 */
export function CartProvider({ children }) {
  const { token } = useContext(AuthContext);
  const queryClient = useQueryClient();

  const { data, isLoading, isError } = useQuery({
    queryKey: CART_QUERY_KEY,
    queryFn: () => api.get("/api/cart"),
    // No token means no cart to fetch. Signing out clears it below.
    enabled: Boolean(token),
    staleTime: 30 * 1000,
  });

  const write = useCallback(
    (payload) => {
      // Mutations answer with the full cart; adopt it rather than refetching.
      if (payload?.cart) {
        queryClient.setQueryData(CART_QUERY_KEY, {
          cart: payload.cart,
          item_count: payload.item_count,
          line_count: payload.line_count,
          subtotal: payload.subtotal,
          total: payload.total,
          has_unavailable_items: payload.has_unavailable_items,
        });
      }
    },
    [queryClient]
  );

  const onError = useCallback((error) => {
    toast.error(error.message, "Could not update your order");
  }, []);

  const addItem = useMutation({
    mutationFn: ({ foodId, quantity = 1, addonIds = [] }) =>
      api.post("/api/cart/items", { food_id: foodId, quantity, addon_ids: addonIds }),
    onSuccess: (payload) => {
      write(payload);
      toast.success(payload.message, "Added to your order");
    },
    onError,
  });

  const setQuantity = useMutation({
    mutationFn: ({ cartItemId, quantity }) =>
      api.patch(`/api/cart/items/${cartItemId}`, { quantity }),
    onSuccess: write,
    onError,
  });

  const removeItem = useMutation({
    mutationFn: ({ cartItemId }) => api.delete(`/api/cart/items/${cartItemId}`),
    onSuccess: write,
    onError,
  });

  const clearCart = useMutation({
    mutationFn: () => api.delete("/api/cart"),
    onSuccess: write,
    onError,
  });

  const cart = data ?? EMPTY;

  const value = useMemo(
    () => ({
      ...cart,
      isLoading: Boolean(token) && isLoading,
      isError,
      isSignedIn: Boolean(token),

      addItem: (input) => addItem.mutateAsync(input),
      setQuantity: (input) => setQuantity.mutateAsync(input),
      removeItem: (input) => removeItem.mutateAsync(input),
      clearCart: () => clearCart.mutateAsync(),

      isAdding: addItem.isPending,
      // Which line is mid-update, so only that row's controls disable.
      pendingLineId:
        setQuantity.variables?.cartItemId ?? removeItem.variables?.cartItemId ?? null,
      isMutating: setQuantity.isPending || removeItem.isPending || clearCart.isPending,
    }),
    [cart, token, isLoading, isError, addItem, setQuantity, removeItem, clearCart]
  );

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export default CartContext;
