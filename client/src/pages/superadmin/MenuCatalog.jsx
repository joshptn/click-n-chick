import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Skeleton, Tooltip } from "@mantine/core";
import { useMediaQuery } from "@mantine/hooks";
import {
  IconArchive,
  IconArchiveOff,
  IconCategory,
  IconCirclePlus,
  IconInfoCircle,
  IconPencil,
  IconPhotoOff,
  IconRefresh,
  IconToolsKitchen2,
  IconTrash,
} from "@tabler/icons-react";

import CategoryFormModal from "../../components/manager/CategoryFormModal";
import DeleteProductModal from "../../components/manager/DeleteProductModal";
import ManageCategoriesModal from "../../components/manager/ManageCategoriesModal";
import ProductPreviewModal from "../../components/manager/ProductPreviewModal";
import FilterBar, { FilterMeta } from "../../components/staff/FilterBar";
import FilterSelect from "../../components/staff/FilterSelect";
import LivePill from "../../components/staff/LivePill";
import StaffLayout from "../../components/staff/StaffLayout";
import Button from "../../components/ui/Button";
import { useCatalog } from "../../hooks/useCatalog";
import { STATUS_FILTERS, isArchived, matchesStatus } from "../../lib/catalog";
import { thumbnailImage } from "../../lib/images";
import { formatPeso } from "../../lib/menu";
import { SALES_STATUS_NOTE, SALES_STATUS_PREVIEW, matchesSalesStatus, salesStatusOf } from "../../lib/salesStatusPreview";
import { PRICE_FILTERS, SORTS, categoryTone, matchesPrice, matchesText } from "../../lib/stock";

const PAGE_TITLE = "Menu Management · Click n Chick";

const DEFAULTS = { search: "", category: "all", status: "all", price: "any", sales: "all" };

const byName = SORTS.find((option) => option.key === "name").compare;

function Thumb({ food, onOpen }) {
  const [failed, setFailed] = useState(false);

  return (
    <button
      type="button"
      onClick={() => onOpen(food)}
      aria-label={`Preview ${food.food_name}`}
      className={`grid h-[52px] w-[52px] shrink-0 place-items-center overflow-hidden rounded-[10px] bg-[#f4f1ec] outline-none ring-brand-300 transition-transform hover:scale-[1.03] focus-visible:ring-2 ${
        isArchived(food) ? "opacity-50 grayscale" : ""
      }`}
    >
      {food.thumbnail && !failed ? (
        <img {...thumbnailImage(food.thumbnail, 52)} alt="" loading="lazy" onError={() => setFailed(true)} className="h-full w-full object-cover" draggable={false} />
      ) : (
        <IconPhotoOff size={18} stroke={1.6} className="text-[#cfc7bd]" />
      )}
    </button>
  );
}

