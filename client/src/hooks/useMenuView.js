import { useSearchParams } from "react-router-dom";

export const MENU_VIEWS = [
  { key: "dishes", label: "Dishes" },
  { key: "addons", label: "Add-ons" },
];

/**
 * Which list a menu page shows, Dishes or Add-ons, kept in the address
 * (`?view=addons`) so a reload or the back button stays on it.
 */
export function useMenuView() {
  const [params, setParams] = useSearchParams();
  const view = params.get("view") === "addons" ? "addons" : "dishes";

  const setView = (next) =>
    setParams(
      (current) => {
        const copy = new URLSearchParams(current);

        if (next === "addons") copy.set("view", "addons");
        else copy.delete("view");

        return copy;
      },
      { replace: true }
    );

  return [view, setView];
}
