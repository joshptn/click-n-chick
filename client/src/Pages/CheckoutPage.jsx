import React, { useContext, useEffect, useState } from "react";
import { CartContext } from "../Contexts/CartProvider";
import CartItemCard from "../Components/CartItemCard";
import AppButton from "../Components/AppButton";
import AuthContext from "../Contexts/AuthContext";
import UserLocationMap from "../Components/LeafletMap";
import { Distance } from "../utils/Distance";
import gcash from "../assets/gcash_icon.svg";
import { Button, FileButton, Image, Text } from "@mantine/core";
import { ImageIcon } from "lucide-react";

function CheckoutPage() {
  const { cart, placeOrder, placingOrder,total } = useContext(CartContext);
  const url = import.meta.env.VITE_API_URL;
  const { token, user } = useContext(AuthContext);
  const [location, setLocation] = useState({
        lat: user.latitude,
        lng: user.longitude,
        city: "",
        country: "",
        full: user.location
      });
  const [orderType, setOrderType] = useState("");
  const [proof, setProof] = useState(null)
  const [preview, setPreview] = useState(null)

  const handleChange = (e) => {
    setOrderType(e.target.value);
    
  };

  const handleFileChange = (file) => {
    if (file) {
      setProof(file);
      setPreview(URL.createObjectURL(file));
    }
  };

    const dis = Distance(location.lat, location.lng)

    let dis_price = 0;
    let extra_km = 0;

    if (orderType === "pickup") {
        dis_price = 0;
    } else {
        const base_km = 3;
        const base_price = 55;
        const extra_price = 10;

        if (dis <= base_km) {
            dis_price = base_price;
        } else {
            extra_km = Math.ceil(dis - base_km); 
            dis_price = base_price + (extra_km * extra_price);
        }
    }

    
  return (
  <div className="min-h-screen p-6 font-sans bg-gray-50">
    <div className="grid gap-6 lg:grid-cols-3">
      
      {/* ================= LEFT COLUMN - CART SUMMARY ================= */}
      <div className="flex flex-col p-6 bg-white shadow-sm rounded-2xl">
        <h2 className="text-3xl font-extrabold leading-tight text-orange-500">
          CART <br /> SUMMARY
        </h2>

        {/* Order Type */}
        <div className="mt-6">
          <select
            className="w-full px-4 py-3 text-gray-700 border border-gray-300 rounded-xl focus:ring-2 focus:ring-orange-400 focus:outline-none"
            value={orderType}
            onChange={handleChange}
          >
            <option value="">Choose your order type</option>
            <option value="delivery">Delivery</option>
            <option value="pickup">Pickup</option>
          </select>
        </div>

        {/* GCash */}
        <button className="flex items-center justify-between px-5 py-3 mt-4 transition border border-gray-200 rounded-full hover:shadow-sm">
          <span className="font-semibold text-blue-500">
            Pay with GCash
          </span>
          <img src={gcash} alt="GCash" className="w-9 h-9" />
        </button>

        {/* Totals */}
        <div className="mt-8 space-y-2 text-sm">
          <div className="flex justify-between">
            <span>Sub Total</span>
            <span>₱{total}</span>
          </div>

          <div className="flex justify-between">
            <span>Paymongo Fee</span>
            <span>₱30</span>
          </div>

          <div className="flex justify-between">
            <span>Extra Km</span>
            <span>{extra_km}</span>
          </div>

          <div className="flex justify-between">
            <span>Delivery Fee</span>
            <span>₱{dis_price}</span>
          </div>
        </div>

        {/* TOTAL */}
        <div className="flex items-center justify-between p-4 mt-6 text-lg font-bold bg-yellow-100 rounded-xl">
          <span>TOTAL</span>
          <span>₱{total + 30 + dis_price}</span>
        </div>

        {/* ================= PROOF UPLOAD ================= */}
        <div className="mt-6">
          <Text fw={600} size="sm" mb={6}>
            Import Proof of Payment
          </Text>

          <div
            className="flex flex-col items-center justify-center p-8 text-sm text-gray-500 transition border-2 border-orange-400 border-dashed cursor-pointer rounded-xl hover:bg-orange-50"
            onClick={() => document.getElementById("food-upload-btn").click()}
          >
            {preview ? (
              <Image
                src={preview}
                height={150}
                radius="md"
                alt="preview"
                className="object-cover"
              />
            ) : (
              <>
                <ImageIcon size={40} color="#f97316" />
                <p className="mt-2 text-center">
                  Browse and select an image.....
                </p>
              </>
            )}
          </div>

          <div className="flex justify-center mt-4">
            <FileButton
              id="food-upload-btn"
              onChange={handleFileChange}
              accept="image/*"
            >
              {(props) => (
                <Button {...props} color="orange" radius="xl" size="sm">
                  Upload
                </Button>
              )}
            </FileButton>
          </div>
        </div>

        {/* Place Order */}
        <div className="mt-6">
          <AppButton
            useCase="menu"
            size="lg"
            onClick={() =>
              placeOrder({ orderType: orderType, location, proof })
            }
            disabled={placingOrder || !orderType}
            className={`w-full ${
              !orderType ? "opacity-50 cursor-not-allowed" : ""
            }`}
          >
            {placingOrder ? "Placing Order..." : "Place Order"}
          </AppButton>
        </div>
      </div>

      {/* ================= MIDDLE COLUMN - ORDER LIST ================= */}
      <div className="flex flex-col p-6 bg-white shadow-sm rounded-2xl">
        <h2 className="text-lg font-bold">Order List</h2>

        <div className="flex-1 mt-6 space-y-4 overflow-y-auto">
          {cart.length > 0 ? (
            cart.map((item) => (
              <CartItemCard
                key={item.food_id}
                item={item}
                url={url}
                isOrder
              />
            ))
          ) : (
            <p className="text-gray-500">No items in cart</p>
          )}
        </div>
      </div>

      {/* ================= RIGHT COLUMN - MAP ================= */}
      <div className="flex flex-col p-6 bg-white shadow-sm rounded-2xl">
        <div className="flex-1 overflow-hidden bg-gray-100 rounded-xl">
          <UserLocationMap
            editMode={true}
            setLocation={setLocation}
            location={location}
            user={user}
          />
        </div>

        <div className="mt-4 text-sm">
          <p className="font-semibold text-gray-900">
            Your Pinned Location
          </p>

          <div className="flex items-center mt-2">
            <span className="mr-2 text-orange-500">📍</span>
            <span className="font-medium">{location.full}</span>
          </div>

          <p className="mt-4 font-bold">
            {user?.first_name} {user?.last_name}
          </p>
          <p className="text-gray-600">
            {user?.phone_number}
          </p>
        </div>
      </div>

    </div>
  </div>
);

}

export default CheckoutPage;
