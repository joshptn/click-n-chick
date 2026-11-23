import { useMemo, useState } from "react";
import { Checkbox, Skeleton, Switch } from "@mantine/core";
import { IconGlassFull, IconRefresh } from "@tabler/icons-react";

import FilterBar, { FilterMeta } from "./FilterBar";
import FilterSelect from "./FilterSelect";
import Button from "../ui/Button";
import { GroupPill } from "../manager/AddonCatalog";
import { addonPriceText, matchesAddonText } from "../../lib/addons";

const DEFAULTS = { search: "", group: "all", status: "all" };

const STATUS_FILTERS = [
  { key: "all", label: "All add-ons" },
  { key: "on", label: "On menu" },
  { key: "off", label: "Switched off" },
  { key: "sizes_off", label: "A size switched off" },
];

function matchesStatus(addon, key) {
  if (key === "on") return addon.is_available;
  if (key === "off") return !addon.is_available;
  if (key === "sizes_off") return addon.sizes.some((size) => !size.is_available);

  return true;
}

const addonCount = (count) => `${count} ${count === 1 ? "add-on" : "add-ons"}`;

function AvailabilityToggle({ addon, canManage, busy, onToggle }) {
  return (
    <label className="flex flex-col items-center gap-1">
      <Switch
        checked={addon.is_available}
        onChange={(event) => onToggle(addon, event.currentTarget.checked)}
        disabled={!canManage || busy}
        color="orange"
        size="sm"
        aria-label={`${addon.addon_name} on the menu`}
        data-testid="addon-availability-switch"
      />
      <span className={`font-display text-[10.5px] ${addon.is_available ? "text-[#2f9e44]" : "text-[#8d8884]"}`}>
        {addon.is_available ? "On Menu" : "Switched off"}
      </span>
    </label>
  );
}

/** One tick per size it comes in; ticked is available. */
function SizeToggles({ addon, canManage, isBusy, onToggleSize, compact = false }) {
  if (!addon.is_sized) {
    return (
      <span className="inline-flex h-[30px] items-center rounded-full bg-[#f4f1ec] px-3 font-display text-[11.5px] font-semibold text-[#8d8884]">
        Not applicable
      </span>
    );
  }

  return (
    <div className={`flex ${compact ? "flex-row flex-wrap" : "flex-col items-center"} gap-1.5`} data-testid="addon-size-toggles">
      {addon.sizes.map((size) => (
        <label
          key={size.size}
          className={`inline-flex h-[30px] min-w-[108px] cursor-pointer items-center gap-2 rounded-full px-3 transition-colors ${
            size.is_available ? "bg-[#eef4ff]" : "bg-[#f6f3ef]"
          } ${canManage ? "" : "cursor-default"}`}
        >
          <Checkbox
            size="xs"
            color="orange"
            checked={size.is_available}
            onChange={(event) => onToggleSize(addon, size.size, event.currentTarget.checked)}
            disabled={!canManage || isBusy(`size-${addon.id}-${size.size}`)}
            aria-label={`${addon.addon_name} ${size.label} available`}
            data-testid={`addon-size-${size.size}`}
          />
          <span className={`font-display text-[11px] font-bold uppercase tracking-[0.4px] ${size.is_available ? "text-ink" : "text-[#a39f9b] line-through"}`}>
            {size.label}
          </span>
        </label>
      ))}
    </div>
  );
}

const GRID = "grid grid-cols-[minmax(170px,2fr)_minmax(96px,0.9fr)_minmax(120px,1fr)_minmax(120px,0.8fr)_minmax(150px,1fr)] items-center gap-x-5";

