import { LayoutGrid, CreditCard, ShoppingCart, LogOut } from "lucide-react";
import { useContext } from "react";
import AuthContext from "../Contexts/AuthContext";
import hocLogo from "../assets/Logo_Single.png";

export default function Sidebar({setActiveTab, activeTab}) {
  const {  logOut } = useContext(AuthContext);
  const active = "bg-orange-400 text-white"
  const inactive = "bg-yellow-200 text-orange-500"
  
  return (
    <div className="fixed top-0 left-0 h-full w-20 bg-white shadow-lg flex flex-col items-center justify-between py-6">
      {/* Logo */}
      <div className="mb-12">
        <img
          src={hocLogo}
          alt="Logo"
          className="w-12 h-12 object-contain"
        />
      </div>

      {/* Navigation Icons */}
      <div className="flex flex-col gap-6">
        <button  onClick={() => setActiveTab("foods")} className={`w-12 h-12 rounded-full flex items-center justify-center ${activeTab == "foods" ? active : inactive} shadow-md hover:scale-110 transition`}>
          <LayoutGrid size={20} />
        </button>

        <button onClick={() => setActiveTab("categories")} className={`w-12 h-12 rounded-full flex items-center justify-center ${activeTab == "categories" ? active : inactive} shadow-md hover:scale-110 transition`}>
          <CreditCard size={20} />
        </button>

        <button onClick={() => setActiveTab("orders")} className={`w-12 h-12 rounded-full flex items-center justify-center ${activeTab == "orders" ? active : inactive} shadow-md hover:scale-110 transition`}>
          <ShoppingCart size={20} />
        </button>

        
      </div>

      

      {/* Logout */}
      <button className="w-12 h-12 mb-4 rounded-full flex items-center justify-center bg-yellow-200 text-orange-500 shadow-md hover:scale-110 transition"
              onClick={logOut}>
        <LogOut size={20} />
      </button>
    </div>
  );
}
