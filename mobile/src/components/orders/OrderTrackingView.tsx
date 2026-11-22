import { useState, type ComponentType } from "react";
import { Alert, Linking, Pressable, RefreshControl, ScrollView, StyleSheet, View } from "react-native";
import { router } from "expo-router";
import { SafeAreaView } from "react-native-safe-area-context";

import { ApiError } from "../../api/client";
import {
  cancelPrompt,
  feedbackMailto,
  isRefused,
  statusTone,
  type ContactChanges,
  type Order,
  type TrackingLink,
} from "../../api/orders";
import { formatPeso } from "../../lib/money";
import { formatDay, formatTime } from "../../lib/time";
import { toast } from "../../lib/toast";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import {
  IconAlertTriangle,
  IconArrowLeft,
  IconCalendarEvent,
  IconChefHat,
  IconCircleCheck,
  IconClock,
  IconCreditCard,
  IconInfoCircle,
  IconLock,
  IconMail,
  IconMapPin,
  IconReceipt,
  IconScooter,
  IconSend,
  IconShoppingBag,
  IconUser,
} from "../icons";
import { Sheet } from "../account/Sheet";
import { ContactEditor } from "./ContactEditor";
import { GREEN, OrderProgress } from "./OrderProgress";
import { TrackingLinkCard } from "./TrackingLinkCard";

type IconProps = { size?: number; color?: string; strokeWidth?: number };
type Hero = { icon: ComponentType<IconProps>; title: string; body: string };

/** The status card's words, as the web's OrderStatusPanel. */
const HERO: Record<string, Hero> = {
  placed: { icon: IconReceipt, title: "Order placed!", body: "We have your order and are confirming it with the store." },
  confirmed: { icon: IconCircleCheck, title: "Order confirmed!", body: "The store has accepted your order. The kitchen starts on it next." },
  preparing: { icon: IconChefHat, title: "We're cooking!", body: "Your food is being prepared right now." },
  ready_for_pickup: { icon: IconShoppingBag, title: "Ready for pickup!", body: "Come and collect it at the counter. Show your order number." },
  on_the_way: { icon: IconScooter, title: "On the way!", body: "Your order has left the store and is heading to you." },
  completed: { icon: IconCircleCheck, title: "Order completed!", body: "Thanks for collecting. Enjoy your meal!" },
  delivered: { icon: IconCircleCheck, title: "Order delivered!", body: "Your food has arrived. Enjoy your meal!" },
  cancelled: { icon: IconAlertTriangle, title: "Order cancelled", body: "This order will not be prepared." },
};

const ADVANCE_HERO: Record<string, Hero> = {
  // "Request sent" itself is the request-sent screen's (decided 2026-11-19).
  submitted: { icon: IconSend, title: "Waiting for the store", body: "A Store Agent is checking whether the kitchen can make this on your date." },
  accepted: { icon: IconCircleCheck, title: "Your request was accepted!", body: "Pay by the time shown to lock in your collection date. If it is not paid by then, the store will cancel it." },
  awaiting_payment: {
    icon: IconCreditCard,
    title: "Ready for payment",
    body: "Pay by the time shown to lock in your collection date. If it is not paid by then, the store will cancel it.",
  },
  confirmed: { icon: IconCircleCheck, title: "Payment received!", body: "Your date is booked. Nothing happens in the kitchen until then." },
  scheduled: { icon: IconCalendarEvent, title: "You're booked in", body: "We start preparing on your collection date. There is nothing to do until then." },
  rejected: { icon: IconAlertTriangle, title: "Request declined", body: "The store could not take this one on. Nothing was charged." },
};

export const TONES = {
  live: { badge: "#fde8d8", text: "#d4500e", ring: "#ffe3c8", dot: colors.orange },
  done: { badge: "#e3f6ea", text: GREEN, ring: "#d5f0de", dot: GREEN },
  cancelled: { badge: colors.dangerSurface, text: colors.danger, ring: "#fde0df", dot: colors.danger },
} as const;

