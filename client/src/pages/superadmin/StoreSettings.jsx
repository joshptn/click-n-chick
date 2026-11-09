import { useEffect, useState } from "react";
import { Skeleton } from "@mantine/core";
import { useQueryClient } from "@tanstack/react-query";
import { IconAlertTriangle, IconPencil, IconRefresh, IconRoute } from "@tabler/icons-react";

import ConfirmActionModal from "../../components/manager/ConfirmActionModal";
import PasswordConfirmModal from "../../components/manager/PasswordConfirmModal";
import LivePill from "../../components/staff/LivePill";
import StaffLayout from "../../components/staff/StaffLayout";
import Button from "../../components/ui/Button";
import Field from "../../components/ui/Field";
import Input from "../../components/ui/Input";
import { useSettingForm } from "../../hooks/useSettingForm";
import { useStepUp } from "../../hooks/useStepUp";
import { formatPeso } from "../../lib/menu";
import { formatRecordDate } from "../../lib/records";
import { STORE_STATUS_KEY, formatStoreTime, useStoreStatus } from "../../lib/store";
import { saveSetting, toNumbers, validateDelivery, validateDiscount, validateHours } from "../../lib/storeSettings";

const PAGE_TITLE = "Store Settings · Click n Chick";

function Section({ title, hint, updatedAt, className = "", testId, children }) {
  return (
    <section data-testid={testId} className={`flex min-w-0 flex-col rounded-[16px] bg-white p-4 ring-1 ring-[#f0e9df] sm:p-5 ${className}`}>
      <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
        <h2 className="m-0 font-display text-[15px] font-bold text-ink">{title}</h2>
        {updatedAt && <span className="font-display text-[10.5px] text-[#a39f9b]">Last updated {formatRecordDate(updatedAt)}</span>}
      </div>
      {hint && <p className="m-0 mt-0.5 font-display text-[12px] leading-snug text-[#8d8884]">{hint}</p>}
      <div className="mt-4 flex flex-1 flex-col gap-3">{children}</div>
    </section>
  );
}

function SectionState({ form, height, what }) {
  if (form.isLoading) return <Skeleton height={height} radius={12} data-testid="settings-loading" />;

  return (
    <div className="flex flex-col items-center gap-2.5 rounded-[12px] bg-[#faf6f0] px-4 py-8 text-center" data-testid="settings-error">
      <p className="m-0 font-display text-[13.5px] font-semibold text-ink">The {what} could not be loaded.</p>
      <p className="m-0 font-display text-[12px] text-[#8d8884]">{form.error?.message}</p>
      <Button size="sm" variant="outline" onClick={() => form.refetch()}>
        <IconRefresh size={16} stroke={2} />
        Try again
      </Button>
    </div>
  );
}

function FormActions({ form, label, testId, onCancel, canCancel = form.dirty }) {
  return (
    <div className="mt-auto flex flex-wrap items-center justify-end gap-2 pt-1">
      {form.dirty && <span className="mr-auto font-display text-[11.5px] font-medium text-[#c98500]">Unsaved changes</span>}
      <Button variant="ghost" size="sm" disabled={!canCancel} onClick={onCancel ?? form.cancel} data-testid={`${testId}-cancel`}>
        Cancel
      </Button>
      <Button type="submit" size="sm" disabled={!form.dirty} data-testid={`${testId}-save`}>
        {label}
      </Button>
    </div>
  );
}

function tradingNow(status) {
  if (!status) return "";

  const delivery = status.delivery_enabled ? "" : " Delivery is paused.";

  if (status.ordering_override === "closed") return `Online ordering is paused from the sidebar.${delivery}`;
  if (status.ordering_override === "open" && !status.within_hours) return `Online ordering was opened after hours from the sidebar.${delivery}`;

  return `${status.accepting_orders ? "Open for checkout now." : `Closed now, opens ${formatStoreTime(status.opens_at)}.`}${delivery}`;
}

const pickHours = (data) => ({ opens_at: data.status.opens_at, closes_at: data.status.closes_at });

