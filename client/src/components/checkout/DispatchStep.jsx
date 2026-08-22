import { useCallback, useEffect, useRef, useState } from "react";
import {
  IconAlertTriangle,
  IconBike,
  IconChevronRight,
  IconClock,
  IconInfoCircle,
  IconMapPin,
  IconPackage,
} from "@tabler/icons-react";
import { AnimatePresence, motion } from "framer-motion";

const MotionDiv = motion.div;

import AddressSearch from "./AddressSearch";
import Button from "../ui/Button";
import DeliveryMap from "./DeliveryMap";
import Field from "../ui/Field";
import Input from "../ui/Input";
import { FULFILMENT, blockerFor, reverseGeocode } from "../../lib/checkout";
import { formatPeso } from "../../lib/menu";

/**
 * Step 1 of checkout: how the order gets to the customer (UC-ORD-002/003/004).
 *
 * Owns no money. Every figure shown here - distance, delivery fee, whether the
 * address is servable - is read off the server's quote, so the customer cannot
 * be shown one price and charged another.
 */

const TYPES = [
  { id: FULFILMENT.DELIVERY, label: "Delivery", icon: IconBike },
  { id: FULFILMENT.PICKUP, label: "Pick up", icon: IconPackage },
];

export function FulfilmentToggle({ value, onChange, deliveryDisabled }) {
  return (
    <div
      role="radiogroup"
      aria-label="How would you like your order?"
      className="inline-flex shrink-0 items-center gap-1 rounded-[10px] bg-[#f4f1ec] p-1"
    >
      {TYPES.map((type) => {
        const { id, label } = type;
        const Icon = type.icon;
        const isActive = value === id;
        const isDisabled = id === FULFILMENT.DELIVERY && deliveryDisabled;

        return (
          <button
            key={id}
            type="button"
            role="radio"
            aria-checked={isActive}
            disabled={isDisabled}
            title={isDisabled ? "Delivery is paused right now" : undefined}
            onClick={() => onChange(id)}
            className={`inline-flex items-center gap-1.5 rounded-[8px] px-3 py-1.5 font-display text-[12.5px] font-bold transition-all duration-150 disabled:cursor-not-allowed disabled:opacity-45 ${
              isActive ? "bg-brand-500 text-white shadow-[0_2px_8px_-2px_rgba(255,139,43,0.6)]" : "bg-transparent text-[#6f6b68] hover:text-ink"
            }`}
          >
            <Icon size={14} stroke={2.1} aria-hidden="true" />
            {label}
          </button>
        );
      })}
    </div>
  );
}

/** A blocking condition, rendered inline where the customer can act on it. */
function Notice({ tone = "warning", icon, children }) {
  const Icon = icon ?? IconAlertTriangle;

  const tones = {
    warning: "border-[#ffe6a8] bg-[#fff9e8] text-[#8a6206]",
    error: "border-[#ffd7d5] bg-[#fff1f1] text-[#c92a2a]",
    info: "border-[#ffe0c2] bg-[#fff8f1] text-[#8a5a20]",
  };

  return (
    <p className={`m-0 flex items-start gap-2 rounded-[10px] border px-3.5 py-2.5 font-display text-[12.5px] leading-snug ${tones[tone]}`}>
      <Icon size={15} stroke={2.1} aria-hidden="true" className="mt-px shrink-0" />
      <span>{children}</span>
    </p>
  );
}

