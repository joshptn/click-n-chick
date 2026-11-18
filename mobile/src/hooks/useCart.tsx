import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { EMPTY_CART, cartApi, type Cart, type CartMode, type CartPayload, type CartScope } from "../api/cart";
import { forgetGuestToken, guestToken, rememberGuestToken } from "../lib/guestCart";
import { toast } from "../lib/toast";
import { useSession } from "./useSession";

/**
 * The carts, for an account or a guest, as the web's CartProvider
 * (client/src/context/CartContext.jsx):
 * - every write answers with the whole cart, which replaces the cached copy;
 * - a guest's first add mints the X-Guest-Token key, kept on the phone;
 * - on sign-in the guest's cart is merged into the account's, and the key is
 *   forgotten once the API has answered;
 * - lines can be unticked (partial checkout, BR-25); ticked is the default.
 *
 * Two carts run side by side: today's ("immediate") and, for a signed-in
 * customer, the advance-order cart (phase 9). `useCart()` is today's;
 * `useCart("advance")` the advance one.
 */

type AddInput = { foodId: number; quantity: number; addonIds: number[] };

type CartContextValue = Cart & {
  mode: CartMode;
  scope: CartScope;
  isLoading: boolean;
  isError: boolean;
  isRefetching: boolean;
  refetch: () => void;

  addItem: (input: AddInput) => Promise<CartPayload>;
  /** The dish being added, so only its button says "Adding...". */
  addingFoodId: number | null;
  setQuantity: (lineId: number, quantity: number) => void;
  removeItem: (lineId: number) => void;
  removeSelected: () => void;
  /** The line a change is waiting on, shown dimmed. */
  pendingLineId: number | null;
  isMutating: boolean;

  isSelected: (lineId: number) => boolean;
  toggleSelected: (lineId: number) => void;
  selectAll: () => void;
  deselectAll: () => void;
  selectedIds: number[];
  selectedCount: number;
  selectedItemCount: number;
  selectedSubtotal: number;
  hasUnavailableSelected: boolean;
  allSelected: boolean;
};

const CartContext = createContext<{ immediate: CartContextValue; advance: CartContextValue } | null>(null);

/** Today's cart keeps the phase 6 key, so `["cart"]` still covers every cart. */
export function cartKey(scope: CartScope, mode: CartMode = "immediate") {
  return mode === "advance" ? (["cart", scope, "advance"] as const) : (["cart", scope] as const);
}

function errorText(error: unknown) {
  return error instanceof Error ? error.message : "Please try again.";
}

