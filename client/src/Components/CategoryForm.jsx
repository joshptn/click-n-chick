import React, { useState, useContext } from "react";
import { Card, TextInput, Button, Stack } from "@mantine/core";
import AuthContext from "../Contexts/AuthContext";

export default function CategoryForm({ onSuccess }) {
  let { token } = useContext(AuthContext);
  const [name, setName] = useState("");

  const preUrl = import.meta.env.VITE_API_URL;

  const handleSubmit = async (e) => {
    e.preventDefault();

    try {
      const res = await fetch(`${preUrl}/api/category`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ name }),
      });

      if (!res.ok) throw new Error("Failed to create category");
      setName("");

      if (onSuccess) onSuccess();
    } catch (err) {
      console.error(err);
      alert("Something went wrong.");
    }
  };

  return (
   
      <form onSubmit={handleSubmit} className="flex flex-col justify-between h-full p-5">
        <Stack>
          <span className="mb-4">Category Name</span>
          <TextInput
            
            placeholder="Enter category name"
            value={name}
            onChange={(e) => setName(e.target.value)}
            required
          />
          
        </Stack>
        <Button type="submit" color="yellow">
        Create Category
      </Button>
      </form>
      
 
  );
}
