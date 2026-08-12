import React, { useState } from "react";
import Header from "../components/Topbar";
import Feature from "../components/ui/Feature";
import AppButton from "../components/ui/AppButton";
import Menu from "./Menu";
import CartComponent from "../components/menu/CartComponent";
import { FastForward } from "lucide-react";


function Home() {
  const [activeTab, setActiveTab] = useState("menu");

  return (
  <div className="flex h-screen overflow-hidden bg-gray-50">

    {/* ================= MAIN CONTENT ================= */}
    <div
      className={`flex flex-col transition-all duration-300 ${
        activeTab === "menu" ? "w-[75%]" : "w-full"
      }`}
    >
      {/* Header */}
      <Header variant="home" search={activeTab === "menu"} />

      {/* Tabs */}
      <div className="px-6 mt-6">
        <div className="flex gap-4">
          <AppButton
            useCase={activeTab === "featured" ? "tabActive" : "tabInactive"}
            roundedType="full"
            size="md"
            bold
            onClick={() => setActiveTab("featured")}
          >
            FEATURED
          </AppButton>

          <AppButton
            useCase={activeTab === "menu" ? "tabActive" : "tabInactive"}
            roundedType="full"
            size="md"
            bold
            onClick={() => setActiveTab("menu")}
          >
            MENU
          </AppButton>
        </div>
      </div>

      {/* Scrollable Content Area */}
      <div className="flex-1 px-6 mt-6 overflow-y-auto">
        {activeTab === "featured" && <Feature />}
        {activeTab === "menu" && <Menu />}
      </div>
    </div>

    {/* ================= CART SIDEBAR ================= */}
    {activeTab === "menu" && (
      <div className="w-[25%]  bg-white shadow-lg p-2">
        <CartComponent />
      </div>
    )}
  </div>
);
}

export default Home;
