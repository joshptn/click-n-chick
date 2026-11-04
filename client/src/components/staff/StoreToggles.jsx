import { useState } from "react";
import { Modal, Switch } from "@mantine/core";
import { useMutation, useQueryClient } from "@tanstack/react-query";

import Button from "../ui/Button";
import toast from "../app/Toast";
import { AGENT_QUEUE_KEY, updateStoreToggles } from "../../lib/agent";
import { STORE_STATUS_KEY, formatStoreTime, useStoreStatus } from "../../lib/store";

/**
 * The two manual overrides a Store Agent runs the day with.
 *
 * "Accepting Orders" sits on a three-way setting: `auto` follows store hours,
 * `closed` pauses checkout, `open` forces it open. Switching off pauses;
 * switching on returns to the hours, unless it is outside them, in which case
 * staying closed would make the switch do nothing - so it asks before forcing
 * the store open.
 */
function orderingCaption(status) {
  if (!status) return "Checking…";

  if (status.ordering_override === "closed") return "Paused by staff";
  if (status.ordering_override === "open" && !status.within_hours) return "Open after hours";
  if (status.accepting_orders) return "Open for checkout";

  const opens = formatStoreTime(status.opens_at);

  return opens ? `Closed · opens ${opens}` : "Closed";
}

function ToggleRow({ title, caption, checked, onChange, disabled, testId }) {
  return (
    <label className="flex cursor-pointer items-center justify-between gap-2 rounded-[10px] px-3 py-1.5 transition-colors hover:bg-[#faf6f0]">
      <span className="min-w-0">
        <span className={`block font-display text-[12px] font-semibold ${checked ? "text-brand-600" : "text-[#6f6b68]"}`}>
          {title}
        </span>
        <span className="block truncate font-display text-[10.5px] text-[#8d8884]">{caption}</span>
      </span>

      <Switch
        checked={checked}
        onChange={(event) => onChange(event.currentTarget.checked)}
        disabled={disabled}
        color="orange"
        size="sm"
        aria-label={title}
        data-testid={testId}
      />
    </label>
  );
}

function StoreToggles() {
  const queryClient = useQueryClient();
  const { status } = useStoreStatus();
  const [confirmingAfterHours, setConfirmingAfterHours] = useState(false);

  const mutation = useMutation({
    mutationFn: updateStoreToggles,
    onSuccess: (response) => {
      if (response?.status) {
        queryClient.setQueryData(STORE_STATUS_KEY, response.status);
      }

      queryClient.invalidateQueries({ queryKey: AGENT_QUEUE_KEY });
      setConfirmingAfterHours(false);
    },
    onError: (error) => toast.error(error.message, "Could not update the store"),
  });

  const accepting = Boolean(status?.accepting_orders);
  const delivery = Boolean(status?.delivery_enabled);

  const setAccepting = (on) => {
    if (!on) {
      mutation.mutate({ ordering_override: "closed" });

      return;
    }

    if (status && !status.within_hours) {
      setConfirmingAfterHours(true);

      return;
    }

    mutation.mutate({ ordering_override: "auto" });
  };

  return (
    <div className="flex flex-col gap-0.5">
      <ToggleRow
        title="Accepting Orders"
        caption={orderingCaption(status)}
        checked={accepting}
        onChange={setAccepting}
        disabled={!status || mutation.isPending}
        testId="toggle-accepting-orders"
      />
      <ToggleRow
        title="Delivery Service"
        caption={delivery ? "Delivery available" : "Pickup only"}
        checked={delivery}
        onChange={(on) => mutation.mutate({ delivery_enabled: on })}
        disabled={!status || mutation.isPending}
        testId="toggle-delivery"
      />

      <Modal
        opened={confirmingAfterHours}
        onClose={() => setConfirmingAfterHours(false)}
        title="Open outside store hours?"
        centered
        radius="md"
      >
        <p className="m-0 font-display text-[13.5px] leading-relaxed text-[#6f6b68]">
          Online ordering normally runs {formatStoreTime(status?.opens_at)} – {formatStoreTime(status?.closes_at)}.
          Opening now lets customers check out until you switch it off again.
        </p>

        <div className="mt-5 flex justify-end gap-2">
          <Button variant="ghost" size="sm" onClick={() => setConfirmingAfterHours(false)} disabled={mutation.isPending}>
            Keep closed
          </Button>
          <Button
            size="sm"
            loading={mutation.isPending}
            loadingLabel="Opening…"
            onClick={() => mutation.mutate({ ordering_override: "open" })}
          >
            Open for orders
          </Button>
        </div>
      </Modal>
    </div>
  );
}

export default StoreToggles;
