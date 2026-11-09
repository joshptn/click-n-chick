import { useContext, useEffect, useMemo, useState } from "react";
import { Checkbox, Menu, Skeleton, Switch, Tooltip } from "@mantine/core";
import { useMediaQuery } from "@mantine/hooks";
import {
  IconChefHat,
  IconDotsVertical,
  IconInfoCircle,
  IconMinus,
  IconPackageOff,
  IconPhotoOff,
  IconPlus,
  IconRefresh,
  IconToolsKitchen2,
} from "@tabler/icons-react";

import AuthContext from "../../context/AuthContext";
import ConfirmActionModal from "../../components/manager/ConfirmActionModal";
import FilterBar, { FilterMeta } from "../../components/staff/FilterBar";
import FilterSelect from "../../components/staff/FilterSelect";
import LivePill from "../../components/staff/LivePill";
import ReadOnlyNotice from "../../components/staff/ReadOnlyNotice";
import StaffLayout from "../../components/staff/StaffLayout";
import StatusSummary from "../../components/staff/StatusSummary";
import StockControl from "../../components/staff/StockControl";
import Button from "../../components/ui/Button";
import { useStock } from "../../hooks/useStock";
import { thumbnailImage } from "../../lib/images";
import { formatPeso } from "../../lib/menu";
import { ROLES } from "../../lib/roles";
import { SALES_STATUS_NOTE, SALES_STATUS_PREVIEW, matchesSalesStatus, salesStatusOf } from "../../lib/salesStatusPreview";
import {
  PRICE_FILTERS,
  SORTS,
  STOCK_FILTERS,
  categoryTone,
  matchesPrice,
  matchesStock,
  matchesText,
} from "../../lib/stock";

const PAGE_TITLE = "Product availability · Click n Chick";

const DEFAULTS = { search: "", category: "all", stock: "all", price: "any", sales: "all", sort: "name" };

/** The summary cards double as the stock filter. */
const SUMMARY = [
  { key: "in_stock", label: "In Stock", tone: "green" },
  { key: "low_stock", label: "Low Stock", tone: "amber" },
  { key: "out_of_stock", label: "Sold Out", tone: "orange" },
  { key: "untracked", label: "Made to Order", tone: "blue" },
  { key: "switched_off", label: "On Archived", tone: "slate" },
];

function Thumb({ food }) {
  const [failed, setFailed] = useState(false);

  return (
    <span
      className={`grid h-[52px] w-[52px] shrink-0 place-items-center overflow-hidden rounded-[10px] bg-[#f4f1ec] ${
        food.is_available ? "" : "opacity-50 grayscale"
      }`}
    >
      {food.thumbnail && !failed ? (
        <img
          {...thumbnailImage(food.thumbnail, 52)}
          alt=""
          loading="lazy"
          onError={() => setFailed(true)}
          className="h-full w-full object-cover"
          draggable={false}
        />
      ) : (
        <IconPhotoOff size={18} stroke={1.6} className="text-[#cfc7bd]" />
      )}
    </span>
  );
}

function CategoryPill({ category }) {
  if (!category) return <span className="font-display text-[11px] text-[#a39f9b]">—</span>;

  return (
    <span
      className={`inline-flex rounded-full px-2.5 py-[3px] font-display text-[10px] font-bold uppercase tracking-[0.4px] ${categoryTone(category.name)}`}
    >
      {category.name}
    </span>
  );
}

function SalesPill({ food }) {
  const status = salesStatusOf(food);

  return (
    <Tooltip label={SALES_STATUS_NOTE} multiline w={240} withArrow openDelay={300}>
      <span
        className={`inline-flex min-w-[104px] justify-center rounded-[8px] border bg-white px-2.5 py-[5px] font-display text-[10.5px] font-medium ${status.tone}`}
        data-testid="sales-status"
      >
        {status.label}
      </span>
    </Tooltip>
  );
}

function AvailabilityToggle({ food, canManage, onToggle }) {
  return (
    <label className="flex flex-col items-center gap-1">
      <Switch
        checked={food.is_available}
        onChange={(event) => onToggle(food, event.currentTarget.checked)}
        disabled={!canManage}
        color="orange"
        size="sm"
        aria-label={`${food.food_name} on the menu`}
        data-testid="availability-switch"
      />
      <span className={`font-display text-[10.5px] ${food.is_available ? "text-[#2f9e44]" : "text-[#8d8884]"}`}>
        {food.is_available ? "On Menu" : "On Archived"}
      </span>
    </label>
  );
}