function DispatchStep({
  value,
  onChange,
  quote,
  isQuoting,
  addresses = [],
  storeStatus,
  showFieldErrors = false,
  onContinue,
  onBack,
}) {
  const isDelivery = value.fulfilmentType === FULFILMENT.DELIVERY;

  const [isLocating, setIsLocating] = useState(false);
  const [isResolving, setIsResolving] = useState(false);
  const [locateError, setLocateError] = useState(null);

  const reverseAbort = useRef(null);

  useEffect(() => () => reverseAbort.current?.abort(), []);

  const deliveryQuote = quote?.delivery ?? null;
  const radiusKm = deliveryQuote?.radius_km ?? storeStatus?.service_radius_km ?? 45;
  const origin = deliveryQuote?.origin ?? null;

  /*
   * Two classes of blocker, shown at different moments.
   *
   * Conditions about the world - the store is shut, that pin is too far -
   * are shown immediately: the customer needs to know before they fill
   * anything in, and none of them read as an accusation.
   *
   * Complaints about a field the customer has not reached yet do not. A form
   * that opens already scolding you for the blank time field you were about
   * to fill is hostile, so those wait until Continue is pressed.
   */
  const storeBlocker = blockerFor(quote, "STORE_CLOSED", "STORE_CLOSED_MANUALLY", "DELIVERY_UNAVAILABLE");
  const areaBlocker = blockerFor(quote, "OUTSIDE_SERVICE_AREA");

  const whenSubmitted = (blocker) => (showFieldErrors ? blocker : null);

  const timeBlocker = whenSubmitted(
    blockerFor(
      quote,
      "PICKUP_TIME_REQUIRED",
      "PICKUP_TIME_INVALID",
      "PICKUP_TIME_TOO_SOON",
      "PICKUP_TIME_TOO_LATE"
    )
  );
  const phoneBlocker = whenSubmitted(blockerFor(quote, "CONTACT_PHONE_INVALID"));
  const nameBlocker = whenSubmitted(blockerFor(quote, "CONTACT_NAME_REQUIRED"));

  /**
   * Adopt a coordinate, then ask the server what it is called.
   *
   * The pin is applied immediately so the map never lags behind the tap; the
   * label arrives a moment later. A failed lookup leaves the coordinate in
   * place - it is what the order is actually placed against, and the customer
   * can type the address themselves.
   */
  const adoptPoint = useCallback(
    async ({ latitude, longitude }, { label, fullAddress, locality, addressId = null } = {}) => {
      onChange({
        addressId,
        destination: {
          latitude,
          longitude,
          full_address: fullAddress ?? "",
          label: label ?? null,
          locality: locality ?? null,
        },
      });

      if (fullAddress) return;

      reverseAbort.current?.abort();

      const controller = new AbortController();
      reverseAbort.current = controller;

      setIsResolving(true);

      try {
        const { result } = await reverseGeocode({ latitude, longitude }, { signal: controller.signal });

        onChange({
          destination: {
            latitude,
            longitude,
            full_address: result.full_address || result.label || "",
            label: result.label ?? null,
            locality: result.locality ?? null,
          },
        });
      } catch (caught) {
        if (caught.name !== "AbortError") {
          // Not surfaced: the pin is set and priced, only its name is missing.
          setLocateError(null);
        }
      } finally {
        setIsResolving(false);
      }
    },
    [onChange]
  );

  /** UC-DEL-001. Permission denial is a normal answer, not a failure. */
  const useMyLocation = useCallback(() => {
    if (!navigator.geolocation) {
      setLocateError("This browser cannot share your location. Search or tap the map instead.");

      return;
    }

    setIsLocating(true);
    setLocateError(null);

    navigator.geolocation.getCurrentPosition(
      (position) => {
        setIsLocating(false);
        adoptPoint({
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
        });
      },
      (error) => {
        setIsLocating(false);
        setLocateError(
          error.code === error.PERMISSION_DENIED
            ? "Location is blocked for this site. Search your address or tap the map instead."
            : "We could not get your location. Search your address or tap the map instead."
        );
      },
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
    );
  }, [adoptPoint]);

  const savedWithCoordinates = addresses.filter(
    (address) => address.latitude != null && address.longitude != null
  );

  return (
    <div className="flex flex-col gap-4">
      {storeBlocker && (
        <Notice tone="error" icon={IconInfoCircle}>
          {storeBlocker.message}
        </Notice>
      )}

      <AnimatePresence mode="wait" initial={false}>
        <MotionDiv
          key={value.fulfilmentType}
          initial={{ opacity: 0, y: 6 }}
          animate={{ opacity: 1, y: 0 }}
          exit={{ opacity: 0, y: -6 }}
          transition={{ duration: 0.18 }}
          className="flex flex-col gap-4"
        >
          {isDelivery ? (
            <>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Recipient Complete Name" error={nameBlocker?.message}>
                  {(id) => (
                    <Input
                      id={id}
                      value={value.contactName}
                      onChange={(event) => onChange({ contactName: event.target.value })}
                      placeholder="John Doe"
                      autoComplete="name"
                      invalid={Boolean(nameBlocker)}
                    />
                  )}
                </Field>

                <Field label="Mobile Number" error={phoneBlocker?.message}>
                  {(id) => (
                    <Input
                      id={id}
                      type="tel"
                      inputMode="numeric"
                      value={value.contactPhone}
                      onChange={(event) => onChange({ contactPhone: event.target.value })}
                      placeholder="Enter your 11-digit mobile number"
                      autoComplete="tel"
                      maxLength={13}
                      invalid={Boolean(phoneBlocker)}
                    />
                  )}
                </Field>
              </div>

              <div>
                <p className="m-0 mb-2 flex items-center gap-1.5 font-display text-[13px] font-bold text-ink">
                  <IconMapPin size={15} stroke={2.2} aria-hidden="true" className="text-brand-500" />
                  Live Delivery Location Pin
                </p>

                <div className="mb-2.5">
                  <AddressSearch
                    onSelect={(place) =>
                      adoptPoint(
                        { latitude: place.latitude, longitude: place.longitude },
                        {
                          label: place.label,
                          fullAddress: place.full_address,
                          locality: place.locality,
                        }
                      )
                    }
                  />
                </div>

                <DeliveryMap
                  selected={value.destination}
                  origin={origin}
                  radiusKm={radiusKm}
                  withinServiceArea={deliveryQuote ? deliveryQuote.within_service_area : true}
                  localityLabel={value.destination?.locality}
                  onPick={(point) => adoptPoint(point)}
                  onLocate={useMyLocation}
                  isLocating={isLocating}
                  isResolving={isResolving || isQuoting}
                />
              </div>

              {locateError && <Notice tone="info">{locateError}</Notice>}

              {savedWithCoordinates.length > 0 && (
                <div className="flex flex-wrap gap-2">
                  {savedWithCoordinates.map((address) => {
                    const isActive = value.addressId === address.id;

                    return (
                      <button
                        key={address.id}
                        type="button"
                        onClick={() =>
                          adoptPoint(
                            { latitude: Number(address.latitude), longitude: Number(address.longitude) },
                            {
                              label: address.label,
                              fullAddress: address.full_address,
                              locality: address.location,
                              addressId: address.id,
                            }
                          )
                        }
                        className={`max-w-full truncate rounded-full px-3.5 py-1.5 font-display text-[12px] font-semibold transition-colors ${
                          isActive
                            ? "bg-brand-500 text-white"
                            : "bg-[#f4f1ec] text-[#6f6b68] hover:bg-[#ece7e0]"
                        }`}
                      >
                        {address.full_address}
                      </button>
                    );
                  })}
                </div>
              )}

              <Field label="Drop-Off Address" error={areaBlocker?.message}>
                {(id) => (
                  <Input
                    id={id}
                    value={value.destination?.full_address ?? ""}
                    onChange={(event) =>
                      onChange({
                        destination: { ...value.destination, full_address: event.target.value },
                      })
                    }
                    placeholder="Pin your location above to fill this in"
                    invalid={Boolean(areaBlocker)}
                  />
                )}
              </Field>

              {deliveryQuote && (
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="inline-flex items-center gap-1.5 font-display text-[12px] text-[#6f6b68]">
                    <IconBike size={14} stroke={1.9} aria-hidden="true" />
                    Distance from restaurant: {deliveryQuote.distance_km} km
                  </span>

                  {deliveryQuote.within_service_area ? (
                    <span className="rounded-full bg-[#fff4e8] px-3 py-1 font-display text-[11.5px] font-bold text-brand-700">
                      Delivery Fee: {formatPeso(deliveryQuote.fee)}
                    </span>
                  ) : (
                    <span className="rounded-full bg-[#fff1f1] px-3 py-1 font-display text-[11.5px] font-bold text-[#c92a2a]">
                      Outside the {radiusKm} km area
                    </span>
                  )}
                </div>
              )}
            </>
          ) : (
            <>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="First Name" error={nameBlocker?.message}>
                  {(id) => (
                    <Input
                      id={id}
                      value={value.firstName}
                      onChange={(event) => onChange({ firstName: event.target.value })}
                      placeholder="Julia"
                      autoComplete="given-name"
                      invalid={Boolean(nameBlocker)}
                    />
                  )}
                </Field>

                <Field label="Last Name">
                  {(id) => (
                    <Input
                      id={id}
                      value={value.lastName}
                      onChange={(event) => onChange({ lastName: event.target.value })}
                      placeholder="Veneese"
                      autoComplete="family-name"
                    />
                  )}
                </Field>

                <Field label="Mobile Number" error={phoneBlocker?.message}>
                  {(id) => (
                    <Input
                      id={id}
                      type="tel"
                      inputMode="numeric"
                      value={value.contactPhone}
                      onChange={(event) => onChange({ contactPhone: event.target.value })}
                      placeholder="09123456789"
                      autoComplete="tel"
                      maxLength={13}
                      invalid={Boolean(phoneBlocker)}
                    />
                  )}
                </Field>

                <Field
                  label="Time"
                  error={timeBlocker?.message}
                  hint={
                    !timeBlocker && quote?.pickup?.earliest
                      ? `Ready from ${new Date(quote.pickup.earliest).toLocaleTimeString("en-PH", {
                          hour: "numeric",
                          minute: "2-digit",
                        })}`
                      : undefined
                  }
                >
                  {(id) => (
                    <Input
                      id={id}
                      type="time"
                      icon={IconClock}
                      value={value.pickupTime}
                      onChange={(event) => onChange({ pickupTime: event.target.value })}
                      invalid={Boolean(timeBlocker)}
                      aria-label="What time will you collect your order?"
                    />
                  )}
                </Field>
              </div>

              <p className="m-0 flex items-start gap-2 font-display text-[12px] leading-snug text-[#8d8884]">
                <IconInfoCircle size={14} stroke={2} aria-hidden="true" className="mt-px shrink-0 text-brand-500" />
                Collect your order at BES House of Chicken, Apalit. We start preparing once payment clears.
              </p>
            </>
          )}
        </MotionDiv>
      </AnimatePresence>

      <div className="mt-1 flex flex-col gap-2.5 sm:flex-row sm:items-center">
        <Button variant="ghost" size="lg" onClick={onBack} className="border border-[#ece7e0] text-[#6f6b68] sm:w-[210px]">
          Back
        </Button>

        {/* Deliberately not disabled while the form is incomplete. A dead
            button explains nothing; pressing it is what reveals which field
            is missing. It only greys out for conditions no amount of typing
            can fix - a closed store, an undeliverable pin. */}
        <Button
          size="lg"
          fullWidth
          onClick={onContinue}
          loading={isQuoting}
          loadingLabel="Checking&hellip;"
          disabled={Boolean(storeBlocker) || Boolean(areaBlocker)}
        >
          Continue to Payment
          <IconChevronRight size={16} stroke={2.6} aria-hidden="true" />
        </Button>
      </div>
    </div>
  );
}

export default DispatchStep;