function TableView({ addons, loading, rowProps }) {
  return (
    <div className="overflow-x-auto rounded-[16px] bg-white ring-1 ring-[#f0e9df]">
      <div className="min-w-[760px]">
        <div className={`${GRID} border-b border-[#f3ede4] px-5 py-3 font-display text-[10px] font-bold uppercase tracking-[0.8px] text-[#8d8884]`}>
          <span>Add-on</span>
          <span>Group</span>
          <span>Price</span>
          <span className="text-center">Availability</span>
          <span className="text-center">Sizes available</span>
        </div>

        {loading
          ? [0, 1, 2, 3].map((key) => (
              <div key={key} className={`${GRID} border-b border-[#f6f1ea] px-5 py-3.5 last:border-b-0`}>
                <Skeleton height={14} width="70%" />
                <Skeleton height={20} width={70} radius="xl" />
                <Skeleton height={14} width={80} />
                <Skeleton height={28} width={40} radius="xl" className="justify-self-center" />
                <Skeleton height={30} width={110} radius="xl" className="justify-self-center" />
              </div>
            ))
          : addons.map((addon) => (
              <div
                key={addon.id}
                data-testid="addon-stock-row"
                data-addon={addon.id}
                className={`${GRID} border-b border-[#f6f1ea] px-5 py-3.5 transition-colors last:border-b-0 hover:bg-[#fffaf4]`}
              >
                <div className="min-w-0">
                  <p className={`[overflow-wrap:anywhere] m-0 font-display text-[13.5px] font-semibold leading-snug ${addon.is_available ? "text-ink" : "text-[#a39f9b]"}`}>
                    {addon.addon_name}
                  </p>
                  <p className="m-0 mt-0.5 font-display text-[10.5px] text-[#a39f9b]">ID: {addon.id}</p>
                </div>
                <div>
                  <GroupPill group={addon.addon_group} />
                </div>
                <span className="font-display text-[13.5px] font-bold text-ink">{addonPriceText(addon)}</span>
                <AvailabilityToggle addon={addon} canManage={rowProps.canManage} busy={rowProps.isBusy(`addon-${addon.id}`)} onToggle={rowProps.onToggle} />
                <div className="flex justify-center">
                  <SizeToggles addon={addon} {...rowProps} />
                </div>
              </div>
            ))}
      </div>
    </div>
  );
}

