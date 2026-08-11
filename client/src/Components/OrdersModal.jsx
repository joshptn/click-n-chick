import React, { useContext } from "react";
import {
  Modal,
  Loader,
  Text,
  Stack,
  Title,
} from "@mantine/core";
import { OrderContext } from "../Contexts/Orderprovider";
import OrderCard from "./OrderCard";

export default function OrdersModal({ opened, onClose }) {
  const { cancelOrder, orders, loading } = useContext(OrderContext);
  const url = import.meta.env.VITE_API_URL;

  const statusColors = {
    pending: "yellow",
    approved: "blue",
    declined: "red",
    completed: "green",
    cancelled: "gray",
  };

  return (
    <Modal
      opened={opened}
      onClose={onClose}
      title={<Title order={3}>Your Orders</Title>}
      size="lg"
      centered
      overlayProps={{
        opacity: 0.55,
        blur: 3,
      }}
    >
      {loading ? (
        <div className="flex justify-center items-center py-10">
          <Loader color="blue" size="lg" />
        </div>
      ) : orders && orders.length > 0 ? (
        <Stack gap="md" className="w-full">
          {orders.map((order) => (
            <OrderCard
              key={order.id}
              order={order}
              statusColors={statusColors}
              url={url}
              cancelOrder={cancelOrder}
            />
          ))}
        </Stack>
      ) : (
        <Text ta="center" c="dimmed">
          No orders found.
        </Text>
      )}
    </Modal>
  );
}
