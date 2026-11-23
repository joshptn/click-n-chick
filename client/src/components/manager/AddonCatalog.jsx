import { useMemo, useState } from "react";
import { Skeleton, Tooltip } from "@mantine/core";
import { IconArchive, IconArchiveOff, IconCirclePlus, IconGlassFull, IconPencil, IconRefresh, IconTrash } from "@tabler/icons-react";

import FilterBar, { FilterMeta } from "../staff/FilterBar";
import FilterSelect from "../staff/FilterSelect";
import Button from "../ui/Button";
import { ADDON_STATUS_FILTERS, addonPriceText, matchesAddonStatus, matchesAddonText } from "../../lib/addons";
import { categoryTone } from "../../lib/stock";

const DEFAULTS = { search: "", group: "all", status: "all" };

const addonCount = (count) => `${count} ${count === 1 ? "add-on" : "add-ons"}`;

export function GroupPill({ group }) {
  return (
    <span className={`inline-flex rounded-full px-2.5 py-[3px] font-display text-[10px] font-bold uppercase tracking-[0.4px] ${categoryTone(group)}`}>
      {group}
    </span>
  );
}

/** The sizes it comes in, or that it has none. */
function SizeList({ addon }) {
  if (!addon.is_sized) return <span className="font-display text-[11.5px] text-[#a39f9b]">No sizes</span>;

  return (
    <span className="flex flex-wrap gap-1">
      {addon.sizes.map((size) => (
        <span key={size.size} className="rounded-full bg-[#eef4ff] px-2 py-[2px] font-display text-[10.5px] font-semibold text-[#2563eb]">
          {size.label}
        </span>
      ))}
    </span>
  );
}

function StatusPill({ addon }) {
  return (
    <span
      data-testid="addon-status"
      className={`inline-flex min-w-[86px] justify-center rounded-full border px-3 py-[5px] font-display text-[11px] font-semibold ${
        addon.is_available ? "border-[#8fd6a2] bg-white text-[#2f9e44]" : "border-[#dcd6ce] bg-[#f6f3ef] text-[#6f6b68]"
      }`}
    >
      {addon.is_available ? "On Menu" : "Archived"}
    </span>
  );
}

function Dishes({ addon }) {
  const count = addon.food_ids?.length ?? 0;

  return (
    <span className={`font-display text-[12px] ${count === 0 ? "text-[#c4302b]" : "text-[#5f5b58]"}`}>
      {count === 0 ? "No dish yet" : `${count} ${count === 1 ? "dish" : "dishes"}`}
    </span>
  );
}

function Actions({ addon, busy, onEdit, onArchive, onDelete }) {
  const archived = !addon.is_available;

  return (
    <div className="flex items-center justify-end gap-1.5">
      <button
        type="button"
        onClick={() => onEdit(addon)}
        data-testid="addon-edit"
        className="inline-flex h-[30px] items-center gap-1 rounded-[8px] border border-[#ffd6b3] bg-[#fff6ec] px-2.5 font-display font-semibold text-brand-600 transition-colors hover:bg-[#ffecd9]"
      >
        <IconPencil size={13} stroke={2.2} />
        <span className="text-[11.5px]">Edit</span>
      </button>
      <button
        type="button"
        disabled={busy}
        onClick={() => onArchive(addon, !archived)}
        data-testid="addon-archive"
        className={`inline-flex h-[30px] items-center gap-1 rounded-[8px] border px-2.5 font-display font-semibold transition-colors disabled:opacity-55 ${
          archived
            ? "border-[#bfe8cb] bg-[#ecf9f0] text-[#2f9e44] hover:bg-[#dff4e6]"
            : "border-[#e8e3dc] bg-[#f7f5f2] text-[#5f5b58] hover:bg-[#efebe6]"
        }`}
      >
        {archived ? <IconArchiveOff size={13} stroke={2.2} /> : <IconArchive size={13} stroke={2.2} />}
        <span className="text-[11.5px]">{busy ? "Saving…" : archived ? "Unarchive" : "Archive"}</span>
      </button>
      <Tooltip label="Delete" withArrow openDelay={300}>
        <button
          type="button"
          onClick={() => onDelete(addon)}
          aria-label={`Delete ${addon.addon_name}`}
          data-testid="addon-delete"
          className="grid h-[30px] w-[30px] place-items-center rounded-[8px] border border-[#f8d4d2] bg-white text-[#e5322d] transition-colors hover:bg-[#fdecec]"
        >
          <IconTrash size={14} stroke={2.1} />
        </button>
      </Tooltip>
    </div>
  );
}

function Name({ addon }) {
  return (
    <span className="min-w-0">
      <span className={`[overflow-wrap:anywhere] block font-display text-[13.5px] font-semibold leading-snug ${addon.is_available ? "text-ink" : "text-[#a39f9b]"}`}>
        {addon.addon_name}
      </span>
      <span className="mt-0.5 block font-display text-[10.5px] text-[#a39f9b]">ID: {addon.id}</span>
    </span>
  );
}

const GRID =
  "grid grid-cols-[minmax(140px,2fr)_minmax(84px,0.8fr)_minmax(112px,1fr)_minmax(120px,1fr)_minmax(76px,0.7fr)_minmax(90px,0.8fr)_minmax(200px,1.2fr)] items-center gap-x-3";

