import { useContext, useEffect, useState } from "react";
import { Navigate, Outlet, useNavigate } from "react-router-dom";
import AuthContext from "../Contexts/AuthContext";
import { Loader, Center } from "@mantine/core";

function AdminRoutes() {
  const { user, setUser, token } = useContext(AuthContext);
  const [loading, setLoading] = useState(true);
  const nav = useNavigate();
  const url = import.meta.env.VITE_API_URL;

  const getUserDetails = async () => {
    setLoading(true);

    if (token) {
      try {
        const result = await fetch(`${url}/api/user`, {
          headers: {
            "Content-Type": "application/json",
            Authorization: `Bearer ${token}`,
          },
        });

        if (result.ok) {
          const data = await result.json();
          setUser(data);
        } else {
          console.error("User fetch failed");
          setUser(null);
          if (!["/login", "/register"].includes(window.location.pathname)) {
            nav("/login");
          }
        }
      } catch (err) {
        console.error("Error fetching user", err);
        setUser(null);
        if (!["/login", "/register"].includes(window.location.pathname)) {
          nav("/login");
        }
      }
    } else {
      setUser(null);
      if (!["/login", "/register"].includes(window.location.pathname)) {
        nav("/login");
      }
    }

    setLoading(false);
  };

  useEffect(() => {
    getUserDetails();
  }, []);

  
  if (loading) {
    return (
      <Center style={{ height: "100vh" }}>
        <Loader color="orange" size="lg" />
      </Center>
    );
  }

 
  return user?.role === "admin" ? <Outlet /> : <Navigate to="/unauthorized" replace />;
}

export default AdminRoutes;
