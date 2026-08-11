import React from "react";
import {
  Modal,
  ScrollArea,
  Text,
  Divider,
  Button,
  Image,
  Badge,
  Tooltip,
} from "@mantine/core";
import UserLocationMap from "./LeafletMap";
import { Distance } from "../utils/Distance";
import gcash from "../assets/gcash_icon.svg";

// ─── Helpers ──────────────────────────────────────────────────────────────────

const DELIVERY_BASE_KM = 3;
const DELIVERY_BASE_PRICE = 55;
const DELIVERY_EXTRA_PER_KM = 10;

function computeDelivery(lat, lng) {
  if (!lat || !lng) return { dis_price: 0, extra_km: 0, distance: 0 };
  const distance = Distance(lat, lng);
  if (distance <= DELIVERY_BASE_KM) {
    return { dis_price: DELIVERY_BASE_PRICE, extra_km: 0, distance };
  }
  const extra_km = Math.ceil(distance - DELIVERY_BASE_KM);
  return {
    dis_price: DELIVERY_BASE_PRICE + extra_km * DELIVERY_EXTRA_PER_KM,
    extra_km,
    distance,
  };
}

function statusColor(status) {
  switch (status?.toLowerCase()) {
    case "completed":
    case "delivered":
      return "teal";
    case "cancelled":
    case "rejected":
      return "red";
    case "pending":
      return "yellow";
    case "preparing":
    case "processing":
      return "blue";
    default:
      return "gray";
  }
}

function paymentColor(status) {
  switch (status?.toLowerCase()) {
    case "paid":
    case "verified":
      return "teal";
    case "failed":
    case "rejected":
      return "red";
    default:
      return "orange";
  }
}

// ─── Sub-components ───────────────────────────────────────────────────────────

function SectionHeading({ children }) {
  return (
    <Text
      size="xs"
      fw={700}
      tt="uppercase"
      c="dimmed"
      style={{ letterSpacing: "0.08em", marginBottom: 8 }}
    >
      {children}
    </Text>
  );
}

function InfoRow({
  label,
  value,
  highlight,
}) {
  return (
    <div className="flex justify-between items-center py-1">
      <Text size="sm" c="dimmed">
        {label}
      </Text>
      <Text size="sm" fw={highlight ? 700 : 500} c={highlight ? "orange.7" : undefined}>
        {value}
      </Text>
    </div>
  );
}

// ─── Main component ───────────────────────────────────────────────────────────

