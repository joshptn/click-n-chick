import { useEffect, useState } from "react";
import { Modal } from "@mantine/core";

import Button from "../ui/Button";

/**
 * Add a category, or rename one (UC-MENU-007/008). The server trims the name
 * and refuses a second spelling of one that exists; its message shows inline.
 */
function CategoryFormModal({ opened, onClose, onSubmit, loading, category = null }) {
  const [name, setName] = useState("");
  const [error, setError] = useState(null);
  const renaming = Boolean(category);

  useEffect(() => {
    if (opened) {
      setName(category?.name ?? "");
      setError(null);
    }
  }, [opened, category]);

  const trimmed = name.trim();
  const unchanged = renaming && trimmed === category.name;

  const submit = async (event) => {
    event.preventDefault();

    if (!trimmed || unchanged) return;

    try {
      await onSubmit(trimmed);
      onClose();
    } catch (failure) {
      setError(failure?.payload?.errors?.name?.[0] ?? failure.message);
    }
  };

  return (
    <Modal
      opened={opened}
      onClose={loading ? () => {} : onClose}
      centered
      radius={16}
      title={
        <span className="block">
          <span className="block font-display text-[17px] font-bold text-cocoa-900">
            {renaming ? "Rename Category" : "Add Category"}
          </span>
          <span className="block font-display text-[12px] font-normal text-[#8d8884]">
            {renaming ? `Rename “${category.name}” everywhere it appears` : "Create a new product category below"}
          </span>
        </span>
      }
      styles={{ header: { borderBottom: "1px solid #f3ede4", paddingBottom: 12 } }}
    >
      <form onSubmit={submit} className="pt-3" data-testid="category-form">
        <label className="block">
          <span className="mb-2 block font-display text-[12.5px] font-semibold text-[#5f5b58]">Category Name</span>
          <input
            value={name}
            onChange={(event) => {
              setName(event.target.value.slice(0, 60));
              setError(null);
            }}
            placeholder="CATEGORY NAME"
            data-autofocus
            aria-invalid={Boolean(error)}
            data-testid="category-name"
            className={`h-[48px] w-full rounded-[12px] border-2 border-dashed bg-white px-4 text-center font-display text-[14px] font-semibold uppercase tracking-[0.6px] text-ink outline-none transition-colors placeholder:text-[#cfc9c1] focus:border-brand-400 ${
              error ? "border-[#e5322d]" : "border-[#e4ddd3]"
            }`}
          />
        </label>
        {error && <p className="m-0 mt-2 font-display text-[12px] text-[#e5322d]">{error}</p>}

        <div className="mt-5 grid grid-cols-2 gap-2.5">
          <Button variant="outline" onClick={onClose} disabled={loading} className="!border-[#e4ddd3] !text-[#5f5b58]">
            Cancel
          </Button>
          <Button type="submit" loading={loading} disabled={!trimmed || unchanged} data-testid="category-submit">
            {renaming ? "Save Name" : "Add Category"}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

export default CategoryFormModal;
