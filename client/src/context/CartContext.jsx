import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { useLocation } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import AuthContext from "./AuthContext";
import { CART_MODE } from "../lib/cartModes";
import { api, apiFetch } from "../lib/api";
import { forgetGuestToken, guestToken, rememberGuestToken } from "../lib/guest";
import toast from "../components/app/Toast";

const CartContext = createContext(null);

const EMPTY = {
  cart: [],
  item_count: 0,
  line_count: 0,
  subtotal: 0,
  total: 0,
  has_unavailable_items: false,
};

/** Scope is in the key as well as the path, so one never serves the other's cache. */
const SCOPE = { ACCOUNT: "account", GUEST: "guest" };

function useCartStore(mode, enabled, scope) {
  const queryClient = useQueryClient();

  const base = scope === SCOPE.GUEST ? "/api/guest/cart" : "/api/cart";

  const queryKey = useMemo(() => ["cart", scope, mode], [scope, mode]);

  const { data, isLoading, isError } = useQuery({
    queryKey,
    queryFn: () => api.get(base, { params: { mode } }),
    enabled,
    staleTime: 30 * 1000,
  });

  const write = useCallback(
    (payload) => {
      // A guest's first write is what mints the token; every call after replays
      // it from storage. Null on an account response, where it is ignored.
      rememberGuestToken(payload?.guest_token);

      if (payload?.cart) {
        queryClient.setQueryData(queryKey, {
          cart: payload.cart,
          item_count: payload.item_count,
          line_count: payload.line_count,
          subtotal: payload.subtotal,
          total: payload.total,
          has_unavailable_items: payload.has_unavailable_items,
        });
      }
    },
    [queryClient, queryKey]
  );

  const onError = useCallback((error) => {
    toast.error(error.message, "Could not update your order");
  }, []);

  const addItem = useMutation({
    mutationFn: ({ foodId, quantity = 1, addonIds = [] }) =>
      api.post(`${base}/items`, {
        food_id: foodId,
        quantity,
        addon_ids: addonIds,
        mode,
      }),
    onSuccess: (payload) => {
      write(payload);
      toast.success(payload.message, "Added to your order");
    },
    onError,
  });

  const setQuantity = useMutation({
    mutationFn: ({ cartItemId, quantity }) =>
      api.patch(`${base}/items/${cartItemId}`, { quantity }),
    onSuccess: write,
    onError,
  });

  const removeItem = useMutation({
    mutationFn: ({ cartItemId }) => api.delete(`${base}/items/${cartItemId}`),
    onSuccess: write,
    onError,
  });

  const clearCart = useMutation({
    mutationFn: () => apiFetch(base, { method: "DELETE", body: { mode } }),
    onSuccess: write,
    onError,
  });

  const removeSelected = useMutation({
    mutationFn: (ids) => apiFetch(`${base}/items`, { method: "DELETE", body: { ids, mode } }),
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

  return useMemo(
    () => ({
      ...cart,
      mode,
      isAdvance: mode === CART_MODE.ADVANCE,
      isLoading: enabled && isLoading,
      isError,

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
      mode,
      enabled,
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
}

export function CartProvider({ children }) {
  const { token } = useContext(AuthContext);
  const { pathname } = useLocation();
  const queryClient = useQueryClient();

  const signedIn = Boolean(token);

  const onAdvancePages = pathname.startsWith("/advance-order");

  /**
   * Bring a pre-sign-in cart into the account.
   *
   * Keyed on the session existing rather than on any one sign-in screen, because
   * login, registration and the two-factor challenge all end the same way - and a
   * reload while signed in retries a merge that failed earlier. The token is only
   * forgotten once the server has answered, so a dropped request is not lost.
   *
   * The ref stops StrictMode's doubled effect sending two requests; the server
   * deletes the guest cart as it merges, so a second one would count nothing anyway.
   */
  const merging = useRef(false);

  useEffect(() => {
    if (!signedIn || !guestToken() || merging.current) return;

    merging.current = true;

    api
      .post("/api/cart/merge")
      .then((payload) => {
        forgetGuestToken();
        queryClient.removeQueries({ queryKey: ["cart", SCOPE.GUEST] });
        queryClient.invalidateQueries({ queryKey: ["cart", SCOPE.ACCOUNT] });

        if (payload?.merged > 0) {
          toast.info(payload.message, "Your cart came with you");
        }
      })
      // Kept on failure: the next load while signed in tries again.
      .catch(() => {})
      .finally(() => {
        merging.current = false;
      });
  }, [signedIn, queryClient]);

  // Always on: a guest has a cart too, it just lives against a token.
  const immediate = useCartStore(
    CART_MODE.IMMEDIATE,
    true,
    signedIn ? SCOPE.ACCOUNT : SCOPE.GUEST
  );

  // Advance ordering is for account holders (FR-03.4), so this store has no
  // guest scope at all rather than a guest scope that would be refused.
  const advance = useCartStore(CART_MODE.ADVANCE, signedIn && onAdvancePages, SCOPE.ACCOUNT);

  const value = useMemo(
    () => ({ [CART_MODE.IMMEDIATE]: immediate, [CART_MODE.ADVANCE]: advance }),
    [immediate, advance]
  );

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export default CartContext;
