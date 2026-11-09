import { useEffect, useRef, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { Skeleton, Tooltip } from "@mantine/core";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { IconArrowLeft, IconChevronDown, IconInfoCircle, IconPhoto, IconRefresh, IconX } from "@tabler/icons-react";

import toast from "../../components/app/Toast";
import PasswordConfirmModal from "../../components/manager/PasswordConfirmModal";
import StaffLayout from "../../components/staff/StaffLayout";
import Button from "../../components/ui/Button";
import {
  CATALOG_KEY,
  CATEGORIES_KEY,
  MAX_PRICE,
  catalogItemKey,
  fetchCatalogFood,
  fieldErrors,
  imageProblem,
  saveFood,
} from "../../lib/catalog";
import { thumbnailImage } from "../../lib/images";
import { fetchCategories, formatPeso } from "../../lib/menu";
import { SALES_STATUS_NOTE } from "../../lib/salesStatusPreview";

const EMPTY = { food_name: "", category_id: "", description: "", price: "" };

const fieldClass = (error) =>
  `w-full rounded-[10px] border bg-[#f9f8f6] px-3.5 font-display text-[14px] text-ink outline-none transition-colors placeholder:text-[#b8b2aa] focus:bg-white ${
    error ? "border-[#e5322d]" : "border-[#ece8e2] focus:border-brand-400"
  }`;

function Label({ htmlFor, required, children }) {
  return (
    <label htmlFor={htmlFor} className="mb-2 block font-display text-[13.5px] font-semibold text-ink">
      {children}
      {required && <span className="ml-0.5 text-brand-500">*</span>}
    </label>
  );
}

function FieldError({ message }) {
  if (!message) return null;

  return <p className="m-0 mt-1.5 font-display text-[12px] text-[#e5322d]">{message}</p>;
}

function ImagePicker({ current, file, onPick, onClear, error }) {
  const input = useRef(null);
  const [dragging, setDragging] = useState(false);
  const [preview, setPreview] = useState(null);

  // A data: URL rather than a blob: one - the CSP's img-src allows data: and
  // has no reason to open up to blob:.
  useEffect(() => {
    if (!file) {
      setPreview(null);

      return undefined;
    }

    const reader = new FileReader();
    reader.onload = () => setPreview(typeof reader.result === "string" ? reader.result : null);
    reader.readAsDataURL(file);

    return () => reader.abort();
  }, [file]);

  const shown = preview ?? current;

  return (
    <div>
      <input
        ref={input}
        type="file"
        accept="image/png,image/jpeg,image/webp,image/gif"
        className="sr-only"
        data-testid="product-image-input"
        onChange={(event) => {
          onPick(event.target.files?.[0] ?? null);
          event.target.value = "";
        }}
      />
      <div
        role="button"
        tabIndex={0}
        onClick={() => input.current?.click()}
        onKeyDown={(event) => {
          if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            input.current?.click();
          }
        }}
        onDragOver={(event) => {
          event.preventDefault();
          setDragging(true);
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={(event) => {
          event.preventDefault();
          setDragging(false);
          onPick(event.dataTransfer.files?.[0] ?? null);
        }}
        aria-label={shown ? "Change the product image" : "Upload a product image"}
        className={`relative flex min-h-[128px] cursor-pointer items-center justify-center gap-4 rounded-[12px] border-2 border-dashed px-4 py-4 text-center outline-none transition-colors focus-visible:border-brand-400 ${
          error ? "border-[#e5322d] bg-[#fdf6f5]" : dragging ? "border-brand-400 bg-[#fff6ec]" : "border-[#e4ddd3] bg-white hover:bg-[#fdfbf8]"
        }`}
      >
        {shown ? (
          <>
            <img {...thumbnailImage(shown, 96)} alt="" className="h-[96px] w-[96px] rounded-[10px] object-cover ring-1 ring-[#f0e9df]" />
            <span className="text-left">
              <span className="block font-display text-[13px] font-semibold text-ink">
                {file ? file.name : "Current photo"}
              </span>
              <span className="block font-display text-[11.5px] text-[#8d8884]">
                Click or drop to {file ? "choose another" : "replace it"} · PNG, JPG, WEBP up to 5 MB
              </span>
            </span>
            {file && (
              <button
                type="button"
                onClick={(event) => {
                  event.stopPropagation();
                  onClear();
                }}
                aria-label="Remove the chosen photo"
                className="absolute right-2 top-2 grid h-7 w-7 place-items-center rounded-full bg-white text-[#8d8884] ring-1 ring-[#ece7e0] hover:text-ink"
              >
                <IconX size={14} stroke={2.2} />
              </button>
            )}
          </>
        ) : (
          <span className="flex flex-col items-center gap-1.5">
            <span className="grid h-9 w-9 place-items-center rounded-full bg-[#f4f1ec] text-[#8d8884]">
              <IconPhoto size={17} stroke={1.8} />
            </span>
            <span className="font-display text-[13.5px] text-[#5f5b58]">Click to upload product image</span>
            <span className="font-display text-[11px] text-[#a39f9b]">PNG, JPG, WEBP — up to 5 MB</span>
          </span>
        )}
      </div>
    </div>
  );
}

/**
 * Add Product and Edit Product (UC-MENU-010, -011, -013, -014).
 *
 * One form for both. A new dish needs a photo; an edit keeps the photo it has
 * unless a new one is chosen. A new price is confirmed with the Manager's
 * password - the server asks, this page shows the prompt and sends the same
 * change again - and every account is told once it is saved. Stock is not set
 * here: a new dish starts made to order, and its count is the Store Agent's.
 */
function ProductForm() {
  const { id } = useParams();
  const editing = Boolean(id);
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const [values, setValues] = useState(EMPTY);
  const [image, setImage] = useState(null);
  const [errors, setErrors] = useState({});
  const [askPassword, setAskPassword] = useState(false);
  const [passwordError, setPasswordError] = useState(null);
  const [loadedId, setLoadedId] = useState(null);

  const categories = useQuery({ queryKey: CATEGORIES_KEY, queryFn: fetchCategories, staleTime: 60 * 1000 });

  const existing = useQuery({
    queryKey: catalogItemKey(id),
    queryFn: ({ signal }) => fetchCatalogFood(id, { signal }),
    enabled: editing,
    // Always read fresh on opening: a cached copy from an earlier visit would
    // fill the form with values since changed, and saving would put them back.
    staleTime: 0,
    refetchOnMount: "always",
    retry: (count, error) => error?.status !== 404 && count < 2,
  });

  const food = existing.data?.data ?? null;
  const fresh = editing && existing.isFetchedAfterMount && !existing.isError;

  // Filled once per dish, from a read made for this visit, so neither an old
  // cache entry nor a later broadcast refetch overwrites what is being typed.
  useEffect(() => {
    if (fresh && food && loadedId !== food.id) {
      setLoadedId(food.id);
      setValues({
        food_name: food.food_name ?? "",
        category_id: food.category?.id ? String(food.category.id) : "",
        description: food.description ?? "",
        price: String(food.price ?? ""),
      });
    }
  }, [fresh, food, loadedId]);

  const ready = !editing || (fresh && loadedId === food?.id);

  useEffect(() => {
    document.title = `${editing ? "Edit" : "Add"} Product · Click n Chick`;

    return () => {
      document.title = "Chick N' Click";
    };
  }, [editing]);

  const priceChanged = editing && food && values.price !== "" && Number(values.price) !== Number(food.price);

  const save = useMutation({
    mutationFn: (password) => saveFood({ id, values, image, password }),
    onSuccess: (response) => {
      const saved = response?.data;

      // The answer is the dish as saved: shown at once, then confirmed by the refetch.
      if (saved?.id) {
        queryClient.setQueryData(catalogItemKey(saved.id), (current) => ({ ...current, data: saved }));
        queryClient.setQueryData(CATALOG_KEY, (current) =>
          current?.data
            ? {
                ...current,
                data: current.data.some((item) => item.id === saved.id)
                  ? current.data.map((item) => (item.id === saved.id ? saved : item))
                  : [...current.data, saved],
              }
            : current
        );
      }

      queryClient.invalidateQueries({ queryKey: ["foods"] });
      toast.success(response?.message, editing ? "Changes saved" : "Product added");
      navigate("/superadmin/menu");
    },
    onError: (error) => {
      const code = error?.payload?.error_code;

      if (code === "PASSWORD_CONFIRMATION_REQUIRED") {
        setPasswordError(null);
        setAskPassword(true);

        return;
      }

      if (code === "PASSWORD_CONFIRMATION_INVALID" || code === "PASSWORD_CONFIRMATION_THROTTLED") {
        setPasswordError(error.message);
        setAskPassword(true);

        return;
      }

      setAskPassword(false);

      if (error.status === 422) {
        setErrors(fieldErrors(error));
        toast.error("Check the highlighted fields.", "Not saved");

        return;
      }

      toast.error(error.message, "Not saved");
    },
  });

  const set = (key) => (event) => {
    const value = event.target.value;

    setValues((current) => ({ ...current, [key]: value }));
    setErrors((current) => ({ ...current, [key]: undefined }));
  };

  const pick = (file) => {
    const problem = imageProblem(file);

    setErrors((current) => ({ ...current, thumbnail: problem ?? undefined }));
    if (file && !problem) setImage(file);
  };

  const validate = () => {
    const found = {};
    const price = values.price.trim();

    if (!values.food_name.trim()) found.food_name = "Enter the dish name.";
    if (!values.category_id) found.category_id = "Pick a category.";
    if (price === "") found.price = "Enter the price.";
    else if (!/^\d+$/.test(price)) found.price = "Use whole pesos, with no centavos.";
    else if (Number(price) > MAX_PRICE) found.price = `The price can be at most ${formatPeso(MAX_PRICE)}.`;
    if (!editing && !image) found.thumbnail = "Add a photo of the dish.";

    setErrors(found);

    return Object.keys(found).length === 0;
  };

  const submit = (event) => {
    event.preventDefault();

    if (validate()) save.mutate(undefined);
  };

  const categoryOptions = (categories.data ?? []).filter((category) => category.name !== "Addons");

  return (
    <StaffLayout>
      <main className="flex flex-1 flex-col">
        <header className="border-b border-[#f0e9df] bg-white px-4 py-4 sm:px-6 lg:px-7">
          <h1 className="m-0 font-display text-[18px] font-bold tracking-[-0.3px] text-ink">Menu Management</h1>
          <p className="m-0 mt-0.5 font-display text-[12px] text-[#8d8884]">Create new dishes, set prices, archive items below</p>
        </header>

        <div className="flex flex-1 justify-center px-4 py-6 sm:px-6 lg:py-8">
          <div className="w-full max-w-[720px]">
            <Link
              to="/superadmin/menu"
              className="mb-3 inline-flex items-center gap-1 font-display text-[12.5px] font-semibold text-[#8d8884] no-underline hover:text-brand-600"
            >
              <IconArrowLeft size={14} stroke={2.2} />
              Back to Menu Management
            </Link>

            {editing && existing.isError ? (
              <div className="flex flex-col items-center gap-3 rounded-[16px] bg-white px-6 py-12 text-center ring-1 ring-[#f0e9df]">
                <p className="m-0 font-display text-[15px] font-semibold text-ink">
                  {existing.error?.status === 404 ? "This dish is no longer in the catalog." : "The dish could not be loaded."}
                </p>
                {existing.error?.status !== 404 && (
                  <Button size="sm" variant="outline" onClick={() => existing.refetch()}>
                    <IconRefresh size={16} stroke={2} />
                    Try again
                  </Button>
                )}
              </div>
            ) : (
              <form
                onSubmit={submit}
                noValidate
                data-testid="product-form"
                className="overflow-hidden rounded-[16px] bg-white shadow-[0_1px_2px_rgba(0,0,0,0.04)] ring-1 ring-[#ece7e0]"
              >
                <div className="border-b border-[#f3ede4] px-5 py-5 sm:px-8">
                  <h2 className="m-0 font-display text-[22px] font-bold text-brand-500">{editing ? "Edit Product" : "Add Product"}</h2>
                  <p className="m-0 mt-0.5 font-display text-[12px] text-[#8d8884]">
                    {editing
                      ? food
                        ? `Editing: ${food.food_name}`
                        : "Loading…"
                      : "Fill in the details below to register a new menu item."}
                  </p>
                </div>

                {!ready ? (
                  <div className="flex flex-col gap-4 px-5 py-6 sm:px-8" aria-hidden="true">
                    <Skeleton height={44} />
                    <Skeleton height={80} />
                    <Skeleton height={44} />
                    <Skeleton height={128} />
                  </div>
                ) : (
                  <div className="grid grid-cols-1 gap-x-4 gap-y-5 px-5 py-6 sm:grid-cols-2 sm:px-8">
                    <div>
                      <Label htmlFor="food_name" required>
                        Product Name
                      </Label>
                      <input
                        id="food_name"
                        value={values.food_name}
                        onChange={set("food_name")}
                        maxLength={255}
                        placeholder="e.g. Chicken Inasal"
                        aria-invalid={Boolean(errors.food_name)}
                        className={`${fieldClass(errors.food_name)} h-[44px]`}
                      />
                      <FieldError message={errors.food_name} />
                    </div>

                    <div>
                      <Label htmlFor="category_id" required>
                        Category
                      </Label>
                      <div className="relative">
                        <select
                          id="category_id"
                          value={values.category_id}
                          onChange={set("category_id")}
                          aria-invalid={Boolean(errors.category_id)}
                          className={`${fieldClass(errors.category_id)} h-[44px] appearance-none pr-9 ${values.category_id ? "" : "text-[#b8b2aa]"}`}
                        >
                          <option value="" disabled>
                            {categories.isPending ? "Loading categories…" : "Pick an option"}
                          </option>
                          {categoryOptions.map((category) => (
                            <option key={category.id} value={category.id} className="text-ink">
                              {category.name}
                            </option>
                          ))}
                        </select>
                        <IconChevronDown
                          size={15}
                          stroke={2}
                          className="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-[#a39f9b]"
                        />
                      </div>
                      <FieldError message={errors.category_id} />
                    </div>

                    <div className="sm:col-span-2">
                      <Label htmlFor="description">Description</Label>
                      <textarea
                        id="description"
                        value={values.description}
                        onChange={set("description")}
                        maxLength={255}
                        rows={3}
                        placeholder="Describe the dish, key ingredients, or serving notes…"
                        className={`${fieldClass(errors.description)} resize-y py-3`}
                      />
                      <p className="m-0 mt-1.5 flex justify-between font-display text-[11.5px] text-[#a39f9b]">
                        <span>Optional — shown on catalog cards</span>
                        <span>{values.description.length}/255</span>
                      </p>
                      <FieldError message={errors.description} />
                    </div>

                    <div>
                      <Label htmlFor="price" required>
                        Price (₱)
                      </Label>
                      <input
                        id="price"
                        inputMode="numeric"
                        value={values.price}
                        onChange={(event) => set("price")({ target: { value: event.target.value.replace(/[^\d]/g, "").slice(0, 5) } })}
                        placeholder="0"
                        aria-invalid={Boolean(errors.price)}
                        data-testid="product-price"
                        className={`${fieldClass(errors.price)} h-[44px]`}
                      />
                      {priceChanged && !errors.price && (
                        <p className="m-0 mt-1.5 font-display text-[11.5px] leading-snug text-[#b26a00]" data-testid="price-change-note">
                          Was {formatPeso(food.price)}. Saving asks for your password, and every account is told about the new price.
                        </p>
                      )}
                      <FieldError message={errors.price} />
                    </div>

                    <div>
                      <span className="mb-2 flex items-center gap-1.5 font-display text-[13.5px] font-semibold text-ink">
                        Sales Status
                        <Tooltip label={SALES_STATUS_NOTE} multiline w={240} withArrow>
                          <span className="inline-flex items-center gap-0.5 rounded-full border border-dashed border-[#e4ddd3] px-1.5 font-display text-[9.5px] font-bold uppercase tracking-[0.4px] text-[#a39f9b]">
                            Preview
                            <IconInfoCircle size={11} stroke={2} />
                          </span>
                        </Tooltip>
                      </span>
                      <div className="flex h-[44px] items-center rounded-[10px] border border-dashed border-[#e4ddd3] bg-[#fdfbf8] px-3.5 font-display text-[13px] text-[#a39f9b]">
                        Assigned from sales, not set by hand
                      </div>
                    </div>

                    <div className="sm:col-span-2">
                      <Label required={!editing}>Product Image</Label>
                      <ImagePicker
                        current={food?.thumbnail ?? null}
                        file={image}
                        onPick={pick}
                        onClear={() => setImage(null)}
                        error={errors.thumbnail}
                      />
                      <FieldError message={errors.thumbnail} />
                    </div>
                  </div>
                )}

                <div className="flex justify-end gap-2.5 border-t border-[#f3ede4] bg-[#fcfbfa] px-5 py-4 sm:px-8">
                  <Button variant="outline" size="sm" onClick={() => navigate("/superadmin/menu")} disabled={save.isPending} className="!border-[#e4ddd3] !text-ink">
                    Cancel
                  </Button>
                  <Button
                    type="submit"
                    size="sm"
                    loading={save.isPending && !askPassword}
                    disabled={save.isPending || !ready}
                    data-testid="product-save"
                  >
                    {editing ? "Save Changes" : "Save Product"}
                  </Button>
                </div>
              </form>
            )}
          </div>
        </div>
      </main>

      <PasswordConfirmModal
        opened={askPassword}
        onClose={() => setAskPassword(false)}
        onConfirm={(password) => save.mutate(password)}
        loading={save.isPending}
        error={passwordError}
        title="Confirm the price change"
        description={
          food
            ? `Changing ${food.food_name} from ${formatPeso(food.price)} to ${formatPeso(values.price || 0)} needs your password. Every account will be told about the new price.`
            : "Changing a price needs your password."
        }
      />
    </StaffLayout>
  );
}

export default ProductForm;
