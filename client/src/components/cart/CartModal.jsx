import { Modal } from "@mantine/core";

import CartPanel from "./CartPanel";

function CartModal({ opened, onClose, onCheckout }) {
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
        onCheckout={(selectedIds) => {
          onClose();
          onCheckout?.(selectedIds);
        }}
      />
    </Modal>
  );
}

export default CartModal;