function StoreHours({ confirm }) {
  const queryClient = useQueryClient();
  const { status } = useStoreStatus();
  const form = useSettingForm({ section: "store", pick: pickHours, validate: validateHours });
  const overnight = form.values && form.values.opens_at && form.values.closes_at && form.values.closes_at < form.values.opens_at;

  const submit = (event) => {
    event.preventDefault();

    if (!form.check()) return;

    const { opens_at: opens, closes_at: closes } = form.values;

    confirm({
      title: "Change the store hours?",
      points: [
        `Online ordering will run ${formatStoreTime(opens)} to ${formatStoreTime(closes)} every day, starting now.`,
        ...(overnight ? ["The closing time is before the opening time, so the store stays open past midnight."] : []),
        "New pickup times and advance orders must fit these hours. Advance orders already accepted keep their collection times.",
      ],
      confirmLabel: "Save hours",
      run: async () => {
        const response = await form.save((values) => saveSetting("store", values));

        queryClient.setQueryData(STORE_STATUS_KEY, response.status);
      },
    });
  };

  return (
    <Section testId="settings-hours" title="Store Hours" hint="When customers can check out, the same every day." updatedAt={form.data?.updated_at}>
      {!form.values ? (
        <SectionState form={form} height={150} what="store hours" />
      ) : (
        <form onSubmit={submit} noValidate className="flex flex-1 flex-col gap-3">
          <div className="grid grid-cols-2 gap-3">
            <Field label="Opens" required error={form.errors.opens_at}>
              <Input type="time" {...form.bind("opens_at")} data-testid="hours-opens" />
            </Field>
            <Field label="Closes" required error={form.errors.closes_at}>
              <Input type="time" {...form.bind("closes_at")} data-testid="hours-closes" />
            </Field>
          </div>

          {overnight && (
            <p className="m-0 flex items-start gap-2 rounded-[10px] bg-[#fff8e1] px-3 py-2 font-display text-[12px] text-[#8a5d00] ring-1 ring-inset ring-[#ffe59a]" data-testid="hours-overnight">
              <IconAlertTriangle size={15} stroke={2} className="mt-[1px] shrink-0" />
              Closing is before opening, so the store would stay open past midnight.
            </p>
          )}

          <div className="rounded-[10px] bg-[#faf6f0] px-3 py-2.5 font-display text-[12px] leading-relaxed text-[#6f6b68]">
            <p className="m-0 font-semibold text-ink" data-testid="hours-now">
              {tradingNow(status)}
            </p>
            <p className="m-0">
              Set closing a little before the physical store closes. The last pickup is {form.data.last_order_buffer_minutes} minutes before it. To pause
              ordering or delivery for a while, use the switches in the sidebar.
            </p>
          </div>

          <FormActions form={form} label="Save Store Hours" testId="hours" />
        </form>
      )}
    </Section>
  );
}

const pickDiscount = (data) => ({ percentage: String(data.percentage) });

