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
  /** "007", "A-007", "#29" before payment, or "Order for Sunday, November 22 at 3:00 AM" for an advance request. */
  reference: string;
  /** "Order 007", or the advance request's "Order for ..." (decided 2026-11-20). */
  title: string;
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
  /** The payment that paid for it and its refund (decided 2026-11-23: the payment is the only record). */
  payment?: OrderPayment;
  /** "Payment received, but your order was cancelled", or "You paid twice": for the payment screen. */
  payment_notice?: PaymentNotice | null;
  cancellation: {
    refundable?: boolean;
    refund_amount?: number;
    code?: string;
    message?: string;
    /** A placed order may be cancelled from then: the store's 15 minutes from payment (decided 2026-11-23). */
    cancel_from?: string | null;
  } | null;
  can_cancel: boolean;
  /** Cancelled by the store rather than by the customer. */
  cancelled_by_store?: boolean;
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
  /** Everything the Senior/PWD discount took off, VAT exemption included. */
  discount_amount: number;
  /** The VAT part of discount_amount (decided 2026-11-23). */
  vat_exempt_amount: number;
  delivery_fee: number;
  /** Paid with loyalty points (decided 2026-11-24). */
  points_amount?: number;
  total: number;
};

export type Refund = {
  payment_id: number;
  amount: number;
  /** refunding (GCash, with Xendit), by_hand (QR Ph, or what Xendit refused), refunded, none (the store chose no refund). */
  state: "refunding" | "by_hand" | "refunded" | "none";
  method: string | null;
  reference: string | null;
  refunded_at: string | null;
};

export type OrderPayment = {
  paid: boolean;
  method: string | null;
  paid_at: string | null;
  amount: number | null;
  refund: Refund | null;
};

export type PaymentNotice = { kind: "cancelled" | "extra"; title: string; body: string; message: string };

export type OrdersPage = {
  data: Order[];
  meta: { current_page: number; last_page: number; total: number };
  /** today and advance come with the My Orders tabs (decided 2026-11-26). */
  counts: { active: number; today?: number; advance?: number };
};

/** An answer to cancel, confirm details, confirm receipt or a contact change. */
export type OrderAnswer = { message?: string; order?: Order };

/**
 * Only what changed is sent, as on the web's EditOrderModal. contact_email is
 * a guest's only: the address their tracking link goes to (FR-02.13b).
 */
export type ContactChanges = { contact_name?: string; contact_phone?: string; contact_email?: string };

/**
 * A guest's resend card (FR-02.13b, decided 2026-11-22); null once the order
 * has closed. resend_until is added here, when the answer arrives: the wait
 * as a moment on this phone's clock (withLinkDeadline).
 */
export type TrackingLink = {
  email: string | null;
  resend_in: number;
  resends_left: number;
  resend_until?: number;
};

export type GuestOrderAnswer = { order: Order; channel: string | null; link: TrackingLink | null };

export type LinkAnswer = { message?: string; link?: TrackingLink | null };

export function withLinkDeadline(link: TrackingLink | null | undefined): TrackingLink | null {
  return link ? { ...link, resend_until: Date.now() + (link.resend_in ?? 0) * 1000 } : null;
}

export const ORDERS_KEY = ["orders"] as const;

/**
 * The Orders tab's lists, as on the web (decided 2026-11-26). Today and
 * Advance keep an order until its day has passed, whatever became of it; Past
 * holds completed and delivered orders after that; Cancelled holds every
 * cancelled, declined or rejected order at once. The API decides which is which.
 */
export type OrderTab = "today" | "advance" | "past" | "cancelled";

export const ORDER_TABS: { key: OrderTab; label: string }[] = [
  { key: "today", label: "Today" },
  { key: "advance", label: "Advance" },
  { key: "past", label: "Past" },
  { key: "cancelled", label: "Cancelled" },
];

const PAYMENT_METHODS: Record<string, string> = {
  gcash: "GCash",
  qrph: "QR Ph",
  qr_ph: "QR Ph",
  maya: "Maya",
  paymaya: "Maya",
  card: "Card",
  grab_pay: "GrabPay",
};

/** A card's status: an advance order waiting to be paid reads "To pay". */
export function cardStatusLabel(order: Order) {
  if (order.is_advance && (order.status === "accepted" || order.status === "awaiting_payment")) return "To pay";

  return order.status_label;
}

/** The small line above a card's total: where the order stands on money. */
export function cardMoneyNote(order: Order) {
  if (order.status === "rejected") return "Declined by the store";
  if (order.is_cancelled) return order.cancelled_by_store ? "Cancelled by the store" : "Cancelled";

  if (order.is_advance) {
    if (order.status === "submitted") return "Waiting for the store's response";
    if (order.awaiting_payment || order.status === "accepted") return "Waiting for payment";
  }

  if (Number(order.points_amount) > 0 && Number(order.total) === 0) return "Paid with points";

  const method = order.payment?.method;

  return method ? `Paid through ${PAYMENT_METHODS[method.toLowerCase()] ?? method}` : "";
}

