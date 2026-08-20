import { createContext, useCallback, useContext, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import AuthContext from "./AuthContext";
import { api, apiFetch } from "../lib/api";
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

export function CartProvider({ children }) {
  const { token } = useContext(AuthContext);
  const queryClient = useQueryClient();

  const { data, isLoading, isError } = useQuery({
    queryKey: CART_QUERY_KEY,
    queryFn: () => api.get("/api/cart"),
    enabled: Boolean(token),
    staleTime: 30 * 1000,
  });

  const write = useCallback(
    (payload) => {
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

  const removeSelected = useMutation({
    mutationFn: (ids) => apiFetch("/api/cart/items", { method: "DELETE", body: { ids } }),
    onSuccess: write,
    onError,
  });

  const cart = data ?? EMPTY;
  const lines = cart.cart;


  const [deselectedIds, setDeselectedIds] = useState(() => new Set());

  const selection = useMemo(() => {
    const selectedLines = lines.filter((line) => !deselectedIds.has(line.id));

    return {
      selectedIds: selectedLines.map((line) => line.id),
      selectedCount: selectedLines.length,
      selectedItemCount: selectedLines.reduce((sum, line) => sum + line.quantity, 0),
      selectedSubtotal: selectedLines.reduce((sum, line) => sum + Number(line.subtotal ?? 0), 0),
      hasUnavailableSelected: selectedLines.some((line) => !line.is_orderable),
      allSelected: lines.length > 0 && selectedLines.length === lines.length,
    };
  }, [lines, deselectedIds]);

  const toggleSelected = useCallback((id) => {
    setDeselectedIds((prev) => {
      const next = new Set(prev);

      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }

      return next;
    });
  }, []);

  const selectAll = useCallback(() => setDeselectedIds(new Set()), []);

  const deselectAll = useCallback(
    () => setDeselectedIds(new Set(lines.map((line) => line.id))),
    [lines]
  );

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

      ...selection,
      isSelected: (id) => !deselectedIds.has(id),
      toggleSelected,
      selectAll,
      deselectAll,
      removeSelected: () => removeSelected.mutateAsync(selection.selectedIds),

      isAdding: addItem.isPending,
      pendingLineId:
        (setQuantity.isPending ? setQuantity.variables?.cartItemId : null) ??
        (removeItem.isPending ? removeItem.variables?.cartItemId : null) ??
        null,
      isMutating:
        setQuantity.isPending ||
        removeItem.isPending ||
        removeSelected.isPending ||
        clearCart.isPending,
    }),
    [
      cart,
      token,
      isLoading,
      isError,
      addItem,
      setQuantity,
      removeItem,
      removeSelected,
      clearCart,
      selection,
      deselectedIds,
      toggleSelected,
      selectAll,
      deselectAll,
    ]
  );

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export default CartContext;