export function StatusBadge({ order }: { order: Order }) {
  const tone = TONES[statusTone(order)];

  return (
    <View style={[styles.badge, { backgroundColor: tone.badge }]}>
      <View style={[styles.badgeDot, { backgroundColor: tone.text }]} />
      <AppText family="display" weight="medium" size={12} color={tone.text}>
        {order.status_label}
      </AppText>
    </View>
  );
}

type Props = {
  order: Order | null;
  isLoading: boolean;
  error: unknown;
  guest?: boolean;
  refreshing: boolean;
  onRefresh: () => void;
  onCancel: () => Promise<unknown>;
  onConfirmReceipt: () => void;
  onConfirmDetails?: () => void;
  cancelling: boolean;
  confirmingReceipt: boolean;
  confirmingDetails?: boolean;
  /** Pay for an accepted advance order (customers only), after Review Advance Order. */
  onPay?: () => void;
  paying?: boolean;
  /** Change the contact name and number (BR-21b); rejects with the API's message. */
  onUpdateContact?: (changes: ContactChanges) => Promise<unknown>;
  /** A guest's resend card (FR-02.13b): its details, null once the order has closed. */
  trackingLink?: TrackingLink | null;
  onResendLink?: () => Promise<unknown>;
  onChangeLinkEmail?: (email: string) => Promise<unknown>;
};

/**
 * Order tracking (designs of 2026-11-17), with the web's behaviour: the
 * steps, the status in words, confirming details and receipt,
 * cancelling on the API's terms, the order itself, and feedback by email.
 * Shared by a customer's order and a guest's tracking link.
 */
