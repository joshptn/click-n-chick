import { useMemo, useState } from "react";
import { Modal } from "@mantine/core";
import { useMediaQuery } from "@mantine/hooks";
import { IconCalendarTime } from "@tabler/icons-react";

import AdvanceOrderCard from "./AdvanceOrderCard";
import FilterBar from "./FilterBar";
import { SCHEDULED_GROUPS, matchesSearch, scheduledGroupFor } from "../../lib/agent";

/**
 * Scheduled Orders (decided 2026-11-20): paid advance orders booked for their
 * collection day, soonest first. On the day an order is started from here; one
 * whose day has passed can only be cancelled.
 *
 * Read from the board's own data, so a move made here or by another agent
 * updates it in place. Opening a card closes the list and opens its drawer.
 */
function ScheduledOrdersModal({ opened, onClose, orders, cardProps }) {
  const wide = useMediaQuery("(min-width: 48em)", true);
  const [group, setGroup] = useState("today");
  const [search, setSearch] = useState("");

  const scheduled = useMemo(() => orders.filter((order) => order.status === "scheduled"), [orders]);

  const tabs = SCHEDULED_GROUPS.map((item) => ({
    key: item.key,
    label: item.label,
    count: scheduled.filter((order) => scheduledGroupFor(order) === item.key).length,
  }));

  const searching = search.trim() !== "";
  const current = SCHEDULED_GROUPS.find((item) => item.key === group) ?? SCHEDULED_GROUPS[0];
  const shown = scheduled
    .filter((order) => scheduledGroupFor(order) === group)
    .filter((order) => !searching || matchesSearch(order, search));

  const open = (order) => {
    onClose();
    cardProps.onOpen(order);
  };

  return (
    <Modal
      opened={opened}
      onClose={onClose}
      size="min(920px, 100%)"
      fullScreen={!wide}
      radius="lg"
      centered
      title={<span className="font-display text-[18px] font-bold text-brand-600">Scheduled Orders</span>}
      data-testid="advance-scheduled"
    >
      <div className="flex flex-col gap-3">
        <FilterBar
          testId="advance-scheduled-filters"
          search={{
            value: search,
            onChange: setSearch,
            placeholder: "Search…",
            title: "Search by order number, customer, phone or item",
            label: "Search scheduled advance orders",
          }}
          tabs={{
            items: tabs,
            value: group,
            onChange: setGroup,
            label: "When the order is collected",
            testIdPrefix: "advance-scheduled",
          }}
        />

        {shown.length === 0 ? (
          <div className="flex flex-col items-center gap-2 rounded-[16px] bg-white px-6 py-12 text-center ring-1 ring-[#f0e9df]">
            <IconCalendarTime size={26} stroke={1.6} className="text-[#c9c2b8]" aria-hidden="true" />
            <p className="m-0 font-display text-[13px] text-[#8d8884]">
              {searching ? "No scheduled order matches that search." : current.empty}
            </p>
          </div>
        ) : (
          <div className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
            {shown.map((order) => (
              <AdvanceOrderCard
                key={order.id}
                order={order}
                busy={cardProps.isBusy(order.id)}
                {...cardProps}
                onOpen={open}
              />
            ))}
          </div>
        )}
      </div>
    </Modal>
  );
}

export default ScheduledOrdersModal;