function DiscountRate({ confirm, stepUp }) {
  const form = useSettingForm({
    section: "discount",
    pick: pickDiscount,
    validate: (values, data) => validateDiscount(values, data.minimum_percentage),
  });
  const [editing, setEditing] = useState(false);

  const stop = () => {
    form.cancel();
    setEditing(false);
  };

  const submit = (event) => {
    event.preventDefault();

    if (!form.check()) return;

    confirm({
      title: `Change the discount to ${Number(form.values.percentage)}%?`,
      points: [
        "Checkout uses the new rate right away, including carts already open.",
        "Orders already placed keep the discount they were placed with.",
        "Every account is notified of the change.",
      ],
      confirmLabel: "Change rate",
      run: async () => {
        await form.save((values) =>
          stepUp.run((password) => saveSetting("discount", toNumbers(values), password), {
            title: "Confirm the discount rate",
            description: "Changing the statutory discount needs your password.",
          })
        );
        setEditing(false);
      },
    });
  };

  return (
    <Section
      testId="settings-discount"
      title="Discount Management"
      hint="Applies to food items only, not to add-ons or the delivery fee."
      updatedAt={form.data?.updated_at}
    >
      {!form.values ? (
        <SectionState form={form} height={150} what="discount rate" />
      ) : (
        <form onSubmit={submit} noValidate className="flex flex-1 flex-col gap-3">
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-[12px] bg-[#faf6f0] p-4">
            <div className="min-w-0">
              <p className="m-0 font-display text-[14px] font-bold text-ink">Senior Citizen and PWD</p>
              <p className="m-0 mt-0.5 font-display text-[11.5px] text-[#8d8884]">Republic Act No. 9994 · Republic Act No. 10754</p>
            </div>
            <div className="flex items-center gap-4">
              <div className="text-right">
                <p className="m-0 font-display text-[28px] font-bold leading-none text-brand-600" data-testid="discount-current">
                  {form.data.percentage}%
                </p>
                <p className="m-0 mt-1 font-display text-[10.5px] text-[#a39f9b]">discount rate</p>
              </div>
              {!editing && (
                <Button size="sm" variant="outline" onClick={() => setEditing(true)} data-testid="discount-edit">
                  <IconPencil size={15} stroke={2} />
                  Edit
                </Button>
              )}
            </div>
          </div>

          {editing ? (
            <>
              <Field
                label="New discount rate (%)"
                required
                error={form.errors.percentage}
                hint={`The law sets the minimum at ${form.data.minimum_percentage}%, so it cannot go lower.`}
              >
                <Input type="number" inputMode="decimal" step="0.01" min={form.data.minimum_percentage} max={100} {...form.bind("percentage")} data-testid="discount-rate" />
              </Field>
              <FormActions form={form} label="Save Discount Rate" testId="discount" onCancel={stop} canCancel />
            </>
          ) : (
            <p className="m-0 font-display text-[12px] leading-relaxed text-[#6f6b68]">
              One rate covers both discounts. It cannot be switched off because the law requires it, and it cannot go below {form.data.minimum_percentage}%.
            </p>
          )}
        </form>
      )}
    </Section>
  );
}

const pickDelivery = (data) => ({
  base_fee: String(data.base_fee),
  base_km: String(data.base_km),
  extra_fee_per_km: String(data.extra_fee_per_km),
  max_driving_km: String(data.max_driving_km),
});

const DELIVERY_FIELDS = [
  { key: "base_fee", label: "Base fee", prefix: "₱", step: "0.01", max: 10000 },
  { key: "base_km", label: "Base fee covers (km)", step: "0.1", max: 100 },
  { key: "extra_fee_per_km", label: "Fee per extra km", prefix: "₱", step: "0.01", max: 10000 },
  { key: "max_driving_km", label: "Maximum driving distance (km)", step: "0.1", min: 1, max: 100 },
];

function DeliveryRates({ confirm, stepUp, className }) {
  const form = useSettingForm({ section: "delivery", pick: pickDelivery, validate: validateDelivery });
  const valid = form.values && Object.keys(validateDelivery(form.values)).length === 0;

  const submit = (event) => {
    event.preventDefault();

    if (!form.check()) return;

    const shrinking = Number(form.values.max_driving_km) < Number(form.saved.max_driving_km);

    confirm({
      title: "Change the delivery rates?",
      points: [
        "New quotes use these rates right away, including carts already open.",
        ...(shrinking ? [`Addresses beyond ${Number(form.values.max_driving_km)} km by road will no longer be able to order delivery.`] : []),
        "Orders already placed keep the fee they were charged.",
        "Every account is notified of the change.",
      ],
      confirmLabel: "Save rates",
      run: () =>
        form.save((values) =>
          stepUp.run((password) => saveSetting("delivery", toNumbers(values), password), {
            title: "Confirm the delivery rates",
            description: "Changing delivery pricing needs your password.",
          })
        ),
    });
  };

  return (
    <Section
      className={className}
      testId="settings-delivery"
      title="Delivery Fee"
      hint="Calculated from the driving distance to each customer at checkout."
      updatedAt={form.data?.updated_at}
    >
      {!form.values ? (
        <SectionState form={form} height={140} what="delivery rates" />
      ) : (
        <form onSubmit={submit} noValidate className="flex flex-1 flex-col gap-3">
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {DELIVERY_FIELDS.map((field) => (
              <Field key={field.key} label={field.label} required error={form.errors[field.key]}>
                <Input
                  type="number"
                  inputMode="decimal"
                  prefix={field.prefix}
                  step={field.step}
                  min={field.min ?? 0}
                  max={field.max}
                  {...form.bind(field.key)}
                  data-testid={`delivery-${field.key}`}
                />
              </Field>
            ))}
          </div>

          {valid && (
            <p className="m-0 flex items-start gap-2 rounded-[10px] bg-[#faf6f0] px-3 py-2.5 font-display text-[12px] leading-relaxed text-[#6f6b68]" data-testid="delivery-summary">
              <IconRoute size={15} stroke={2} className="mt-[2px] shrink-0 text-brand-600" />
              <span>
                {formatPeso(form.values.base_fee)} for the first {Number(form.values.base_km)} km, then {formatPeso(form.values.extra_fee_per_km)} for each further km or part of
                one, up to {Number(form.values.max_driving_km)} km by road.
              </span>
            </p>
          )}

          <FormActions form={form} label="Save Delivery Settings" testId="delivery" />
        </form>
      )}
    </Section>
  );
}

/**
 * Store Settings (UC-OPS-011, UC-DEL-008, UC-DISC-009), for the Store Manager.
 *
 * Each section saves on its own, through the server's settings endpoints, which
 * are limited to the Store Manager and apply the rules again. Rates that move
 * money also go through the password step-up.
 */
function StoreSettings() {
  const stepUp = useStepUp();
  const [confirming, setConfirming] = useState(null);

  useEffect(() => {
    document.title = PAGE_TITLE;

    return () => {
      document.title = "Chick N' Click";
    };
  }, []);

  return (
    <StaffLayout>
      <main className="flex min-w-0 flex-1 flex-col">
        <header className="border-b border-[#f0e9df] bg-white px-4 py-4 sm:px-6 lg:px-7">
          <div className="flex flex-wrap items-center gap-2.5">
            <h1 className="m-0 font-display text-[18px] font-bold tracking-[-0.3px] text-ink">Store Settings</h1>
            <LivePill />
          </div>
          <p className="m-0 mt-0.5 font-display text-[12px] text-[#8d8884]">Manage store hours, delivery fees, and discount rules.</p>
        </header>

        <div className="grid flex-1 content-start gap-3 px-4 py-4 sm:px-6 lg:grid-cols-2 lg:px-7">
          <StoreHours confirm={setConfirming} />
          <DiscountRate confirm={setConfirming} stepUp={stepUp} />
          <DeliveryRates confirm={setConfirming} stepUp={stepUp} className="lg:col-span-2" />
        </div>
      </main>

      <ConfirmActionModal opened={Boolean(confirming)} onClose={() => setConfirming(null)} onConfirm={() => confirming.run()} {...(confirming ?? {})} />

      <PasswordConfirmModal {...stepUp.modalProps} zIndex={500} />
    </StaffLayout>
  );
}

export default StoreSettings;