export function OrderTrackingView({
  order,
  isLoading,
  error,
  guest = false,
  refreshing,
  onRefresh,
  onCancel,
  onConfirmReceipt,
  onConfirmDetails,
  cancelling,
  confirmingReceipt,
  confirmingDetails = false,
  onPay,
  paying = false,
  onUpdateContact,
  trackingLink = null,
  onResendLink,
  onChangeLinkEmail,
}: Props) {
  // Review Advance Order (decided 2026-11-20): on every Pay now, before Xendit.
  const [reviewing, setReviewing] = useState(false);

  const back = () => (router.canGoBack() ? router.back() : router.replace(guest ? "/home" : "/notifications"));

  const confirmCancel = () => {
    if (!order) return;

    const prompt = cancelPrompt(order);

    Alert.alert(prompt.title, prompt.body, [
      { text: prompt.keep, style: "cancel" },
      { text: prompt.confirm, style: "destructive", onPress: () => void onCancel().catch(() => {}) },
    ]);
  };

  const sendFeedback = async () => {
    if (!order) return;

    try {
      await Linking.openURL(feedbackMailto(order));
    } catch {
      toast.error("No email app found", "Write to clicknchick.feedback@gmail.com with your order number.");
    }
  };

  return (
    <SafeAreaView style={styles.safe} edges={["top", "left", "right"]}>
      <View style={styles.header}>
        <Pressable accessibilityRole="button" accessibilityLabel="Back" onPress={back} style={styles.back} hitSlop={6} testID="order-back">
          <IconArrowLeft size={18} color={colors.authInk} strokeWidth={2.2} />
        </Pressable>
        <AppText family="display" weight="semibold" size={19} color={colors.authInk} style={styles.reference} numberOfLines={1}>
          {order?.reference ?? "Your order"}
        </AppText>
        {order && <StatusBadge order={order} />}
      </View>

      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} colors={[colors.orange]} />}
      >
        {isLoading && (
          <>
            <View style={[styles.skeleton, { height: 130 }]} />
            <View style={[styles.skeleton, { height: 220 }]} />
          </>
        )}

        {!order && !isLoading && error ? <LoadError error={error} guest={guest} onBack={back} /> : null}

        {order && (
          <>
            <OrderProgress order={order} />
            <StatusCard order={order} />

            {order.is_advance && order.awaiting_payment && onPay && (
              <>
                <PayCard order={order} paying={paying} onPay={() => setReviewing(true)} />
                <Sheet visible={reviewing} title="Review Advance Order" onClose={() => setReviewing(false)} testID="review-advance-order">
                  <View style={styles.reviewBody}>
                    <OrderItems order={order} />
                    <View style={styles.reviewNotice}>
                      <IconAlertTriangle size={16} color={colors.brand800} strokeWidth={2.2} />
                      <AppText family="display" weight="semibold" size={12.5} color={colors.brand800} style={styles.flex}>
                        No cancellation and refund after the store confirms your payment.
                      </AppText>
                    </View>
                    <Pressable
                      accessibilityRole="button"
                      onPress={() => {
                        setReviewing(false);
                        onPay();
                      }}
                      style={({ pressed }) => [styles.action, { backgroundColor: colors.orange }, pressed && styles.pressed]}
                      testID="review-continue"
                    >
                      <IconLock size={16} color={colors.white} strokeWidth={2.2} />
                      <AppText family="display" weight="semibold" size={13.5} color={colors.white}>
                        Continue to Payment
                      </AppText>
                    </Pressable>
                  </View>
                </Sheet>
              </>
            )}

            {order.can_confirm_receipt && (
              <View style={[styles.card, styles.receiptCard]}>
                <AppText family="display" weight="semibold" size={14} color="#2c6b3f">
                  Has your order arrived?
                </AppText>
                <AppText family="display" size={12.5} color="#3d7a52">
                  Let us know once you have it, so we can close the order off. No rush: the store can also confirm it for you.
                </AppText>
                <ActionButton
                  label={confirmingReceipt ? "Confirming..." : "Yes, I received my order"}
                  onPress={onConfirmReceipt}
                  disabled={confirmingReceipt}
                  color={GREEN}
                  testID="confirm-receipt"
                />
              </View>
            )}

            {!guest && order.can_confirm_details && onConfirmDetails && (
              <View style={styles.card}>
                <AppText family="display" size={12.5} color={colors.ink500}>
                  Everything correct? Tell us and we can get started sooner. You can still cancel for a full refund until the
                  kitchen begins.
                </AppText>
                <ActionButton
                  label={confirmingDetails ? "Sending..." : "Details are correct"}
                  onPress={onConfirmDetails}
                  disabled={confirmingDetails}
                  color={colors.orange}
                  testID="confirm-details"
                />
              </View>
            )}

            {/* Since 2026-11-20 only Review Advance Order writes this, before paying. */}
            {order.details_confirmed_at && !order.is_terminal && !order.is_advance && (
              <View style={styles.noteRow}>
                <IconCircleCheck size={13} color={GREEN} strokeWidth={2.2} />
                <AppText family="display" size={12} color={GREEN}>
                  You confirmed these details. The kitchen can start any time.
                </AppText>
              </View>
            )}

            {onUpdateContact && !order.is_terminal && (order.editable?.contact_name || order.editable?.contact_phone) && (
              <ContactEditor order={order} onSave={onUpdateContact} />
            )}

            <OrderItems order={order} />

            {guest && trackingLink && onResendLink && onChangeLinkEmail && !order.is_terminal && (
              <TrackingLinkCard link={trackingLink} onResend={onResendLink} onChangeEmail={onChangeLinkEmail} />
            )}

            {order.can_cancel && !order.is_terminal && <CancelCard order={order} cancelling={cancelling} onCancel={confirmCancel} />}

            <View style={styles.card}>
              <View style={styles.feedbackHead}>
                <View style={styles.mailIcon}>
                  <IconMail size={19} color={colors.orange} strokeWidth={2} />
                </View>
                <View style={styles.flex}>
                  <AppText family="display" weight="semibold" size={14} color={colors.authInk}>
                    Need Help or Want to Share Your Experience?
                  </AppText>
                  <AppText family="display" size={12} color={colors.ink500} style={styles.feedbackText}>
                    Have concerns, complaints, suggestions, or positive feedback? We&apos;d love to hear from you. Your order
                    number is filled in for you.
                  </AppText>
                </View>
              </View>
              <Pressable accessibilityRole="button" onPress={sendFeedback} style={styles.feedbackButton} testID="feedback-email">
                <IconMail size={16} color={colors.ink300} strokeWidth={2} />
                <AppText family="display" weight="medium" size={13.5} color={colors.ink300}>
                  Feedback Email
                </AppText>
              </Pressable>
            </View>

            {order.is_terminal && (
              <Pressable
                accessibilityRole="button"
                onPress={() => router.navigate("/home")}
                style={({ pressed }) => [styles.again, pressed && styles.pressed]}
                testID="order-again"
              >
                <AppText family="display" weight="semibold" size={15} color={colors.orange}>
                  Order Again
                </AppText>
              </Pressable>
            )}
          </>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

function StatusCard({ order }: { order: Order }) {
  const hero = (order.is_advance ? ADVANCE_HERO[order.status] : undefined) ?? HERO[order.status] ?? HERO.placed;
  const tone = TONES[statusTone(order)];
  const Icon = hero.icon;
  const refused = isRefused(order);
  const collectAt = order.is_advance && order.scheduled_for ? `${formatDay(order.scheduled_for)}, ${formatTime(order.scheduled_for)}` : null;

  return (
    <View style={[styles.card, styles.hero]}>
      {/* The design's confetti, only for a finished order. */}
      {order.is_terminal && !refused && (
        <>
          <View style={[styles.confetti, { top: 18, left: 26, backgroundColor: "#ffb36b" }]} />
          <View style={[styles.confetti, { top: 14, right: 70, backgroundColor: "#ffc98f", width: 6, height: 6 }]} />
          <View style={[styles.confetti, { top: 104, left: 46, backgroundColor: "#ff9b3d", width: 5, height: 5 }]} />
          <View style={[styles.confetti, { bottom: 30, right: 36, backgroundColor: "#ffb36b" }]} />
        </>
      )}

      <View style={[styles.ring, { backgroundColor: tone.ring }]}>
        <View style={[styles.ringDot, { backgroundColor: tone.dot }]}>
          <Icon size={28} color={colors.white} strokeWidth={2.2} />
        </View>
      </View>

      <AppText family="display" weight="bold" size={22} color={colors.authInk} style={styles.center}>
        {hero.title}
      </AppText>
      <AppText family="display" size={13.5} color={colors.ink500} style={styles.center}>
        {refused && order.cancellation_reason ? order.cancellation_reason : hero.body}
      </AppText>

      {collectAt && !refused && (
        <View style={styles.infoBox}>
          <IconCalendarEvent size={18} color={colors.orange} strokeWidth={2} />
          <View>
            <AppText family="display" weight="semibold" size={10.5} color={colors.ink300}>
              COLLECTION
            </AppText>
            <AppText family="display" weight="semibold" size={13.5} color={colors.authInk}>
              {collectAt}
            </AppText>
          </View>
        </View>
      )}

    </View>
  );
}

/**
 * An accepted advance order waiting on its payment (BR-16f; the web's
 * PaymentPanel): Xendit's page, the same one while it is still open.
 */
function PayCard({ order, paying, onPay }: { order: Order; paying: boolean; onPay: () => void }) {
  const due = order.payment_due_at ? `${formatDay(order.payment_due_at)}, ${formatTime(order.payment_due_at)}` : null;

  return (
    <View style={[styles.card, styles.payCard]} testID="pay-card">
      <View style={styles.payHead}>
        <View style={styles.payIcon}>
          <IconCreditCard size={19} color={colors.white} strokeWidth={2} />
        </View>
        <View style={styles.flex}>
          <AppText family="display" weight="bold" size={15} color={colors.authInk}>
            Pay {formatPeso(order.total)} to lock in your date
          </AppText>
          <AppText family="display" size={12.5} color={colors.ink500}>
            Your collection date is booked once payment goes through.
          </AppText>
        </View>
      </View>
      {due && (
        <View style={styles.payDue}>
          <IconClock size={14} color={colors.brand800} strokeWidth={2.2} />
          <AppText family="display" weight="semibold" size={12.5} color={colors.brand800}>
            Pay by {due}
          </AppText>
        </View>
      )}
      <AppText family="display" size={12} color={colors.ink500}>
        If it is not paid by then, the store will cancel it.
      </AppText>
      <Pressable
        accessibilityRole="button"
        accessibilityState={{ busy: paying, disabled: paying }}
        disabled={paying}
        onPress={onPay}
        style={({ pressed }) => [styles.action, { backgroundColor: colors.orange }, (pressed || paying) && styles.pressed]}
        testID="pay-now"
      >
        <IconLock size={16} color={colors.white} strokeWidth={2.2} />
        <AppText family="display" weight="semibold" size={13.5} color={colors.white}>
          {paying ? "Opening payment..." : "Pay now with QR Ph or GCash"}
        </AppText>
      </Pressable>
    </View>
  );
}

function OrderItems({ order }: { order: Order }) {
  const delivery = order.order_type === "delivery";
  const where = delivery
    ? order.delivery?.full_address || order.delivery?.location
    : order.pickup?.at
      ? `Pick up at ${formatTime(order.pickup.at)}`
      : null;

  return (
    <View style={styles.card}>
      <AppText family="display" weight="semibold" size={11} color={colors.ink300} style={styles.eyebrow}>
        YOUR ORDER
      </AppText>

      {order.items.map((line, index) => (
        <View key={line.id} style={[styles.itemRow, index > 0 && styles.dashed]}>
          <View style={styles.flex}>
            <AppText family="display" weight="medium" size={14} color={colors.authInk}>
              {line.food_name}
            </AppText>
            <AppText family="display" size={11.5} color={colors.ink300}>
              {line.quantity}×{line.addons.length > 0 ? ` (${line.addons.map((addon) => addon.addon_name).join(", ")})` : ""}
            </AppText>
          </View>
          <AppText family="display" weight="semibold" size={14} color={colors.orange}>
            {formatPeso(line.subtotal)}
          </AppText>
        </View>
      ))}

      <View style={styles.totals}>
        {order.delivery_fee > 0 && <TotalRow label="Delivery fee" value={formatPeso(order.delivery_fee)} />}
        {order.discount_amount > 0 && <TotalRow label="Discount" value={`-${formatPeso(order.discount_amount)}`} />}
        <TotalRow label="Total" value={formatPeso(order.total)} strong />
      </View>

      {order.contact.name || order.contact.phone ? (
        <View style={styles.whereRow} testID="order-contact">
          <IconUser size={14} color={colors.ink300} strokeWidth={2} />
          <AppText family="display" size={12} color={colors.ink500} style={styles.flex}>
            {[order.contact.name, order.contact.phone].filter(Boolean).join(" · ")}
          </AppText>
        </View>
      ) : null}

      {where ? (
        <View style={styles.whereRow}>
          {delivery ? (
            <IconMapPin size={14} color={colors.ink300} strokeWidth={2} />
          ) : (
            <IconShoppingBag size={14} color={colors.ink300} strokeWidth={2} />
          )}
          <AppText family="display" size={12} color={colors.ink500} style={styles.flex}>
            {delivery ? `Delivery to ${where}` : where}
          </AppText>
        </View>
      ) : null}

    </View>
  );
}

function TotalRow({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
  return (
    <View style={styles.totalRow}>
      <AppText family="display" weight={strong ? "semibold" : "regular"} size={strong ? 14 : 12.5} color={strong ? colors.authInk : colors.ink500}>
        {label}
      </AppText>
      <AppText family="display" weight={strong ? "bold" : "regular"} size={strong ? 15 : 12.5} color={strong ? colors.authInk : colors.ink500}>
        {value}
      </AppText>
    </View>
  );
}

function CancelCard({ order, cancelling, onCancel }: { order: Order; cancelling: boolean; onCancel: () => void }) {
  const refundable = order.cancellation?.refundable ?? false;
  const prompt = cancelPrompt(order);

  return (
    <View style={[styles.card, !refundable && styles.cancelWarn]}>
      <View style={styles.noteRow}>
        {!refundable && <IconInfoCircle size={15} color="#8a6206" strokeWidth={2} />}
        <AppText family="display" size={12.5} color={refundable ? colors.ink500 : "#8a6206"} style={styles.flex}>
          {order.cancellation?.message ?? "You can still cancel this order."}
        </AppText>
      </View>
      <Pressable
        accessibilityRole="button"
        disabled={cancelling}
        onPress={onCancel}
        style={[styles.cancelButton, cancelling && styles.pressed]}
        testID="cancel-order"
      >
        <AppText family="display" weight="semibold" size={13} color={colors.danger}>
          {cancelling ? "Working..." : prompt.action}
        </AppText>
      </Pressable>
    </View>
  );
}

function ActionButton({
  label,
  onPress,
  disabled,
  color,
  testID,
}: {
  label: string;
  onPress: () => void;
  disabled: boolean;
  color: string;
  testID?: string;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      disabled={disabled}
      onPress={onPress}
      style={({ pressed }) => [styles.action, { backgroundColor: color }, (pressed || disabled) && styles.pressed]}
      testID={testID}
    >
      <IconCircleCheck size={17} color={colors.white} strokeWidth={2.2} />
      <AppText family="display" weight="semibold" size={13.5} color={colors.white}>
        {label}
      </AppText>
    </Pressable>
  );
}

function LoadError({ error, guest, onBack }: { error: unknown; guest: boolean; onBack: () => void }) {
  const status = error instanceof ApiError ? error.status : 0;
  const serverMessage = error instanceof ApiError && typeof error.payload.message === "string" ? error.payload.message : null;
  const code = error instanceof ApiError && typeof error.payload.error_code === "string" ? error.payload.error_code : null;

  // An expired link says how the order ended (FR-02.13, decided 2026-11-22).
  const title =
    code === "ORDER_COMPLETED"
      ? "Order completed"
      : code === "ORDER_CANCELLED"
        ? "Order cancelled"
        : status === 410
          ? "This link has expired"
          : status === 403 || status === 404
            ? "We can't find that order"
            : "That order could not be loaded";

  const body =
    guest && serverMessage && status !== 0
      ? serverMessage
      : status === 403 || status === 404
        ? "It may belong to another account, or the link may be out of date."
        : "Please check your connection and try again.";

  return (
    <View style={[styles.card, styles.hero]}>
      <AppText family="display" weight="semibold" size={17} color={colors.authInk} style={styles.center}>
        {title}
      </AppText>
      <AppText family="display" size={13} color={colors.ink500} style={styles.center}>
        {body}
      </AppText>
      <Pressable accessibilityRole="button" onPress={onBack} style={[styles.action, { backgroundColor: colors.orange }]}>
        <AppText family="display" weight="semibold" size={13.5} color={colors.white}>
          {guest ? "Back to the menu" : "Back"}
        </AppText>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.landing },
  header: { flexDirection: "row", alignItems: "center", gap: 12, paddingHorizontal: 16, paddingVertical: 12 },
  back: {
    width: 38,
    height: 38,
    borderRadius: 19,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: "#ece7e0",
  },
  reference: { flex: 1 },
  badge: { flexDirection: "row", alignItems: "center", gap: 6, paddingHorizontal: 12, paddingVertical: 5, borderRadius: 999 },
  badgeDot: { width: 7, height: 7, borderRadius: 4 },
  content: { paddingHorizontal: 16, paddingBottom: 40, gap: 14 },
  skeleton: { borderRadius: 16, backgroundColor: "#f0e9df" },
  card: {
    borderRadius: 16,
    backgroundColor: colors.white,
    padding: 16,
    gap: 10,
    elevation: 2,
    shadowColor: "#412111",
    shadowOpacity: 0.08,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 2 },
  },
  hero: { alignItems: "center", paddingVertical: 26, overflow: "hidden" },
  confetti: { position: "absolute", width: 8, height: 8, borderRadius: 4 },
  ring: { width: 92, height: 92, borderRadius: 46, alignItems: "center", justifyContent: "center", marginBottom: 6 },
  ringDot: { width: 66, height: 66, borderRadius: 33, alignItems: "center", justifyContent: "center" },
  center: { textAlign: "center" },
  infoBox: {
    alignSelf: "stretch",
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
    marginTop: 6,
    padding: 12,
    borderRadius: 12,
    backgroundColor: "#faf7f3",
  },
  flex: { flex: 1 },
  receiptCard: { backgroundColor: "#f2fbf5", borderWidth: 1, borderColor: "#c8ebd4" },
  payCard: { backgroundColor: "#fff8ef", borderWidth: 1, borderColor: "#ffd8a8" },
  payHead: { flexDirection: "row", alignItems: "flex-start", gap: 12 },
  payIcon: { width: 40, height: 40, borderRadius: 20, alignItems: "center", justifyContent: "center", backgroundColor: colors.orange },
  payDue: {
    alignSelf: "flex-start",
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
    paddingHorizontal: 12,
    paddingVertical: 4,
    borderRadius: 999,
    backgroundColor: colors.white,
  },
  noteRow: { flexDirection: "row", alignItems: "flex-start", justifyContent: "center", gap: 6 },
  eyebrow: { letterSpacing: 1 },
  itemRow: { flexDirection: "row", alignItems: "center", gap: 12, paddingVertical: 8 },
  dashed: { borderTopWidth: 1, borderStyle: "dashed", borderTopColor: "#e6ded4" },
  totals: { gap: 4, paddingTop: 10, borderTopWidth: 1, borderTopColor: "#f0e9df" },
  totalRow: { flexDirection: "row", alignItems: "center", justifyContent: "space-between" },
  whereRow: { flexDirection: "row", alignItems: "center", gap: 6 },
  cancelWarn: { backgroundColor: "#fff9e8", borderWidth: 1, borderColor: "#ffe6a8" },
  cancelButton: {
    alignSelf: "flex-start",
    paddingHorizontal: 16,
    paddingVertical: 8,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: "#f3d4d4",
    backgroundColor: colors.white,
  },
  feedbackHead: { flexDirection: "row", gap: 12 },
  mailIcon: { width: 40, height: 40, borderRadius: 20, alignItems: "center", justifyContent: "center", backgroundColor: "#fff1e6" },
  feedbackText: { marginTop: 4 },
  feedbackButton: {
    height: 44,
    borderRadius: 12,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
    backgroundColor: "#f2f2f3",
    borderWidth: 1,
    borderColor: "#e9e9ec",
  },
  again: {
    height: 50,
    borderRadius: 14,
    borderWidth: 1.5,
    borderColor: colors.orange,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
  },
  action: {
    height: 44,
    borderRadius: 12,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
    paddingHorizontal: 16,
    alignSelf: "stretch",
  },
  pressed: { opacity: 0.7 },
  reviewBody: { gap: 12 },
  reviewNotice: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: 8,
    padding: 12,
    borderRadius: 12,
    backgroundColor: "#fff4e8",
    borderWidth: 1,
    borderColor: "#ffd8a8",
  },
});

export default OrderTrackingView;
