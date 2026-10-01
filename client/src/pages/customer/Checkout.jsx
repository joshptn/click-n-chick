import { useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { IconLock } from "@tabler/icons-react";

import AppHeader from "../../components/app/AppHeader";
import AuthContext from "../../context/AuthContext";
import CheckoutStepper from "../../components/checkout/CheckoutStepper";
import DiscountStep from "../../components/checkout/DiscountStep";
import DispatchStep, { FulfilmentToggle } from "../../components/checkout/DispatchStep";
import CancellationNotice from "../../components/checkout/CancellationNotice";
import OrderSummary from "../../components/checkout/OrderSummary";
import StepCard from "../../components/checkout/StepCard";
import toast from "../../components/app/Toast";
import {
  FULFILMENT,
  fetchAddresses,
  fetchCheckoutQuote,
  pickupTimeToIso,
  toLocalMobile,
} from "../../lib/checkout";
import { formatPeso } from "../../lib/menu";
import { useCart } from "../../context/useCart";
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

const PAYMENT_AVAILABLE = false;

function Checkout() {
  const navigate = useNavigate();
  const location = useLocation();
  const { user, token } = useContext(AuthContext);
  const signedIn = Boolean(token);
  const steps = signedIn ? STEPS : GUEST_STEPS;
  const stepNumber = (id) => steps.findIndex((entry) => entry.id === id) + 1;
  const { selectedIds, isLoading: cartLoading, item_count: cartCount } = useCart();
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

  const contactName =
    dispatchState.fulfilmentType === FULFILMENT.DELIVERY
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
    if (cartLoading) return;
    if (cartCount === 0) {
      toast.info("Add something to your order first.", "Your cart is empty");
      navigate("/home", { replace: true });
    }
  }, [cartCount, cartLoading, navigate]);

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
              <p className="m-0 flex items-center gap-2 font-display text-[13px] text-[#8d8884]">
                <IconLock size={15} stroke={2} aria-hidden="true" />
                Payment is coming next.
              </p>
            </StepCard>
          </div>

          <div className="flex flex-col gap-3.5 lg:sticky lg:top-[84px]">
            <OrderSummary
              quote={quote}
              isLoading={quoteLoading}
              canPlaceOrder={PAYMENT_AVAILABLE && acknowledgedTerms && Boolean(quote?.can_place)}
              placeOrderLabel="Place Order"
              acknowledged={acknowledgedTerms}
              onAcknowledge={setAcknowledgedTerms}
            />

            <CancellationNotice quote={quote} />
          </div>
        </div>
      </main>
    </div>
  );
}

export default Checkout;