function CardView({ addons, loading, rowProps }) {
  if (loading) {
    return (
      <div className="flex flex-col gap-2.5">
        {[0, 1, 2].map((key) => (
          <Skeleton key={key} height={120} radius={14} />
        ))}
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-2.5">
      {addons.map((addon) => (
        <article key={addon.id} data-testid="addon-stock-row" data-addon={addon.id} className="rounded-[14px] bg-white p-3.5 ring-1 ring-[#f0e9df]">
          <div className="flex items-start gap-3">
            <div className="min-w-0 flex-1">
              <p className={`[overflow-wrap:anywhere] m-0 font-display text-[13.5px] font-semibold leading-snug ${addon.is_available ? "text-ink" : "text-[#a39f9b]"}`}>
                {addon.addon_name}
              </p>
              <div className="mt-1 flex flex-wrap items-center gap-1.5">
                <GroupPill group={addon.addon_group} />
                <span className="font-display text-[10.5px] text-[#a39f9b]">ID: {addon.id}</span>
              </div>
              <p className="m-0 mt-1 font-display text-[13.5px] font-bold text-ink">{addonPriceText(addon)}</p>
            </div>
            <AvailabilityToggle addon={addon} canManage={rowProps.canManage} busy={rowProps.isBusy(`addon-${addon.id}`)} onToggle={rowProps.onToggle} />
          </div>
          <div className="mt-3 flex items-center justify-between gap-2 border-t border-dashed border-[#f0e9df] pt-3">
            <span className="font-display text-[10px] font-bold uppercase tracking-[0.6px] text-[#8d8884]">Sizes</span>
            <SizeToggles addon={addon} {...rowProps} compact />
          </div>
        </article>
      ))}
    </div>
  );
}

/**
 * Product Availability's add-on list (decided 2026-11-24): the Store Agent
 * switches an add-on on or off, and each size it comes in, as it runs out or
 * comes back. Names, groups and prices are the Store Manager's, shown
 * read-only; the Store Manager sees the switches read-only too.
 */
function AddonAvailability({ addons: source, wide, canManage }) {
  const [filters, setFilters] = useState(DEFAULTS);
  const set = (key) => (value) => setFilters((current) => ({ ...current, [key]: value }));
  const filtered = Object.keys(DEFAULTS).some((key) => filters[key] !== DEFAULTS[key]);
  const clear = () => setFilters(DEFAULTS);

  const groups = useMemo(() => {
    const counts = source.addons.reduce((total, addon) => ({ ...total, [addon.addon_group]: (total[addon.addon_group] ?? 0) + 1 }), {});

    return Object.keys(counts)
      .sort((a, b) => a.localeCompare(b))
      .map((group) => ({ key: group, label: group, count: counts[group] }));
  }, [source.addons]);

  const group = filters.group !== "all" && !groups.some((item) => item.key === filters.group) ? "all" : filters.group;

  const visible = useMemo(
    () =>
      source.addons
        .filter((addon) => matchesAddonText(addon, filters.search))
        .filter((addon) => group === "all" || addon.addon_group === group)
        .filter((addon) => matchesStatus(addon, filters.status))
        .sort((a, b) => a.addon_name.localeCompare(b.addon_name)),
    [source.addons, filters, group]
  );

  const total = source.addons.length;

  const rowProps = {
    canManage,
    isBusy: source.isBusy,
    onToggle: source.toggle,
    onToggleSize: source.toggleSize,
  };

  return (
    <>
      <FilterBar
        testId="addon-stock-filters"
        search={{
          value: filters.search,
          onChange: set("search"),
          placeholder: "Search add-on name or ID…",
          title: "Search by add-on name, ID or group",
          label: "Search add-ons",
        }}
        tabs={{
          items: [{ key: "all", label: "ALL", count: source.isLoading ? undefined : total }, ...groups],
          value: group,
          onChange: set("group"),
          label: "Filter by group",
          testIdPrefix: "addon-group",
        }}
        searchBelow
        below={
          <>
            <FilterSelect
              label="Availability"
              value={filters.status}
              onChange={set("status")}
              options={STATUS_FILTERS}
              active={filters.status !== "all"}
              testId="addon-filter-availability"
            />
            <FilterMeta onClear={filtered ? clear : undefined}>
              {!source.isLoading && (visible.length === total ? addonCount(total) : `${visible.length} of ${addonCount(total)}`)}
            </FilterMeta>
          </>
        }
      />

      {source.isError ? (
        <div className="flex flex-col items-center gap-3 rounded-[16px] bg-white px-6 py-12 text-center ring-1 ring-[#f0e9df]">
          <p className="m-0 font-display text-[15px] font-semibold text-ink">The add-ons could not be loaded.</p>
          <p className="m-0 font-display text-[13px] text-[#8d8884]">{source.error?.message}</p>
          <Button size="sm" variant="outline" onClick={() => source.refetch()}>
            <IconRefresh size={16} stroke={2} />
            Try again
          </Button>
        </div>
      ) : !source.isLoading && visible.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-[16px] border border-dashed border-[#e7dfd4] bg-white/60 px-6 py-12 text-center">
          <IconGlassFull size={28} stroke={1.6} className="text-[#cfc7bd]" />
          <p className="m-0 font-display text-[14px] font-semibold text-ink">
            {total === 0 ? "There are no add-ons yet. The Store Manager adds them in Menu Management." : "No add-on matches these filters."}
          </p>
          {filtered && (
            <Button size="sm" variant="ghost" onClick={clear}>
              Clear filters
            </Button>
          )}
        </div>
      ) : wide ? (
        <TableView addons={visible} loading={source.isLoading} rowProps={rowProps} />
      ) : (
        <CardView addons={visible} loading={source.isLoading} rowProps={rowProps} />
      )}
    </>
  );
}

export default AddonAvailability;