function PickBox({ food, selection }) {
  return (
    <Checkbox
      size="xs"
      color="orange"
      checked={selection.picked.has(food.id)}
      onChange={(event) => selection.onPick(food, event.currentTarget.checked)}
      aria-label={`Select ${food.food_name}`}
      data-testid="stock-pick"
    />
  );
}

const GRID =
  "grid grid-cols-[52px_minmax(170px,2fr)_minmax(104px,1fr)_minmax(84px,0.8fr)_minmax(120px,1fr)_minmax(140px,0.7fr)_minmax(156px,1.5fr)] items-center gap-x-5";

const PICK_GRID =
  "grid grid-cols-[18px_52px_minmax(170px,2fr)_minmax(104px,1fr)_minmax(84px,0.8fr)_minmax(120px,1fr)_minmax(140px,0.7fr)_minmax(156px,1.5fr)] items-center gap-x-4";

function TableView({ foods, loading, rowProps, selection }) {
  const grid = selection ? PICK_GRID : GRID;

  return (
    <div className="overflow-x-auto rounded-[16px] bg-white ring-1 ring-[#f0e9df]">
      <div className={selection ? "min-w-[1000px]" : "min-w-[960px]"}>
        <div
          className={`${grid} border-b border-[#f3ede4] px-5 py-3 font-display text-[10px] font-bold uppercase tracking-[0.8px] text-[#8d8884]`}
        >
          {selection && (
            <Checkbox
              size="xs"
              color="orange"
              checked={selection.all}
              indeterminate={selection.some}
              onChange={(event) => selection.onPickAll(event.currentTarget.checked)}
              aria-label="Select every dish shown"
              data-testid="stock-pick-all"
            />
          )}
          <span>Photo</span>
          <span>Product</span>
          <span>Category</span>
          <span>Price</span>
          <span className="inline-flex items-center gap-1">
            Sales status
            <Tooltip label={SALES_STATUS_NOTE} multiline w={240} withArrow>
              <IconInfoCircle size={12} stroke={2} className="text-[#c4bdb4]" />
            </Tooltip>
          </span>
          <span className="text-center">Availability</span>
          <span className="text-center">Stock</span>
        </div>

        {loading
          ? [0, 1, 2, 3, 4].map((key) => (
              <div key={key} className={`${grid} border-b border-[#f6f1ea] px-5 py-3 last:border-b-0`}>
                {selection && <Skeleton height={16} width={16} radius={4} />}
                <Skeleton height={52} width={52} radius={10} />
                <Skeleton height={14} width="70%" />
                <Skeleton height={20} width={80} radius="xl" />
                <Skeleton height={14} width={60} />
                <Skeleton height={26} width={104} radius={8} />
                <Skeleton height={28} width={40} radius="xl" className="justify-self-center" />
                <Skeleton height={20} width={140} radius="xl" className="justify-self-center" />
              </div>
            ))
          : foods.map((food) => (
              <div
                key={food.id}
                data-testid="stock-row"
                data-food={food.id}
                className={`${grid} border-b border-[#f6f1ea] px-5 py-3 transition-colors last:border-b-0 ${
                  selection?.picked.has(food.id) ? "bg-[#fff6ec]" : "hover:bg-[#fffaf4]"
                }`}
              >
                {selection && <PickBox food={food} selection={selection} />}
                <Thumb food={food} />
                <div className="min-w-0">
                  <p
                    className={`m-0 font-display text-[13.5px] font-semibold leading-snug ${food.is_available ? "text-ink" : "text-[#a39f9b]"}`}
                  >
                    {food.food_name}
                  </p>
                  <p className="m-0 mt-0.5 font-display text-[10.5px] text-[#a39f9b]">ID: {food.id}</p>
                </div>
                <div>
                  <CategoryPill category={food.category} />
                </div>
                <span className="font-display text-[13.5px] font-bold text-ink">{formatPeso(food.price)}</span>
                <div>
                  <SalesPill food={food} />
                </div>
                <AvailabilityToggle food={food} canManage={rowProps.canManage} onToggle={rowProps.onToggle} />
                <StockControl food={food} {...rowProps} busy={rowProps.isBusy(food.id)} />
              </div>
            ))}
      </div>
    </div>
  );
}

