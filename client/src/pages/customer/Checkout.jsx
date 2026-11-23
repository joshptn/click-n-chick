import { useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { IconLock, IconQrcode, IconWallet } from "@tabler/icons-react";

import AppHeader from "../../components/app/AppHeader";
import AuthContext from "../../context/AuthContext";
import CheckoutStepper from "../../components/checkout/CheckoutStepper";
import DiscountStep from "../../components/checkout/DiscountStep";
import DispatchStep, { FulfilmentToggle } from "../../components/checkout/DispatchStep";
import CancellationNotice from "../../components/checkout/CancellationNotice";
import OrderSummary from "../../components/checkout/OrderSummary";
import StepCard from "../../components/checkout/StepCard";
import toast from "../../components/app/Toast";
import SecurityCheck from "../../components/ui/SecurityCheck";
import useRecaptcha from "../../hooks/useRecaptcha";
import {
  FULFILMENT,
  fetchAddresses,
  fetchCheckoutQuote,
  pickupTimeToIso,
  toLocalMobile,
} from "../../lib/checkout";
import { formatPeso } from "../../lib/menu";
import { goToPayment, placeOrder } from "../../lib/payments";
import { useCartState } from "../../context/useCart";
import { useStoreStatus } from "../../lib/store";


const STEPS = [
  { id: "dispatch", label: "Dispatch", shortLabel: "Dispatch" },
  { id: "discount", label: "Discount", shortLabel: "Discount" },
  { id: "payment", label: "Payment", shortLabel: "Pay" },
];

// A guest may not claim a statutory discount so the step is absent
// rather than present and refusing. The server says the same thing if asked.
const GUEST_STEPS = STEPS.filter((step) => step.id !== "discount");

const QUOTE_DEBOUNCE_MS = 400;
const PIN_DEBOUNCE_MS = 900;
const QUOTE_STALE_MS = 30 * 1000;

function Checkout() {
  const navigate = useNavigate();
  const location = useLocation();
  const queryClient = useQueryClient();
  const [placing, setPlacing] = useState(false);
  const { user, token } = useContext(AuthContext);
  const signedIn = Boolean(token);
  // Only a guest's order is checked (decided 2026-11-22).
  const captcha = useRecaptcha({ enabled: !signedIn });
  const steps = signedIn ? STEPS : GUEST_STEPS;
  const stepNumber = (id) => steps.findIndex((entry) => entry.id === id) + 1;
  const { selectedIds, isLoading: cartLoading, item_count: cartCount } = useCartState();
  const { status: storeStatus } = useStoreStatus();

  const routeSelection = location.state?.selectedIds ?? null;
  const initialSelection = useRef(routeSelection);

  const cartItemIds = initialSelection.current ?? selectedIds;

  const [step, setStep] = useState("dispatch");
  const [completed, setCompleted] = useState([]);
  const [attemptedContinue, setAttemptedContinue] = useState(false);
  const [applyDiscount, setApplyDiscount] = useState(false);
  const [acknowledgedTerms, setAcknowledgedTerms] = useState(false);

  const [dispatchState, setDispatchState] = useState(() => ({
    fulfilmentType: FULFILMENT.PICKUP,
    firstName: "",
    lastName: "",
    contactName: "",
    contactPhone: "",
    contactEmail: "",
    pickupTime: "",
    addressId: null,
    destination: null,
  }));

  useEffect(() => {
    if (!user) return;

    setDispatchState((prev) => ({
      ...prev,
      firstName: prev.firstName || user.first_name || "",
      lastName: prev.lastName || user.last_name || "",
      contactName:
        prev.contactName || [user.first_name, user.last_name].filter(Boolean).join(" "),
      contactPhone: prev.contactPhone || toLocalMobile(user.phone_number),
      contactEmail: prev.contactEmail || user.email || "",
    }));
  }, [user]);

  const updateDispatch = useCallback((patch) => {
    setDispatchState((prev) => ({
      ...prev,
      ...patch,
      destination:
        patch.destination === undefined
          ? prev.destination
          : patch.destination === null
            ? null
            : { ...prev.destination, ...patch.destination },
    }));
  }, []);

  const { data: addressPayload } = useQuery({
    queryKey: ["addresses"],
    queryFn: fetchAddresses,
    // A guest has no address book, and asking for one would only be refused.
    enabled: signedIn,
    staleTime: 5 * 60 * 1000,
  });

  const addresses = useMemo(() => addressPayload?.addresses ?? [], [addressPayload]);

  // One name field for a delivery's recipient and a guest's pickup person;
  // an account's pickup joins its first and last name.
  const contactName =
    dispatchState.fulfilmentType === FULFILMENT.DELIVERY || !signedIn
      ? dispatchState.contactName
      : [dispatchState.firstName, dispatchState.lastName].filter(Boolean).join(" ");

  const quoteInput = useMemo(
    () => ({
      fulfilment_type: dispatchState.fulfilmentType,
      cart_item_ids: cartItemIds,
      address_id: dispatchState.addressId,
      latitude: dispatchState.destination?.latitude ?? null,
      longitude: dispatchState.destination?.longitude ?? null,
      pickup_at:
        dispatchState.fulfilmentType === FULFILMENT.PICKUP
          ? pickupTimeToIso(dispatchState.pickupTime)
          : null,
      contact_name: contactName,
      contact_phone: dispatchState.contactPhone,
      contact_email: dispatchState.contactEmail,
      apply_discount: applyDiscount,
    }),
    [dispatchState, cartItemIds, contactName, applyDiscount]
  );

  const [debouncedInput, setDebouncedInput] = useState(quoteInput);

  const settledPin = useRef({
    latitude: quoteInput.latitude,
    longitude: quoteInput.longitude,
  });

  useEffect(() => {
    const moved =
      quoteInput.latitude !== settledPin.current.latitude ||
      quoteInput.longitude !== settledPin.current.longitude;

    const timer = window.setTimeout(
      () => {
        settledPin.current = {
          latitude: quoteInput.latitude,
          longitude: quoteInput.longitude,
        };

        setDebouncedInput(quoteInput);
      },
      moved ? PIN_DEBOUNCE_MS : QUOTE_DEBOUNCE_MS
    );

    return () => window.clearTimeout(timer);
  }, [quoteInput]);

  const {
    data: quote,
    isLoading: quoteLoading,
    isFetching: quoteFetching,
  } = useQuery({
    queryKey: ["checkout", "quote", debouncedInput],
    queryFn: () => fetchCheckoutQuote(debouncedInput),
    enabled: Boolean(cartItemIds),
    staleTime: QUOTE_STALE_MS,
    placeholderData: (previous) => previous,
    retry: false,
  });

  useEffect(() => {
    if (cartLoading || placing) return;
    if (cartCount === 0) {
      toast.info("Add something to your order first.", "Your cart is empty");
      navigate("/home", { replace: true });
    }
  }, [cartCount, cartLoading, navigate, placing]);

  const handleContinue = () => {
    setAttemptedContinue(true);

    if (!quote?.can_place) {
      toast.info(
        quote?.blockers?.[0]?.message ?? "Please complete this step first.",
        "Almost there"
      );

      return;
    }

    setCompleted((prev) => (prev.includes("dispatch") ? prev : [...prev, "dispatch"]));
    setStep(signedIn ? "discount" : "payment");
  };

  const handleDiscountContinue = () => {
    if (!quote?.can_place) {
      toast.info(quote?.blockers?.[0]?.message ?? "Please check this step.", "Almost there");

      return;
    }

    setCompleted((prev) => (prev.includes("discount") ? prev : [...prev, "discount"]));
    setStep("payment");
  };

  // The order is created waiting for payment and the browser goes to Xendit's
  // page. Nothing reaches the shop until it is paid (FR-04.5). A guest passes
  // the security check first; "Opening payment…" starts once it has.
  const handlePlaceOrder = () => {
    if (placing || step !== "payment" || !acknowledgedTerms || !quote?.can_place) return;

    const payload = {
      ...quoteInput,
      full_address: dispatchState.destination?.full_address ?? null,
      location: dispatchState.destination?.locality ?? null,
      // The total on screen: refused (PRICE_CHANGED) if prices moved since.
      expected_total: quote.total,
    };

    captcha.run((recaptchaToken) => submitOrder(payload, recaptchaToken));
  };

  const submitOrder = async (payload, recaptchaToken) => {
    setPlacing(true);

    try {
      const result = await placeOrder(payload, recaptchaToken);

      // The cart is not refetched here: it is now empty, and the empty-cart
      // redirect would race the browser leaving for the payment page. The app
      // loads afresh on the way back.
      goToPayment(
        result.payment.url,
        result.tracking_token ? { token: result.tracking_token } : { orderId: result.order.id }
      );
    } catch (err) {
      setPlacing(false);
      queryClient.invalidateQueries({ queryKey: ["checkout", "quote"] });

      toast.error(
        err?.message ?? "Your order could not be placed. Please try again.",
        err?.status === 502 ? "Payment unavailable" : "Order not placed"
      );
    }
  };

  const discountSummary = useMemo(() => {
    const discount = quote?.discount;

    if (!discount) return null;
    if (discount.applied) {
      return `${discount.type_label} discount applied (−${formatPeso(discount.amount)})`;
    }

    return discount.eligible && !discount.used_today ? "Not using my discount" : "No discount";
  }, [quote]);

  const dispatchSummary = useMemo(() => {
    if (dispatchState.fulfilmentType === FULFILMENT.DELIVERY) {
      return dispatchState.destination?.full_address || "Delivery";
    }

    const time = quote?.pickup?.requested_at
      ? new Date(quote.pickup.requested_at).toLocaleTimeString("en-PH", {
          hour: "numeric",
          minute: "2-digit",
        })
      : null;

    return time ? `Pick up at ${time}` : "Pick up";
  }, [dispatchState, quote]);

  const stateFor = (id) => {
    if (step === id) return "active";

    return completed.includes(id) ? "done" : "upcoming";
  };

  return (
    <div className="min-h-dvh bg-[#fdfaf6] font-display text-ink">
      <AppHeader />

      <main className="mx-auto w-full max-w-[1180px] px-4 py-5 sm:px-6 lg:px-8">
        <div className="mb-5">
          <CheckoutStepper steps={steps} current={step} completed={completed} />
        </div>

        <div className="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
          <div className="flex min-w-0 flex-col gap-3.5">
            <StepCard
              index={stepNumber("dispatch")}
              title="Order Dispatch"
              state={stateFor("dispatch")}
              summary={dispatchSummary}
              onReopen={() => setStep("dispatch")}
              headerAction={
                step === "dispatch" ? (
                  <FulfilmentToggle
                    value={dispatchState.fulfilmentType}
                    onChange={(next) => updateDispatch({ fulfilmentType: next })}
                    deliveryDisabled={storeStatus ? !storeStatus.delivery_enabled : false}
                  />
                ) : null
              }
            >
              <DispatchStep
                value={dispatchState}
                onChange={updateDispatch}
                quote={quote}
                isQuoting={quoteFetching && !quoteLoading}
                addresses={addresses}
                storeStatus={storeStatus}
                showFieldErrors={attemptedContinue}
                requireEmail={!signedIn}
                guest={!signedIn}
                onContinue={handleContinue}
                onBack={() => navigate("/home")}
              />
            </StepCard>

            {signedIn && (
              <StepCard
                index={stepNumber("discount")}
                title="Senior / PWD Discount"
                state={stateFor("discount")}
                summary={discountSummary}
                onReopen={() => setStep("discount")}
              >
                <DiscountStep
                  discount={quote?.discount ?? null}
                  applied={applyDiscount}
                  onChange={setApplyDiscount}
                  isQuoting={quoteFetching && !quoteLoading}
                  onContinue={handleDiscountContinue}
                  onBack={() => setStep("dispatch")}
                />
              </StepCard>
            )}

            <StepCard index={stepNumber("payment")} title="Secure Payment" state={stateFor("payment")}>
              <div className="flex flex-col gap-3">
                <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                  <div className="flex items-center gap-3 rounded-[12px] border border-[#f0e9df] bg-[#faf7f3] px-4 py-3">
                    <IconQrcode size={22} stroke={1.8} aria-hidden="true" className="shrink-0 text-brand-600" />
                    <span className="min-w-0">
                      <span className="block font-display text-[13.5px] font-bold text-ink">QR Ph</span>
                      <span className="block font-display text-[12px] leading-snug text-[#6f6b68]">
                        Scan with any bank or e-wallet app
                      </span>
                    </span>
                  </div>

                  <div className="flex items-center gap-3 rounded-[12px] border border-[#f0e9df] bg-[#faf7f3] px-4 py-3">
                    <IconWallet size={22} stroke={1.8} aria-hidden="true" className="shrink-0 text-brand-600" />
                    <span className="min-w-0">
                      <span className="block font-display text-[13.5px] font-bold text-ink">GCash</span>
                      <span className="block font-display text-[12px] leading-snug text-[#6f6b68]">
                        Pay from your GCash account
                      </span>
                    </span>
                  </div>
                </div>

                <p className="m-0 flex items-start gap-2 font-display text-[12.5px] leading-snug text-[#6f6b68]">
                  <IconLock size={15} stroke={2} aria-hidden="true" className="mt-px shrink-0" />
                  You choose how to pay on Xendit&rsquo;s secure page. Your order is held for 10 minutes while
                  you pay, and reaches the store once payment goes through.
                </p>
              </div>
            </StepCard>
          </div>

          <div className="flex flex-col gap-3.5 lg:sticky lg:top-[84px]">
            <OrderSummary
              quote={quote}
              isLoading={quoteLoading}
              canPlaceOrder={
                step === "payment" && acknowledgedTerms && Boolean(quote?.can_place) && !placing
              }
              onPlaceOrder={handlePlaceOrder}
              placeOrderLabel={placing ? "Opening payment…" : "Place Order & Pay"}
              acknowledged={acknowledgedTerms}
              onAcknowledge={setAcknowledgedTerms}
            />

            <SecurityCheck captcha={captcha} action="Place Order & Pay" className="px-1" />

            <CancellationNotice quote={quote} />
          </div>
        </div>
      </main>
    </div>
  );
}

export default Checkout;
