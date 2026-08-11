import { Modal, Select, Button, Loader } from '@mantine/core';
import React, { useContext, useEffect, useState } from 'react';
import AppButton from './AppButton';
import { AddOnContext } from '../Contexts/AddOnContext';
import AuthModal from './AuthModal';
import AuthContext from '../Contexts/AuthContext';

function AddToCart({ setCartOpened, cartOpened, quantity, setQuantity, handleAddToCart, food, close }) {
  const url = import.meta.env.VITE_API_URL;
  const { sides, drinks } = useContext(AddOnContext);
  const { user } = useContext(AuthContext);

  const [orderSides, setOrderSides] = useState([]);
  const [orderDrinks, setOrderDrinks] = useState([]);
  const [opened, setOpened] = useState(false);
  const [loading, setLoading] = useState(false); // 🟡 NEW loading state

  // 🟡 NEW: control Select values
  const [selectedSide, setSelectedSide] = useState(null);
  const [selectedDrink, setSelectedDrink] = useState(null);

  // --- Add a new side/drink entry ---
  const addItem = (item, type) => {
    const [list, setList] = type === "side" ? [orderSides, setOrderSides] : [orderDrinks, setOrderDrinks];
    const maxAllowed = quantity;

    if (list.length >= maxAllowed) {
      alert(`You can only select up to ${maxAllowed} ${type === "side" ? "sides" : "drinks"}`);
      return;
    }

    if (type === "side") {
      setList([...list, { id: item.id, name: item.food_name }]);
    } else {
      setList([...list, { id: item.id, name: item.food_name, size: "medium" }]);
    }
  };

  // --- Remove an item by index ---
  const removeItem = (index, type) => {
    const [list, setList] = type === "side" ? [orderSides, setOrderSides] : [orderDrinks, setOrderDrinks];
    const updated = [...list];
    updated.splice(index, 1);
    setList(updated);
  };

  // --- Confirm selection ---
  const handleConfirm = async () => {
    if (!user) {
      setOpened(true);
      return;
    }

    try {
      setLoading(true);
      await handleAddToCart(food, quantity, close, orderSides, orderDrinks);
      setCartOpened(false);
    } catch (err) {
      console.error("Error adding to cart:", err);
    } finally {
      setLoading(false);
    }
  };

  const isSideOrDrink = food.categories?.some(cat =>
    ['Sides', 'Drinks'].includes(cat.name)
  );

  return (
    <Modal opened={cartOpened} onClose={() => setCartOpened(false)} centered size={"100%"}>
      <div className="h-[80vh] flex flex-row w-full">

        {/* LEFT SECTION */}
        <div className="w-[30%] flex flex-col justify-between h-full p-4">
          <h1 className="hoc_font text-5xl pr-[30px] text-amber-600 font-extrabold py-10">
            {food.food_name}
          </h1>

          <div>
            <h4 className="text-amber-700">{food.description}</h4>
            {food.categories?.length > 0 && (
              <div className="flex flex-wrap gap-2 mt-2">
                {food.categories.map((cat) => (
                  <span
                    key={cat.id}
                    className="text-xs bg-amber-100 text-gray-700 px-2 py-1 rounded-md"
                  >
                    {cat.name}
                  </span>
                ))}
              </div>
            )}
          </div>

          {/* QUANTITY */}
          <div className="flex flex-row w-[50%] mt-6">
            <div className="bg-yellow-300 px-2 py-1 rounded-l-lg w-[15%] flex justify-center">
              <button
                className="text-lg font-bold text-orange-700 hover:text-orange-900"
                onClick={(e) => {
                  e.stopPropagation();
                  setQuantity(prev => prev + 1);
                }}
              >+</button>
            </div>
            <div className="font-bold w-[20%] flex items-center justify-center">{quantity}</div>
            <div className="bg-yellow-300 px-2 py-1 rounded-r-lg w-[15%] flex justify-center">
              <button
                className="text-lg font-bold text-orange-700 hover:text-orange-900"
                onClick={(e) => {
                  e.stopPropagation();
                  if (quantity > 1) setQuantity(prev => prev - 1);
                }}
              >-</button>
            </div>
          </div>

          {/* CONFIRM BUTTON */}
          <AppButton
            useCase="checkout"
            fullWidth
            className="mt-6 flex justify-center items-center"
            onClick={handleConfirm}
            disabled={loading}
          >
            {loading ? <Loader size="sm" color="white" /> : "Confirm Add"}
          </AppButton>
        </div>

        {/* IMAGE SECTION */}
        <div className="w-[40%] flex flex-col justify-center items-center">
          <img
            src={food.thumbnail ? `${food.thumbnail}` : "https://via.placeholder.com/320x200?text=No+Image"}
            alt={food.food_name}
            className="w-full h-full object-cover rounded-2xl"
          />
        </div>

        {/* SIDEBAR SECTION */}
        <div className="w-[30%] h-full bg-gray-50 p-4 overflow-y-auto border-l border-gray-300">
          <h1 className='w-full text-right hoc_font text-5xl pr-[30px] text-amber-500 font-extrabold '>
            P{food.price}
          </h1>

          {!isSideOrDrink && (
            <>
              <h2 className="text-2xl font-bold text-amber-600 mb-4">Add-ons</h2>

              {/* SIDES */}
              <div>
                <h3 className="text-lg font-semibold text-amber-700 mb-2">
                  Sides ({orderSides.length}/{quantity})
                </h3>

                <div className="flex flex-col gap-2">
                  {orderSides.map((side, index) => (
                    <div key={index} className="flex justify-between items-center bg-white p-2 rounded shadow-sm">
                      <span>{side.name}</span>
                      <Button size="xs" color="red" variant="light" onClick={() => removeItem(index, "side")}>×</Button>
                    </div>
                  ))}
                </div>

                <Select
                  placeholder="Add side..."
                  value={selectedSide}
                  data={sides.map(s => ({ value: String(s.id), label: s.food_name }))}
                  onChange={(id) => {
                    const item = sides.find(s => String(s.id) === id);
                    if (item) {
                      addItem(item, "side");
                      setSelectedSide(null);
                    }
                  }}
                  mt="sm"
                />
              </div>

              <hr className="my-4 border-amber-300" />

              {/* DRINKS */}
              <div>
                <h3 className="text-lg font-semibold text-amber-700 mb-2">
                  Drinks ({orderDrinks.length}/{quantity})
                </h3>

                <div className="flex flex-col gap-2">
                  {orderDrinks.map((drink, index) => (
                    <div key={index} className="flex justify-between items-center bg-white p-2 rounded shadow-sm">
                      <span>{drink.name}</span>
                      <Button size="xs" color="red" variant="light" onClick={() => removeItem(index, "drink")}>×</Button>
                    </div>
                  ))}
                </div>

                <Select
                  placeholder="Add drink..."
                  value={selectedDrink}
                  data={drinks.map(d => ({ value: String(d.id), label: d.food_name }))}
                  onChange={(id) => {
                    const item = drinks.find(d => String(d.id) === id);
                    if (item) {
                      addItem(item, "drink");
                      setSelectedDrink(null);
                    }
                  }}
                  mt="sm"
                />
              </div>
            </>
          )}
        </div>
      </div>

      <AuthModal opened={opened} onClose={() => setOpened(false)} />
    </Modal>
  );
}

export default AddToCart;
