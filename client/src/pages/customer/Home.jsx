import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { IconMoodEmpty, IconSearchOff } from "@tabler/icons-react";

import AppHeader from "../../components/app/AppHeader";
import CartModal from "../../components/cart/CartModal";
import CartPanel from "../../components/cart/CartPanel";
import FoodCard from "../../components/menu/FoodCard";
import FoodDetailModal from "../../components/menu/FoodDetailModal";
import MenuCategoryBar from "../../components/menu/MenuCategoryBar";
import MenuSection from "../../components/menu/MenuSection";
import GuestOrderBanner from "../../components/orders/GuestOrderBanner";
import PosterBanner from "../../components/menu/PosterBanner";
import toast from "../../components/app/Toast";
import { fetchFoods } from "../../lib/menu";
import { dishCount, menuSections } from "../../lib/menuSections";
import { useCartActions } from "../../context/useCart";
import { useMenuScrollSpy } from "../../hooks/useMenuScrollSpy";
import { useStoreStatus } from "../../lib/store";

/** Long enough that typing does not fire a request per keystroke. */
const SEARCH_DEBOUNCE_MS = 300;

/** Cards whose photos load first: the top row, the part of the menu seen first. */
const FIRST_ROW = 3;

/**
 * Where a section counts as being read: under the sticky header (68px) and
 * category bar (48px), with a little room.
 */
const READING_LINE = 140;

/**
 * The customer's home menu (redesigned 2026-11-26, after onthewayph.com/menu):
 * posters, then every dish in sections, BES Seller first and then each
 * category in the order it was added, under a category bar that sticks below
 * the header and follows the section being read. A search shows one list of
 * results instead.
 */
