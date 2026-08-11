import React, { useContext, useState } from "react";
import { Group, Button, Drawer, Stack } from "@mantine/core";
import { Search, MoreHorizontal, ShoppingCart, PhoneCall, User } from "lucide-react";
import hocLogo from "../assets/Logo_banner.png";
import { useNavigate } from "react-router-dom";
import CartDrawer from "./CartComponent";
import AppButton from "./AppButton";
import Order from "./Order";
import AuthContext from "../Contexts/AuthContext";
import AuthModal from "./AuthModal";
import { FoodContext } from "../Contexts/FoodProvider";

export default function Header({ variant = "default" , search = true}) {
  const nav = useNavigate();
  const [opened, setOpened] = useState(false);
  const { user } = useContext(AuthContext);
  const { 
    searchQuery, 
    setSearchQuery,
  } = useContext(FoodContext);

  return (
    <>
      <header className="bg-white shadow-sm">
        <div className="flex items-center justify-between w-full px-6 py-3 mx-auto">
          {variant === "home" ? (
            <>
              {/* Logo */}
              <img
                src={hocLogo}
                alt="Click N' Chick"
                className="object-contain w-auto cursor-pointer h-14"
                onClick={() => nav("/")}
              />

              {/* Right Icons */}
              <div className="flex items-center justify-center gap-2 px-6">
                {/* Search bar with live functionality */}
                {search && <div className="flex items-center w-full max-w-md px-4 py-2 border border-orange-200 rounded-full bg-orange-50">
                  <Search size={16} className="mr-2 text-gray-400" />
                  <input
                    type="text"
                    placeholder="Search food here..."
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="w-full text-sm text-gray-600 placeholder-gray-400 bg-transparent focus:outline-none"
                  />
                  {/* Clear button (appears when there's text) */}
                  {searchQuery && (
                    <button
                      onClick={() => setSearchQuery("")}
                      className="ml-2 text-xs text-gray-400 hover:text-gray-600"
                    >
                      ✕
                    </button>
                  )}
                </div> }
                

                {/* More button */}
                <AppButton
                  useCase="menu"
                  size="lg"
                  roundedType="full"
                  onClick={() => nav('/settings')}
                  iconOnly
                  icon={User}
                />

                <Order />
              </div>
            </>
          ) : (
            // Default (non-home) header layout
            <div className="flex items-center justify-between w-full">
              {/* Logo */}
              <img
                src={hocLogo}
                alt="Click N' Chick"
                className="object-contain w-auto cursor-pointer h-14"
                onClick={() => nav("/")}
              />
              
              <Group gap="lg" visibleFrom="md" className="font-medium text-gray-800">
                <a href="#about" className="transition hover:text-yellow-500">About Us</a> 
                <a href="#deals" className="transition hover:text-yellow-500">Deals</a> 
                <a href="#find-us" className="transition hover:text-yellow-500">Find Us</a> 
              </Group>

              <Group gap="md" visibleFrom="sm" className="hidden md:flex">
                <Button
                  component="a"
                  href="tel:+639108765432"
                  variant="outline"
                  color="brown"  
                  radius="xl"
                  leftSection={<PhoneCall size={16} color="black" />}
                  className="text-gray-700 border-gray-300 hover:bg-gray-100"
                >
                  Call: +63 910 8765 432
                </Button>

                <AppButton 
                  useCase="signup" 
                  bgColor={"bg-amber-400"} 
                  hoverColor={"hover:bg-amber-800"} 
                  onClick={() => nav('/register')}
                >
                  Sign Up
                </AppButton>

                <AppButton useCase="signin" onClick={() => nav('/login')}>
                  Log In
                </AppButton>
              </Group>
            </div>
          )}
        </div>

        <AuthModal opened={opened} onClose={() => setOpened(false)} />
      </header>
    </>
  );
}