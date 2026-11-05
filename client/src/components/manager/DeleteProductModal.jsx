import { useEffect, useState } from "react";
import { Modal } from "@mantine/core";
import { IconAlertTriangle, IconPhotoOff } from "@tabler/icons-react";

import Button from "../ui/Button";
import { formatPeso } from "../../lib/menu";

/**
 * Delete is destructive, so it names the dish, says what happens, and waits
 * for an explicit tick before the button arms. The server soft-deletes: past
 * orders keep the dish, but the catalogue has no way to bring it back.
 */
function DeleteProductModal({ food, opened, onClose, onConfirm, loading }) {
  const [understood, setUnderstood] = useState(false);
  const [error, setError] = useState(null);
  const [failedImage, setFailedImage] = useState(false);

  useEffect(() => {
    if (opened) {
      setUnderstood(false);
      setError(null);
      setFailedImage(false);
    }
  }, [opened, food?.id]);

  const confirm = async () => {
    setError(null);

    try {
      await onConfirm(food);
      onClose();
    } catch (failure) {
      setError(failure.message);
    }
  };

  return (
    <Modal
      opened={opened}
      onClose={loading ? () => {} : onClose}
      centered
      radius={16}
      title={<span className="font-display text-[17px] font-bold text-[#c4302b]">Delete this dish?</span>}
      styles={{ header: { borderBottom: "1px solid #f3ede4", paddingBottom: 12 } }}
    >
      {food && (
        <div className="pt-3" data-testid="delete-modal" data-food={food.id}>
          <div className="flex items-center gap-3 rounded-[12px] bg-[#faf6f0] p-3">
            <span className="grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-[10px] bg-[#f1ece5]">
              {food.thumbnail && !failedImage ? (
                <img src={food.thumbnail} alt="" onError={() => setFailedImage(true)} className="h-full w-full object-cover" />
              ) : (
                <IconPhotoOff size={18} stroke={1.6} className="text-[#cfc7bd]" />
              )}
            </span>
            <span className="min-w-0">
              <span className="block font-display text-[14px] font-bold text-ink">{food.food_name}</span>
              <span className="block font-display text-[11.5px] text-[#8d8884]">
                ID {food.id} · {food.category?.name ?? "No category"} · {formatPeso(food.price)}
              </span>
            </span>
          </div>

          <div className="mt-3 flex gap-2.5 rounded-[12px] border border-[#f8c9c7] bg-[#fdf3f2] p-3">
            <IconAlertTriangle size={18} stroke={2} className="mt-[1px] shrink-0 text-[#e5322d]" />
            <ul className="m-0 flex list-none flex-col gap-1 p-0 font-display text-[12.5px] leading-relaxed text-[#6f2b28]">
              <li>It leaves the catalog and the customer menu, and is removed from every cart.</li>
              <li>Past orders keep it, so receipts and order records stay complete.</li>
              <li className="font-semibold">This cannot be undone here. To hide it for now, archive it instead.</li>
            </ul>
          </div>

          <label className="mt-3 flex cursor-pointer items-start gap-2 font-display text-[12.5px] text-ink">
            <input
              type="checkbox"
              checked={understood}
              onChange={(event) => setUnderstood(event.target.checked)}
              disabled={loading}
              data-testid="delete-understood"
              className="mt-[3px] h-4 w-4 accent-[#e5322d]"
            />
            <span>I understand that {food.food_name} will be deleted.</span>
          </label>

          {error && (
            <p className="m-0 mt-3 rounded-[10px] bg-[#fdecec] px-3 py-2 font-display text-[12px] text-[#c4302b]" role="alert">
              {error}
            </p>
          )}

          <div className="mt-5 flex justify-end gap-2 text-[13px]">
            <Button variant="ghost" size="sm" onClick={onClose} disabled={loading}>
              Keep dish
            </Button>
            <button
              type="button"
              disabled={!understood || loading}
              onClick={confirm}
              data-testid="delete-confirm"
              className="inline-flex h-[38px] items-center justify-center rounded-[9px] bg-[#e5322d] px-4 font-display font-semibold text-white transition-colors hover:bg-[#cc2a25] disabled:cursor-not-allowed disabled:opacity-55"
            >
              <span className="text-[13px]">{loading ? "Deleting…" : "Delete dish"}</span>
            </button>
          </div>
        </div>
      )}
    </Modal>
  );
}

export default DeleteProductModal;
