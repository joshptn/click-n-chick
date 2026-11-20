import { api } from "./client";
import { formatPeso } from "../lib/money";

/**
 * A customer's orders and a guest's tracked order, as the web reads them
 * (client/src/lib/orders.js). Both are the API's OrderTrackingResource, so
 * the tracking screen renders the same thing for either.
 */

export type StepState = "done" | "current" | "upcoming";

export type Order = {
  id: number;
  reference: string;
  order_type: "delivery" | "pickup";
  status: string;
  status_label: string;
  is_terminal: boolean;
  is_cancelled: boolean;
  cancellation_reason: string | null;
  steps: { key: string; label: string; state: StepState }[];
  step_index: number | null;
  step_count: number;
  is_advance: boolean;
  scheduled_for: string | null;
  /** Open for payment now (an accepted advance order, BR-16f). */
  awaiting_payment?: boolean;
  /** An advance order's pay-by time: 24 hours, or collection less preparation time for today or tomorrow. */
  payment_due_at?: string | null;
  payment_status?: string | null;
  queue: { number: number | null; label: string | null; in_line: boolean; position: number | null; ahead: number | null };
  cancellation: { refundable?: boolean; refund_amount?: number; code?: string; message?: string } | null;
  can_cancel: boolean;
  details_confirmed_at: string | null;
  can_confirm_details: boolean;
  can_confirm_receipt: boolean;
  placed_at: string | null;
  closed_at: string | null;
  pickup?: { at: string | null };
  delivery?: { full_address: string | null; location: string | null; note: string | null };
  contact: { name: string | null; phone: string | null };
  /** What the customer may still change (BR-21b: only the contact, until the order closes). */
  editable?: { contact_name?: boolean; contact_phone?: boolean };
  items: {
    id: number;
    food_name: string;
    thumbnail: string | null;
    quantity: number;
    unit_price: number;
    subtotal: number;
    addons: { id: number; addon_name: string }[];
  }[];
  subtotal: number;
  discount_amount: number;
  delivery_fee: number;
  total: number;
};

export type OrdersPage = {
  data: Order[];
  meta: { current_page: number; last_page: number; total: number };
  counts: { active: number };
};

/** An answer to cancel, confirm details, confirm receipt or a contact change. */
export type OrderAnswer = { message?: string; order?: Order };

/** Only what changed is sent, as on the web's EditOrderModal. */
export type ContactChanges = { contact_name?: string; contact_phone?: string };

export const ORDERS_KEY = ["orders"] as const;
export const orderKey = (id: number | string) => ["orders", String(id)] as const;
export const guestOrderKey = (token: string) => ["guest-order", token] as const;

export const ordersApi = {
  list: (page: number, signal?: AbortSignal) =>
    api.get<OrdersPage>("/api/orders", { params: { filter: "all", page, per_page: 15 }, signal }),

  active: (signal?: AbortSignal) =>
    api.get<OrdersPage>("/api/orders", { params: { filter: "active", per_page: 1 }, signal }),

  show: (id: number | string, signal?: AbortSignal) => api.get<{ order: Order }>(`/api/orders/${id}`, { signal }),

  cancel: (id: number | string) => api.post<OrderAnswer>(`/api/order/${id}/cancel`),
  confirmDetails: (id: number | string) => api.post<OrderAnswer>(`/api/orders/${id}/confirm-details`),
  confirmReceipt: (id: number | string) => api.post<OrderAnswer>(`/api/orders/${id}/received`),
  updateContact: (id: number | string, changes: ContactChanges) => api.patch<OrderAnswer>(`/api/orders/${id}`, changes),
};

/**
 * A guest's order, named by the token from the tracking link (X-Order-Token),
 * never by an id. No bearer token rides along: these are a guest's requests.
 */
function holding(token: string) {
  return { auth: false, headers: { "X-Order-Token": token } };
}

export const guestOrdersApi = {
  show: (token: string, signal?: AbortSignal) =>
    api.get<{ order: Order; channel: string | null }>("/api/guest/order", { ...holding(token), signal }),
  cancel: (token: string) => api.post<OrderAnswer>("/api/guest/order/cancel", undefined, holding(token)),
  confirmReceipt: (token: string) => api.post<OrderAnswer>("/api/guest/order/received", undefined, holding(token)),
  updateContact: (token: string, changes: ContactChanges) => api.patch<OrderAnswer>("/api/guest/order", changes, holding(token)),
};

