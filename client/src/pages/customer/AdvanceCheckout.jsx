import { useContext, useEffect, useMemo, useRef, useState } from "react";
import { Navigate, useLocation, useNavigate } from "react-router-dom";
import { useMutation, useQuery } from "@tanstack/react-query";
import { useQueryClient } from "@tanstack/react-query";

import AdvanceSummary from "../../components/advance/AdvanceSummary";
import AppHeader from "../../components/app/AppHeader";
import AuthContext from "../../context/AuthContext";
import CheckoutStepper from "../../components/checkout/CheckoutStepper";
import DiscountStep from "../../components/checkout/DiscountStep";
import ScheduleStep from "../../components/advance/ScheduleStep";
import StepCard from "../../components/checkout/StepCard";
import toast from "../../components/app/Toast";
import { CART_MODE } from "../../lib/cartModes";
import { ROLES } from "../../lib/roles";
import { fetchAdvanceQuote, formatCollectionDate, submitAdvanceOrder, toScheduleIso } from "../../lib/advance";
import { formatPeso } from "../../lib/menu";
import { primeRecaptcha } from "../../lib/recaptcha";
import { useCart } from "../../context/useCart";

const STEPS = [
  { id: "schedule", label: "Schedule", shortLabel: "When" },
  { id: "discount", label: "Discount", shortLabel: "Discount" },
];

const QUOTE_DEBOUNCE_MS = 400;

function AdvanceCheckout() {
  const navigate = useNavigate();
  const location = useLocation();
  const queryClient = useQueryClient();
  const { user } = useContext(AuthContext);
  const { selectedIds, isLoading: cartLoading, item_count: cartCount } = useCart(CART_MODE.ADVANCE);

  const routeSelection = location.state?.selectedIds ?? null;
  const initialSelection = useRef(routeSelection);

  const cartItemIds = initialSelection.current ?? selectedIds;

  const [step, setStep] = useState("schedule");
  const [completed, setCompleted] = useState([]);
  const [attemptedContinue, setAttemptedContinue] = useState(false);
  const [applyDiscount, setApplyDiscount] = useState(false);
  const [acknowledged, setAcknowledged] = useState(false);
  const [schedule, setSchedule] = useState({ date: "", time: "" });

  const quoteInput = useMemo(
    () => ({
      cart_item_ids: cartItemIds,
      scheduled_for: toScheduleIso(schedule.date, schedule.time),
      apply_discount: applyDiscount,
    }),
    [cartItemIds, schedule, applyDiscount]
  );

  const [debouncedInput, setDebouncedInput] = useState(quoteInput);

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedInput(quoteInput), QUOTE_DEBOUNCE_MS);

    return () => window.clearTimeout(timer);
  }, [quoteInput]);

  useEffect(() => {
    primeRecaptcha();
  }, []);

  const {
    data: quote,
    isLoading: quoteLoading,
    isFetching: quoteFetching,
  } = useQuery({
    queryKey: ["advance", "quote", debouncedInput],
    queryFn: () => fetchAdvanceQuote(debouncedInput),
    enabled: Boolean(cartItemIds),
    staleTime: 0,
    placeholderData: (previous) => previous,
    retry: false,
  });

  useEffect(() => {
    if (schedule.date || !quote?.schedule?.earliest_date) return;

    setSchedule((prev) => ({
      date: quote.schedule.earliest_date,
      time: prev.time || quote.schedule.opens_at || "",
    }));
  }, [quote, schedule.date]);

  useEffect(() => {
    if (cartLoading) return;
    if (cartCount === 0) {
      toast.info("Add something to schedule first.", "Nothing to send");
      navigate("/advance-order", { replace: true });
    }
  }, [cartCount, cartLoading, navigate]);

  const submit = useMutation({
    mutationFn: () => submitAdvanceOrder(quoteInput),
    onSuccess: (payload) => {
      queryClient.invalidateQueries({ queryKey: ["cart", CART_MODE.ADVANCE] });
      queryClient.invalidateQueries({ queryKey: ["orders"] });

      navigate(`/advance-order/sent/${payload.order.id}`, { replace: true, state: { order: payload.order } });
    },
    onError: (error) => {
      toast.error(error.message, "Could not send your request");
    },
  });

  const handleScheduleContinue = () => {
    setAttemptedContinue(true);

    const blocker = quote?.blockers?.find((item) => item.code.startsWith("SCHEDULE_") || item.code.startsWith("CONTACT_"));

    if (blocker) {
      toast.info(blocker.message, "Almost there");

      return;
    }

    setCompleted((prev) => (prev.includes("schedule") ? prev : [...prev, "schedule"]));
    setStep("discount");
  };

  const handleDiscountContinue = () => {
    if (!quote?.can_submit) {
      toast.info(quote?.blockers?.[0]?.message ?? "Please check this step.", "Almost there");

      return;
    }

    setCompleted((prev) => (prev.includes("discount") ? prev : [...prev, "discount"]));
  };

  const scheduleSummary = useMemo(() => {
    const when = formatCollectionDate(quote?.schedule?.requested_at);

    return when ? `${when}, ${schedule.time}` : "Not chosen yet";
  }, [quote, schedule.time]);

  const discountSummary = useMemo(() => {
    const discount = quote?.discount;

    if (!discount) return null;
    if (discount.applied) {
      return `${discount.type_label} discount applied (−${formatPeso(discount.amount)})`;
    }

    return discount.eligible && !discount.used_today ? "Not using my discount" : "No discount";
  }, [quote]);

  const stateFor = (id) => {
    if (step === id) return "active";

    return completed.includes(id) ? "done" : "upcoming";
  };

  if (user && user.role !== ROLES.CUSTOMER) {
    return <Navigate to="/home" replace />;
  }

  return (
    <div className="min-h-dvh bg-[#fdfaf6] font-display text-ink">
      <AppHeader cartMode={CART_MODE.ADVANCE} />

      <main className="mx-auto w-full max-w-[1180px] px-4 py-5 sm:px-6 lg:px-8">
        <div className="mb-5">
          <CheckoutStepper steps={STEPS} current={step} completed={completed} />
        </div>

        <div className="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
          <div className="flex min-w-0 flex-col gap-3.5">
            <StepCard
              index={1}
              title="When will you collect?"
              state={stateFor("schedule")}
              summary={scheduleSummary}
              onReopen={() => setStep("schedule")}
            >
              <ScheduleStep
                value={schedule}
                onChange={(patch) => setSchedule((prev) => ({ ...prev, ...patch }))}
                quote={quote}
                isQuoting={quoteFetching && !quoteLoading}
                showErrors={attemptedContinue}
                onContinue={handleScheduleContinue}
                onBack={() => navigate("/advance-order")}
              />
            </StepCard>

            <StepCard
              index={2}
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
                continueLabel="Review Request"
                onContinue={handleDiscountContinue}
                onBack={() => setStep("schedule")}
              />
            </StepCard>
          </div>

          <div className="flex flex-col gap-3.5 lg:sticky lg:top-[84px]">
            <AdvanceSummary
              quote={quote}
              isLoading={quoteLoading}
              canSubmit={Boolean(quote?.can_submit)}
              isSubmitting={submit.isPending}
              acknowledged={acknowledged}
              onAcknowledge={setAcknowledged}
              onSubmit={() => submit.mutate()}
            />
          </div>
        </div>
      </main>
    </div>
  );
}

export default AdvanceCheckout;
