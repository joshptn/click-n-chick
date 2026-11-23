import { useCallback, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import toast from "../components/app/Toast";
import {
  ADDONS_KEY,
  archiveAddon,
  deleteAddon,
  fetchAddons,
  setAddonAvailability,
  setAddonSize,
  setAvailabilityOfAddons,
} from "../lib/addons";

/** The list with one add-on replaced by the server's answer. */
function withAddon(data, addon) {
  if (!data?.data || !addon) return data;

  return { ...data, data: data.data.map((item) => (item.id === addon.id ? addon : item)) };
}

/**
 * The add-on list both staff pages read, and the actions taken from it.
 *
 * The Store Agent's switches move on screen at once and roll back if refused;
 * the Store Manager's archive and delete wait for the server. Every change is
 * also broadcast on `menu`, which refetches this key on every open page.
 */
export function useAddons() {
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState(() => new Set());

  const mark = useCallback((key, on) => {
    setBusy((previous) => {
      const next = new Set(previous);

      if (on) next.add(key);
      else next.delete(key);

      return next;
    });
  }, []);

  const query = useQuery({
    queryKey: ADDONS_KEY,
    queryFn: ({ signal }) => fetchAddons({ signal }),
    staleTime: 15 * 1000,
    refetchOnWindowFocus: true,
  });

  // The menu and open carts follow an add-on change too.
  const settle = () => {
    queryClient.invalidateQueries({ queryKey: ADDONS_KEY });
    queryClient.invalidateQueries({ queryKey: ["foods"] });
  };

  const optimistic = async (id, change) => {
    await queryClient.cancelQueries({ queryKey: ADDONS_KEY });

    const previous = queryClient.getQueryData(ADDONS_KEY);
    const addon = previous?.data?.find((item) => item.id === id);

    if (addon) queryClient.setQueryData(ADDONS_KEY, withAddon(previous, change(addon)));

    return { previous };
  };

  const rollback = (context) => {
    if (context?.previous) queryClient.setQueryData(ADDONS_KEY, context.previous);
  };

  const answer = (response) => {
    if (response?.data) queryClient.setQueryData(ADDONS_KEY, (current) => withAddon(current, response.data));
  };

  const toggle = useMutation({
    mutationFn: ({ addon, on }) => setAddonAvailability(addon.id, on),
    onMutate: ({ addon, on }) => {
      mark(`addon-${addon.id}`, true);

      return optimistic(addon.id, (item) => ({ ...item, is_available: on }));
    },
    onSuccess: (response) => {
      answer(response);
      toast.success(response?.message, "Saved");
    },
    onError: (error, _vars, context) => {
      rollback(context);
      toast.error(error.message, "Could not change the add-on");
    },
    onSettled: (_data, _error, { addon }) => {
      mark(`addon-${addon.id}`, false);
      settle();
    },
  });

  const toggleSize = useMutation({
    mutationFn: ({ addon, size, on }) => setAddonSize(addon.id, size, on),
    onMutate: ({ addon, size, on }) => {
      mark(`size-${addon.id}-${size}`, true);

      return optimistic(addon.id, (item) => ({
        ...item,
        sizes: item.sizes.map((row) => (row.size === size ? { ...row, is_available: on } : row)),
      }));
    },
    onSuccess: (response) => {
      answer(response);
      toast.success(response?.message, "Saved");
    },
    onError: (error, _vars, context) => {
      rollback(context);
      toast.error(error.message, "Could not change the size");
    },
    onSettled: (_data, _error, { addon, size }) => {
      mark(`size-${addon.id}-${size}`, false);
      settle();
    },
  });

  // The ticked add-ons together. Awaited by the confirmation, which shows a refusal.
  const switchMany = useMutation({
    mutationFn: ({ addons, on }) => setAvailabilityOfAddons(addons.map((addon) => addon.id), on),
    onMutate: async ({ addons, on }) => {
      await queryClient.cancelQueries({ queryKey: ADDONS_KEY });

      const previous = queryClient.getQueryData(ADDONS_KEY);
      const ids = new Set(addons.map((addon) => addon.id));

      if (previous?.data) {
        queryClient.setQueryData(ADDONS_KEY, {
          ...previous,
          data: previous.data.map((item) => (ids.has(item.id) ? { ...item, is_available: on } : item)),
        });
      }

      return { previous };
    },
    onSuccess: (response) => {
      (response?.data ?? []).forEach((addon) => queryClient.setQueryData(ADDONS_KEY, (current) => withAddon(current, addon)));
      toast.success(response?.message, "Saved");
    },
    onError: (_error, _vars, context) => rollback(context),
    onSettled: settle,
  });

  const archive = useMutation({
    mutationFn: ({ addon, archived }) => archiveAddon(addon.id, archived),
    onMutate: ({ addon }) => mark(`addon-${addon.id}`, true),
    onSuccess: (response, { archived }) => {
      answer(response);
      toast.success(response?.message, archived ? "Archived" : "Back on the menu");
    },
    onError: (error) => toast.error(error.message, "Could not change the add-on"),
    onSettled: (_data, _error, { addon }) => {
      mark(`addon-${addon.id}`, false);
      settle();
    },
  });

  const remove = useMutation({
    mutationFn: (addon) => deleteAddon(addon.id),
    onSuccess: (response) => toast.success(response?.message, "Add-on deleted"),
    onSettled: settle,
  });

  return {
    addons: query.data?.data ?? [],
    meta: query.data?.meta ?? null,
    isLoading: query.isPending,
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
    isBusy: (key) => busy.has(key),

    toggle: (addon, on) => toggle.mutate({ addon, on }),
    toggleSize: (addon, size, on) => toggleSize.mutate({ addon, size, on }),
    switchMany: (addons, on) => switchMany.mutateAsync({ addons, on }),
    archive: (addon, archived) => archive.mutate({ addon, archived }),
    remove,
    settle,
  };
}