function Home() {
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [selectedFood, setSelectedFood] = useState(null);
  const [cartOpen, setCartOpen] = useState(false);

  const navigate = useNavigate();
  const { addItem, isAdding } = useCartActions();
  const { status: storeStatus } = useStoreStatus();

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), SEARCH_DEBOUNCE_MS);

    return () => window.clearTimeout(timer);
  }, [search]);

  const { data, isLoading, isError, refetch } = useQuery({
    // The search is in the key, so clearing it serves the whole menu from cache.
    queryKey: ["foods", "menu", debouncedSearch],
    queryFn: () => fetchFoods({ search: debouncedSearch }),
    staleTime: 60 * 1000,
  });

  const foods = useMemo(() => data?.data ?? [], [data]);

  // The open dish follows the listing, so a live stock or price change reaches
  // the modal without it asking the server again.
  const openFood = selectedFood ? (foods.find((food) => food.id === selectedFood.id) ?? selectedFood) : null;

  // Sections while browsing; a search is one list across the whole menu.
  const isBrowsing = debouncedSearch === "";
  const sections = useMemo(() => (isBrowsing ? menuSections(foods) : []), [foods, isBrowsing]);
  const sectionKeys = useMemo(() => sections.map((section) => section.key), [sections]);
  const { active, pick } = useMenuScrollSpy(sectionKeys, READING_LINE);

  const handleQuickAdd = async (food) => {
    // Anything with add-ons opens the modal instead: adding it blind would
    // silently choose "no add-ons" on the customer's behalf.
    if ((food.addon_groups?.length ?? 0) > 0) {
      setSelectedFood(food);
      return;
    }

    await addItem({ foodId: food.id, quantity: 1, addonIds: [] }).catch(() => {});
  };

  const handleAddFromModal = async (input) => {
    await addItem(input)
      .then(() => setSelectedFood(null))
      .catch(() => {});
  };

  // The ticked lines travel with the navigation (BR-25, partial checkout) so
  // checkout prices exactly what the customer selected, not the whole cart.
  const handleCheckout = (ids) => {
    if (!storeStatus?.accepting_orders) {
      toast.info(
        storeStatus?.blockers?.pickup?.message ?? "The store is not taking orders right now.",
        "Store closed"
      );

      return;
    }

    navigate("/checkout", { state: { selectedIds: ids ?? null } });
  };

  return (
    <div className="min-h-dvh bg-[#fdfaf6] font-display text-ink">
      <AppHeader
        search={search}
        onSearchChange={setSearch}
        onOpenCart={() => setCartOpen(true)}
      />

      <main className="mx-auto w-full max-w-[1440px] px-4 py-5 sm:px-6 lg:px-8">
        <div className="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">

          <div className="min-w-0">
            <GuestOrderBanner />

            <PosterBanner />

            {isBrowsing && sections.length > 0 && !isError && (
              <MenuCategoryBar sections={sections} active={active} onPick={pick} />
            )}

            {isError && (
              <div className="mt-8 rounded-[14px] border border-[#ffd7d5] bg-[#fff1f1] px-5 py-6 text-center">
                <p className="m-0 font-display text-[14px] font-semibold text-[#e5322d]">
                  We couldn&rsquo;t load the menu.
                </p>
                <button
                  type="button"
                  onClick={() => refetch()}
                  className="mt-3 rounded-[10px] bg-brand-500 px-5 py-2 font-display text-[13px] font-semibold text-white hover:bg-brand-600"
                >
                  Try again
                </button>
              </div>
            )}

            {isLoading && (
              <>
                <div className="mt-5 h-12 animate-pulse rounded-[4px] bg-[#f0e9df]" />
                <div className="mt-7 grid grid-cols-2 gap-4 sm:grid-cols-3">
                  {Array.from({ length: 6 }).map((_, i) => (
                    <div key={i} className="h-[290px] animate-pulse rounded-[16px] bg-[#f0e9df]" />
                  ))}
                </div>
              </>
            )}

            {!isLoading && !isError && foods.length === 0 && (
              <div className="mt-10 grid place-items-center px-6 py-12 text-center">
                {debouncedSearch ? (
                  <>
                    <IconSearchOff size={42} stroke={1.4} aria-hidden="true" className="text-[#d9d3cb]" />
                    <p className="mt-3 font-display text-[15px] font-semibold text-[#6f6b68]">
                      Nothing matches &ldquo;{debouncedSearch}&rdquo;
                    </p>
                    <p className="m-0 font-display text-[13px] text-[#a39f9b]">
                      Try a different word, or clear the search to see the whole menu.
                    </p>
                  </>
                ) : (
                  <>
                    <IconMoodEmpty size={42} stroke={1.4} aria-hidden="true" className="text-[#d9d3cb]" />
                    <p className="mt-3 font-display text-[15px] font-semibold text-[#6f6b68]">
                      Nothing on the menu yet
                    </p>
                    <p className="m-0 font-display text-[13px] text-[#a39f9b]">
                      Dishes added by the store appear here.
                    </p>
                  </>
                )}
              </div>
            )}

            {!isLoading && !isError && isBrowsing && sections.length > 0 && (
              <div data-testid="menu-sections">
                {sections.map((section, index) => (
                  <MenuSection
                    key={section.key}
                    section={section}
                    first={index === 0}
                    isAdding={isAdding}
                    onSelect={setSelectedFood}
                    onQuickAdd={handleQuickAdd}
                  />
                ))}
              </div>
            )}

            {!isLoading && !isError && !isBrowsing && foods.length > 0 && (
              <section className="mt-7" data-testid="menu-results">
                <div className="mb-3.5 flex items-baseline justify-between gap-3">
                  <h2 className="m-0 font-display text-[18px] font-extrabold text-cocoa-900">
                    Results for &ldquo;{debouncedSearch}&rdquo;
                  </h2>
                  <span className="shrink-0 font-display text-[11.5px] text-[#a39f9b]">{dishCount(foods.length)}</span>
                </div>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                  {foods.map((food, index) => (
                    <FoodCard
                      key={food.id}
                      food={food}
                      index={index}
                      priority={index < FIRST_ROW}
                      isAdding={isAdding}
                      onSelect={setSelectedFood}
                      onQuickAdd={handleQuickAdd}
                    />
                  ))}
                </div>
              </section>
            )}
          </div>

          {/* Docked only where there is room for it. Below xl the same panel
              is reached through the header's cart button as a modal. */}
          <aside className="sticky top-[84px] hidden max-h-[calc(100dvh-104px)] xl:block">
            <CartPanel className="max-h-[calc(100dvh-104px)]" onCheckout={handleCheckout} />
          </aside>
        </div>
      </main>

      <FoodDetailModal
        food={openFood}
        opened={Boolean(selectedFood)}
        onClose={() => setSelectedFood(null)}
        onAdd={handleAddFromModal}
        isAdding={isAdding}
      />

      <CartModal opened={cartOpen} onClose={() => setCartOpen(false)} onCheckout={handleCheckout} />
    </div>
  );
}

export default Home;
