import React from "react";
import AppButton from "./AppButton"; // Make sure the path is correct

export default function TabsComponent({ categories = [], setActiveCategory, activeCategory }) {
  return (
    <div className="w-full">
      {/* Tabs List */}
      <div className="flex border-b border-gray-300 rounded-md overflow-hidden">
        {/* Scrollable tabs container */}
        <div className="flex flex-row overflow-x-scroll gap-2 p-1">
          {/* "All" tab */}
          <AppButton
            useCase={activeCategory === "all" ? "foodTabActive" : "foodTabInactive"}
            size="md"
            roundedType="full"
            onClick={() => setActiveCategory("all")}
          >
            All
          </AppButton>

          {/* Category tabs */}
          {categories.map((cat) => (
            <AppButton
              key={cat.id}
              useCase={activeCategory === cat.id.toString() ? "foodTabActive" : "foodTabInactive"}
              size="md"
              roundedType="full"
              onClick={() => setActiveCategory(cat.id.toString())}
            >
              {cat.name}
            </AppButton>
          ))}
        </div>
      </div>
    </div>
  );
}
