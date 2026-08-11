import React, { useState } from "react";
import {
  Card,
  Group,
  Badge,
  Divider,
  Text,
  Stack,
  Button,
} from "@mantine/core";
import {
  IconClock,
  IconShoppingBag,
  IconX,
} from "@tabler/icons-react";
import CartItemCard from "./CartItemCard"; // adjust path if needed
import OrderDetailsModal from "./OrderDetailsModal";

function OrderCard({ order, statusColors, url, cancelOrder }) {
  if (!order) return null;
  const [opened, setOpened] = useState(false);

  return (
    <Card
      key={order.id}
      shadow="md"
      padding="lg"
      radius="md"
      withBorder
      className="hover:shadow-lg transition-all duration-200"
    >
      {/* ===== Order Header ===== */}
      <Group justify="space-between" mb="xs">
        <Text fw={600} size="lg">
          Order #{order.id}
        </Text>
        <Badge
          color={statusColors?.[order.status] || "gray"}
          size="lg"
          radius="md"
          variant="filled"
        >
          {order.status?.toUpperCase() || "UNKNOWN"}
        </Badge>
      </Group>

      <Text size="sm" c="dimmed">
        Placed on {new Date(order.created_at).toLocaleString()}
      </Text>

      {/* ===== Estimated Time ===== */}
      {order.estimated_time_of_completion > 0 && (
        <Group mt="xs">
          <Badge
            size="lg"
            color="teal"
            leftSection={<IconClock size={16} />}
            radius="lg"
          >
            {order.estimated_time_of_completion} min ETA
          </Badge>
        </Group>
      )}

      <Divider my="sm" />

      {/* ===== Order Type ===== */}
      {order.type && (
        <Group align="center" gap="xs">
          <IconShoppingBag size={18} />
          <Text fw={500}>Order Type:</Text>
          <Text>{order.type}</Text>
        </Group>
      )}

      {/* ===== Items ===== */}
      <Stack gap="xs" mt="md">
        {order.items?.map((item, index) => (
          <CartItemCard
            key={index}
            item={item}
            url={url}
            isOrder
            orderId={order.id}
          />
        ))}
      </Stack>

      <Divider my="sm" />

      {/* ===== Footer Section ===== */}
      <Group justify="space-between" align="center" mt="sm">
        <Text fw={600} size="md">
          Total: ₱{order.total_price || "0.00"}
        </Text>

        

        
          {order.status === "pending" && (
            <Button
              color="red"
              size="xs"
              variant="light"
              leftSection={<IconX size={14} />}
              onClick={() => cancelOrder(order.id)}
            >
              Cancel Order
            </Button>
          )}
          
            <Button
                mt={6}
                color={statusColors[order.status]}
                size="xs"
                onClick={() => setOpened(true)}
                className="mb-2"
                >
                View Details
            </Button>
        
        
      </Group>
      <OrderDetailsModal opened={opened} order={order} setOpened={setOpened} />
    </Card>
  );
}

export default OrderCard;
