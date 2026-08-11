import React, { useContext, useState } from "react";
import FoodList from "../Components/FoodList";
import AdminOrders from "../Components/AdminOrders";
import CategoryForm from "../Components/CategoryForm";
import CategoryList from "../Components/CategoryList";
import Sidebar from "../Components/Sidebar";
import AddFood from "../Components/AddFood";
import { Search } from "lucide-react";
import { FoodContext } from "../Contexts/FoodProvider";

function Admin() {
  const [activeTab, setActiveTab] = useState("orders");
   const { 
      searchQuery, 
      setSearchQuery,
    } = useContext(FoodContext);

  return (
    <div className="flex flex-row w-full bg-gray-100 p-6 gap-5">
      {/* Sidebar */}
      <div className="m-10">
        <Sidebar setActiveTab={setActiveTab} activeTab={activeTab}/>
      </div>

      {/* Main Content */}
      <div className="w-full">
       
        

        {/* Panels */}
        {activeTab === "foods" && (
          <div className="flex flex-row mt-4 min-h-screen w-full">
            <div>
              <div>
                <div>
                  <h1 className="hoc_font text-amber-800 text-[70px]">Good Day</h1>
                  <h1 className="hoc_font text-amber-500 text-[100px]">admin!</h1>
                </div>

                <div className="text-wrap text-2xl w-[40%] p-4 text-gray-500">This page allows you to manage food items. You can add new dishes, review existing ones, and remove items as needed.</div>
                
                <div className="flex items-center w-full max-w-md  border border-orange-200 rounded-full px-4 py-2 gap-2">

                  <Search size={16} className="text-gray-400 mr-2" />
                  <input
                    type="text"
                    placeholder="Search food here..."
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="w-full bg-transparent focus:outline-none text-sm text-gray-600 placeholder-gray-400"
                  />
                 
                  {searchQuery && (
                    <button
                      onClick={() => setSearchQuery("")}
                      className="ml-2 text-gray-400 hover:text-gray-600 text-xs"
                    >
                      ✕
                    </button>
                  )}

                </div>

              </div>
              <div className="mt-1">
                  <FoodList className="flex-1 overflow-auto " />
              </div>
              
            </div>
            
            <div className="flex flex-col sticky bottom-10">
              <AddFood />
            </div>
          </div>
        )}

        {activeTab === "categories" && (
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mt-4 min-h-screen h-full">
            <div className="h-full">
              <div className="h-[30%] w-[97%] flex flex-col justify-center items-center p-4 bg-slate-300 m-3 rounded-lg text-gray-600">
                <h4 className="text-lg font-semibold mb-4">This page lets you manage categories. You can create new categories and delete existing ones as needed.</h4>
              </div>
              <div className="bg-white shadow-md rounded-md p-4 h-[70%]">
                <CategoryForm onSuccess={() => window.location.reload()} />
              </div>
            </div>
            <div className="md:col-span-2 bg-amber-50 shadow-md rounded-md p-4">
              <CategoryList />
            </div>
          </div>
        )}

        {activeTab === "orders" && (
          <div className="bg-white shadow-md rounded-md p-4 mt-4 min-h-screen w-full">
            
            <AdminOrders />
          </div>
        )}
      </div>
    </div>
  );
}

export default Admin;
