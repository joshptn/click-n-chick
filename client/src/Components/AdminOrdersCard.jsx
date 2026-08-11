import React, { useContext, useEffect, useState, useRef } from "react";
import {
  Card,
  Text,
  Select,
  NumberInput,
  Button,
  Badge,
} from "@mantine/core";
import AuthContext from "../Contexts/AuthContext";
import OrderDetailsModal from "./OrderDetailsModal";

function AdminOrdersCard({ order, statusColors, updateStatus, setOrders }) {
  const url = import.meta.env.VITE_API_URL;
  const { token } = useContext(AuthContext);
  const [etc, setEtc] = useState(order.estimated_time_of_completion || 0);
  const [opened, setOpened] = useState(false);
  const initialETC = useRef(order.estimated_time_of_completion || 0);

  const [location] = useState({
    lat: order.user.latitude,
    lng: order.user.longitude,
    full: order.user.location,
  });

  // 🔹 Update ETC API
  const updateETC = async () => {
    try {
      const res = await fetch(`${url}/api/order/${order.id}/etc`, {
        method: "PUT",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ etc }),
      });

      if (!res.ok) throw new Error("Failed to update ETC");
      const data = await res.json();

      setOrders((prev) =>
        prev.map((o) =>
          o.id === order.id &&
          o.estimated_time_of_completion !==
            data.order.estimated_time_of_completion
            ? {
                ...o,
                estimated_time_of_completion:
                  data.order.estimated_time_of_completion,
              }
            : o
        )
      );

      initialETC.current = data.order.estimated_time_of_completion;
    } catch (err) {
      console.error(err);
      alert("Error updating ETC");
    }
  };

  // 🔹 Debounce ETC updates only if changed
  useEffect(() => {
    if (etc === initialETC.current) return; // no change, skip

    const delay = setTimeout(() => {
      if (etc > 0) updateETC();
    }, 1000);

    return () => clearTimeout(delay);
  }, [etc]);

  // 🔹 Update status API
  const handleUpdateStatus = async (newStatus) => {
    if (newStatus === order.status) return; // no change

    try {
      const res = await fetch(`${url}/api/order/${order.id}/status`, {
        method: "PUT",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ status: newStatus }),
      });

      if (!res.ok) throw new Error("Failed to update status");
      const data = await res.json();

      // Update only if different
      setOrders((prev) =>
        prev.map((o) =>
          o.id === order.id && o.status !== data.order.status
            ? { ...o, status: data.order.status }
            : o
        )
      );
    } catch (err) {
      console.error(err);
      alert("Error updating status");
    }
  };

  return (
    <>
      {/* ================= CARD ================= */}
      <Card
        withBorder
        radius="md"
        shadow="sm"
        className="transition-shadow duration-200 bg-white hover:shadow-md"
        style={{
          borderLeft: `6px solid var(--mantine-color-${statusColors[order.status]}-6)`,
        }}
      >
        <div className="grid grid-cols-[2fr_1fr_1fr_1fr_1fr] items-center gap-2 w-full">
          {/* 1️⃣ ORDER INFO */}
          <div className="flex flex-col">
            <Text fw={700}>Order #{order.id}</Text>
            <Text size="xs" c="dimmed">
              {new Date(order.created_at).toLocaleString()}
            </Text>

            <Text fw={500} mt={4}>
              Customer:{" "}
              <Text span c="blue.8" fw={600}>
                {order.user?.first_name
                  ? `${order.user.first_name} ${order.user.last_name}`
                  : order.user?.name}
              </Text>
            </Text>

            <Text fw={500}>
              Location:{" "}
              <Text span c="teal.8">
                {order.location || "No location provided"}
              </Text>
            </Text>

            <Text size="xs" c="dimmed">
              Lat:{" "}
              <Text span c="orange.8">
                {order.latitude || "N/A"}
              </Text>{" "}
              | Lng:{" "}
              <Text span c="orange.8">
                {order.longitude || "N/A"}
              </Text>
            </Text>

            <Text fw={500}>
              Phone:{" "}
              <Text span c="orange.8">
                {order.user?.phone_number || "N/A"}
              </Text>
            </Text>

            {order.type && (
              <Badge color="gray" variant="light" size="lg" radius="sm" mt={4} fw={500}>
                {order.type}
              </Badge>
            )}
          </div>

          {/* 2️⃣ STATUS */}
          <div className="flex justify-center">
            <Badge color={statusColors[order.status]} variant="filled" size="lg" radius="sm">
              {order.status.toUpperCase()}
            </Badge>
          </div>

          {/* 3️⃣ UPDATE STATUS */}
          <div className="flex justify-center">
            {order.status === "cancelled" ? (
              <Button size="xs" color="gray" disabled radius="sm">
                Cancelled
              </Button>
            ) : (
              <Select
                size="sm"
                data={["pending", "approved", "declined", "completed"]}
                value={order.status}
                onChange={handleUpdateStatus}
                w={150}
                withinPortal
              />
            )}
          </div>

          {/* 4️⃣ ETC */}
          <div className="flex items-center justify-center">
            <NumberInput
              placeholder="ETC"
              min={0}
              w={100}
              value={etc}
              onChange={setEtc}
              size="sm"
            />
            <Text size="xs" ml={6} c="dimmed">
              min
            </Text>
          </div>

          {/* 5️⃣ TOTAL + DETAILS BUTTON */}
          <div className="flex flex-col items-end">
            <Text fw={700} c="green.7" size="lg">
              ₱{order.total_price}
            </Text>
          </div>
        </div>

        <div className="flex flex-row items-end justify-end w-full gap-4">
          <Button
            mt={6}
            color={statusColors[order.status]}
            size="md"
            onClick={() => setOpened(true)}
          >
            View Details
          </Button>
        </div>
      </Card>

      <OrderDetailsModal opened={opened} order={order} setOpened={setOpened} />
    </>
  );
}

export default AdminOrdersCard;