/**
 * An advance order's steps as the app shows them (decided 2026-11-19): To pay
 * → Scheduled → Preparing → Ready for pickup → Completed. The API's chain also
 * has Request sent, Accepted and Confirmed; "Request sent" is the
 * request-sent screen's, Accepted is when paying opens, and Confirmed (paid)
 * counts as Scheduled.
 */
const ADVANCE_STEPS = [
  { key: "awaiting_payment", label: "To pay" },
  { key: "scheduled", label: "Scheduled" },
  { key: "preparing", label: "Preparing" },
  { key: "ready_for_pickup", label: "Ready for pickup" },
  { key: "completed", label: "Completed" },
];

const ADVANCE_AT: Record<string, number> = {
  submitted: -1,
  accepted: 0,
  awaiting_payment: 0,
  confirmed: 1,
  scheduled: 1,
  preparing: 2,
  ready_for_pickup: 3,
  completed: 4,
};

/** The steps to draw: the API's for today's orders, the app's own for advance ones. */
export function displaySteps(order: Order): { steps: Order["steps"]; stepIndex: number; stepCount: number } {
  if (!order.is_advance) {
    return { steps: order.steps, stepIndex: order.step_index ?? 0, stepCount: order.step_count };
  }

  const at = isRefused(order) ? -1 : (ADVANCE_AT[order.status] ?? -1);

  return {
    steps: ADVANCE_STEPS.map((step, index) => ({
      ...step,
      state: index < at ? "done" : index === at ? (order.is_terminal ? "done" : "current") : "upcoming",
    })),
    stepIndex: at + 1,
    stepCount: ADVANCE_STEPS.length,
  };
}

/** "1 order ahead of you", or null when the order is not waiting in line. */
export function queueMessage(queue: Order["queue"] | undefined) {
  if (!queue?.in_line) return null;

  const ahead = queue.ahead ?? 0;

  if (ahead <= 0) return { headline: "We're starting your order now", detail: "Nobody is ahead of you." };
  if (ahead === 1) return { headline: "1 order ahead of you", detail: "You're next after this one." };

  return { headline: `${ahead} orders ahead of you`, detail: "We're working through them in order." };
}

/** "2× Chicken Inasal + 1 more". */
export function itemsSummary(items: Order["items"] = []) {
  if (items.length === 0) return "No items";

  const [first, ...rest] = items;
  const head = first.quantity > 1 ? `${first.quantity}× ${first.food_name}` : first.food_name;

  return rest.length === 0 ? head : `${head} + ${rest.length} more`;
}

export function isRefused(order: Pick<Order, "is_cancelled" | "status"> | null | undefined) {
  return Boolean(order?.is_cancelled) || order?.status === "rejected";
}

/** live (orange), done (green) or cancelled (red): the badge and hero colours. */
export function statusTone(order: Order): "live" | "done" | "cancelled" {
  if (isRefused(order)) return "cancelled";
  if (order.is_terminal) return "done";
  return "live";
}

/** The cancel confirmation's wording, from the API's cancellation terms (as on the web). */
export function cancelPrompt(order: Order | null) {
  const terms = order?.cancellation ?? null;
  const withdrawing = order?.status === "submitted";
  const advance = Boolean(order?.is_advance);

  const outcome = terms?.refundable
    ? `You will be refunded ${formatPeso(terms.refund_amount)} in full.`
    : terms?.code === "NOTHING_PAID"
      ? "You have not paid for this, so there is nothing to refund."
      : "The kitchen has already started on this, so it cannot be refunded.";

  const consequence = withdrawing
    ? "The store will not see this request any more."
    : advance
      ? "Your collection date is released and the kitchen will not prepare it."
      : "The kitchen will be told straight away.";

  return {
    action: withdrawing ? "Withdraw request" : "Cancel order",
    title: withdrawing ? "Withdraw this request?" : "Cancel this order?",
    body: `${outcome} ${consequence}${terms?.refundable ? "" : " This cannot be undone."}`,
    confirm: withdrawing ? "Yes, withdraw it" : "Yes, cancel it",
    keep: withdrawing ? "Keep my request" : "Keep my order",
  };
}

/** The feedback email, with the order number filled in (as on the web). */
export function feedbackMailto(order: Order, address = "clicknchick.feedback@gmail.com") {
  const subject = `Feedback on order ${order.reference ?? ""}`.trim();
  const body = `Order: ${order.reference ?? ""}\n\nTell us what went well or what we can do better:\n\n`;

  return `mailto:${address}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
}
