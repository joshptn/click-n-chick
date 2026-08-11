import React, { useContext, useEffect, useState } from "react";
import { Text, Loader, Button } from "@mantine/core";
import AuthContext from "../Contexts/AuthContext";
import CartItemCard from "./CartItemCard";
import PayWithGcash from "./PayWithGcash";

export default function Cart() {
  const { token } = useContext(AuthContext);
  const [cart, setCart] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [placingOrder, setPlacingOrder] = useState(false);
  const url = import.meta.env.VITE_API_URL;

  
  const fetchCart = async () => {
    try {
      setLoading(true);
      const res = await fetch(`${url}/api/cart`, {
        headers: { Authorization: `Bearer ${token}` },
        credentials: "include",
      });

      if (!res.ok) throw new Error("Failed to fetch cart");
      const data = await res.json();
      setCart(data.cart || []);
      setTotal(data.total || 0);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchCart();
  }, []);


  const updateCartItem = async (foodId, newQty) => {
    try {
      const res = await fetch(`${url}/api/cart/${foodId}`, {
        method: "PUT",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ quantity: newQty }),
      });

     
      const data = await res.json();
      setCart(data.cart || []);
      setTotal(data.total || 0);
    } catch (err) {
      console.error(err);
    }
  };

  
  const removeCartItem = async (foodId) => {
    try {
      const res = await fetch(`${url}/api/cart/${foodId}`, {
        method: "DELETE",
        headers: { Authorization: `Bearer ${token}` },
      });

      
      const data = await res.json();
      
      setCart(data.cart || []);
      setTotal(data.total || 0);
      
      
    } catch (err) {
      console.error(err);
    }
  };

  
  const handleUpdate = (foodId, newQty) => {
    setCart((prev) =>
      prev.map((item) =>
        item.food_id === foodId
          ? { ...item, quantity: newQty, subtotal: newQty * item.price }
          : item
      )
    );
    setTotal((prev) =>
      cart.reduce(
        (sum, i) =>
          sum + (i.food_id === foodId ? newQty * i.price : i.subtotal),
        0
      )
    );
    updateCartItem(foodId, newQty);
  };


  const handleRemove = (foodId) => {
    setCart((prev) => prev.filter((item) => item.food_id !== foodId));
    setTotal((prev) =>
      cart
        .filter((i) => i.food_id !== foodId)
        .reduce((sum, i) => sum + i.subtotal, 0)
    );
    removeCartItem(foodId);
    
  };


  const placeOrder = async () => {
    if (cart.length === 0) {
      alert("Your cart is empty!");
      return;
    }

    try {
      setPlacingOrder(true);
      const res = await fetch(`${url}/api/order/place`, {
        method: "POST",
        headers: {
          Authorization: `Bearer ${token}`,
        },
        credentials: "include",
      });

     
      const data = await res.json();
    
      
      
      
      setCart([]); 
      setTotal(0);

      PayWithGcash(data)
    } catch (err) {
      console.error(err);
      alert("Error placing order.");
    } finally {
      setPlacingOrder(false);
    }
  };

  if (loading) return <Loader />;

  return (
    <div className="space-y-4">
      <h1>Total: {total}</h1>

      {cart.length > 0 ? (
        <>
          {cart.map((item) => (
            <CartItemCard
              key={item.food_id}
              item={item}
              url={url}
              onUpdate={handleUpdate}
              onRemove={handleRemove}
            />
          ))}

          {/* Place Order Button */}
          <Button
            fullWidth
            color="green"
            mt="md"
            onClick={placeOrder}
            loading={placingOrder}
          >
            Place Order
          </Button>
        </>
      ) : (
        <Text>No items in cart</Text>
      )}
    </div>
  );
}