function useCartStore(mode: CartMode, signedIn: boolean, hasGuestCart: boolean, onGuestToken: (token: string) => void): CartContextValue {
  const queryClient = useQueryClient();
  const advance = mode === "advance";
  const scope: CartScope = signedIn ? "account" : "guest";
  const queryKey = useMemo(() => cartKey(scope, mode), [scope, mode]);

  const query = useQuery({
    queryKey,
    queryFn: ({ signal }) => cartApi.get(scope, mode, signal),
    // A guest has a cart only once they have added something, and never an
    // advance one (the API asks for an account).
    enabled: advance ? signedIn : signedIn || hasGuestCart,
    staleTime: 30 * 1000,
  });

  const write = useCallback(
    (payload: CartPayload, writtenScope: CartScope) => {
      if (writtenScope === "guest" && payload.guest_token) onGuestToken(payload.guest_token);

      queryClient.setQueryData<Cart>(cartKey(writtenScope, mode), {
        cart: payload.cart,
        item_count: payload.item_count,
        line_count: payload.line_count,
        subtotal: payload.subtotal,
        total: payload.total,
        has_unavailable_items: payload.has_unavailable_items,
      });
    },
    [queryClient, mode, onGuestToken]
  );

  // A failed change may mean the cart moved on elsewhere (a line removed on
  // the website, stock sold): show what the API now holds.
  const onError = useCallback(
    (error: unknown) => {
      toast.error("Could not update your order", errorText(error));
      void queryClient.invalidateQueries({ queryKey: ["cart"] });
    },
    [queryClient]
  );

  const add = useMutation({
    mutationFn: ({ input, scope: target }: { input: AddInput; scope: CartScope }) => cartApi.add(target, mode, input),
    onSuccess: (payload, { scope: writtenScope }) => {
      write(payload, writtenScope);
      toast.success(advance ? "Added to your advance order" : "Added to your order", payload.message);
    },
    onError,
  });

  const change = useMutation({
    mutationFn: ({ lineId, quantity, scope: target }: { lineId: number; quantity: number; scope: CartScope }) =>
      cartApi.setQuantity(target, lineId, quantity),
    onSuccess: (payload, { scope: writtenScope }) => write(payload, writtenScope),
    onError,
  });

  const remove = useMutation({
    mutationFn: ({ lineId, scope: target }: { lineId: number; scope: CartScope }) => cartApi.remove(target, lineId),
    onSuccess: (payload, { scope: writtenScope }) => write(payload, writtenScope),
    onError,
  });

  const removeMany = useMutation({
    mutationFn: ({ ids, scope: target }: { ids: number[]; scope: CartScope }) => cartApi.removeMany(target, mode, ids),
    onSuccess: (payload, { scope: writtenScope }) => write(payload, writtenScope),
    onError,
  });

  // A signed-out phone has no advance cart, whatever is cached.
  const cart = (advance && !signedIn ? null : query.data) ?? EMPTY_CART;
  const lines = cart.cart;

  // Unticked lines, for the cart they were unticked in: another cart (signed
  // in or out) starts fully ticked.
  const [unticked, setUnticked] = useState<{ scope: CartScope; ids: Set<number> }>(() => ({ scope, ids: new Set() }));
  const deselected = useMemo(() => (unticked.scope === scope ? unticked.ids : new Set<number>()), [unticked, scope]);

  const setDeselected = useCallback(
    (update: (prev: Set<number>) => Set<number>) => setUnticked((prev) => ({ scope, ids: update(prev.scope === scope ? prev.ids : new Set()) })),
    [scope]
  );

  const selection = useMemo(() => {
    const selected = lines.filter((line) => !deselected.has(line.id));

    return {
      selectedIds: selected.map((line) => line.id),
      selectedCount: selected.length,
      selectedItemCount: selected.reduce((sum, line) => sum + line.quantity, 0),
      selectedSubtotal: selected.reduce((sum, line) => sum + Number(line.subtotal ?? 0), 0),
      // Stock does not apply ahead of time: the store checks on acceptance.
      hasUnavailableSelected: !advance && selected.some((line) => !line.is_orderable),
      allSelected: lines.length > 0 && selected.length === lines.length,
    };
  }, [lines, deselected, advance]);

  const toggleSelected = useCallback(
    (id: number) =>
      setDeselected((prev) => {
        const next = new Set(prev);
        if (next.has(id)) next.delete(id);
        else next.add(id);
        return next;
      }),
    [setDeselected]
  );

  const selectAll = useCallback(() => setDeselected(() => new Set()), [setDeselected]);
  const deselectAll = useCallback(
    () => setDeselected(() => new Set(lines.map((line) => line.id))),
    [setDeselected, lines]
  );

  const addAsync = add.mutateAsync;
  const changeAsync = change.mutate;
  const removeAsync = remove.mutate;
  const removeManyAsync = removeMany.mutate;
  const refetchCart = query.refetch;

  const pendingLineId =
    (change.isPending ? change.variables?.lineId : null) ?? (remove.isPending ? remove.variables?.lineId : null) ?? null;

  return useMemo<CartContextValue>(
    () => ({
      ...cart,
      mode,
      scope,
      isLoading: (advance ? signedIn : signedIn || hasGuestCart) && query.isLoading,
      isError: query.isError,
      isRefetching: query.isRefetching,
      refetch: () => void refetchCart(),

      addItem: (input) => addAsync({ input, scope }),
      addingFoodId: add.isPending ? (add.variables?.input.foodId ?? null) : null,
      setQuantity: (lineId, quantity) => changeAsync({ lineId, quantity, scope }),
      removeItem: (lineId) => removeAsync({ lineId, scope }),
      removeSelected: () => removeManyAsync({ ids: selection.selectedIds, scope }),
      pendingLineId,
      isMutating: change.isPending || remove.isPending || removeMany.isPending,

      ...selection,
      isSelected: (id) => !deselected.has(id),
      toggleSelected,
      selectAll,
      deselectAll,
    }),
    [
      cart,
      mode,
      scope,
      advance,
      signedIn,
      hasGuestCart,
      query.isLoading,
      query.isError,
      query.isRefetching,
      refetchCart,
      addAsync,
      add.isPending,
      add.variables,
      changeAsync,
      removeAsync,
      removeManyAsync,
      pendingLineId,
      change.isPending,
      remove.isPending,
      removeMany.isPending,
      selection,
      deselected,
      toggleSelected,
      selectAll,
      deselectAll,
    ]
  );
}

export function CartProvider({ children }: { children: ReactNode }) {
  const { signedIn } = useSession();
  const queryClient = useQueryClient();

  const [hasGuestCart, setHasGuestCart] = useState(() => guestToken() !== null);

  const onGuestToken = useCallback((token: string) => {
    void rememberGuestToken(token);
    setHasGuestCart(true);
  }, []);

  const immediate = useCartStore("immediate", signedIn, hasGuestCart, onGuestToken);
  const advance = useCartStore("advance", signedIn, hasGuestCart, onGuestToken);

  /**
   * Bring a pre-sign-in cart into the account. Keyed on the session existing,
   * so sign-in, sign-up, a reset and two-step sign-in all end the same way,
   * and an app restart while signed in retries a merge that failed. The key is
   * forgotten only once the API has answered; the API deletes the guest cart
   * as it merges, so a repeat counts nothing.
   */
  const merging = useRef(false);

  useEffect(() => {
    if (!signedIn || !guestToken() || merging.current) return;

    merging.current = true;

    cartApi
      .merge()
      .then(async (payload) => {
        await forgetGuestToken();
        setHasGuestCart(false);
        queryClient.removeQueries({ queryKey: cartKey("guest") });
        await queryClient.invalidateQueries({ queryKey: cartKey("account") });

        if ((payload.merged ?? 0) > 0) toast.info("Your cart came with you", payload.message);
      })
      // Kept on failure: the next start while signed in tries again.
      .catch(() => {})
      .finally(() => {
        merging.current = false;
      });
  }, [signedIn, queryClient]);

  const value = useMemo(() => ({ immediate, advance }), [immediate, advance]);

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart(mode: CartMode = "immediate"): CartContextValue {
  const value = useContext(CartContext);

  if (!value) throw new Error("useCart must be used inside a CartProvider.");

  return value[mode];
}
