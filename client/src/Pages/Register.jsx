import React, { useContext, useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Notification, Loader } from "@mantine/core";
import AuthContext from "../Contexts/AuthContext";
import UserLocationMap from "../Components/LeafletMap";
import logo from "../assets/hoc_logo.png";

function Register() {
  const nav = useNavigate();
  const { loginUser } = useContext(AuthContext);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false); // 👈 NEW
  const [showConfirmPassword, setShowConfirmPassword] = useState(false); // 👈 NEW
  const [location, setLocation] = useState({
    lat: null,
    lng: null,
    city: "",
    country: "",
    full: "",
  });

  const RegisterUser = async (e) => {
    e.preventDefault();
    setError("");
    setLoading(true);

    const phone = e.target.phone_number.value.trim();

    if (!/^\d{10}$/.test(phone)) {
      setError("Please enter a valid 10-digit phone number (digits only).");
      setLoading(false);
      setTimeout(() => setError(""), 4000);
      return;
    }

    const url = import.meta.env.VITE_API_URL;

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

      // Auto-login after successful registration
      await loginUser({
        preventDefault: () => {},
        target: {
          email: { value: e.target.email.value },
          password: { value: e.target.password.value },
        },
      });

      nav("/home");
    } catch (err) {
      console.error("Registration error:", err);
      setError(err.message);
    } finally {
      setLoading(false);
      setTimeout(() => setError(""), 4000);
    }
  };


  return (
    <div className="flex min-h-screen bg-[#FFF9F1] font-sans w-full">
      {/* LEFT PANEL */}
      <div className="flex flex-col items-start justify-center flex-1 px-24">
        <h1 className="text-[#B54719] font-extrabold text-5xl leading-none hoc_font">
          CREATE AN
        </h1>
        <h1 className="text-[#FF9119] font-extrabold text-6xl mb-6 leading-none hoc_font">
          ACCOUNT!
        </h1>
        <p className="max-w-xs text-sm text-gray-600">
          Join Click n' Chicks — where your account clicks with endless flavor!
        </p>
      </div>

      {/* RIGHT PANEL (FORM CARD) */}
      <div className="relative flex items-center justify-center flex-1 w-full px-6">
        <div className="w-full p-10 bg-white shadow-md rounded-2xl">
          {/* Logo */}
          <div className="flex justify-center mb-6">
            <img src={logo} alt="Click n' Chick" className="h-20" />
          </div>

          {error && (
            <Notification
              color="red"
              onClose={() => setError("")}
              title="Registration Error"
              className="mb-4"
            >
              {error}
            </Notification>
          )}

          {/* Registration Form */}
          <form onSubmit={RegisterUser} className="flex flex-col space-y-4">
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

            {/* Password Field with Toggle */}
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

            {/* Confirm Password Field with Toggle */}
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
                onClick={() =>
                  setShowConfirmPassword(!showConfirmPassword)
                }
                className="absolute inset-y-0 right-4 flex items-center text-gray-500 hover:text-[#FF9119] text-sm focus:outline-none"
                tabIndex={-1}
              >
                {showConfirmPassword ? "Hide" : "Show"}
              </button>
            </div>

            {/* Phone Field */}
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

            {/* Map Selector */}
            <div className="mt-4">
              <p className="mb-2 ml-2 text-xs text-gray-500">
                Select your location
              </p>
              <div className="w-full h-[220px] rounded-xl overflow-hidden border border-gray-200 shadow-inner">
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

            {/* Submit Button */}
            <button
              type="submit"
              disabled={loading || !location.lat}
              className={`mt-6 w-full py-3 rounded-full text-white font-semibold transition flex justify-center items-center ${
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
          </form>

          {/* Login Redirect */}
          <p className="mt-4 text-sm text-center text-gray-600">
            Already have an account?{" "}
            <a
              href="/login"
              className="text-[#FF9119] font-semibold hover:underline"
            >
              Sign In
            </a>
          </p>
        </div>
      </div>
    </div>
  );
}

export default Register;
