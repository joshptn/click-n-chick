import React, { useEffect } from "react";
import { useNavigate, useLocation } from "react-router-dom";
import { useContext } from "react";
import AuthContext from "../Contexts/AuthContext";

function Loading() {
  const { user, fetchingUser } = useContext(AuthContext);
  const nav = useNavigate();
  const location = useLocation();

  useEffect(() => {
    if (!fetchingUser) {
      if (user) {
      
        const from = location.state?.from?.pathname;
        if (from) {
          nav(from, { replace: true });
        } else {
         
          nav(user.role === "admin" ? "/admin" : "/", { replace: true });
        }
      } else {
        nav("/login", { replace: true });
      }
    }
  }, [fetchingUser, user, nav, location]);

  return (
    <div className="flex items-center justify-center h-screen">
      <p className="text-lg font-semibold">Loading...</p>
    </div>
  );
}

export default Loading;
