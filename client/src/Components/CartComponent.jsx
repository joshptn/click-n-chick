import React, { useContext, useState } from "react";
import { Loader, Text } from "@mantine/core";
import { CartContext } from "../Contexts/CartProvider";
import CartItemCard from "./CartItemCard";
import AppButton from "./AppButton";
import AuthContext from "../Contexts/AuthContext";
import { useNavigate } from "react-router-dom";

export default function CartComponent() {
  const {
    cart,
    total,
    loading,
    placingOrder,
    handleUpdate,
    handleRemove,
    placeOrder,
    fetchCart,
  } = useContext(CartContext);

  const [selectedItems, setSelectedItems] = useState([]);
  const url = import.meta.env.VITE_API_URL;
  const { token, user } = useContext(AuthContext);
  const nav = useNavigate();

  const toggleSelect = (foodId) => {
    setSelectedItems((prev) =>
      prev.includes(foodId)
        ? prev.filter((id) => id !== foodId)
        : [...prev, foodId]
    );
  };

  const handleRemoveToCart = async (foodID) => {
    try {
      const res = await fetch(`${url}/api/cart/remove/${foodID}`, {
        method: "DELETE",
        credentials: "include",
        headers: {
          Authorization: `Bearer ${token}`,
        },
      });

      await res.json();
      handleRemove(foodID);
    } catch (err) {
      console.error(err);
      alert("Error removing item.");
    }
  };

  const removeSelected = async () => {
    for (const foodId of selectedItems) {
      await handleRemoveToCart(foodId);
    }
    setSelectedItems([]);
    fetchCart();
  };

  // 🟠 If user is not logged in
  if (!user || !token) {
    return (
      <div className="flex flex-col items-center justify-center h-screen">
        <Text size="xl" fw={700} c="dimmed">
          You are not logged in.
        </Text>
        <AppButton
          useCase="menu"
          size="lg"
          className="mt-4"
          onClick={() => nav("/login")}
        >
          Go to Login
        </AppButton>
      </div>
    );
  }

  return (
  <div className="flex flex-col h-screen font-sans bg-gray-50">
    {loading ? (
      <div className="flex items-center justify-center flex-1">
        <Loader />
      </div>
    ) : (
      <>
        {/* ================= HEADER ================= */}
        <div className="px-6 pt-6">
          <h1 className="text-3xl font-extrabold leading-tight text-orange-600 hoc_font">
            CART <br /> SUMMARY
          </h1>
        </div>

        {/* ================= CART ITEMS ================= */}
        <div className="flex-1 px-6 mt-6 overflow-y-auto">
          {cart.length > 0 ? (
            <div className="pb-6 space-y-4">
              {cart.map((item) => (
                <div
                  key={item.food_id}
                  className={`relative transition-all ${
                    selectedItems.includes(item.food_id)
                      ? "ring-2 ring-orange-500 rounded-xl"
                      : ""
                  }`}
                  onClick={() => toggleSelect(item.food_id)}
                >
                  <CartItemCard
                    item={item}
                    url={url}
                    onUpdate={handleUpdate}
                    onRemove={handleRemove}
                    onToggleSelect={toggleSelect}
                    selectedItems={selectedItems}
                  />
                </div>
              ))}
            </div>
          ) : (
            <div className="flex items-center justify-center h-full text-gray-500">
              No items in cart
            </div>
          )}
        </div>

        {/* ================= BOTTOM SUMMARY ================= */}
        {cart.length > 0 && (
          <div className="sticky bottom-0 bg-white shadow-md">
            <div className="px-6 py-4">
              
              {/* Subtotal */}
              <div className="flex justify-between mb-4 text-base font-bold">
                <span>SUB TOTAL</span>
                <span>₱{total}</span>
              </div>

              {/* Action Button */}
              {selectedItems.length > 0 ? (
                <AppButton
                  useCase="remove"
                  size="lg"
                  onClick={removeSelected}
                  className="w-full"
                >
                  Remove Selected ({selectedItems.length})
                </AppButton>
              ) : (
                <AppButton
                  useCase="menu"
                  size="lg"
                  onClick={() => nav("/checkout")}
                  className="w-full"
                >
                  {placingOrder ? "Placing Order..." : "Place Order"}
                </AppButton>
              )}
            </div>
          </div>
        )}
      </>
    )}
  </div>
);
}
