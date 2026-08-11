import React, { createContext, useContext, useEffect, useState, useRef } from "react";
import AuthContext from "./AuthContext";
import { CartContext} from "./CartProvider";

export const OrderContext = createContext();

export const OrderProvider = ({ children }) => {
    const { token, user } = useContext(AuthContext);
    const {setCart} = useContext(CartContext)
    const [orders, setOrders] = useState([]);
    const [loading, setLoading] = useState(false);
    const url = import.meta.env.VITE_API_URL;
    const wsUrl = import.meta.env.VITE_WS_URL; 

    const fetchOrders = async () => {
        try {
        setLoading(true);
        const res = await fetch(`${url}/api/orders`, {
            headers: {
            "Content-Type": "application/json",
            Authorization: `Bearer ${token}`,
            },
        });

        if (!res.ok) throw new Error("Failed to fetch orders");
        const data = await res.json();
        setOrders(data.orders || []);
        } catch (err) {
        console.error(err);
        } finally {
        setLoading(false);
        }
    };

    const cancelOrder = async (orderId) => {
        if (!confirm("Are you sure you want to cancel this order?")) return;

        try {
        const res = await fetch(`${url}/api/order/${orderId}/cancel`, {
            method: "POST",
            headers: {
            "Content-Type": "application/json",
            Authorization: `Bearer ${token}`,
            },
        });

        const data = await res.json();
        if (!res.ok) throw new Error(data.message || "Failed to cancel order");

        fetchOrders();
        } catch (err) {
        console.error(err);
        alert(err.message);
        }
    };

    const handleOrderEvent = (msg) => {
        const { event, order } = msg;
        setOrders((prev) => {
            switch (event) {
                case "create":
                return [order, ...prev];
                

                case "update":
                return prev.map((o) => (o.id === order.id ? order : o));

                case "cancelled":
                return prev.map((o) =>
                    o.id === order.id ? { ...o, status: "cancelled" } : o
                );

                case "delete":
                return prev.filter((o) => o.id !== order.id);

                default:
                return prev;
            }
            });
    };

    useEffect(() => {
        if (!token || !user) return;
        const ws = new WebSocket(`${wsUrl}/ws/order/${user?.id}`);


        ws.onopen = () => console.log("WS Connected");
        ws.onmessage = (e) => {
        try {
            const msg = JSON.parse(e.data);
            if (msg.type === "order") handleOrderEvent(msg);
        } catch (err) {
            console.error(err);
        }
        };

        ws.onerror = (err) => {
            console.error("WebSocket error:", err);
        };

        ws.onclose = () => {
            console.log("WebSocket closed");
        };

        return () => {
            ws.close();
        };
        
    }, [user]);

    useEffect(() => {
        if (token) {
            fetchOrders();
        } else {
           
            setOrders([]);

        }
        }, [token]);

    
        const context ={ 
            fetchOrders,
            cancelOrder,
            orders,
            loading,
            setLoading
        }

    return (
        <OrderContext.Provider value={context}>
        {children}
        </OrderContext.Provider>
    );
};
