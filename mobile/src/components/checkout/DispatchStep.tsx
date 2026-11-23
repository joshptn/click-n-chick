import { useEffect, useRef, useState } from "react";
import { ActivityIndicator, Pressable, StyleSheet, View } from "react-native";
import * as Location from "expo-location";
import { useQuery } from "@tanstack/react-query";

import {
  blockerFor,
  checkoutApi,
  isEmail,
  mobileDigits,
  toLocalMobile,
  type CheckoutScope,
  type Fulfilment,
  type Quote,
} from "../../api/checkout";
import { formatPeso } from "../../lib/money";
import { formatTime } from "../../lib/time";
import { clockOnDay, isoToClock, timeWindow } from "../../lib/timeWindow";
import { colors } from "../../theme/colors";
import { AppText } from "../AppText";
import { TimeDialPicker } from "../pickers/TimeDialPicker";
import { TextField } from "../auth/TextField";
import { IconClock, IconInfoCircle, IconMapPin, IconPackage, IconScooter, IconTruckDelivery } from "../icons";
import { DeliveryMap, type Point } from "./DeliveryMap";
import { Notice } from "./parts";

export type Destination = Point & { full_address: string; locality: string | null };

export type DispatchValues = {
  fulfilment: Fulfilment;
  name: string;
  phone: string;
  email: string;
  /** ISO, one of the API's pickup slots. */
  pickupAt: string | null;
  destination: Destination | null;
  note: string;
};

const SEARCH_DEBOUNCE_MS = 450;

/** Delivery | Pick up, in the step card's header. */
export function FulfilmentToggle({
  value,
  onChange,
  deliveryDisabled,
}: {
  value: Fulfilment;
  onChange: (next: Fulfilment) => void;
  deliveryDisabled: boolean;
}) {
  const options: { id: Fulfilment; label: string; Icon: typeof IconScooter }[] = [
    { id: "delivery", label: "Delivery", Icon: IconScooter },
    { id: "pickup", label: "Pick up", Icon: IconPackage },
  ];

  return (
    <View style={styles.toggle} accessibilityRole="radiogroup">
      {options.map(({ id, label, Icon }) => {
        const active = value === id;
        const disabled = id === "delivery" && deliveryDisabled;

        return (
          <Pressable
            key={id}
            accessibilityRole="radio"
            accessibilityState={{ selected: active, disabled }}
            accessibilityHint={disabled ? "Delivery is paused right now" : undefined}
            disabled={disabled}
            onPress={() => onChange(id)}
            style={[styles.toggleOption, active && styles.toggleActive, disabled && styles.faded]}
            testID={`fulfilment-${id}`}
          >
            <Icon size={14} color={active ? colors.white : colors.authMuted} strokeWidth={2.1} />
            <AppText family="display" weight="semibold" size={12} color={active ? colors.white : colors.authMuted}>
              {label}
            </AppText>
          </Pressable>
        );
      })}
    </View>
  );
}

/** Missing or wrong fields, in the order they appear: the first is shown under the button. */
export function dispatchProblems(values: DispatchValues, guest: boolean): string[] {
  const problems: string[] = [];
  const delivery = values.fulfilment === "delivery";

  if (values.name.trim() === "") problems.push(delivery ? "Enter the recipient's name" : "Enter the pick up person's name");
  if (!toLocalMobile(values.phone)) problems.push("Enter an 11-digit mobile number starting 09");
  if (guest && !isEmail(values.email)) problems.push("Enter your email address");
  if (delivery && !values.destination) problems.push("Pin your delivery location");
  if (!delivery && !values.pickupAt) problems.push("Choose a pick-up time");

  return problems;
}

type DispatchStepProps = {
  values: DispatchValues;
  onChange: (patch: Partial<DispatchValues>) => void;
  quote: Quote | null;
  /** The quote is being worked out for what was just typed or pinned. */
  quoting: boolean;
  scope: CheckoutScope;
  onMapTouch: (touching: boolean) => void;
};

/**
 * Step 1, Order Details (the web's DispatchStep, design of 2026-11-19):
 * who receives or collects the order, and where or when. Field errors appear
 * only once something has been typed; the API's blockers say the rest.
 */
