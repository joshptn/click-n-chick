import React, { useContext, useEffect, useState } from "react";
import AuthContext from "../Contexts/AuthContext";
import { Text } from "@mantine/core";

export default function CartItemCard({
  item,
  url,
  onUpdate = null,
  onRemove,
  selected = null,
  onToggleSelect = null,
  selectedItems = null,
  isOrder = false,
  orderId = null
}) {
  const { token } = useContext(AuthContext);
  const [quantity, setQuantity] = useState(item.quantity);

  useEffect(() => {
    if (quantity !== item.quantity && !item.is_addon) {
      const timeout = setTimeout(() => {
        handleUpdateQuantity(quantity);
      }, 500);

      return () => clearTimeout(timeout);
    }
  }, [quantity]);

  const handleUpdateQuantity = async (newQty) => {
    try {
      const res = await fetch(`${url}/api/cart/add/${item.food_id}`, {
        method: "POST",
        credentials: "include",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ quantity: newQty }),
      });

      await res.json();
      onUpdate(item.food_id, newQty);
    } catch (err) {
      console.error(err);
    }
  };

  return (
    <div className="relative flex flex-row items-center w-full">

      {!isOrder && !item.is_addon && (
        <div
          onClick={(e) => {
            e.stopPropagation();
            onToggleSelect(item.food_id);
          }}
          className={`absolute -left-6 top-1/2 -translate-y-1/2 w-4 h-4 rounded-full border-2 cursor-pointer flex items-center justify-center transition
            ${selectedItems.includes(item.food_id) ? "bg-orange-500 border-orange-500" : "border-gray-400"}`}
        >
          {selectedItems.includes(item.food_id) && <span className="w-2 h-2 bg-white rounded-full"></span>}
        </div>
      )}

      <div
        className={`flex items-center bg-[#fef9e7] rounded-3xl overflow-hidden w-full shadow-md transition
          ${selected ? "ring-2 ring-orange-500" : ""}`}
      >
        {/* Image */}
        <div className="flex-shrink-0 w-[100px] h-20 overflow-hidden rounded-3xl bg-yellow-400 flex items-center justify-center">
          <img
            src={item.thumbnail || `${item.food?.thumbnail}`}
            alt={item.food_name}
            className="w-full h-full object-cover"
          />
        </div>

        {/* Info */}
        <div className="flex-1 px-3">
          <h3 className="font-bold text-black">{item.food_name || item.food?.food_name}</h3>
          <p className="text-[#ff6600] font-semibold">
            ₱{isOrder ? item.quantity * (item.price ?? item.food?.price ?? 0) : (item.price ?? item.food?.price ?? 0)}
          </p>
        </div>

        {isOrder && <span>{item.quantity} ×</span>}

        {/* Quantity Controls */}
        <div className="bg-yellow-300 px-2 py-1 rounded-r-lg w-[30px] h-[80px]">
          {isOrder ? (
            <div className="flex flex-col items-center justify-center mt-2">
              <h1 className="py-[10px] w-[40px] text-center whitespace-nowrap text-sm rotate-90 font-light text-black">
                Order #{orderId}
              </h1>
            </div>
          ) : (
            <>
              <button
                className={`text-lg font-bold text-orange-700 hover:text-orange-900 ${item.is_addon ? "opacity-50 cursor-not-allowed" : ""}`}
                onClick={(e) => {
                  e.stopPropagation();
                  if (!item.is_addon) setQuantity((prev) => prev + 1);
                }}
              >
                +
              </button>
              <span className="font-bold">{quantity}</span>
              <button
                className={`text-lg font-bold text-orange-700 hover:text-orange-900 ${item.is_addon ? "opacity-50 cursor-not-allowed" : ""}`}
                onClick={(e) => {
                  e.stopPropagation();
                  if (!item.is_addon && quantity > 1) setQuantity((prev) => prev - 1);
                }}
              >
                -
              </button>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