function CardView({ foods, loading, rowProps, selection }) {
  if (loading) {
    return (
      <div className="flex flex-col gap-2.5">
        {[0, 1, 2].map((key) => (
          <Skeleton key={key} height={128} radius={14} />
        ))}
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-2.5">
      {foods.map((food) => (
        <article
          key={food.id}
          data-testid="stock-row"
          data-food={food.id}
          className={`rounded-[14px] p-3.5 ring-1 ${selection?.picked.has(food.id) ? "bg-[#fff6ec] ring-[#ffd6b3]" : "bg-white ring-[#f0e9df]"}`}
        >
          <div className="flex items-start gap-3">
            {selection && (
              <span className="mt-[18px]">
                <PickBox food={food} selection={selection} />
              </span>
            )}
            <Thumb food={food} />
            <div className="min-w-0 flex-1">
              <p className={`m-0 font-display text-[13.5px] font-semibold leading-snug ${food.is_available ? "text-ink" : "text-[#a39f9b]"}`}>
                {food.food_name}
              </p>
              <div className="mt-1 flex flex-wrap items-center gap-1.5">
                <CategoryPill category={food.category} />
                <span className="font-display text-[10.5px] text-[#a39f9b]">ID: {food.id}</span>
              </div>
              <p className="m-0 mt-1 font-display text-[13.5px] font-bold text-ink">{formatPeso(food.price)}</p>
            </div>
            <AvailabilityToggle food={food} canManage={rowProps.canManage} onToggle={rowProps.onToggle} />
          </div>
          <div className="mt-3 flex items-center justify-between gap-2 border-t border-dashed border-[#f0e9df] pt-3">
            <SalesPill food={food} />
            <StockControl food={food} {...rowProps} busy={rowProps.isBusy(food.id)} />
          </div>
        </article>
      ))}
    </div>
  );
}

const dishCount = (count) => `${count} ${count === 1 ? "dish" : "dishes"}`;

const stepButton =
  "grid h-[28px] w-[28px] shrink-0 place-items-center rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-40";

/** One control for the count of every ticked dish; made-to-order dishes have none. */
function SharedStock({ foods, onStep, onSoldOut }) {
  const counted = foods.filter((food) => food.is_tracked);

  if (counted.length === 0) {
    return (
      <div className="flex justify-center">
        <span className="inline-flex h-[28px] items-center gap-1 rounded-full bg-[#eef4ff] px-3 font-display font-semibold text-[#2563eb]">
          <IconChefHat size={14} stroke={2} />
          <span className="text-[12px]">Made to order</span>
        </span>
      </div>
    );
  }

  const skipped = foods.length - counted.length;

  return (
    <div className="flex flex-col items-center gap-1" data-testid="shared-stock">
      <div className="flex items-center gap-1.5 text-[12px]">
        <button
          type="button"
          onClick={() => onStep(-1)}
          disabled={counted.every((food) => food.stock_quantity <= 0)}
          aria-label="One fewer of each selected dish"
          data-testid="shared-minus"
          className={`${stepButton} border border-[#ffd6b3] bg-white text-brand-600 hover:bg-brand-50`}
        >
          <IconMinus size={14} stroke={2.6} />
        </button>
        <button
          type="button"
          onClick={() => onStep(1)}
          disabled={counted.every((food) => food.stock_quantity >= 9999)}
          aria-label="One more of each selected dish"
          data-testid="shared-plus"
          className={`${stepButton} bg-brand-500 text-white shadow-[0_4px_10px_-4px_rgba(255,139,43,0.7)] hover:bg-brand-600`}
        >
          <IconPlus size={14} stroke={2.6} />
        </button>
        <Menu position="bottom-end" width={220} shadow="md" radius="md" withinPortal>
          <Menu.Target>
            <button type="button" aria-label="More stock actions for the selected dishes" data-testid="shared-menu" className={`${stepButton} bg-transparent text-[#a39f9b] hover:bg-[#f4f1ec] hover:text-ink`}>
              <IconDotsVertical size={15} stroke={2} />
            </button>
          </Menu.Target>
          <Menu.Dropdown>
            <Menu.Item leftSection={<IconPackageOff size={16} stroke={1.9} />} disabled={counted.every((food) => food.stock_quantity === 0)} onClick={onSoldOut}>
              Mark sold out (set to 0)
            </Menu.Item>
          </Menu.Dropdown>
        </Menu>
      </div>
      <span className="font-display text-[10.5px] text-[#8d8884]">{skipped > 0 ? `${skipped} made to order, not changed` : "Changes each by 1"}</span>
    </div>
  );
}

/** Its columns repeat PICK_GRID's, so the shared controls sit above their own. */
function SelectionBar({ foods, onClear, onSwitch, onStep, onSoldOut }) {
  const anyOn = foods.some((food) => food.is_available);

  return (
    <div
      data-testid="stock-selection"
      className="sticky top-[58px] z-20 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-[14px] bg-white px-3.5 py-2.5 shadow-[0_8px_20px_-12px_rgba(65,33,17,0.35)] ring-1 ring-[#ffd6b3] lg:top-2 xl:grid xl:grid-cols-[18px_52px_minmax(170px,2fr)_minmax(104px,1fr)_minmax(84px,0.8fr)_minmax(120px,1fr)_minmax(140px,0.7fr)_minmax(156px,1.5fr)] xl:px-5"
    >
      <div className="flex w-full items-center gap-3 xl:col-span-6 xl:w-auto">
        <span className="font-display text-[12.5px] font-semibold text-ink">{dishCount(foods.length)} selected</span>
        <button type="button" onClick={onClear} className="bg-transparent font-semibold text-brand-600 hover:text-brand-800">
          <span className="text-[11.5px]">Clear</span>
        </button>
      </div>

      <label className="flex flex-col items-center gap-1">
        <Switch
          checked={anyOn}
          onChange={(event) => onSwitch(event.currentTarget.checked)}
          color="orange"
          size="sm"
          aria-label="Selected dishes on the menu"
          data-testid="shared-availability"
        />
        <span className={`font-display text-[10.5px] ${anyOn ? "text-[#2f9e44]" : "text-[#8d8884]"}`}>{anyOn ? "On Menu" : "On Archived"}</span>
      </label>

      <SharedStock foods={foods} onStep={onStep} onSoldOut={onSoldOut} />
    </div>
  );
}

function confirmCopy({ mode, foods }) {
  const count = dishCount(foods.length);

  if (mode === "sold_out") {
    const skipped = foods.filter((food) => !food.is_tracked).length;

    return {
      title: `Mark ${count} sold out?`,
      points: [
        "Each count is set to 0 and the old counts are not kept.",
        "Customers cannot order them until you enter a new count.",
        ...(skipped > 0 ? ["Made-to-order dishes have no count, so they do not change."] : []),
        "Orders already placed are not affected.",
      ],
      confirmLabel: "Mark sold out",
      danger: true,
    };
  }

  if (mode === "on") {
    const empty = foods.filter((food) => food.is_tracked && food.stock_quantity === 0).length;

    return {
      title: `Turn on ${count}?`,
      points: [
        "They go back on the customer menu right away.",
        ...(empty > 0 ? [`${dishCount(empty)} with a count of 0 will show as Sold out until you enter a new count.`] : []),
      ],
      confirmLabel: "Turn on",
    };
  }

  return {
    title: `Turn off ${count}?`,
    points: ["They leave the customer menu right away.", "Their stock counts stay as they are.", "Orders already placed are not affected."],
    confirmLabel: "Turn off",
    danger: true,
  };
}

/**
 * Product Availability: the Store Agent's stock page (BR-29, UC-MENU-016).
 *
 * The agent changes two things here - how many of a dish are left, and whether
 * it is on the menu - one dish at a time or for the ticked dishes together.
 * Names, prices, categories and photos are the Store Manager's and are shown
 * read-only. Sales, cancellations and other agents' changes arrive over the
 * `menu` channel.
 */
function ProductAvailability() {
  const { user } = useContext(AuthContext);
  const stock = useStock();
  const wide = useMediaQuery("(min-width: 64em)", true);
  const [filters, setFilters] = useState(DEFAULTS);
  const [picked, setPicked] = useState(() => new Set());
  const [confirming, setConfirming] = useState(null); // { mode: "on" | "off" | "sold_out", foods } | null

  // The server's word decides; the role is only a fallback while it loads.
  const canManage = stock.meta ? Boolean(stock.meta.can_manage) : user?.role === ROLES.ADMIN;
  const threshold = stock.meta?.low_stock_threshold ?? 5;

  const set = (key) => (value) => setFilters((current) => ({ ...current, [key]: value }));
  const filtered = Object.keys(DEFAULTS).some((key) => key !== "sort" && filters[key] !== DEFAULTS[key]);

  const visible = useMemo(() => {
    const sort = SORTS.find((option) => option.key === filters.sort) ?? SORTS[0];

    return stock.foods
      .filter((food) => matchesText(food, filters.search))
      .filter((food) => filters.category === "all" || String(food.category?.id) === filters.category)
      .filter((food) => matchesStock(food, filters.stock))
      .filter((food) => matchesPrice(food, filters.price))
      .filter((food) => matchesSalesStatus(food, filters.sales))
      .sort(sort.compare);
  }, [stock.foods, filters]);

  // Only dishes on screen count, so a filter can never hide what is switched.
  const pickedFoods = useMemo(() => visible.filter((food) => picked.has(food.id)), [visible, picked]);

  const pick = (food, on) =>
    setPicked((current) => {
      const next = new Set(current);

      if (on) next.add(food.id);
      else next.delete(food.id);

      return next;
    });

  const clearPicked = () => setPicked(new Set());

  const selection = canManage
    ? {
        picked,
        onPick: pick,
        all: visible.length > 0 && pickedFoods.length === visible.length,
        some: pickedFoods.length > 0 && pickedFoods.length < visible.length,
        onPickAll: (on) => setPicked(on ? new Set(visible.map((food) => food.id)) : new Set()),
      }
    : null;

  const confirmAction = () =>
    confirming.mode === "sold_out" ? stock.soldOutMany(confirming.foods) : stock.switchMany(confirming.foods, confirming.mode === "on");

  const categoryCounts = useMemo(
    () =>
      stock.foods.reduce((total, food) => {
        if (food.category?.id) total[food.category.id] = (total[food.category.id] ?? 0) + 1;

        return total;
      }, {}),
    [stock.foods]
  );

  const summaryCards = SUMMARY.map((card) => ({ ...card, total: stock.summary?.[card.key] ?? 0 }));
  const soldOut = stock.summary?.out_of_stock ?? 0;

  useEffect(() => {
    document.title = soldOut > 0 ? `(${soldOut}) Sold out · Click n Chick` : PAGE_TITLE;
  }, [soldOut]);

  useEffect(() => () => {
    document.title = "Chick N' Click";
  }, []);

  const rowProps = {
    threshold,
    canManage,
    isBusy: stock.isBusy,
    onStep: stock.step,
    onRecount: stock.recount,
    onToggle: stock.toggle,
  };

  return (
    <StaffLayout>
      <main className="flex flex-1 flex-col">
        <header className="border-b border-[#f0e9df] bg-white px-4 py-4 sm:px-6 lg:px-7">
          <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2.5">
                <h1 className="m-0 font-display text-[18px] font-bold tracking-[-0.3px] text-ink">Product Availability</h1>
                <LivePill />
              </div>
              <p className="m-0 mt-0.5 font-display text-[12px] text-[#8d8884]">
                Adjust stock and switch dishes on or off. Names, prices and categories are set by the Store Manager.
              </p>
            </div>
          </div>

          <div className="mt-4">
            <StatusSummary
              columns={summaryCards}
              loading={stock.isLoading}
              onSelect={(key) => set("stock")(filters.stock === key ? "all" : key)}
            />
          </div>
        </header>

        <div className="flex flex-1 flex-col gap-3 px-4 py-4 sm:px-6 lg:px-7">
          {!canManage && stock.meta && (
            <ReadOnlyNotice>Stock and availability are changed by the Store Agent (BR-29). You are viewing them read-only.</ReadOnlyNotice>
          )}

          <FilterBar
            testId="stock-filters"
            search={{
              value: filters.search,
              onChange: set("search"),
              placeholder: "Search dish name or ID…",
              title: "Search by dish name, ID or category",
              label: "Search dishes",
            }}
            tabs={{
              items: [
                { key: "all", label: "ALL", count: stock.isLoading ? undefined : stock.foods.length },
                ...stock.categories.map((category) => ({ key: String(category.id), label: category.name, count: categoryCounts[category.id] ?? 0 })),
              ],
              value: filters.category,
              onChange: set("category"),
              label: "Filter by category",
              testIdPrefix: "category",
            }}
            searchBelow
            below={
              <>
                <FilterSelect
                  label="Stock"
                  value={filters.stock}
                  onChange={set("stock")}
                  options={STOCK_FILTERS}
                  active={filters.stock !== "all"}
                  testId="filter-stock"
                />
                <FilterSelect
                  label="Price"
                  value={filters.price}
                  onChange={set("price")}
                  options={PRICE_FILTERS}
                  active={filters.price !== "any"}
                  testId="filter-price"
                />
                <FilterSelect
                  label="Sort"
                  value={filters.sort}
                  onChange={set("sort")}
                  options={SORTS}
                  active={false}
                  testId="filter-sort"
                />

                {/* Kept apart from the real filters: its values are a placeholder. */}
                <div className="col-span-2 flex h-[34px] items-center gap-1.5 rounded-full border border-dashed border-[#e4ddd3] bg-[#fdfbf8] pl-2.5 pr-0.5 sm:col-span-1 [&_select]:h-[28px]">
                  <Tooltip label={SALES_STATUS_NOTE} multiline w={240} withArrow>
                    <span className="inline-flex shrink-0 items-center gap-1 font-display text-[10px] font-bold uppercase tracking-[0.5px] text-[#a39f9b]">
                      Preview
                      <IconInfoCircle size={12} stroke={2} />
                    </span>
                  </Tooltip>
                  <FilterSelect
                    label="Sales status (preview)"
                    value={filters.sales}
                    onChange={set("sales")}
                    options={[{ key: "all", label: "Any sales status" }, ...SALES_STATUS_PREVIEW]}
                    active={filters.sales !== "all"}
                    testId="filter-sales"
                  />
                </div>

                <FilterMeta onClear={filtered ? () => setFilters((current) => ({ ...DEFAULTS, sort: current.sort })) : undefined}>
                  {!stock.isLoading &&
                    (visible.length === stock.foods.length ? `${stock.foods.length} dishes` : `${visible.length} of ${stock.foods.length} dishes`)}
                </FilterMeta>
              </>
            }
          />

          {selection && pickedFoods.length > 0 && (
            <SelectionBar
              foods={pickedFoods}
              onClear={clearPicked}
              onSwitch={(on) => setConfirming({ mode: on ? "on" : "off", foods: pickedFoods })}
              onStep={(by) => stock.stepMany(pickedFoods, by)}
              onSoldOut={() => setConfirming({ mode: "sold_out", foods: pickedFoods })}
            />
          )}

          {stock.isError ? (
            <div className="flex flex-col items-center gap-3 rounded-[16px] bg-white px-6 py-12 text-center ring-1 ring-[#f0e9df]">
              <p className="m-0 font-display text-[15px] font-semibold text-ink">The menu could not be loaded.</p>
              <p className="m-0 font-display text-[13px] text-[#8d8884]">{stock.error?.message}</p>
              <Button size="sm" variant="outline" onClick={() => stock.refetch()}>
                <IconRefresh size={16} stroke={2} />
                Try again
              </Button>
            </div>
          ) : !stock.isLoading && visible.length === 0 ? (
            <div
              data-testid="stock-empty"
              className="flex flex-col items-center gap-2 rounded-[16px] border border-dashed border-[#e7dfd4] bg-white/60 px-6 py-12 text-center"
            >
              <IconToolsKitchen2 size={28} stroke={1.6} className="text-[#cfc7bd]" />
              <p className="m-0 font-display text-[14px] font-semibold text-ink">
                {stock.foods.length === 0 ? "There are no dishes on the menu yet." : "No dish matches these filters."}
              </p>
              {filtered && (
                <Button size="sm" variant="ghost" onClick={() => setFilters((current) => ({ ...DEFAULTS, sort: current.sort }))}>
                  Clear filters
                </Button>
              )}
            </div>
          ) : wide ? (
            <TableView foods={visible} loading={stock.isLoading} rowProps={rowProps} selection={selection} />
          ) : (
            <CardView foods={visible} loading={stock.isLoading} rowProps={rowProps} selection={selection} />
          )}
        </div>
      </main>

      <ConfirmActionModal opened={Boolean(confirming)} onClose={() => setConfirming(null)} onConfirm={confirmAction} {...(confirming ? confirmCopy(confirming) : {})} />
    </StaffLayout>
  );
}

export default ProductAvailability;