export function DispatchStep({ values, onChange, quote, quoting, scope, onMapTouch }: DispatchStepProps) {
  const delivery = values.fulfilment === "delivery";
  const guest = scope === "guest";

  const [timeOpen, setTimeOpen] = useState(false);
  const [locating, setLocating] = useState(false);
  const [resolving, setResolving] = useState(false);
  const [locateError, setLocateError] = useState<string | null>(null);

  // Address search, debounced; at least 3 letters, as the API requires.
  const [search, setSearch] = useState("");
  const [debounced, setDebounced] = useState("");

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(search.trim()), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [search]);

  const results = useQuery({
    queryKey: ["geocode", scope, debounced],
    queryFn: ({ signal }) => checkoutApi.search(scope, debounced, signal),
    enabled: delivery && debounced.length >= 3,
    staleTime: 5 * 60 * 1000,
    retry: false,
  });

  const reverseAbort = useRef<AbortController | null>(null);
  useEffect(() => () => reverseAbort.current?.abort(), []);

  /** A tapped, dragged or located point: pinned at once, its address looked up after. */
  const adoptPoint = async (point: Point) => {
    onChange({ destination: { ...point, full_address: "", locality: null } });
    setLocateError(null);

    reverseAbort.current?.abort();
    const controller = new AbortController();
    reverseAbort.current = controller;
    setResolving(true);

    try {
      const place = await checkoutApi.reverse(scope, point, controller.signal);
      if (controller.signal.aborted) return;
      onChange({
        destination: { ...point, full_address: place.full_address || place.label || "", locality: place.locality ?? null },
      });
    } catch {
      // No address for that spot (or the geocoder is down): the pin still
      // counts, and the customer can type the address.
    } finally {
      if (reverseAbort.current === controller) setResolving(false);
    }
  };

  const locateMe = async () => {
    setLocating(true);
    setLocateError(null);

    try {
      const permission = await Location.requestForegroundPermissionsAsync();

      if (permission.status !== "granted") {
        setLocateError("Location is off for Click n Chick. Search your address or tap the map instead.");
        return;
      }

      const position = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
      await adoptPoint({ latitude: position.coords.latitude, longitude: position.coords.longitude });
    } catch {
      setLocateError("We could not get your location. Search your address or tap the map instead.");
    } finally {
      setLocating(false);
    }
  };

  const leg = quote?.fulfilment_type === "delivery" ? quote.delivery : null;
  const areaBlocker = blockerFor(quote, "OUTSIDE_SERVICE_AREA", "NO_ROUTE_FOUND", "ROUTING_UNAVAILABLE");
  const timeBlocker = values.pickupAt
    ? blockerFor(quote, "PICKUP_TIME_TOO_SOON", "PICKUP_TIME_TOO_LATE", "PICKUP_TIME_INVALID")
    : null;

  const phoneError = values.phone.trim() !== "" && !toLocalMobile(values.phone) ? "Enter an 11-digit mobile number starting 09." : undefined;
  const emailError = guest && values.email.trim() !== "" && !isEmail(values.email) ? "That email address does not look right." : undefined;

  // Decided 2026-11-20: the clock dial offers only today's bookable times, from
  // the quote's window (now plus the lead time, to closing less 15).
  const pickupWindow = quote?.fulfilment_type === "pickup" ? quote.pickup : null;
  const pickupMin = isoToClock(pickupWindow?.earliest);
  const pickupMax = isoToClock(pickupWindow?.latest);
  const pickupOpen = Boolean(timeWindow(pickupMin, pickupMax));
  const showResults = delivery && search.trim().length >= 3;

  return (
    <>
      <TextField
        label={delivery ? "Recipient Name" : "Pick up Person"}
        value={values.name}
        onChangeText={(name) => onChange({ name })}
        placeholder="John Doe"
        autoComplete="name"
        textContentType="name"
        maxLength={255}
        testID="contact-name"
      />

      <TextField
        label="Mobile Number"
        value={values.phone}
        onChangeText={(phone) => onChange({ phone: mobileDigits(phone) })}
        placeholder="09123456789"
        keyboardType="number-pad"
        autoComplete="tel"
        maxLength={11}
        error={phoneError}
        testID="contact-phone"
      />

      {guest && (
        <TextField
          label="Email Address"
          value={values.email}
          onChangeText={(email) => onChange({ email })}
          placeholder="you@example.com"
          keyboardType="email-address"
          autoCapitalize="none"
          autoComplete="email"
          error={emailError}
          testID="contact-email"
        />
      )}

      {delivery ? (
        <>
          <View style={styles.labelRow}>
            <IconMapPin size={15} color={colors.orange} strokeWidth={2.2} />
            <AppText family="display" weight="medium" size={13} color={colors.authLabel}>
              Live Delivery Location Pin
            </AppText>
          </View>

          <TextField
            label="Search your address"
            value={search}
            onChangeText={setSearch}
            placeholder="Street, barangay or landmark"
            returnKeyType="search"
            testID="address-search"
          />

          {showResults && (
            <View style={styles.results}>
              {results.isFetching && <ActivityIndicator color={colors.orange} style={styles.resultsSpinner} />}
              {results.isError && (
                <AppText family="display" size={12.5} color={colors.authMuted} style={styles.resultsNote}>
                  Search is unavailable right now. Tap the map instead.
                </AppText>
              )}
              {results.data?.length === 0 && !results.isFetching && (
                <AppText family="display" size={12.5} color={colors.authMuted} style={styles.resultsNote}>
                  No places found. Try another name, or tap the map.
                </AppText>
              )}
              {results.data?.slice(0, 5).map((place, index) => (
                <Pressable
                  key={`${place.latitude},${place.longitude},${index}`}
                  accessibilityRole="button"
                  onPress={() => {
                    setSearch("");
                    onChange({
                      destination: {
                        latitude: place.latitude,
                        longitude: place.longitude,
                        full_address: place.full_address || place.label,
                        locality: place.locality ?? null,
                      },
                    });
                  }}
                  style={[styles.result, index > 0 && styles.resultDivider]}
                >
                  <IconMapPin size={15} color={colors.authMuted} strokeWidth={2} />
                  <View style={styles.flex}>
                    <AppText family="display" weight="medium" size={13} color={colors.authInk} numberOfLines={1}>
                      {place.label}
                    </AppText>
                    <AppText family="display" size={11.5} color={colors.authHint} numberOfLines={1}>
                      {place.full_address}
                    </AppText>
                  </View>
                </Pressable>
              ))}
            </View>
          )}

          <DeliveryMap
            pin={values.destination}
            origin={leg?.origin ?? null}
            maxDrivingKm={leg?.max_driving_km ?? 0}
            route={leg?.geometry ?? null}
            withinServiceArea={leg ? leg.within_service_area : true}
            resolving={resolving || quoting}
            locality={values.destination?.locality ?? null}
            onPick={(point) => void adoptPoint(point)}
            onLocate={() => void locateMe()}
            locating={locating}
            onTouching={onMapTouch}
          />

          {locateError && <Notice tone="info">{locateError}</Notice>}
          {areaBlocker && values.destination && <Notice tone={areaBlocker.code === "OUTSIDE_SERVICE_AREA" ? "error" : "warning"}>{areaBlocker.message}</Notice>}

          {values.destination && (
            <TextField
              label="Drop-off Address"
              value={values.destination.full_address}
              onChangeText={(full_address) =>
                values.destination && onChange({ destination: { ...values.destination, full_address } })
              }
              placeholder={resolving ? "Finding the address..." : "House no., street, barangay"}
              maxLength={500}
              multiline
              testID="drop-off-address"
            />
          )}

          <TextField
            label="Delivery Instructions"
            value={values.note}
            onChangeText={(note) => onChange({ note })}
            placeholder="Note to rider - e.g. Landmark"
            maxLength={255}
            testID="delivery-note"
          />

          {leg && values.destination && (
            <View style={styles.feeRow}>
              <View style={styles.distance}>
                <IconTruckDelivery size={15} color={colors.authMuted} strokeWidth={1.9} />
                <AppText family="display" size={12} color={colors.authMuted} style={styles.flex}>
                  {leg.distance_km !== null ? `Driving distance: ${leg.distance_km} km` : "Driving distance unavailable"}
                </AppText>
              </View>
              {leg.within_service_area && leg.fee !== null ? (
                <View style={styles.feePill}>
                  <AppText family="display" weight="semibold" size={11.5} color={colors.brand800}>
                    Delivery Fee: {formatPeso(leg.fee)}
                  </AppText>
                </View>
              ) : null}
            </View>
          )}
        </>
      ) : (
        <>
          <View style={styles.wrap}>
            <AppText family="display" weight="medium" size={13} color={colors.authLabel}>
              Pick-up Time Choice
            </AppText>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={values.pickupAt ? `Pick-up time ${formatTime(values.pickupAt)}. Change` : "Choose a pick-up time"}
              disabled={!pickupOpen}
              onPress={() => setTimeOpen(true)}
              style={[styles.timeField, timeBlocker && styles.timeFieldError]}
              testID="pickup-time"
            >
              <AppText family="display" size={14.5} color={values.pickupAt ? colors.authInk : colors.authHint} style={styles.flex}>
                {values.pickupAt ? formatTime(values.pickupAt) : pickupOpen || !pickupWindow ? "Choose a time" : "No pick-up times left today"}
              </AppText>
              <IconClock size={18} color={colors.authHint} strokeWidth={2} />
            </Pressable>
            {timeBlocker ? (
              <AppText family="display" size={12} color={colors.danger}>
                {timeBlocker.message}
              </AppText>
            ) : quote?.pickup?.earliest ? (
              <AppText family="display" size={12} color={colors.authHint}>
                Ready from {formatTime(quote.pickup.earliest)}
              </AppText>
            ) : null}
          </View>

          <View style={styles.infoLine}>
            <IconInfoCircle size={14} color={colors.orange} strokeWidth={2} style={styles.infoIcon} />
            <AppText family="display" size={12} color={colors.authMuted} style={styles.flex}>
              Collect your order at BES House of Chicken, Apalit. We start preparing once payment clears.
            </AppText>
          </View>

          <TimeDialPicker
            visible={timeOpen}
            onClose={() => setTimeOpen(false)}
            value={isoToClock(values.pickupAt)}
            min={pickupMin}
            max={pickupMax}
            title="Select pick-up time"
            earlyMessage={(first) => `That time has passed or is too soon for the kitchen to prepare your order. The earliest pick-up is ${first}.`}
            lateMessage={(last) => `Too late: the last pick-up today is ${last}, shortly before the store closes.`}
            onConfirm={(clock) => {
              const pickupAt = pickupWindow?.earliest ? clockOnDay(pickupWindow.earliest, clock) : null;
              if (pickupAt) onChange({ pickupAt });
              setTimeOpen(false);
            }}
          />
        </>
      )}
    </>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  faded: { opacity: 0.45 },
  wrap: { gap: 7 },
  toggle: { flexDirection: "row", gap: 4, padding: 3, borderRadius: 11, backgroundColor: "#f4f1ec" },
  toggleOption: { flexDirection: "row", alignItems: "center", gap: 5, paddingHorizontal: 10, paddingVertical: 6, borderRadius: 9 },
  toggleActive: { backgroundColor: colors.orange },
  labelRow: { flexDirection: "row", alignItems: "center", gap: 6, marginBottom: -6 },
  results: { marginTop: -6, borderRadius: 14, borderWidth: 1, borderColor: colors.fieldBorder, backgroundColor: colors.white, overflow: "hidden" },
  resultsSpinner: { paddingVertical: 10 },
  resultsNote: { padding: 12 },
  result: { flexDirection: "row", alignItems: "center", gap: 10, paddingHorizontal: 12, paddingVertical: 10 },
  resultDivider: { borderTopWidth: 1, borderTopColor: "#f3f4f6" },
  feeRow: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: 8 },
  distance: { flex: 1, flexDirection: "row", alignItems: "center", gap: 6 },
  feePill: { paddingHorizontal: 10, paddingVertical: 4, borderRadius: 999, backgroundColor: "#fff1e6" },
  timeField: {
    minHeight: 48,
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
    paddingHorizontal: 16,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: colors.fieldBorder,
    backgroundColor: colors.white,
  },
  timeFieldError: { borderColor: "#f5b4b2" },
  infoLine: { flexDirection: "row", alignItems: "flex-start", gap: 6 },
  infoIcon: { marginTop: 2 },
});
