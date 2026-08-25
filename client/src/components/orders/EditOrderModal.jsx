import { useEffect, useState } from "react";
import { Modal } from "@mantine/core";
import { IconAlertTriangle, IconClock, IconMapPin, IconNote } from "@tabler/icons-react";

import AddressSearch from "../checkout/AddressSearch";
import Button from "../ui/Button";
import DeliveryMap from "../checkout/DeliveryMap";
import { isoToPickupTime, pickupTimeToIso, reverseGeocode } from "../../lib/checkout";

function EditOrderModal({ order, opened, onClose, onSave, isSaving, error }) {
  const editable = order?.editable ?? {};
  const isDelivery = order?.order_type === "delivery";

  const [destination, setDestination] = useState(null);
  const [note, setNote] = useState("");
  const [pickupTime, setPickupTime] = useState("");
  const [isResolving, setIsResolving] = useState(false);

  useEffect(() => {
    if (!opened || !order) return;

    setDestination(null);
    setNote(order.delivery?.note ?? "");
    setPickupTime(isoToPickupTime(order.pickup?.at) ?? "");
  }, [opened, order]);

  const pickPoint = async ({ latitude, longitude }) => {
    setDestination({ latitude, longitude, full_address: "", locality: null });
    setIsResolving(true);

    try {
      const place = await reverseGeocode({ latitude, longitude });

      setDestination({
        latitude,
        longitude,
        full_address: place?.full_address ?? "",
        locality: place?.locality ?? null,
      });
    } catch {
    } finally {
      setIsResolving(false);
    }
  };

  const submit = () => {
    const changes = {};

    if (editable.address && destination) {
      changes.latitude = destination.latitude;
      changes.longitude = destination.longitude;
      changes.full_address = destination.full_address || null;
      changes.location = destination.locality ?? null;
    }

    if (editable.note && note !== (order.delivery?.note ?? "")) {
      changes.delivery_note = note;
    }

    if (editable.pickup_at && pickupTime && pickupTime !== isoToPickupTime(order.pickup?.at)) {
      changes.pickup_at = pickupTimeToIso(pickupTime);
    }

    onSave(changes);
  };

  const nothingChanged =
    !destination &&
    note === (order?.delivery?.note ?? "") &&
    (!pickupTime || pickupTime === isoToPickupTime(order?.pickup?.at));

  return (
    <Modal
      opened={opened}
      onClose={onClose}
      title="Correct your order"
      centered
      radius="md"
      size="lg"
    >
      <div className="flex flex-col gap-4 font-display">
        <p className="m-0 text-[12.5px] leading-snug text-[#6f6b68]">
          You can fix the details below while the kitchen still has your order. The food itself and the
          choice between pick-up and delivery cannot be changed &mdash; for those, cancel and order again.
        </p>

        {error && (
          <div className="flex items-start gap-2 rounded-[10px] border border-[#f3d4d4] bg-[#fdecec] px-3.5 py-2.5">
            <IconAlertTriangle size={16} stroke={2} aria-hidden="true" className="mt-0.5 shrink-0 text-[#c92a2a]" />
            <p className="m-0 text-[12.5px] leading-snug text-[#c92a2a]">{error}</p>
          </div>
        )}

        {editable.address && (
          <div className="flex flex-col gap-2">
            <label className="flex items-center gap-1.5 text-[12.5px] font-bold text-ink">
              <IconMapPin size={14} stroke={2.2} aria-hidden="true" className="text-brand-500" />
              Delivery address
            </label>

            <p className="m-0 rounded-[8px] bg-[#faf7f3] px-3 py-2 text-[12px] leading-snug text-[#6f6b68]">
              Currently: {order.delivery?.full_address || "not set"}
            </p>

            <AddressSearch
              onSelect={(place) =>
                setDestination({
                  latitude: place.latitude,
                  longitude: place.longitude,
                  full_address: place.full_address,
                  locality: place.locality,
                })
              }
            />

            <DeliveryMap
              selected={destination}
              onPick={pickPoint}
              isResolving={isResolving}
              height={200}
            />

            <p className="m-0 text-[11.5px] leading-snug text-[#a39f9b]">
              The delivery fee has to stay the same. If your new address costs a different amount to reach,
              we will tell you and you can call the store.
            </p>
          </div>
        )}

        {editable.note && (
          <div className="flex flex-col gap-2">
            <label htmlFor="delivery-note" className="flex items-center gap-1.5 text-[12.5px] font-bold text-ink">
              <IconNote size={14} stroke={2.2} aria-hidden="true" className="text-brand-500" />
              Note for the rider
            </label>

            <textarea
              id="delivery-note"
              value={note}
              onChange={(event) => setNote(event.target.value)}
              rows={2}
              maxLength={255}
              placeholder="Blue gate, ring twice"
              className="w-full resize-none rounded-[10px] border border-[#e8e4de] bg-white px-3.5 py-2.5 text-[13.5px] text-ink outline-none transition-colors placeholder:text-[#b8b2aa] focus:border-brand-500 focus:shadow-[0_0_0_3px_rgba(255,139,43,0.12)]"
            />
          </div>
        )}

        {editable.pickup_at && (
          <div className="flex flex-col gap-2">
            <label htmlFor="pickup-time" className="flex items-center gap-1.5 text-[12.5px] font-bold text-ink">
              <IconClock size={14} stroke={2.2} aria-hidden="true" className="text-brand-500" />
              Collection time
            </label>

            <input
              id="pickup-time"
              type="time"
              value={pickupTime}
              onChange={(event) => setPickupTime(event.target.value)}
              className="h-[46px] w-full rounded-[10px] border border-[#e8e4de] bg-white px-3.5 text-[13.5px] text-ink outline-none transition-colors focus:border-brand-500 focus:shadow-[0_0_0_3px_rgba(255,139,43,0.12)] sm:w-[180px]"
            />
          </div>
        )}

        {!isDelivery && !editable.pickup_at && !editable.note && (
          <p className="m-0 text-[12.5px] text-[#8d8884]">
            There is nothing left to change on this order.
          </p>
        )}

        <div className="mt-1 flex justify-end gap-2">
          <Button variant="ghost" size="sm" onClick={onClose} disabled={isSaving}>
            Never mind
          </Button>
          <Button
            size="sm"
            loading={isSaving}
            loadingLabel="Saving&hellip;"
            disabled={nothingChanged}
            onClick={submit}
          >
            Save changes
          </Button>
        </div>
      </div>
    </Modal>
  );
}

export default EditOrderModal;
