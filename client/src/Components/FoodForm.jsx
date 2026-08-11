import React, { useState, useEffect, useContext } from "react";
import {
  TextInput,
  NumberInput,
  Textarea,
  Button,
  FileButton,
  Select,
  Image,
  Text,
  Loader,
} from "@mantine/core";
import { UploadCloud, Image as ImageIcon } from "lucide-react";
import { showNotification } from "@mantine/notifications";
import { CheckCircle2, AlertCircle } from "lucide-react";
import AuthContext from "../Contexts/AuthContext";
import { FoodContext } from "../Contexts/FoodProvider";

export default function FoodForm({ food = null, onSuccess }) {
  const { categories } = useContext(FoodContext);
  const { token } = useContext(AuthContext);
  const preUrl = import.meta.env.VITE_API_URL;

  const [formData, setFormData] = useState({
    food_name: "",
    price: 0,
    category: "",
    availability: "",
    short_description: "",
    description: "",
    thumbnail: null,
  });

  const [preview, setPreview] = useState(null);
  const [loading, setLoading] = useState(false);

  // preload food for editing
  useEffect(() => {
    if (food) {
      setFormData({
        food_name: food.food_name || "",
        price: food.price || 0,
        category: food.category_id?.toString() || "",
        availability: food.availability || "",
        short_description: food.short_description || "",
        description: food.description || "",
        thumbnail: null,
      });
      if (food.thumbnail)
        setPreview(
          food.thumbnail.startsWith("http")
            ? food.thumbnail
            : `${food.thumbnail}`
        );
    }
  }, [food]);

  // handle file upload
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
    data.append("category", formData.category);
    data.append("availability", formData.availability);
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

      if (!res.ok)
        throw new Error(result.message || "Failed to save food item.");

      showNotification({
        title: "Success",
        message: `Food ${food ? "updated" : "added"} successfully.`,
        color: "green",
        icon: <CheckCircle2 />,
      });

      if (onSuccess) onSuccess();
    } catch (err) {
      showNotification({
        title: "Error",
        message: err.message,
        color: "red",
        icon: <AlertCircle />,
      });
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex justify-center items-center min-h-screen bg-gray-50">
      <form
        onSubmit={handleSubmit}
        className="bg-white rounded-2xl shadow-md p-10 w-[400px] flex flex-col gap-5"
      >
        {/* Image upload */}
        <div>
          <Text fw={600} size="sm" mb={6}>
            Import Image
          </Text>

          <div className="border-2 border-dashed border-orange-400 rounded-xl p-8 flex flex-col items-center justify-center text-gray-500 text-sm cursor-pointer hover:bg-orange-50 transition">
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
                  Browse and select an image…..
                </p>
              </>
            )}

            <div className="mt-4">
              <FileButton onChange={handleFileChange} accept="image/*">
                {(props) => (
                  <Button {...props} color="orange" radius="xl" size="sm">
                    Upload
                  </Button>
                )}
              </FileButton>
            </div>
          </div>
        </div>

        {/* Food Name */}
        <TextInput
          label="Food Name"
          placeholder="Enter food name"
          value={formData.food_name}
          onChange={(e) =>
            setFormData({ ...formData, food_name: e.target.value })
          }
          classNames={{
            input:
              "rounded-full bg-gray-50 focus:ring-2 focus:ring-orange-400 border",
          }}
          required
        />

        {/* Price */}
        <NumberInput
          label="Price"
          placeholder="₱ 0"
          value={formData.price}
          min={0}
          onChange={(val) => setFormData({ ...formData, price: val || 0 })}
          classNames={{
            input:
              "rounded-full bg-gray-50 focus:ring-2 focus:ring-orange-400 border",
          }}
          required
        />

        {/* Categories */}
        <Select
          label="Categories"
          placeholder="Select categories"
          data={categories.map((c) => ({
            value: c.id.toString(),
            label: c.name,
          }))}
          value={formData.category}
          onChange={(value) => setFormData({ ...formData, category: value })}
          classNames={{
            input:
              "rounded-full bg-gray-50 focus:ring-2 focus:ring-orange-400 border",
          }}
          required
        />

        {/* Availability */}
        <Select
          label="Availability"
          placeholder="Select availability"
          data={[
            { value: "Available", label: "Available" },
            { value: "Unavailable", label: "Unavailable" },
          ]}
          value={formData.availability}
          onChange={(value) =>
            setFormData({ ...formData, availability: value })
          }
          classNames={{
            input:
              "rounded-full bg-gray-50 focus:ring-2 focus:ring-orange-400 border",
          }}
          required
        />

      
        {/* Description */}
        <Textarea
          label="Description"
          placeholder="Enter description"
          value={formData.description}
          onChange={(e) =>
            setFormData({ ...formData, description: e.target.value })
          }
          classNames={{
            input:
              "rounded-2xl bg-gray-50 focus:ring-2 focus:ring-orange-400 border",
          }}
          required
        />

        {/* Submit Button */}
        <Button
          type="submit"
          fullWidth
          radius="xl"
          color="orange"
          size="md"
          disabled={loading}
        >
          {loading ? <Loader color="white" size="sm" /> : "Edit Food"}
        </Button>
      </form>
    </div>
  );
}
