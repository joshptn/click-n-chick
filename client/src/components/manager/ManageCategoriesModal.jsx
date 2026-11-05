import { useEffect, useState } from "react";
import { Modal, Tooltip } from "@mantine/core";
import { IconPencil, IconPlus, IconTrash } from "@tabler/icons-react";

import { categoryTone } from "../../lib/stock";

/**
 * Every category with how many dishes it holds (archived ones included):
 * rename any of them, delete one only when it is empty (UC-MENU-008/009).
 * Deleting asks a second time in place.
 */
function ManageCategoriesModal({ opened, onClose, categories, counts, onAdd, onRename, onDelete, deletingId }) {
  const [confirming, setConfirming] = useState(null);

  useEffect(() => {
    if (!opened) setConfirming(null);
  }, [opened]);

  return (
    <Modal
      opened={opened}
      onClose={onClose}
      centered
      radius={16}
      size="md"
      title={
        <span className="block">
          <span className="block font-display text-[17px] font-bold text-cocoa-900">Manage Categories</span>
          <span className="block font-display text-[12px] font-normal text-[#8d8884]">
            Rename a category, or delete one that has no dishes
          </span>
        </span>
      }
      styles={{ header: { borderBottom: "1px solid #f3ede4", paddingBottom: 12 } }}
    >
      <ul className="m-0 flex list-none flex-col p-0 pt-2" data-testid="category-list">
        {categories.map((category) => {
          const count = counts[category.id] ?? 0;
          const asking = confirming === category.id;

          return (
            <li
              key={category.id}
              data-testid="category-entry"
              data-category={category.id}
              className="flex items-center gap-3 border-b border-[#f6f1ea] py-2.5 last:border-b-0"
            >
              <span
                className={`inline-flex rounded-full px-2.5 py-[3px] font-display text-[10px] font-bold uppercase tracking-[0.4px] ${categoryTone(category.name)}`}
              >
                {category.name}
              </span>
              <span className="flex-1 font-display text-[11.5px] text-[#8d8884]">
                {count} {count === 1 ? "dish" : "dishes"}
              </span>

              {asking ? (
                <span className="flex items-center gap-1.5 text-[12px]">
                  <button
                    type="button"
                    onClick={() => setConfirming(null)}
                    className="rounded-[8px] bg-transparent px-2 py-1 font-display font-semibold text-[#6f6b68] hover:bg-[#f7f4f0]"
                  >
                    <span className="text-[12px]">Keep</span>
                  </button>
                  <button
                    type="button"
                    disabled={deletingId === category.id}
                    onClick={() => onDelete(category)}
                    data-testid="category-delete-confirm"
                    className="rounded-[8px] bg-[#e5322d] px-2.5 py-1 font-display font-semibold text-white hover:bg-[#cc2a25] disabled:opacity-55"
                  >
                    <span className="text-[12px]">{deletingId === category.id ? "Deleting…" : "Delete"}</span>
                  </button>
                </span>
              ) : (
                <span className="flex items-center gap-1">
                  <button
                    type="button"
                    onClick={() => onRename(category)}
                    aria-label={`Rename ${category.name}`}
                    className="grid h-8 w-8 place-items-center rounded-[8px] bg-transparent text-[#8d8884] transition-colors hover:bg-[#fff6ec] hover:text-brand-600"
                  >
                    <IconPencil size={15} stroke={2} />
                  </button>
                  <Tooltip
                    label={count > 0 ? `Move or delete its ${count} ${count === 1 ? "dish" : "dishes"} first` : "Delete"}
                    withArrow
                    openDelay={200}
                  >
                    <span>
                      <button
                        type="button"
                        disabled={count > 0}
                        onClick={() => setConfirming(category.id)}
                        aria-label={`Delete ${category.name}`}
                        data-testid="category-delete"
                        className="grid h-8 w-8 place-items-center rounded-[8px] bg-transparent text-[#8d8884] transition-colors hover:bg-[#fdecec] hover:text-[#e5322d] disabled:cursor-not-allowed disabled:opacity-35 disabled:hover:bg-transparent disabled:hover:text-[#8d8884]"
                      >
                        <IconTrash size={15} stroke={2} />
                      </button>
                    </span>
                  </Tooltip>
                </span>
              )}
            </li>
          );
        })}
      </ul>

      {categories.length === 0 && (
        <p className="m-0 py-6 text-center font-display text-[12.5px] text-[#8d8884]">No categories yet.</p>
      )}

      <button
        type="button"
        onClick={onAdd}
        className="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-[10px] border border-dashed border-[#f0c79e] bg-[#fffaf4] py-2.5 font-display font-semibold text-brand-600 transition-colors hover:bg-[#fff1e3]"
      >
        <IconPlus size={15} stroke={2.2} />
        <span className="text-[12.5px]">Add Category</span>
      </button>
    </Modal>
  );
}

export default ManageCategoriesModal;
