import { useEffect, useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { IconMoodEmpty, IconSearchOff } from "@tabler/icons-react";

import AppHeader from "../../components/app/AppHeader";
import CartModal from "../../components/cart/CartModal";
import CartPanel from "../../components/cart/CartPanel";
import CategoryTabs from "../../components/menu/CategoryTabs";
import FoodCard from "../../components/menu/FoodCard";
import FoodDetailModal from "../../components/menu/FoodDetailModal";
import PosterBanner from "../../components/menu/PosterBanner";
import toast from "../../components/app/Toast";
import { ALL_CATEGORY, fetchFoods } from "../../lib/menu";
import { useCart } from "../../context/useCart";

/** Long enough that typing does not fire a request per keystroke. */
const SEARCH_DEBOUNCE_MS = 300;

function Home() {
  const [category, setCategory] = useState(ALL_CATEGORY);
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [selectedFood, setSelectedFood] = useState(null);
  const [cartOpen, setCartOpen] = useState(false);

  const { addItem, isAdding } = useCart();

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), SEARCH_DEBOUNCE_MS);

    return () => window.clearTimeout(timer);
  }, [search]);

  const { data, isLoading, isError, refetch } = useQuery({
    // Both filters are in the key, so switching back to a previous
    // category/search pair serves from cache instead of refetching.
    queryKey: ["foods", category, debouncedSearch],
    queryFn: () => fetchFoods({ category, search: debouncedSearch }),
    staleTime: 60 * 1000,
  });

  const foods = useMemo(() => data?.data ?? [], [data]);

  // A search is a search across the whole menu, so the "Popular Dishes" split
  // only makes sense while browsing.
  const isBrowsing = debouncedSearch === "";
  const popular = useMemo(() => (isBrowsing ? foods.filter((food) => food.is_best_seller) : []), [foods, isBrowsing]);
  const rest = useMemo(
    () => (isBrowsing ? foods.filter((food) => !food.is_best_seller) : foods),
    [foods, isBrowsing]
  );

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

  const handleCheckout = () => {
    toast.info("Checkout is the next module. Your order is saved.", "Coming soon");
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
            <PosterBanner />

            <h2 className="mb-3 mt-6 font-display text-[11.5px] font-bold uppercase tracking-[0.14em] text-[#a39f9b]">
              Category
            </h2>

            <CategoryTabs value={category} onChange={setCategory} />

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
              <div className="mt-7 grid grid-cols-2 gap-4 sm:grid-cols-3">
                {Array.from({ length: 6 }).map((_, i) => (
                  <div key={i} className="h-[290px] animate-pulse rounded-[16px] bg-[#f0e9df]" />
                ))}
              </div>
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
                      Try a different word, or browse the categories above.
                    </p>
                  </>
                ) : (
                  <>
                    <IconMoodEmpty size={42} stroke={1.4} aria-hidden="true" className="text-[#d9d3cb]" />
                    <p className="mt-3 font-display text-[15px] font-semibold text-[#6f6b68]">
                      Nothing on the menu here yet
                    </p>
                    <p className="m-0 font-display text-[13px] text-[#a39f9b]">
                      Pick another category to keep looking.
                    </p>
                  </>
                )}
              </div>
            )}

            {!isLoading && !isError && popular.length > 0 && (
              <section className="mt-7">
                <h2 className="m-0 mb-3.5 font-display text-[17px] font-extrabold text-ink">Popular Dishes</h2>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                  {popular.map((food, index) => (
                    <FoodCard
                      key={food.id}
                      food={food}
                      index={index}
                      isAdding={isAdding}
                      onSelect={setSelectedFood}
                      onQuickAdd={handleQuickAdd}
                    />
                  ))}
                </div>
              </section>
            )}

            {!isLoading && !isError && rest.length > 0 && (
              <section className="mt-8">
                <h2 className="m-0 mb-3.5 font-display text-[17px] font-extrabold text-ink">
                  {isBrowsing ? "More on the Menu" : `Results for “${debouncedSearch}”`}
                </h2>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                  {rest.map((food, index) => (
                    <FoodCard
                      key={food.id}
                      food={food}
                      index={index}
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
        food={selectedFood}
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