function TableView({ addons, loading, rowProps }) {
  return (
    <div className="overflow-x-auto rounded-[16px] bg-white ring-1 ring-[#f0e9df]">
      <div className="min-w-[960px]">
        <div className={`${GRID} border-b border-[#f3ede4] px-5 py-3 font-display text-[10px] font-bold uppercase tracking-[0.8px] text-[#8d8884]`}>
          <span>Add-on name</span>
          <span>Group</span>
          <span>Price</span>
          <span>Sizes</span>
          <span>Offered with</span>
          <span>Status</span>
          <span className="text-right">Actions</span>
        </div>

        {loading
          ? [0, 1, 2, 3].map((key) => (
              <div key={key} className={`${GRID} border-b border-[#f6f1ea] px-5 py-3.5 last:border-b-0`}>
                <Skeleton height={14} width="70%" />
                <Skeleton height={20} width={70} radius="xl" />
                <Skeleton height={14} width={80} />
                <Skeleton height={18} width={110} radius="xl" />
                <Skeleton height={14} width={60} />
                <Skeleton height={26} width={86} radius="xl" />
                <Skeleton height={30} width={180} radius={8} className="justify-self-end" />
              </div>
            ))
          : addons.map((addon) => (
              <div
                key={addon.id}
                data-testid="addon-row"
                data-addon={addon.id}
                className={`${GRID} border-b border-[#f6f1ea] px-5 py-3.5 transition-colors last:border-b-0 hover:bg-[#fffaf4]`}
              >
                <Name addon={addon} />
                <div>
                  <GroupPill group={addon.addon_group} />
                </div>
                <span className="font-display text-[13.5px] font-bold text-ink">{addonPriceText(addon)}</span>
                <SizeList addon={addon} />
                <Dishes addon={addon} />
                <div>
                  <StatusPill addon={addon} />
                </div>
                <Actions addon={addon} busy={rowProps.isBusy(`addon-${addon.id}`)} {...rowProps} />
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
          <Skeleton key={key} height={132} radius={14} />
        ))}
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-2.5">
      {addons.map((addon) => (
        <article key={addon.id} data-testid="addon-row" data-addon={addon.id} className="rounded-[14px] bg-white p-3.5 ring-1 ring-[#f0e9df]">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <Name addon={addon} />
              <span className="mt-1.5 flex flex-wrap items-center gap-1.5">
                <GroupPill group={addon.addon_group} />
                <SizeList addon={addon} />
              </span>
              <span className="mt-1.5 block font-display text-[13.5px] font-bold text-ink">{addonPriceText(addon)}</span>
              <Dishes addon={addon} />
            </div>
            <StatusPill addon={addon} />
          </div>
          <div className="mt-3 flex justify-end border-t border-dashed border-[#f0e9df] pt-3">
            <Actions addon={addon} busy={rowProps.isBusy(`addon-${addon.id}`)} {...rowProps} />
          </div>
        </article>
      ))}
    </div>
  );
}

/**
 * Menu Management's add-on list (decided 2026-11-24): the Store Manager's
 * catalogue of add-ons, with the same archive and delete a dish has. Adding
 * and editing open AddonFormModal.
 */
function AddonCatalog({ addons: source, wide, onAdd, onEdit, onDelete }) {
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

  // A group emptied elsewhere cannot stay selected.
  const group = filters.group !== "all" && !groups.some((item) => item.key === filters.group) ? "all" : filters.group;

  const visible = useMemo(
    () =>
      source.addons
        .filter((addon) => matchesAddonText(addon, filters.search))
        .filter((addon) => group === "all" || addon.addon_group === group)
        .filter((addon) => matchesAddonStatus(addon, filters.status))
        .sort((a, b) => a.addon_name.localeCompare(b.addon_name)),
    [source.addons, filters, group]
  );

  const archivedCount = source.addons.filter((addon) => !addon.is_available).length;
  const total = source.addons.length;

  const rowProps = {
    isBusy: source.isBusy,
    onEdit,
    onArchive: source.archive,
    onDelete,
  };

  return (
    <>
      <FilterBar
        testId="addon-filters"
        search={{
          value: filters.search,
          onChange: set("search"),
          placeholder: "Search add-on name…",
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
              label="Status"
              value={filters.status}
              onChange={set("status")}
              options={ADDON_STATUS_FILTERS.map((option) => (option.key === "archived" ? { ...option, label: `Archived (${archivedCount})` } : option))}
              active={filters.status !== "all"}
              testId="addon-filter-status"
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
        <div data-testid="addon-empty" className="flex flex-col items-center gap-2 rounded-[16px] border border-dashed border-[#e7dfd4] bg-white/60 px-6 py-12 text-center">
          <IconGlassFull size={28} stroke={1.6} className="text-[#cfc7bd]" />
          <p className="m-0 font-display text-[14px] font-semibold text-ink">{total === 0 ? "There are no add-ons yet." : "No add-on matches these filters."}</p>
          {filtered ? (
            <Button size="sm" variant="ghost" onClick={clear}>
              Clear filters
            </Button>
          ) : (
            <button type="button" onClick={onAdd} className="inline-flex items-center gap-1 font-display font-semibold text-brand-600">
              <IconCirclePlus size={15} stroke={2} />
              <span className="text-[13px]">Add the first add-on</span>
            </button>
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

export default AddonCatalog;
