import React, { useContext, useEffect, useState } from "react";
import FoodCard from "./FoodCard";
import AuthContext from "../Contexts/AuthContext";
import { FoodContext } from "../Contexts/FoodProvider";
import { CartContext } from "../Contexts/CartProvider";
import { SegmentedControl } from "@mantine/core";

export default function FoodList() {
  const { 
    setFoods, 
    foods, 
    categories, 
    searchQuery,
    filteredFoods: searchFilteredFoods 
  } = useContext(FoodContext);

  const { fetchCart } = useContext(CartContext);
  const { token } = useContext(AuthContext);
  const url = import.meta.env.VITE_API_URL;

  const [displayFoods, setDisplayFoods] = useState([]);
  const [activeCategory, setActiveCategory] = useState("all");
  
  const data = [
    { label: "All", value: "all" },
    ...categories.map((c) => ({
      label: c.name,
      value: c.id.toString(),
    })),
  ];

  // Fetch foods
  const fetchFoods = async () => {
    try {
      const res = await fetch(`${url}/api/foods`, {
        credentials: "include",
        headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      });
      const data = await res.json();
      setFoods(data);
    } catch (err) {
      console.error("Failed to fetch foods:", err);
    }
  };

  useEffect(() => {
    fetchFoods();
  }, []);

  const onDelete = (id) => setFoods((prev) => prev.filter((f) => f.id !== id));

  const handleUpdate = (updatedFood) => {
    if (!updatedFood?.id) return;
    setFoods((prev) => prev.map((f) => (f.id === updatedFood.id ? updatedFood : f)));
  };

  const handleDelete = async (food) => {
    try {
      await fetch(`${url}/api/foods/${food.id}`, {
        method: "DELETE",
        headers: { Authorization: `Bearer ${token}` },
      });
      onDelete(food.id);
    } catch (err) {
      console.error(err);
      alert("Failed to delete food.");
    }
  };

  const handleAddToCart = async (food, quantity, close, sides, drinks) => {
    try {
      const res = await fetch(`${url}/api/cart/add/${food.id}`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ quantity, sides, drinks }),
      });
      if (res.ok) {
        fetchCart();
        close();
      }
    } catch (err) {
      console.error(err);
      alert("Failed to add to cart.");
    }
  };

  // Combined filter: search + category
  const applyFilters = () => {
    let result = searchFilteredFoods;

    if (activeCategory !== "all") {
      result = result.filter((f) =>
        f.categories?.some((c) => c.id.toString() === activeCategory)
      );
    }

    return result;
  };

  useEffect(() => {
    setDisplayFoods(applyFilters());
  }, [searchFilteredFoods, activeCategory]);

  const groupByCategory = (foodsList) => {
      const groups = {};

      foodsList.forEach((food) => {
        if (food.categories?.length) {
          food.categories.forEach((cat) => {
            if (!groups[cat.id]) {
              groups[cat.id] = { category: cat, foods: [] };
            }
            groups[cat.id].foods.push(food);
          });
        } else {
         
          if (!groups["no-category"]) {
            groups["no-category"] = { category: { id: "no-category", name: "Uncategorized" }, foods: [] };
          }
          groups["no-category"].foods.push(food);
        }
      });

      const sortedGroups = Object.values(groups).sort((a, b) => {
        if (a.category.name === "Addons") return 1;
        if (b.category.name === "Addons") return -1;
        return a.category.name.localeCompare(b.category.name);
      });

      return sortedGroups;
    };

    
  return (
    <div className="w-full flex flex-col gap-2 px-4 md:px-8">
      {searchQuery && (
        <div className="mb-4 text-sm text-gray-600 px-4 py-2 rounded-lg bg-gray-100">
          Searching for: <span className="font-semibold">"{searchQuery}"</span>
          <span className="ml-2 text-gray-500">
            ({displayFoods.length} result{displayFoods.length !== 1 ? "s" : ""})
          </span>
        </div>
      )}

      <SegmentedControl
        value={activeCategory}
        onChange={setActiveCategory}
        fullWidth
        radius="xl"
        color="orange"
        data={data}
        transitionDuration={200}
      />

      <div className="flex flex-col gap-8 mt-10">
        {displayFoods.length > 0 ? (
          activeCategory === "all" ? (
            groupByCategory(displayFoods).map((group) => (
              <div key={group.category.id} className="flex flex-col gap-4">
                <h2 className="text-xl font-bold text-orange-600 border-b border-orange-200 pb-2 mb-4">
                  {group.category.name}
                </h2>
                <div className="flex flex-wrap gap-6 justify-center md:justify-start">
                  {group.foods.map((food) => (
                    <div
                      key={food.id}
                      className="transition-transform hover:-translate-y-1 hover:shadow-lg"
                    >
                      <FoodCard
                        food={food}
                        url={url}
                        onDelete={handleDelete}
                        onUpdate={handleUpdate}
                        handleAddToCart={handleAddToCart}
                      />
                    </div>
                  ))}
                </div>
              </div>
            ))
          ) : (
            <div className="flex flex-wrap gap-6 justify-center md:justify-start">
              {displayFoods.map((food) => (
                <FoodCard
                  key={food.id}
                  food={food}
                  url={url}
                  onDelete={handleDelete}
                  onUpdate={handleUpdate}
                  handleAddToCart={handleAddToCart}
                />
              ))}
            </div>
          )
        ) : (
          <div className="text-center mt-6">
            <p className="text-gray-500">
              {searchQuery
                ? `No foods found matching "${searchQuery}"${
                    activeCategory !== "all" ? " in this category" : ""
                  }`
                : "No foods found for this category."}
            </p>
          </div>
        )}
      </div>
    </div>
  );
}
