import { Modal } from "@mantine/core";

import CartPanel from "./CartPanel";
import { CART_MODE } from "../../lib/cartModes";

function CartModal({ opened, onClose, onCheckout, mode = CART_MODE.IMMEDIATE }) {
  return (
    <Modal
      opened={opened}
      onClose={onClose}
      size="min(460px, 94vw)"
      padding={0}
      radius="18px"
      withCloseButton={false}
      centered
      overlayProps={{ backgroundOpacity: 0.5, blur: 3 }}
      classNames={{ body: "p-0" }}
    >
      <CartPanel
        className="max-h-[86vh] border-0"
        mode={mode}
        onCheckout={(selectedIds) => {
          onClose();
          onCheckout?.(selectedIds);
        }}
      />
    </Modal>
  );
}

export default CartModal;
