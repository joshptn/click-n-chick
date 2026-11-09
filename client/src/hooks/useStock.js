import { useCallback, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import toast from "../components/app/Toast";
import { STOCK_KEY, adjustStock, adjustStockOf, fetchStock, markSoldOutOf, setAvailability, setAvailabilityOf, setStock } from "../lib/stock";

const STEP_KEY = ["stock-step"];

/** The list with one dish replaced. */
function withItem(data, item) {
  if (!data || !item) return data;

  return { ...data, data: data.data.map((food) => (food.id === item.id ? { ...food, ...item } : food)) };
}

/** A dish's stock facts after a local change, ahead of the server's answer. */
function stepped(food, by, threshold) {
  const count = Math.max(0, (food.stock_quantity ?? 0) + by);
  const left = Math.max(0, count - (food.held_quantity ?? 0));

  return {
    ...food,
    stock_quantity: count,
    available_quantity: left,
    stock_status: left <= 0 ? "out_of_stock" : left <= threshold ? "low_stock" : "in_stock",
    is_orderable: food.is_available && left > 0,
  };
}

/**
 * The stock page's data and the three things an agent does to it.
 *
 * Steps (+/-) and the availability switch move on screen at once and roll back
 * if refused; the server's answer then replaces the row. Every change also
 * arrives over the `menu` channel, which refetches this key for every open page.
 */
export function useStock() {
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState(() => new Set());

  const mark = useCallback((id, on) => {
    setBusy((previous) => {
      const next = new Set(previous);

      if (on) next.add(id);
      else next.delete(id);

      return next;
    });
  }, []);

  const query = useQuery({
    queryKey: STOCK_KEY,
    queryFn: ({ signal }) => fetchStock({ signal }),
    refetchInterval: 60 * 1000,
    refetchOnWindowFocus: true,
    staleTime: 5 * 1000,
  });

  const threshold = query.data?.meta?.low_stock_threshold ?? 5;

  const optimistic = useCallback(
    async (id, change) => {
      await queryClient.cancelQueries({ queryKey: STOCK_KEY });

      const previous = queryClient.getQueryData(STOCK_KEY);
      const food = previous?.data?.find((item) => item.id === id);

      if (food) queryClient.setQueryData(STOCK_KEY, withItem(previous, change(food)));

      return { previous };
    },
    [queryClient]
  );

  const rollback = (context) => {
    if (context?.previous) queryClient.setQueryData(STOCK_KEY, context.previous);
  };

  const settle = () => queryClient.invalidateQueries({ queryKey: ["foods"] });

  // Several quick presses are several requests. Only the last one to finish
  // writes its answer back, so the figure does not flicker through each reply.
  const lastStep = () => queryClient.isMutating({ mutationKey: STEP_KEY }) <= 1;

  const step = useMutation({
    mutationKey: STEP_KEY,
    mutationFn: ({ food, by }) => adjustStock(food.id, by),
    onMutate: ({ food, by }) => optimistic(food.id, (current) => stepped(current, by, threshold)),
    onSuccess: (response) => {
      if (lastStep()) queryClient.setQueryData(STOCK_KEY, (current) => withItem(current, response?.item));
    },
    onError: (error, _vars, context) => {
      rollback(context);
      toast.error(error.message, "Could not change the stock");
    },
    onSettled: () => {
      if (lastStep()) settle();
    },
  });

  const recount = useMutation({
    mutationFn: ({ food, count }) => setStock(food.id, count, food.stock_quantity),
    onMutate: ({ food }) => mark(food.id, true),
    onSuccess: (response) => {
      queryClient.setQueryData(STOCK_KEY, (current) => withItem(current, response?.item));
      toast.success(response?.message ?? "Stock updated.", "Stock updated");
    },
    onError: (error) => {
      // Someone sold or recounted it meanwhile: show the real figure.
      if (error?.payload?.error_code === "STOCK_CHANGED") {
        queryClient.setQueryData(STOCK_KEY, (current) => withItem(current, error.payload.item));
        toast.warning(error.message, "The count changed");

        return;
      }

      toast.error(error.message, "Could not save the count");
    },
    onSettled: (_response, _error, { food }) => {
      mark(food.id, false);
      settle();
    },
  });

  const toggle = useMutation({
    mutationFn: ({ food, isAvailable }) => setAvailability(food.id, isAvailable),
    onMutate: ({ food, isAvailable }) =>
      optimistic(food.id, (current) => ({
        ...current,
        is_available: isAvailable,
        is_orderable: isAvailable && (current.available_quantity == null || current.available_quantity > 0),
      })),
    onSuccess: (response) => {
      queryClient.setQueryData(STOCK_KEY, (current) => withItem(current, response?.item));
      toast.success(response?.message ?? "Availability updated.", response?.item?.is_available ? "Back on the menu" : "Archived");
    },
    onError: (error, _vars, context) => {
      rollback(context);
      toast.error(error.message, "Could not change availability");
    },
    onSettled: settle,
  });

  const applyItems = (response) =>
    queryClient.setQueryData(STOCK_KEY, (current) => (response?.items ?? []).reduce((data, item) => withItem(data, item), current));

  // The steps for ticked dishes share the single steps' key, so quick presses
  // of either settle together.
  const stepMany = useMutation({
    mutationKey: STEP_KEY,
    mutationFn: ({ foods, by }) => adjustStockOf(foods.map((food) => food.id), by),
    onMutate: async ({ foods, by }) => {
      await queryClient.cancelQueries({ queryKey: STOCK_KEY });

      const previous = queryClient.getQueryData(STOCK_KEY);
      const ids = new Set(foods.map((food) => food.id));

      if (previous) {
        queryClient.setQueryData(STOCK_KEY, {
          ...previous,
          data: previous.data.map((food) => (ids.has(food.id) && food.is_tracked ? stepped(food, by, threshold) : food)),
        });
      }

      return { previous };
    },
    onSuccess: (response) => {
      if (lastStep()) applyItems(response);
    },
    onError: (error, _vars, context) => {
      rollback(context);
      toast.error(error.message, "Could not change the stock");
    },
    onSettled: () => {
      if (lastStep()) settle();
    },
  });

  // Not optimistic, and no error toast: the confirm dialog waits for the answer
  // and shows the reason itself.
  const switchMany = useMutation({
    mutationFn: ({ foods, isAvailable }) => setAvailabilityOf(foods.map((food) => food.id), isAvailable),
    onSuccess: (response, { isAvailable }) => {
      applyItems(response);
      toast.success(response?.message ?? "Availability updated.", isAvailable ? "Back on the menu" : "Turned off");
    },
    onSettled: settle,
  });

  const soldOutMany = useMutation({
    mutationFn: (foods) => markSoldOutOf(foods.map((food) => food.id)),
    onSuccess: (response) => {
      applyItems(response);
      toast.success(response?.message ?? "Stock updated.", "Marked sold out");
    },
    onSettled: settle,
  });

  return {
    foods: query.data?.data ?? [],
    categories: query.data?.categories ?? [],
    summary: query.data?.summary ?? null,
    meta: query.data?.meta ?? null,
    isLoading: query.isLoading,
    isError: query.isError && !query.data,
    error: query.error,
    refetch: query.refetch,
    isBusy: (id) => busy.has(id),
    step: (food, by) => step.mutate({ food, by }),
    recount: (food, count) => recount.mutateAsync({ food, count }),
    toggle: (food, isAvailable) => toggle.mutate({ food, isAvailable }),
    stepMany: (foods, by) => stepMany.mutate({ foods, by }),
    switchMany: (foods, isAvailable) => switchMany.mutateAsync({ foods, isAvailable }),
    soldOutMany: (foods) => soldOutMany.mutateAsync(foods),
  };
}

export default useStock;
