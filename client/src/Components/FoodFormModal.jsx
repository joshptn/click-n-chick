import React, { useState, useEffect, useContext } from "react";
import {
  Modal,
  Text,
  TextInput,
  NumberInput,
  Textarea,
  Button,
  Image,
  MultiSelect,
  FileButton,
  Loader,
  Select,
} from "@mantine/core";
import { Image as ImageIcon } from "lucide-react";
import AuthContext from "../Contexts/AuthContext";
import { FoodContext } from "../Contexts/FoodProvider";

export default function FoodFormModal({ opened, onClose, food = null, onSuccess }) {
  const { categories } = useContext(FoodContext);
  const { token } = useContext(AuthContext);
  const preUrl = import.meta.env.VITE_API_URL;

  const [formData, setFormData] = useState({
    food_name: "",
    price: 0,
    categories: [],
    availability: "",
    description: "",
    thumbnail: null,
  });

  const [preview, setPreview] = useState(null);
  const [loading, setLoading] = useState(false);

  // Load existing food data if editing
  useEffect(() => {
      if (food) {
        setFormData({
          food_name: food.food_name || "",
          price: food.price || 0,
          categories:
            food.categories?.map((cat) => cat.id.toString()) ||
            [food.category_id?.toString()].filter(Boolean),
          availability: food.available ? "Available" : "Unavailable", 
          description: food.description || "",
          thumbnail: null,
        });

        if (food.thumbnail) {
          setPreview(
            food.thumbnail.startsWith("http")
              ? food.thumbnail
              : `${preUrl}/storage/${food.thumbnail}`
          );
        }
      }
    }, [food]);

  // Handle file upload
  const handleFileChange = (file) => {
    if (file) {
      setFormData({ ...formData, thumbnail: file });
      setPreview(URL.createObjectURL(file));
    }
  };

  
  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);

    const data = new FormData();
    data.append("food_name", formData.food_name);
    data.append("price", formData.price);

    formData.categories.forEach((cat) => data.append("categories[]", cat));

    const isAvailable = formData.availability === "Available";
    data.append("available", isAvailable ? "1" : "0");

    data.append("description", formData.description);
    if (formData.thumbnail) data.append("thumbnail", formData.thumbnail);
    if (food) data.append("_method", "PUT");

    const url = food
      ? `${preUrl}/api/foods/${food.id}`
      : `${preUrl}/api/foods`;

    try {
      const res = await fetch(url, {
        method: "POST",
        headers: { Authorization: `Bearer ${token}` },
        body: data,
      });

      const result = await res.json();

      if (!res.ok) {
        console.error(result);
        throw new Error(result.message || "Failed to save food item");
      }

     
      if (onSuccess) onSuccess();

     
      if (!food) {
        setFormData({
          food_name: "",
          price: 0,
          categories: [],
          availability: "",
          description: "",
          thumbnail: null,
        });
        setPreview(null);
      }

      // Close modal
      onClose();
    } catch (err) {
      console.error(err);
      alert("Something went wrong while saving the food item.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <Modal
      opened={opened}
      onClose={onClose}
      withCloseButton={false}
      centered
      size="lg"
      overlayProps={{ backgroundOpacity: 0.55, blur: 3 }}
      radius="lg"
      padding="xl"
    >
      <form
        onSubmit={handleSubmit}
        className="flex flex-col gap-5 bg-white p-8 rounded-3xl"
      >
        {/* ================= IMAGE UPLOAD ================= */}
        <div>
          <Text fw={600} size="sm" mb={6}>
            Import Image
          </Text>

          <div
            className="border-2 border-dashed border-orange-400 rounded-xl p-8 flex flex-col items-center justify-center text-gray-500 text-sm cursor-pointer hover:bg-orange-50 transition"
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

          <div className="mt-4 flex justify-center">
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

        {/* ================= FOOD NAME ================= */}
        <TextInput
          label="Food Name"
          placeholder="Enter food name"
          value={formData.food_name}
          onChange={(e) =>
            setFormData({ ...formData, food_name: e.target.value })
          }
          classNames={{
            input:
              "rounded-full bg-gray-50 focus:ring-2 focus:ring-orange-400 border-none",
          }}
          required
        />

        {/* ================= PRICE ================= */}
        <NumberInput
          label="Price"
          placeholder="₱ 0"
          min={0}
          value={formData.price}
          onChange={(val) => setFormData({ ...formData, price: val || 0 })}
          classNames={{
            input:
              "rounded-full bg-gray-50 focus:ring-2 focus:ring-orange-400 border-none",
          }}
          required
        />

        {/* ================= MULTI CATEGORY ================= */}
        <MultiSelect
          label="Categories"
          placeholder="Select categories"
          data={categories.map((c) => ({
            value: c.id.toString(),
            label: c.name,
          }))}
          value={formData.categories}
          onChange={(values) => setFormData({ ...formData, categories: values })}
          classNames={{
            input:
              "rounded-full bg-gray-50 focus:ring-2 focus:ring-orange-400 border-none",
          }}
          required
        />

        {/* ================= AVAILABILITY ================= */}
        {/* Availability */} 
        <Select
          label="Availability" 
          placeholder="Select availability" 
          data={[ 
            { value: "Available", label: "Available" }, 
            { value: "Unavailable", label: "Unavailable" }, 
          ]} 
          value={formData.availability} 
          onChange={(value) => setFormData({ ...formData, availability: value })} 
          classNames={{ 
            input: "rounded-full bg-gray-50 focus:ring-2 focus:ring-orange-400 border-none" 
          }} 
          required 
        />

        {/* ================= DESCRIPTION ================= */}
        <Textarea
          label="Description"
          placeholder="Enter description"
          value={formData.description}
          onChange={(e) =>
            setFormData({ ...formData, description: e.target.value })
          }
          classNames={{
            input:
              "rounded-2xl bg-gray-50 focus:ring-2 focus:ring-orange-400 border-none",
          }}
          required
        />

        {/* ================= SUBMIT BUTTON ================= */}
        <Button
          type="submit"
          fullWidth
          radius="xl"
          color="orange"
          size="md"
          disabled={loading}
        >
          {loading ? (
            <Loader color="white" size="sm" />
          ) : food ? (
            "Edit Food"
          ) : (
            "Create Food"
          )}
        </Button>
      </form>
    </Modal>
  );
}
