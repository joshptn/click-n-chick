import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import toast from "../components/app/Toast";
import {
  CATALOG_KEY,
  CATEGORIES_KEY,
  archiveFood,
  createCategory,
  deleteCategory,
  deleteFood,
  fetchCatalog,
  renameCategory,
} from "../lib/catalog";
import { fetchCategories } from "../lib/menu";

/**
 * The catalogue list, its categories, and the actions taken from the list.
 *
 * Nothing is optimistic: archiving changes what customers can order, and the
 * server's answer is what the row should show. The menu broadcast refreshes
 * the list for every other open page.
 */
export function useCatalog() {
  const queryClient = useQueryClient();
  const [busyId, setBusyId] = useState(null);

  const list = useQuery({
    queryKey: CATALOG_KEY,
    queryFn: ({ signal }) => fetchCatalog({ signal }),
    staleTime: 15 * 1000,
  });

  const categories = useQuery({
    queryKey: CATEGORIES_KEY,
    queryFn: fetchCategories,
    staleTime: 60 * 1000,
  });

  const refreshMenu = () => queryClient.invalidateQueries({ queryKey: ["foods"] });

  const refreshCategories = () =>
    Promise.all([queryClient.invalidateQueries({ queryKey: CATEGORIES_KEY }), refreshMenu()]);

  const archive = useMutation({
    mutationFn: ({ food, archived }) => archiveFood(food.id, archived),
    onMutate: ({ food }) => setBusyId(food.id),
    onSuccess: (response, { archived }) =>
      toast.success(response?.message, archived ? "Archived" : "Back on the menu"),
    onError: (error) => toast.error(error.message, "Could not change the dish"),
    onSettled: () => {
      setBusyId(null);
      refreshMenu();
    },
  });

  const remove = useMutation({
    mutationFn: (food) => deleteFood(food.id),
    onSuccess: (response) => toast.success(response?.message, "Dish deleted"),
    onSettled: refreshMenu,
  });

  const addCategory = useMutation({
    mutationFn: (name) => createCategory(name),
    onSuccess: (category) => toast.success(`${category?.name} was added.`, "Category added"),
    onSettled: refreshCategories,
  });

  const rename = useMutation({
    mutationFn: ({ id, name }) => renameCategory(id, name),
    onSuccess: (category) => toast.success(`Renamed to ${category?.name}.`, "Category renamed"),
    onSettled: refreshCategories,
  });

  const dropCategory = useMutation({
    mutationFn: (category) => deleteCategory(category.id),
    onSuccess: (response) => toast.success(response?.message, "Category deleted"),
    // CATEGORY_NOT_EMPTY: a dish was added to it since the list was read.
    onError: (error) => toast.error(error.message, "Could not delete the category"),
    onSettled: refreshCategories,
  });

  return {
    foods: list.data?.data ?? [],
    categories: (categories.data ?? []).filter((category) => category.name !== "Addons"),
    isLoading: list.isPending,
    isError: list.isError,
    error: list.error,
    refetch: list.refetch,
    isBusy: (id) => busyId === id,

    archive: (food, archived) => archive.mutate({ food, archived }),
    remove,
    addCategory,
    rename,
    dropCategory,
  };
}