/** How many dishes, counting quantities: "3 items". */
export function itemCount(items: Order["items"] = []) {
  const count = items.reduce((sum, item) => sum + Number(item.quantity ?? 0), 0);

  return `${count} item${count === 1 ? "" : "s"}`;
}
export const orderKey = (id: number | string) => ["orders", String(id)] as const;
export const guestOrderKey = (token: string) => ["guest-order", token] as const;

export const ordersApi = {
  list: (page: number, signal?: AbortSignal) =>
    api.get<OrdersPage>("/api/orders", { params: { filter: "all", page, per_page: 15 }, signal }),

  /** One of the Orders tab's lists (the web's My Orders, decided 2026-11-26). */
  tab: (tab: OrderTab, page: number, signal?: AbortSignal) =>
    api.get<OrdersPage>("/api/orders", { params: { filter: tab, page, per_page: 15 }, signal }),

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
  show: async (token: string, signal?: AbortSignal): Promise<GuestOrderAnswer> => {
    const answer = await api.get<GuestOrderAnswer>("/api/guest/order", { ...holding(token), signal });

    return { ...answer, link: withLinkDeadline(answer.link) };
  },
  cancel: (token: string) => api.post<OrderAnswer>("/api/guest/order/cancel", undefined, holding(token)),
  confirmReceipt: (token: string) => api.post<OrderAnswer>("/api/guest/order/received", undefined, holding(token)),
  updateContact: (token: string, changes: ContactChanges) => api.patch<OrderAnswer>("/api/guest/order", changes, holding(token)),
  /** Email the tracking link again: once per 30 seconds, 10 per order (FR-02.13b). */
  resendLink: (token: string) => api.post<LinkAnswer>("/api/guest/order/link", undefined, holding(token)),
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

/**
 * A placed order's Senior/PWD saving as receipt lines (decided 2026-11-23, as on
 * the web): the VAT exemption, then the discount itself. discount_amount is
 * everything taken off; an order discounted before the VAT exemption has no VAT line.
 */
export function discountLines(order: Pick<Order, "discount_amount" | "vat_exempt_amount">): { key: string; label: string; amount: number }[] {
  const total = Number(order.discount_amount) || 0;

  if (total <= 0) return [];

  const vat = Math.min(Number(order.vat_exempt_amount) || 0, total);
  const lines = vat > 0 ? [{ key: "vat", label: "VAT exemption", amount: vat }] : [];

  return [...lines, { key: "discount", label: "Senior/PWD discount", amount: Math.round((total - vat) * 100) / 100 }];
}

/**
 * The refund under a cancelled paid order's total (decided 2026-11-23, as on
 * the web): "Refund pending", "Refunded", or "Refunded amount" ₱0.00 when the
 * store chose no refund. Null for an order that is not cancelled or never paid.
 */
export function refundLine(order: Order): { label: string; amount: number } | null {
  if (!order.is_cancelled || !order.payment?.paid) return null;

  const refund = order.payment.refund;

  if (refund?.state === "refunded") return { label: "Refunded", amount: refund.amount };
  if (refund?.state === "refunding" || refund?.state === "by_hand") return { label: "Refund pending", amount: refund.amount };

  return { label: "Refunded amount", amount: 0 };
}

/** The cancel confirmation's wording, from the API's cancellation terms (as on the web). */
export function cancelPrompt(order: Order | null) {
  const terms = order?.cancellation ?? null;
  const withdrawing = order?.status === "submitted";
  const advance = Boolean(order?.is_advance);

  // The only other answer a customer can cancel with (decided 2026-11-17).
  const outcome = terms?.refundable
    ? `You will be refunded ${formatPeso(terms.refund_amount)} in full.`
    : "You have not paid for this, so there is nothing to refund.";

  const consequence = withdrawing
    ? "The store will not see this request any more."
    : advance
      ? "Your collection date is released and the kitchen will not prepare it."
      : "The kitchen will be told straight away.";

  return {
    action: withdrawing ? "Withdraw request" : "Cancel order",
    title: withdrawing ? "Withdraw this request?" : "Cancel this order?",
    body: `${outcome} ${consequence}${terms?.refundable ? "" : " This cannot be undone."}`,
    confirm: withdrawing ? "Withdraw request" : "Cancel order",
    keep: withdrawing ? "Keep my request" : "Keep my order",
  };
}

/** The feedback email, with the order number filled in (as on the web). */
export function feedbackMailto(order: Order, address = "clicknchick.feedback@gmail.com") {
  const title = order.title ?? `Order ${order.reference ?? ""}`.trim();
  const subject = `Feedback on ${title.replace(/^Order/, "order")}`;
  const body = `${title}\n\nTell us what went well or what we can do better:\n\n`;

  return `mailto:${address}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
}
