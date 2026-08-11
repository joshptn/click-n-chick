import React, { useContext, useState, useEffect } from "react";
import { Modal, Notification, Loader } from "@mantine/core";
import AuthContext from "../Contexts/AuthContext";
import { useNavigate } from "react-router-dom";
import UserLocationMap from "../Components/LeafletMap";
import logo from "../assets/hoc_logo.png";

function AuthModal({ opened, onClose }) {
  const { loginUser } = useContext(AuthContext);
  const nav = useNavigate();

  const [isLogin, setIsLogin] = useState(true);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  //  Show/Hide Password states
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);

  //  For location selection in registration
  const [location, setLocation] = useState({
    lat: null,
    lng: null,
    city: "",
    country: "",
    full: "",
  });

  // ---  Login Handler ---
  const handleLogin = async (e) => {
    e.preventDefault();
    setLoading(true);
    try {
      await loginUser(e);
      nav("/home");
      onClose();
    } catch (err) {
      setError(err.message || "Login failed. Check your credentials.");
      setTimeout(() => setError(""), 3000);
    } finally {
      setLoading(false);
    }
  };

  // ---  Register Handler ---
  const handleRegister = async (e) => {
    e.preventDefault();
    setError("");
    setLoading(true);
    const url = import.meta.env.VITE_API_URL;

    const phone = e.target.phone_number.value.trim();
    if (!/^\d{10}$/.test(phone)) {
      setError("Please enter a valid 10-digit phone number (digits only).");
      setLoading(false);
      setTimeout(() => setError(""), 4000);
      return;
    }

    try {
      const body = {
        first_name: e.target.first_name.value,
        last_name: e.target.last_name.value,
        name: e.target.name.value,
        email: e.target.email.value,
        password: e.target.password.value,
        password_confirmation: e.target.password_confirmation.value,
        phone_number: `+63${phone}`,
        latitude: location.lat,
        longitude: location.lng,
        location: location.full || "",
      };

      const response = await fetch(`${url}/api/register`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify(body),
        credentials: "include",
      });

      const data = await response.json();
      if (!response.ok) throw new Error(data.message || "Registration failed");

      // Auto-login
      await loginUser({
        preventDefault: () => {},
        target: {
          email: { value: e.target.email.value },
          password: { value: e.target.password.value },
        },
      });

      nav("/home");
      onClose();
    } catch (err) {
      console.error("Registration error:", err);
      setError(err.message);
    } finally {
      setLoading(false);
      setTimeout(() => setError(""), 4000);
    }
  };


  return (
    <Modal
      opened={opened}
      onClose={onClose}
      centered
      size="lg"
      radius="lg"
      overlayProps={{
        backgroundOpacity: 0.45,
        blur: 3,
      }}
      classNames={{
        body: "p-0",
      }}
    >
      <div className="relative max-w-lg p-10 mx-auto bg-white rounded-2xl">
        {/* Logo */}
        <div className="flex justify-center mb-4">
          <img src={logo} alt="Click n' Chick" className="h-16" />
        </div>

        {/* Heading */}
        {isLogin ? (
          <>
            <h1 className="text-[#B54719] text-3xl font-extrabold text-center leading-none hoc_font">
              GOOD DAY,
            </h1>
            <h2 className="text-[#FF9119] text-4xl font-extrabold text-center mb-4 leading-none hoc_font">
              BES-TIES!
            </h2>
            <p className="mb-6 text-sm text-center text-gray-600">
              Join Click n' Chicks — where your food clicks with your cravings!
            </p>
          </>
        ) : (
          <>
            <h1 className="text-[#B54719] text-3xl font-extrabold text-center leading-none hoc_font">
              CREATE AN
            </h1>
            <h2 className="text-[#FF9119] text-4xl font-extrabold text-center mb-4 leading-none hoc_font">
              ACCOUNT!
            </h2>
            <p className="mb-6 text-sm text-center text-gray-600">
              Join Click n' Chicks — where your account clicks with endless flavor!
            </p>
          </>
        )}

        {error && (
          <Notification
            color="red"
            onClose={() => setError("")}
            title={isLogin ? "Login Error" : "Registration Error"}
            className="mb-4"
          >
            {error}
          </Notification>
        )}

        {/* FORMS */}
        {isLogin ? (
          // LOGIN FORM
          <form onSubmit={handleLogin} className="flex flex-col space-y-3">
            <input
              type="text"
              name="email"
              placeholder="Email"
              className="w-full px-4 py-2 rounded-full border border-gray-300 text-gray-700 placeholder-gray-400 focus:border-[#FF9119] outline-none"
              required
            />

            {/* Password with Show/Hide */}
            <div className="relative">
              <input
                type={showPassword ? "text" : "password"}
                name="password"
                placeholder="Password"
                className="w-full px-4 py-2 rounded-full border border-gray-300 text-gray-700 placeholder-gray-400 focus:border-[#FF9119] outline-none pr-12"
                required
              />
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                className="absolute inset-y-0 right-4 flex items-center text-gray-500 hover:text-[#FF9119] text-sm focus:outline-none"
                tabIndex={-1}
              >
                {showPassword ? "Hide" : "Show"}
              </button>
            </div>

            <div className="flex justify-between text-xs text-gray-500">
              <span>
                Don’t have an account?{" "}
                <button
                  type="button"
                  onClick={() => setIsLogin(false)}
                  className="text-[#FF9119] font-semibold hover:underline"
                >
                  Sign Up
                </button>
              </span>
              <a href="/forgot" className="hover:underline">
                Forgot Password?
              </a>
            </div>

            <button
              type="submit"
              disabled={loading}
              className={`mt-4 w-28 py-2 rounded-full text-white font-semibold transition flex justify-center items-center mx-auto ${
                loading
                  ? "bg-[#FFD364] cursor-not-allowed"
                  : "bg-[#FFB600] hover:bg-[#ffae00]"
              }`}
            >
              {loading ? (
                <>
                  <Loader size="xs" color="white" className="mr-2" /> Signing In...
                </>
              ) : (
                "SIGN IN"
              )}
            </button>
          </form>
        ) : (
          // REGISTER FORM
          <form onSubmit={handleRegister} className="flex flex-col space-y-3">
            <div className="flex gap-2">
              <input
                type="text"
                name="first_name"
                placeholder="First Name"
                className="flex-1 px-4 py-2 border border-gray-300 rounded-full text-gray-700 placeholder-gray-400 focus:border-[#FF9119] outline-none"
                required
              />
              <input
                type="text"
                name="last_name"
                placeholder="Last Name"
                className="flex-1 px-4 py-2 border border-gray-300 rounded-full text-gray-700 placeholder-gray-400 focus:border-[#FF9119] outline-none"
                required
              />
            </div>

            <input
              type="text"
              name="name"
              placeholder="Username"
              className="px-4 py-2 border border-gray-300 rounded-full text-gray-700 placeholder-gray-400 focus:border-[#FF9119] outline-none"
              required
            />

            <input
              type="email"
              name="email"
              placeholder="Email"
              className="px-4 py-2 border border-gray-300 rounded-full text-gray-700 placeholder-gray-400 focus:border-[#FF9119] outline-none"
              required
            />

            {/* Password with Toggle */}
            <div className="relative">
              <input
                type={showPassword ? "text" : "password"}
                name="password"
                placeholder="Password"
                className="w-full px-4 py-2 border border-gray-300 rounded-full text-gray-700 placeholder-gray-400 focus:border-[#FF9119] outline-none pr-12"
                required
              />
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                className="absolute inset-y-0 right-4 flex items-center text-gray-500 hover:text-[#FF9119] text-sm focus:outline-none"
                tabIndex={-1}
              >
                {showPassword ? "Hide" : "Show"}
              </button>
            </div>

            {/* Confirm Password with Toggle */}
            <div className="relative">
              <input
                type={showConfirmPassword ? "text" : "password"}
                name="password_confirmation"
                placeholder="Confirm Password"
                className="w-full px-4 py-2 border border-gray-300 rounded-full text-gray-700 placeholder-gray-400 focus:border-[#FF9119] outline-none pr-12"
                required
              />
              <button
                type="button"
                onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                className="absolute inset-y-0 right-4 flex items-center text-gray-500 hover:text-[#FF9119] text-sm focus:outline-none"
                tabIndex={-1}
              >
                {showConfirmPassword ? "Hide" : "Show"}
              </button>
            </div>

            {/* Phone Number */}
            <div className="flex items-center border border-gray-300 rounded-full px-4 py-2 focus-within:border-[#FF9119]">
              <span className="mr-2 text-gray-500 select-none">+63</span>
              <input
                type="text"
                name="phone_number"
                placeholder="Enter your phone number"
                className="flex-1 text-gray-700 placeholder-gray-400 outline-none"
                maxLength={10}
                pattern="\d{10}"
                onInput={(e) => {
                  e.target.value = e.target.value.replace(/\D/g, "");
                }}
                required
              />
            </div>

            {/*  Map Picker */}
            <div className="mt-3">
              <p className="mb-2 ml-2 text-xs text-gray-500">Select your location</p>
              <div className="w-full h-[180px] rounded-xl overflow-hidden border border-gray-200 shadow-inner">
                <UserLocationMap
                  editMode={true}
                  setLocation={setLocation}
                  location={location}
                  user={null}
                />
              </div>
              {location.full && (
                <p className="mt-2 text-xs italic text-center text-gray-600">
                  📍 {location.full}
                </p>
              )}
            </div>

            <button
              type="submit"
              disabled={loading || !location.lat}
              className={`mt-4 w-28 py-2 rounded-full text-white font-semibold transition flex justify-center items-center mx-auto ${
                loading || !location.lat
                  ? "bg-gray-400 cursor-not-allowed"
                  : "bg-[#FFB600] hover:bg-[#ffae00]"
              }`}
            >
              {loading ? (
                <>
                  <Loader size="xs" color="white" className="mr-2" /> Signing Up...
                </>
              ) : (
                "SIGN UP"
              )}
            </button>

            <p className="text-sm text-center text-gray-600">
              Already have an account?{" "}
              <button
                type="button"
                onClick={() => setIsLogin(true)}
                className="text-[#FF9119] font-semibold hover:underline"
              >
                Sign In
              </button>
            </p>
          </form>
        )}
      </div>
    </Modal>
  );
}

export default AuthModal;
