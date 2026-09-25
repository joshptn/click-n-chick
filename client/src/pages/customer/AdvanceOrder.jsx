import { useContext, useEffect, useMemo, useState } from "react";
import { Navigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { IconMoodEmpty, IconSearchOff } from "@tabler/icons-react";

import AdvanceNotice from "../../components/advance/AdvanceNotice";
import AppHeader from "../../components/app/AppHeader";
import AuthContext from "../../context/AuthContext";
import CartModal from "../../components/cart/CartModal";
import CartPanel from "../../components/cart/CartPanel";
import CategoryTabs from "../../components/menu/CategoryTabs";
import FoodCard from "../../components/menu/FoodCard";
import FoodDetailModal from "../../components/menu/FoodDetailModal";
import toast from "../../components/app/Toast";
import { ALL_CATEGORY, fetchFoods } from "../../lib/menu";
import { CART_MODE } from "../../lib/cartModes";
import { ROLES } from "../../lib/roles";
import { useCart } from "../../context/useCart";

const SEARCH_DEBOUNCE_MS = 300;

function AdvanceOrder() {
  const { user } = useContext(AuthContext);

  const [category, setCategory] = useState(ALL_CATEGORY);
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [selectedFood, setSelectedFood] = useState(null);
  const [cartOpen, setCartOpen] = useState(false);

  const { addItem, isAdding } = useCart(CART_MODE.ADVANCE);

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), SEARCH_DEBOUNCE_MS);

    return () => window.clearTimeout(timer);
  }, [search]);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ["foods", category, debouncedSearch],
    queryFn: () => fetchFoods({ category, search: debouncedSearch }),
    staleTime: 60 * 1000,
  });

  const foods = useMemo(() => data?.data ?? [], [data]);

  const isBrowsing = debouncedSearch === "";

  const handleQuickAdd = async (food) => {
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

  const handleContinue = () => {
    toast.info("Picking a collection date is the next piece of work.", "Almost there");
  };

  if (user && user.role !== ROLES.CUSTOMER) {
    return <Navigate to="/home" replace />;
  }

  return (
    <div className="min-h-dvh bg-[#fdfaf6] font-display text-ink">
      <AppHeader
        search={search}
        onSearchChange={setSearch}
        searchPlaceholder="Search the menu to schedule..."
        onOpenCart={() => setCartOpen(true)}
        cartMode={CART_MODE.ADVANCE}
      />

      <main className="mx-auto w-full max-w-[1440px] px-4 py-5 sm:px-6 lg:px-8">
        <div className="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
          <div className="min-w-0">
            <AdvanceNotice />

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

            {!isLoading && !isError && foods.length > 0 && (
              <section className="mt-7">
                <h2 className="m-0 mb-3.5 font-display text-[17px] font-extrabold text-ink">
                  {isBrowsing ? "What would you like us to prepare?" : `Results for “${debouncedSearch}”`}
                </h2>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                  {foods.map((food, index) => (
                    <FoodCard
                      key={food.id}
                      food={food}
                      index={index}
                      isAdding={isAdding}
                      isAdvance
                      onSelect={setSelectedFood}
                      onQuickAdd={handleQuickAdd}
                    />
                  ))}
                </div>
              </section>
            )}
          </div>

          <aside className="sticky top-[84px] hidden max-h-[calc(100dvh-104px)] xl:block">
            <CartPanel
              className="max-h-[calc(100dvh-104px)]"
              mode={CART_MODE.ADVANCE}
              onCheckout={handleContinue}
            />
          </aside>
        </div>
      </main>

      <FoodDetailModal
        food={selectedFood}
        opened={Boolean(selectedFood)}
        onClose={() => setSelectedFood(null)}
        onAdd={handleAddFromModal}
        isAdding={isAdding}
        isAdvance
      />

      <CartModal
        opened={cartOpen}
        onClose={() => setCartOpen(false)}
        onCheckout={handleContinue}
        mode={CART_MODE.ADVANCE}
      />
    </div>
  );
}

export default AdvanceOrder;
