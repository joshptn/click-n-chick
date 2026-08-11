import { useEffect, useState } from "react";
import { useParams, Link } from "react-router-dom";
import { Card, Text, Title, Button, Center, Loader, Stack, Divider } from "@mantine/core";
import { IconCheck, IconX, IconAlertTriangle } from "@tabler/icons-react";
import logo from "../assets/hoc_logo.png";

export default function CheckoutSuccess() {
  const { order_id } = useParams();
  const url = import.meta.env.VITE_API_URL;
  const [status, setStatus] = useState(null);
  const [details, setDetails] = useState(null);

  useEffect(() => {
    async function verifyPayment() {
      try {
        const res = await fetch(`${url}/api/payments/verify/${order_id}`);
        const data = await res.json();
      

        if (data.status === "paid") {
          setStatus("success");
          setDetails(data);
        } else if (data.status === "unpaid") {
          setStatus("failed");
          setDetails(data);
        } else {
          setStatus("error");
        }
      } catch (err) {
        setStatus("error");
      }
    }
    verifyPayment();
  }, [order_id, url]);

  const renderDetails = () => {
    if (!details) return null;

    return (
      <Stack spacing="xs" mt="md" align="center">
        {/* GCash Reference Number */}
        {details.gcash_reference_number && (
          <Text size="sm" fw={600} c="blue">
            GCash Ref No: <b>{details.gcash_reference_number}</b>
          </Text>
        )}

        {/* PayMongo Checkout Reference */}
        {details.reference_number && (
          <Text size="sm" c="dimmed">
            Checkout Ref: <b>{details.reference_number}</b>
          </Text>
        )}

        <Divider my="sm" w="100%" />

        {/* Items */}
        {details.checkout_details?.line_items?.length > 0 && (
          <Stack spacing={4} w="100%" mt="sm">
            <Text fw={500} size="sm">
              Items:
            </Text>
            {details.checkout_details.line_items.map((item, idx) => (
              <Text key={idx} size="sm" c="dimmed">
                • {item.name} — {item.amount / 100}{" "}
                {item.currency?.toUpperCase()} × {item.quantity}
              </Text>
            ))}
          </Stack>
        )}

        {/* Billing */}
        {details.checkout_details?.billing && (
          <Text size="sm" c="dimmed" mt="sm">
            Billed to: {details.checkout_details.billing.name}
          </Text>
        )}
      </Stack>
    );
  };

  const renderContent = () => {
    switch (status) {
      case "success":
        return (
          <Stack align="center" spacing="md">
            <div className="flex justify-center mb-4">
               <img src={logo} alt="Click n' Chick" className="h-16" />
            </div>
            <Title order={2} c="orange">
              Payment Successful!
            </Title>
            <Text c="dimmed" ta="center">
              Thank you for your purchase. Your order #{order_id} has been confirmed.
            </Text>
            {renderDetails()}
            <Button component={Link} to="/home" color="orange" size="md" radius="md">
              Continue Shopping
            </Button>
          </Stack>
        );

      case "failed":
        return (
          <Stack align="center" spacing="md">
            <IconX size={64} color="red" />
            <Title order={2} c="red">
              Payment Failed
            </Title>
            <Text c="dimmed" ta="center">
              We couldn’t confirm your payment for order #{order_id}.
            </Text>
            {renderDetails()}
            <Button component={Link} to="/checkout" color="red" size="md" radius="md">
              Try Again
            </Button>
          </Stack>
        );

      case "error":
        return (
          <Stack align="center" spacing="md">
            <IconAlertTriangle size={64} color="orange" />
            <Title order={2} c="orange">
              Verification Error
            </Title>
            <Text c="dimmed" ta="center">
              Something went wrong while verifying your payment. Please try again.
            </Text>
            <Button component={Link} to="/checkout" color="orange" size="md" radius="md">
              Retry Verification
            </Button>
          </Stack>
        );

      default:
        return (
          <Center>
            <Stack align="center">
              <Loader size="lg" />
              <Text c="dimmed">Verifying payment...</Text>
            </Stack>
          </Center>
        );
    }
  };

  return (
    <Center mih="100vh" bg="gray.0" p="md">
      <Card shadow="md" radius="lg" p="xl" withBorder w={400}>
        {renderContent()}
      </Card>
    </Center>
  );
}
