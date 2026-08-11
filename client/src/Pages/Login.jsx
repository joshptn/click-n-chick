import React, { useContext, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Notification, Loader } from "@mantine/core";
import AuthContext from "../Contexts/AuthContext";
import loginImage from "../assets/login_image.svg";
import logo from "../assets/hoc_logo.png";

function Login() {
  const { loginUser } = useContext(AuthContext);
  const nav = useNavigate();

  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false); 

  const handleLogin = async (e) => {
    e.preventDefault();
    setLoading(true);
    try {
      const data = await loginUser(e);
      if (data.user.role === "admin") nav("/admin");
      else nav("/home");
    } catch (err) {
      setError(err.message);
      setTimeout(() => setError(""), 4000);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex min-h-screen font-sans bg-[#FFF9F1]">
      {/* Left side - Login Form */}
      <div className="flex flex-col justify-center items-start flex-1 px-20 relative">
        {/* Background circular accents */}
        <div className="absolute inset-0 overflow-hidden">
          <div className="absolute top-[-100px] left-[-100px] w-[700px] h-[700px] rounded-full bg-[#FFF2DC]" />
          <div className="absolute top-[100px] left-[-150px] w-[900px] h-[900px] rounded-full bg-[#FFF9E8]" />
        </div>

        <div className="relative z-10 max-w-md w-full">
          {/* Logo */}
          <img src={logo} alt="Click n' Chick" className="h-20 mb-6" />

          {/* Headings */}
          <h1 className="text-[#B54719] font-extrabold text-[90px] mb-0 leading-none hoc_font">
            GOOD DAY,
          </h1>
          <h2 className="text-[#FF9119] font-extrabold text-[75px] mb-6 leading-none hoc_font">
            BES-TIES!
          </h2>

          {/* Subtext */}
          <p className="text-gray-600 text-sm mb-8">
            Welcome to Click n' Chicks — where your food clicks with your
            cravings!
          </p>

          {error && (
            <Notification
              color="red"
              onClose={() => setError("")}
              title="Login Error"
              className="mb-4"
              disallowClose={false}
            >
              {error}
            </Notification>
          )}

          {/* Login Form */}
          <form onSubmit={handleLogin} className="flex flex-col space-y-4">
            <input
              type="text"
              name="email"
              placeholder="Email"
              className="w-full px-4 py-3 rounded-full border border-gray-300 focus:border-[#FF9119] outline-none text-gray-700 placeholder-gray-400"
              required
              disabled={loading}
            />

            {/* Password Field with Toggle */}
            <div className="relative">
              <input
                type={showPassword ? "text" : "password"}
                name="password"
                placeholder="Password"
                className="w-full px-4 py-3 rounded-full border border-gray-300 focus:border-[#FF9119] outline-none text-gray-700 placeholder-gray-400 pr-12"
                required
                disabled={loading}
              />
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                className="absolute inset-y-0 right-4 flex items-center text-gray-500 hover:text-[#FF9119] focus:outline-none text-sm"
                tabIndex={-1}
              >
                {showPassword ? "Hide" : "Show"}
              </button>
            </div>

            <div className="flex justify-between text-xs text-gray-500">
              <span>
                Don’t have an account?{" "}
                <a
                  href="/register"
                  className="text-[#FF9119] font-semibold hover:underline"
                >
                  Sign Up
                </a>
              </span>
            </div>

            <button
              type="submit"
              disabled={loading}
              className={`mt-4 w-28 py-2 rounded-full text-white font-semibold transition flex justify-center items-center ${
                loading
                  ? "bg-[#FFD364] cursor-not-allowed"
                  : "bg-[#FFB600] hover:bg-[#ffae00]"
              }`}
            >
              {loading ? (
                <>
                  <Loader size="xs" color="white" className="mr-2" /> Loading...
                </>
              ) : (
                "SIGN IN"
              )}
            </button>
          </form>
        </div>
      </div>

      {/* Right Side Image */}
      <div className="hidden md:flex justify-center items-center relative p-4">
        <img
          src={loginImage}
          alt="Login visual"
          className="object-cover h-full w-full rounded-l-3xl"
        />
      </div>
    </div>
  );
}

export default Login;