function OrderDetailsModal({ opened, order, setOpened }) {
  const [proofOpen, setProofOpen] = React.useState(false);

  if (!order) return null;

  const totalPrice = parseFloat(order.total_price ?? 0);
  const isDelivery = order.type !== "pickup";
  const { dis_price, extra_km, distance } = isDelivery
    ? computeDelivery(order.latitude, order.longitude)
    : { dis_price: 0, extra_km: 0, distance: 0 };

  // Subtract Paymongo fee + delivery from total to get food subtotal
  const PAYMONGO_FEE = 30;
  const foodSubtotal = Math.max(0, totalPrice - PAYMONGO_FEE - dis_price);

  const customerName = order.user?.first_name
    ? `${order.user.first_name} ${order.user.last_name ?? ""}`.trim()
    : (order.user?.name ?? "Customer");



  return (
    <>
      <Modal
        opened={opened}
        onClose={() => setOpened(false)}
        centered
        size="100%"
        scrollAreaComponent={ScrollArea.Autosize}
        radius="lg"
        overlayProps={{ opacity: 0.45, blur: 5 }}
        title={
          <div className="flex items-center gap-3">
            <Text fw={800} size="lg" c="gray.9">
              Order #{order.id}
            </Text>
            {order.reference_id ? (
              <Tooltip label="Reference ID" withArrow position="right">
                <Badge
                  variant="light"
                  color="orange"
                  size="sm"
                  style={{ cursor: "default", letterSpacing: "0.04em" }}
                >
                  {order.reference_id}
                </Badge>
              </Tooltip>
            ) : null}
            <Badge variant="light" color={statusColor(order.status)} size="sm">
              {order.status?.toUpperCase() ?? "—"}
            </Badge>
            <Badge variant="light" color={paymentColor(order.payment_status)} size="sm">
              {(order.payment_status ?? "pending").toUpperCase()}
            </Badge>
          </div>
        }
      >
        <div className="flex flex-col gap-6 md:flex-row">
          {/* ── LEFT column: map + billing ── */}
          <div className="flex flex-col gap-4 w-full md:w-2/5">
            {/* Map */}
            <div className="overflow-hidden rounded-xl border border-gray-100 shadow-sm h-56">
              <UserLocationMap
                editMode={false}
                setLocation={() => {}}
                location={{
                  lat: parseFloat(order.latitude) || 14.5995,
                  lng: parseFloat(order.longitude) || 120.9842,
                  full: order.location || "No location provided",
                }}
                user={order.user}
              />
            </div>

            {/* Delivery address */}
            {isDelivery ? (
              <div className="p-4 rounded-xl bg-gray-50 border border-gray-100">
                <SectionHeading>Delivery address</SectionHeading>
                <Text size="sm" c="teal.8" fw={500}>
                  {order.location || "No location provided"}
                </Text>
                <Text size="xs" c="dimmed" mt={4}>
                  {distance.toFixed(2)} km away
                </Text>
              </div>
            ) : (
              <div className="p-3 rounded-xl bg-orange-50 border border-orange-100 flex items-center gap-2">
                <Text size="sm" fw={700} c="orange.7">
                  🏪 Pickup order
                </Text>
              </div>
            )}

            {/* Bill */}
            <div className="p-4 rounded-xl border border-gray-100 bg-white shadow-sm">
              <SectionHeading>Bill summary</SectionHeading>

              <InfoRow label="Food subtotal" value={`₱${foodSubtotal.toFixed(2)}`} />
              <InfoRow label="Paymongo fee" value="₱30.00" />
              {isDelivery ? (
                <InfoRow
                  label={`Delivery fee${extra_km > 0 ? ` (+${extra_km} km)` : ""}`}
                  value={`₱${dis_price.toFixed(2)}`}
                />
              ) : null}

              <Divider my="xs" variant="dashed" />

              <div className="flex justify-between items-center">
                <Text fw={700}>Total</Text>
                <Text fw={800} size="lg" c="orange.6">
                  ₱{totalPrice.toFixed(2)}
                </Text>
              </div>

              <Divider my="xs" />

              {/* Payment method */}
              <div className="flex items-center gap-2 mt-2">
                <div className="w-8 h-8 flex-shrink-0">
                  <Image src={gcash} alt="GCash" />
                </div>
                <div>
                  <Text size="xs" c="dimmed">
                    Paid via
                  </Text>
                  <Text size="sm" fw={600}>
                    {order.payment_method ?? "GCash"}
                  </Text>
                </div>
              </div>
            </div>

            {/* Proof of payment */}
            {order.proof_of_payment ? (
              <div className="p-4 rounded-xl border border-gray-100 bg-white shadow-sm">
                <SectionHeading>Payment proof</SectionHeading>
                <button
                  onClick={() => setProofOpen(true)}
                  className="relative w-full overflow-hidden rounded-lg group cursor-zoom-in"
                  style={{ padding: 0, border: "none", background: "none" }}
                >
                  <Image
                    src={order.proof_of_payment}
                    alt="Payment proof"
                    radius="md"
                    style={{ width: "100%", height: 140, objectFit: "cover" }}
                  />
                  <div className="absolute inset-0 bg-black/20 opacity-0 group-hover:opacity-100 transition-opacity rounded-lg flex items-center justify-center">
                    <span className="px-3 py-1.5 rounded-full bg-black/60 text-white text-xs font-semibold">
                      🔍 View full image
                    </span>
                  </div>
                </button>
              </div>
            ) : (
              <div className="p-3 rounded-xl bg-gray-50 border border-dashed border-gray-200 flex items-center gap-2">
                <Text size="sm" c="dimmed">No payment proof uploaded.</Text>
              </div>
            )}
          </div>

          {/* ── RIGHT column: customer + items ── */}
          <div className="flex flex-col gap-4 flex-1">
            {/* Customer */}
            <div className="p-4 rounded-xl bg-white border border-gray-100 shadow-sm">
              <SectionHeading>Customer</SectionHeading>
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center font-bold text-orange-600 text-lg flex-shrink-0">
                  {customerName.charAt(0).toUpperCase()}
                </div>
                <div>
                  <Text fw={700} size="md">
                    {customerName}
                  </Text>
                  {order.user?.email ? (
                    <Text size="xs" c="dimmed">
                      {order.user.email}
                    </Text>
                  ) : null}
                  {order.user?.phone_number ? (
                    <Text size="xs" c="dimmed">
                      📞 {order.user.phone_number}
                    </Text>
                  ) : null}
                </div>
              </div>

              {/* ETC — visually highlighted */}
              {order.estimated_time_of_completion ? (
                <div className="mt-4 flex items-center gap-3 p-3 rounded-lg bg-orange-50 border border-orange-100">
                  <div className="text-xl">⏱️</div>
                  <div>
                    <Text size="xs" fw={700} tt="uppercase" c="orange.5" style={{ letterSpacing: "0.06em" }}>
                      Est. completion time
                    </Text>
                    <Text fw={800} c="orange.7" size="md">
                      {order.estimated_time_of_completion} min
                    </Text>
                  </div>
                </div>
              ) : null}
            </div>

            {/* Items */}
            <div className="p-4 rounded-xl bg-white border border-gray-100 shadow-sm flex-1 flex flex-col">
              <SectionHeading>
                Ordered items ({order.items?.length ?? 0})
              </SectionHeading>

              <Divider mb="sm" />

              <div className="flex flex-col gap-3 max-h-[320px] overflow-y-auto pr-1">
                {order.items?.length > 0 ? (
                  order.items.map((item) => {
                    const name = item.food?.food_name ?? "Unknown item";
                    const price = parseFloat(item.price ?? 0);
                    const qty = parseInt(item.quantity ?? 0);
                    const subtotal = price * qty;

                    return (
                      <div
                        key={item.id}
                        className="flex items-center gap-3 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition"
                      >
                        {/* ✅ Fixed Image Size */}
                        <Image
                          src={
                            item.food?.thumbnail ||
                            "https://via.placeholder.com/60"
                          }
                          alt={name}
                          radius="md"
                          fit="cover"
                          w={48}
                          h={48}
                          className="flex-shrink-0"
                        />

                        {/* ✅ Prevent text overflow */}
                        <div className="flex-1 min-w-0">
                          <Text size="sm" fw={600} truncate>
                            {name}
                          </Text>
                          <Text size="xs" c="dimmed">
                            ₱{price.toFixed(2)} × {qty}
                          </Text>
                        </div>

                        {/* ✅ Right aligned price (no overlap) */}
                        <div className="text-right flex-shrink-0">
                          <Text size="sm" fw={700}>
                            ₱{subtotal.toFixed(2)}
                          </Text>
                        </div>
                      </div>
                    );
                  })
                ) : (
                  <div className="flex items-center justify-center py-10 text-gray-400">
                    <Text size="sm">No items in this order.</Text>
                  </div>
                )}
              </div>
            </div>

            {/* Note */}
            {order.note ? (
              <div className="p-4 rounded-xl border border-dashed border-amber-200 bg-amber-50">
                <SectionHeading>Customer note</SectionHeading>
                <Text size="sm" c="yellow.9">
                  {order.note}
                </Text>
              </div>
            ) : null}

            <div className="flex justify-end mt-2">
              <Button
                variant="light"
                color="orange"
                radius="xl"
                onClick={() => setOpened(false)}
              >
                Done
              </Button>
            </div>
          </div>
        </div>
      </Modal>

      {/* ── Full-screen proof preview ── */}
      {order.proof_of_payment ? (
        <Modal
          opened={proofOpen}
          onClose={() => setProofOpen(false)}
          centered
          size="xl"
          radius="lg"
          overlayProps={{ opacity: 0.85, blur: 8 }}
          withCloseButton
          title={
            <Text fw={700} size="sm" c="gray.7">
              Payment proof — Order #{order.id}
            </Text>
          }
        >
          <div className="flex flex-col items-center gap-4">
            <Image
              src={order.proof_of_payment}
              alt="Payment proof full"
              fit="contain"
              style={{ maxHeight: "70vh", width: "100%" }}
              radius="md"
            />
            <Button
              variant="light"
              color="gray"
              radius="xl"
              size="sm"
              onClick={() => setProofOpen(false)}
            >
              Close
            </Button>
          </div>
        </Modal>
      ) : null}
    </>
  );
}

export default OrderDetailsModal;