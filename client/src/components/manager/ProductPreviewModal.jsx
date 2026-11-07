import { useEffect, useState } from "react";
import { Modal } from "@mantine/core";
import { IconPhotoOff, IconX } from "@tabler/icons-react";

import { isArchived } from "../../lib/catalog";
import { responsiveImage } from "../../lib/images";
import { formatPeso } from "../../lib/menu";
import { categoryTone } from "../../lib/stock";

/** The dish as its catalogue card reads: photo, name, category, description, price, status. */
function ProductPreviewModal({ food, opened, onClose }) {
  const [failed, setFailed] = useState(false);

  useEffect(() => setFailed(false), [food?.id]);

  return (
    <Modal
      opened={opened}
      onClose={onClose}
      centered
      radius={20}
      size={360}
      padding={0}
      withCloseButton={false}
      aria-label={food?.food_name ?? "Dish"}
    >
      {food && (
        <div data-testid="product-preview" data-food={food.id}>
          <div className="relative grid aspect-[16/9] place-items-center overflow-hidden bg-[#f4f1ec]">
            {food.thumbnail && !failed ? (
              <img {...responsiveImage(food.thumbnail, { widths: [480, 640, 960], sizes: "360px", fallback: 480 })} alt="" onError={() => setFailed(true)} className="h-full w-full object-cover" />
            ) : (
              <IconPhotoOff size={28} stroke={1.5} className="text-[#cfc7bd]" />
            )}
          </div>
          <div className="p-5">
            <div className="flex items-start justify-between gap-3">
              <h2 className="m-0 font-display text-[18px] font-bold leading-snug text-ink">{food.food_name}</h2>
              <button
                type="button"
                onClick={onClose}
                aria-label="Close"
                className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-transparent text-[#a39f9b] hover:bg-[#f7f4f0] hover:text-ink"
              >
                <IconX size={16} stroke={2} />
              </button>
            </div>
            {food.category && (
              <span
                className={`mt-2 inline-flex rounded-full px-2.5 py-[3px] font-display text-[10px] font-bold uppercase tracking-[0.4px] ${categoryTone(food.category.name)}`}
              >
                {food.category.name}
              </span>
            )}
            <p className="m-0 mt-3 font-display text-[13px] leading-relaxed text-[#6f6b68]">
              {food.description || <span className="italic text-[#a39f9b]">No description.</span>}
            </p>
            <div className="mt-4 flex items-center justify-between border-t border-[#f3ede4] pt-3.5">
              <span className="font-display text-[17px] font-bold text-brand-600">{formatPeso(food.price)}</span>
              <span
                className={`rounded-full px-3 py-1 font-display text-[12px] font-semibold ${
                  isArchived(food) ? "bg-[#f1eee9] text-[#5f5b58]" : "bg-[#e7f8ec] text-[#2f9e44]"
                }`}
              >
                {isArchived(food) ? "Archived" : "On Menu"}
              </span>
            </div>
          </div>
        </div>
      )}
    </Modal>
  );
}

export default ProductPreviewModal;