function CategoryPill({ category }) {
  if (!category) return <span className="font-display text-[11px] text-[#a39f9b]">No category</span>;

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

function StatusPill({ food }) {
  const archived = isArchived(food);

  return (
    <span
      data-testid="menu-status"
      className={`inline-flex min-w-[86px] justify-center rounded-full border px-3 py-[5px] font-display text-[11px] font-semibold ${
        archived ? "border-[#dcd6ce] bg-[#f6f3ef] text-[#6f6b68]" : "border-[#8fd6a2] bg-white text-[#2f9e44]"
      }`}
    >
      {archived ? "Archived" : "On Menu"}
    </span>
  );
}

function Actions({ food, busy, onArchive, onDelete }) {
  const archived = isArchived(food);

  return (
    <div className="flex items-center justify-end gap-1.5">
      <Link
        to={`/superadmin/menu/${food.id}/edit`}
        data-testid="product-edit"
        className="inline-flex h-[30px] items-center gap-1 rounded-[8px] border border-[#ffd6b3] bg-[#fff6ec] px-2.5 font-display text-[11.5px] font-semibold text-brand-600 no-underline transition-colors hover:bg-[#ffecd9]"
      >
        <IconPencil size={13} stroke={2.2} />
        Edit
      </Link>
      <button
        type="button"
        disabled={busy}
        onClick={() => onArchive(food, !archived)}
        data-testid="product-archive"
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
          onClick={() => onDelete(food)}
          aria-label={`Delete ${food.food_name}`}
          data-testid="product-delete"
          className="grid h-[30px] w-[30px] place-items-center rounded-[8px] border border-[#f8d4d2] bg-white text-[#e5322d] transition-colors hover:bg-[#fdecec]"
        >
          <IconTrash size={14} stroke={2.1} />
        </button>
      </Tooltip>
    </div>
  );
}

const GRID =
  "grid grid-cols-[52px_minmax(170px,2fr)_minmax(96px,1fr)_minmax(84px,0.8fr)_minmax(112px,1fr)_minmax(92px,0.8fr)_minmax(216px,1.3fr)] items-center gap-x-4";

function TableView({ foods, loading, rowProps }) {
  return (
    <div className="overflow-x-auto rounded-[16px] bg-white ring-1 ring-[#f0e9df]">
      <div className="min-w-[940px]">
        <div
          className={`${GRID} border-b border-[#f3ede4] px-5 py-3 font-display text-[10px] font-bold uppercase tracking-[0.8px] text-[#8d8884]`}
        >
          <span>Photo</span>
          <span>Product name</span>
          <span>Category</span>
          <span>Price</span>
          <span className="inline-flex items-center gap-1">
            Sales status
            <Tooltip label={SALES_STATUS_NOTE} multiline w={240} withArrow>
              <IconInfoCircle size={12} stroke={2} className="text-[#c4bdb4]" />
            </Tooltip>
          </span>
          <span>Status</span>
          <span className="text-right">Actions</span>
        </div>

        {loading
          ? [0, 1, 2, 3, 4].map((key) => (
              <div key={key} className={`${GRID} border-b border-[#f6f1ea] px-5 py-3 last:border-b-0`}>
                <Skeleton height={52} width={52} radius={10} />
                <Skeleton height={14} width="70%" />
                <Skeleton height={20} width={80} radius="xl" />
                <Skeleton height={14} width={60} />
                <Skeleton height={26} width={104} radius={8} />
                <Skeleton height={26} width={86} radius="xl" />
                <Skeleton height={30} width={180} radius={8} className="justify-self-end" />
              </div>
            ))
          : foods.map((food) => (
              <div
                key={food.id}
                data-testid="catalog-row"
                data-food={food.id}
                className={`${GRID} border-b border-[#f6f1ea] px-5 py-3 transition-colors last:border-b-0 hover:bg-[#fffaf4]`}
              >
                <Thumb food={food} onOpen={rowProps.onPreview} />
                <button
                  type="button"
                  onClick={() => rowProps.onPreview(food)}
                  className="min-w-0 bg-transparent text-left"
                >
                  <span
                    className={`block font-display text-[13.5px] font-semibold leading-snug ${isArchived(food) ? "text-[#a39f9b]" : "text-ink"}`}
                  >
                    {food.food_name}
                  </span>
                  <span className="mt-0.5 block font-display text-[10.5px] text-[#a39f9b]">ID: {food.id}</span>
                </button>
                <div>
                  <CategoryPill category={food.category} />
                </div>
                <span className="font-display text-[13.5px] font-bold text-ink">{formatPeso(food.price)}</span>
                <div>
                  <SalesPill food={food} />
                </div>
                <div>
                  <StatusPill food={food} />
                </div>
                <Actions food={food} busy={rowProps.isBusy(food.id)} onArchive={rowProps.onArchive} onDelete={rowProps.onDelete} />
              </div>
            ))}
      </div>
    </div>
  );
}

function CardView({ foods, loading, rowProps }) {
  if (loading) {
    return (
      <div className="flex flex-col gap-2.5">
        {[0, 1, 2].map((key) => (
          <Skeleton key={key} height={148} radius={14} />
        ))}
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-2.5">
      {foods.map((food) => (
        <article key={food.id} data-testid="catalog-row" data-food={food.id} className="rounded-[14px] bg-white p-3.5 ring-1 ring-[#f0e9df]">
          <div className="flex items-start gap-3">
            <Thumb food={food} onOpen={rowProps.onPreview} />
            <button type="button" onClick={() => rowProps.onPreview(food)} className="min-w-0 flex-1 bg-transparent text-left">
              <span
                className={`block font-display text-[13.5px] font-semibold leading-snug ${isArchived(food) ? "text-[#a39f9b]" : "text-ink"}`}
              >
                {food.food_name}
              </span>
              <span className="mt-1 flex flex-wrap items-center gap-1.5">
                <CategoryPill category={food.category} />
                <span className="font-display text-[10.5px] text-[#a39f9b]">ID: {food.id}</span>
              </span>
              <span className="mt-1 block font-display text-[13.5px] font-bold text-ink">{formatPeso(food.price)}</span>
            </button>
            <StatusPill food={food} />
          </div>
          <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-dashed border-[#f0e9df] pt-3">
            <SalesPill food={food} />
            <Actions food={food} busy={rowProps.isBusy(food.id)} onArchive={rowProps.onArchive} onDelete={rowProps.onDelete} />
          </div>
        </article>
      ))}
    </div>
  );
}

/**
 * Menu Management: the Store Manager's dishes and categories (BR-29,
 * UC-MENU-007..014).
 *
 * Adding and editing open their own page; archiving is the availability
 * switch and is undone with Unarchive; deleting removes a dish from the
 * catalogue while past orders keep it. Every change reaches the customer menu
 * and the Store Agent's page over the `menu` channel.
 */
function MenuCatalog() {
  const catalog = useCatalog();
  const wide = useMediaQuery("(min-width: 75em)", true);
  const [filters, setFilters] = useState(DEFAULTS);
  const [previewing, setPreviewing] = useState(null);
  const [deleting, setDeleting] = useState(null);
  const [categoryForm, setCategoryForm] = useState(null); // { category } | null
  const [managing, setManaging] = useState(false);

  const set = (key) => (value) => setFilters((current) => ({ ...current, [key]: value }));
  const filtered = Object.keys(DEFAULTS).some((key) => filters[key] !== DEFAULTS[key]);
  const clear = () => setFilters(DEFAULTS);

  const visible = useMemo(
    () =>
      catalog.foods
        .filter((food) => matchesText(food, filters.search))
        .filter((food) => filters.category === "all" || String(food.category?.id) === filters.category)
        .filter((food) => matchesStatus(food, filters.status))
        .filter((food) => matchesPrice(food, filters.price))
        .filter((food) => matchesSalesStatus(food, filters.sales))
        .sort(byName),
    [catalog.foods, filters]
  );

  const counts = useMemo(
    () =>
      catalog.foods.reduce((total, food) => {
        if (food.category?.id) total[food.category.id] = (total[food.category.id] ?? 0) + 1;

        return total;
      }, {}),
    [catalog.foods]
  );

  const archivedCount = catalog.foods.filter(isArchived).length;

  // A category deleted elsewhere cannot stay selected.
  useEffect(() => {
    if (filters.category !== "all" && !catalog.categories.some((category) => String(category.id) === filters.category)) {
      setFilters((current) => ({ ...current, category: "all" }));
    }
  }, [catalog.categories, filters.category]);

  useEffect(() => {
    document.title = PAGE_TITLE;

    return () => {
      document.title = "Chick N' Click";
    };
  }, []);

  const rowProps = {
    isBusy: catalog.isBusy,
    onArchive: catalog.archive,
    onDelete: setDeleting,
    onPreview: setPreviewing,
  };

  const submitCategory = (name) =>
    categoryForm?.category
      ? catalog.rename.mutateAsync({ id: categoryForm.category.id, name })
      : catalog.addCategory.mutateAsync(name);

  const removeCategory = async (category) => {
    try {
      await catalog.dropCategory.mutateAsync(category);
    } catch {
      // The hook has said why; the list refreshes either way.
    }
  };

  return (
    <StaffLayout>
      <main className="flex flex-1 flex-col">
        <header className="border-b border-[#f0e9df] bg-white px-4 py-4 sm:px-6 lg:px-7">
          <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2.5">
                <h1 className="m-0 font-display text-[18px] font-bold tracking-[-0.3px] text-ink">Menu Management</h1>
                <LivePill />
              </div>
              <p className="m-0 mt-0.5 font-display text-[12px] text-[#8d8884]">
                Create new dishes, set prices, archive items below
              </p>
            </div>

            <div className="grid grid-cols-2 gap-2 sm:flex sm:items-center">
              <button
                type="button"
                onClick={() => setCategoryForm({ category: null })}
                data-testid="add-category"
                className="inline-flex h-[38px] items-center justify-center gap-1.5 rounded-full border border-[#ffd6b3] bg-[#fff6ec] px-4 font-display font-semibold text-brand-600 transition-colors hover:bg-[#ffecd9]"
              >
                <IconCirclePlus size={16} stroke={2} />
                <span className="text-[13px]">Add Category</span>
              </button>
              <Link
                to="/superadmin/menu/new"
                data-testid="add-product"
                className="inline-flex h-[38px] items-center justify-center gap-1.5 rounded-full bg-brand-500 px-4 font-display text-[13px] font-semibold text-white no-underline shadow-[0_6px_16px_-4px_rgba(255,139,43,0.5)] transition-colors hover:bg-brand-600"
              >
                <IconCirclePlus size={16} stroke={2} />
                Add Product
              </Link>
            </div>
          </div>
        </header>

        <div className="flex flex-1 flex-col gap-3 px-4 py-4 sm:px-6 lg:px-7">
          <FilterBar
            testId="catalog-filters"
            search={{
              value: filters.search,
              onChange: set("search"),
              placeholder: "Search dish name…",
              title: "Search by dish name, ID or category",
              label: "Search dishes",
            }}
            tabs={{
              items: [
                { key: "all", label: "ALL", count: catalog.isLoading ? undefined : catalog.foods.length },
                ...catalog.categories.map((category) => ({ key: String(category.id), label: category.name, count: counts[category.id] ?? 0 })),
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
                  label="Status"
                  value={filters.status}
                  onChange={set("status")}
                  options={STATUS_FILTERS.map((option) =>
                    option.key === "archived" ? { ...option, label: `Archived (${archivedCount})` } : option
                  )}
                  active={filters.status !== "all"}
                  testId="filter-status"
                />
                <FilterSelect
                  label="Price"
                  value={filters.price}
                  onChange={set("price")}
                  options={PRICE_FILTERS}
                  active={filters.price !== "any"}
                  testId="filter-price"
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

                <FilterMeta onClear={filtered ? clear : undefined}>
                  {!catalog.isLoading &&
                    (visible.length === catalog.foods.length ? `${catalog.foods.length} dishes` : `${visible.length} of ${catalog.foods.length} dishes`)}
                </FilterMeta>
              </>
            }
          >
            <button
              type="button"
              onClick={() => setManaging(true)}
              data-testid="manage-categories"
              className="inline-flex h-[34px] shrink-0 items-center justify-center gap-1 rounded-full border border-dashed border-[#e4ddd3] bg-transparent px-3 font-display font-semibold text-[#8d8884] transition-colors hover:border-brand-300 hover:text-brand-600"
            >
              <IconCategory size={14} stroke={2} />
              <span className="text-[11.5px]">Manage categories</span>
            </button>
          </FilterBar>

          {catalog.isError ? (
            <div className="flex flex-col items-center gap-3 rounded-[16px] bg-white px-6 py-12 text-center ring-1 ring-[#f0e9df]">
              <p className="m-0 font-display text-[15px] font-semibold text-ink">The catalog could not be loaded.</p>
              <p className="m-0 font-display text-[13px] text-[#8d8884]">{catalog.error?.message}</p>
              <Button size="sm" variant="outline" onClick={() => catalog.refetch()}>
                <IconRefresh size={16} stroke={2} />
                Try again
              </Button>
            </div>
          ) : !catalog.isLoading && visible.length === 0 ? (
            <div
              data-testid="catalog-empty"
              className="flex flex-col items-center gap-2 rounded-[16px] border border-dashed border-[#e7dfd4] bg-white/60 px-6 py-12 text-center"
            >
              <IconToolsKitchen2 size={28} stroke={1.6} className="text-[#cfc7bd]" />
              <p className="m-0 font-display text-[14px] font-semibold text-ink">
                {catalog.foods.length === 0 ? "The catalog is empty." : "No dish matches these filters."}
              </p>
              {filtered ? (
                <Button size="sm" variant="ghost" onClick={clear}>
                  Clear filters
                </Button>
              ) : (
                <Link to="/superadmin/menu/new" className="font-display text-[13px] font-semibold text-brand-600">
                  Add the first dish
                </Link>
              )}
            </div>
          ) : wide ? (
            <TableView foods={visible} loading={catalog.isLoading} rowProps={rowProps} />
          ) : (
            <CardView foods={visible} loading={catalog.isLoading} rowProps={rowProps} />
          )}
        </div>
      </main>

      <ProductPreviewModal food={previewing} opened={Boolean(previewing)} onClose={() => setPreviewing(null)} />

      <DeleteProductModal
        food={deleting}
        opened={Boolean(deleting)}
        onClose={() => setDeleting(null)}
        onConfirm={(food) => catalog.remove.mutateAsync(food)}
        loading={catalog.remove.isPending}
      />

      <ManageCategoriesModal
        opened={managing && !categoryForm}
        onClose={() => setManaging(false)}
        categories={catalog.categories}
        counts={counts}
        onAdd={() => setCategoryForm({ category: null })}
        onRename={(category) => setCategoryForm({ category })}
        onDelete={removeCategory}
        deletingId={catalog.dropCategory.isPending ? catalog.dropCategory.variables?.id : null}
      />

      <CategoryFormModal
        opened={Boolean(categoryForm)}
        category={categoryForm?.category ?? null}
        onClose={() => setCategoryForm(null)}
        onSubmit={submitCategory}
        loading={catalog.addCategory.isPending || catalog.rename.isPending}
      />
    </StaffLayout>
  );
}

export default MenuCatalog;
