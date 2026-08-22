import { useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { IconLock } from "@tabler/icons-react";

import AppHeader from "../../components/app/AppHeader";
import AuthContext from "../../context/AuthContext";
import CheckoutStepper from "../../components/checkout/CheckoutStepper";
import DispatchStep, { FulfilmentToggle } from "../../components/checkout/DispatchStep";
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
import { useCart } from "../../context/useCart";
import { useStoreStatus } from "../../lib/store";

/**
 * Checkout (UC-ORD-001).
 *
 * Three steps; this module implements the first. Discount and Payment are
 * separate modules with their own rules - the once-per-day statutory limit and
 * the PayMongo flow respectively - so they are rendered as locked shells here
 * rather than stubbed with behaviour that would have to be undone.
 *
 * The screen holds the customer's *choices*. It holds no prices: every figure
 * comes back from POST /api/checkout/quote, which is the same code place-order
 * runs before it writes anything. That is what stops the summary and the order
 * from ever disagreeing.
 */

const STEPS = [
  { id: "dispatch", label: "Dispatch", shortLabel: "Dispatch" },
  { id: "discount", label: "Discount", shortLabel: "Discount" },
  { id: "payment", label: "Payment", shortLabel: "Pay" },
];

/** How long to sit on a keystroke before re-quoting. */
const QUOTE_DEBOUNCE_MS = 400;

function Checkout() {
  const navigate = useNavigate();
  const location = useLocation();
  const { user } = useContext(AuthContext);
  const { selectedIds, isLoading: cartLoading, item_count: cartCount } = useCart();
  const { status: storeStatus } = useStoreStatus();

  /*
   * The cart page passes the ticked lines through router state (BR-25, partial
   * checkout). Landing here directly - a refresh, a bookmark - falls back to
   * whatever is currently ticked in the cart, and the server refuses an empty
   * selection either way.
   */
  const routeSelection = location.state?.selectedIds ?? null;
  const initialSelection = useRef(routeSelection);

  const cartItemIds = initialSelection.current ?? selectedIds;

  const [step, setStep] = useState("dispatch");
  const [completed, setCompleted] = useState([]);
  // Field-level complaints stay quiet until the customer has actually tried
  // to continue. See DispatchStep for the split.
  const [attemptedContinue, setAttemptedContinue] = useState(false);

  const [dispatchState, setDispatchState] = useState(() => ({
    fulfilmentType: FULFILMENT.PICKUP,
    firstName: "",
    lastName: "",
    contactName: "",
    contactPhone: "",
    pickupTime: "",
    addressId: null,
    destination: null,
  }));

  // Prefill from the account once it arrives. Only fills blanks, so it cannot
  // overwrite something the customer has already typed.
  useEffect(() => {
    if (!user) return;

    setDispatchState((prev) => ({
      ...prev,
      firstName: prev.firstName || user.first_name || "",
      lastName: prev.lastName || user.last_name || "",
      contactName:
        prev.contactName || [user.first_name, user.last_name].filter(Boolean).join(" "),
      // Shown the way Filipinos write it. The profile stores +639..., which
      // is correct but is not what anyone reads back off a form.
      contactPhone: prev.contactPhone || toLocalMobile(user.phone_number),
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
    staleTime: 5 * 60 * 1000,
  });

  const addresses = useMemo(() => addressPayload?.addresses ?? [], [addressPayload]);

  /*
   * Pickup takes a first/last pair to match the form, delivery a single
   * recipient field. Both collapse to one contact name for the server, which
   * has no reason to care which shape the screen used.
   */
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
      full_address: dispatchState.destination?.full_address ?? null,
      location: dispatchState.destination?.locality ?? null,
      pickup_at:
        dispatchState.fulfilmentType === FULFILMENT.PICKUP
          ? pickupTimeToIso(dispatchState.pickupTime)
          : null,
      contact_name: contactName,
      contact_phone: dispatchState.contactPhone,
    }),
    [dispatchState, cartItemIds, contactName]
  );

  // Debounced so typing a phone number does not fire a request per character.
  const [debouncedInput, setDebouncedInput] = useState(quoteInput);

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedInput(quoteInput), QUOTE_DEBOUNCE_MS);

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
    // Always refetched on mount: a quote is a statement about the store and
    // the menu right now, and serving a cached one would show a fee or an
    // availability that has since moved.
    staleTime: 0,
    placeholderData: (previous) => previous,
    retry: false,
  });

  // An empty cart has nothing to check out. Sent back rather than shown a
  // screen whose every control is disabled.
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
    setStep("discount");
  };

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
          <CheckoutStepper steps={STEPS} current={step} completed={completed} />
        </div>

        <div className="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
          <div className="flex min-w-0 flex-col gap-3.5">
            <StepCard
              index={1}
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
                onContinue={handleContinue}
                onBack={() => navigate("/home")}
              />
            </StepCard>

            {/* Steps 2 and 3 are separate modules. Shown so the customer can
                see the whole flow, inert until those modules land. */}
            <StepCard index={2} title="Senior / PWD Discount" state={stateFor("discount")}>
              <p className="m-0 flex items-center gap-2 font-display text-[13px] text-[#8d8884]">
                <IconLock size={15} stroke={2} aria-hidden="true" />
                The discount step is coming next.
              </p>
            </StepCard>

            <StepCard index={3} title="Secure Payment" state={stateFor("payment")}>
              <p className="m-0 flex items-center gap-2 font-display text-[13px] text-[#8d8884]">
                <IconLock size={15} stroke={2} aria-hidden="true" />
                Payment is coming next.
              </p>
            </StepCard>
          </div>

          <div className="lg:sticky lg:top-[84px]">
            <OrderSummary
              quote={quote}
              isLoading={quoteLoading}
              canPlaceOrder={false}
              placeOrderLabel="Place Order"
            />
          </div>
        </div>
      </main>
    </div>
  );
}

export default Checkout;
