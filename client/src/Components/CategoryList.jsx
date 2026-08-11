import React, { useEffect, useState, useContext } from "react";
import { Card, Text, Button, TextInput, Group } from "@mantine/core";
import AuthContext from "../Contexts/AuthContext";
import { FoodContext } from "../Contexts/FoodProvider";
import DeleteModal from "./DeleteModal";

export default function CategoryList() {
  const { token } = useContext(AuthContext);
  const { categories, setCategories } = useContext(FoodContext); // get setter from context
  const [openedDelete, setOpenedDelete] = useState(false);
  const [editingId, setEditingId] = useState(null);
  const [editName, setEditName] = useState("");
  const [deleteId, setDeleteId] = useState(null); // track which category to delete
  const preUrl = import.meta.env.VITE_API_URL;

  // Handle edit
  const handleEdit = (cat) => {
    setEditingId(cat.id);
    setEditName(cat.name);
  };

  const handleUpdate = async (id) => {
    try {
      const res = await fetch(`${preUrl}/api/category/${id}`, {
        method: "PUT",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ name: editName }),
      });

      if (!res.ok) throw new Error("Failed to update category");

      const updated = await res.json();

      // Update categories in context
      setCategories((prev) => prev.map((c) => (c.id === id ? updated : c)));

      // Reset editing state
      setEditingId(null);
      setEditName("");
    } catch (err) {
      console.error(err);
    }
  };

  // Handle delete
  const handleDelete = async (id) => {
    try {
      await fetch(`${preUrl}/api/category/${id}`, {
        method: "DELETE",
        headers: { Authorization: `Bearer ${token}` },
      });

      // Remove deleted category from context
      setCategories((prev) => prev.filter((c) => c.id !== id));
      setOpenedDelete(false);
    } catch (err) {
      console.error(err);
    }
  };

  return (
    <div className="">
      <h1 className="text-[50px] font-medium leading-tight text-amber-600 hoc_font m-4">
        Categories
      </h1>

      {categories.map((cat) => (
        <div
          key={cat.id}
          className="flex justify-between items-center bg-white p-4 rounded-2xl gap-4 mb-4 xl"
        >
          {editingId === cat.id ? (
            <Group className="w-full">
              <TextInput
                value={editName}
                onChange={(e) => setEditName(e.target.value)}
                className="flex-1"
              />
              <Button
                size="xs"
                color="green"
                onClick={() => handleUpdate(cat.id)}
              >
                Save
              </Button>
              <Button
                size="xs"
                variant="light"
                onClick={() => setEditingId(null)}
              >
                Cancel
              </Button>
            </Group>
          ) : (
            <>
              <span className="text-[25px] font-mono">{cat.name}</span>
              <Group>
                <Button size="xs" color="yellow" onClick={() => handleEdit(cat)}>
                  Edit
                </Button>
                <Button
                  size="xs"
                  color="brown"
                  onClick={() => {
                    setDeleteId(cat.id);
                    setOpenedDelete(true);
                  }}
                >
                  Delete
                </Button>
              </Group>

              {/* Delete Modal */}
              <DeleteModal
                opened={openedDelete && deleteId === cat.id}
                onClose={() => setOpenedDelete(false)}
                onConfirm={() => handleDelete(cat.id)}
                itemName={cat.name}
              />
            </>
          )}
        </div>
      ))}
    </div>
  );
}
