import { Modal } from "@mantine/core";

import CartPanel from "./CartPanel";

/**
 * The cart as a modal.
 *
 * Wraps the same CartPanel the home page docks into its grid, so the two
 * presentations cannot drift apart. On wide screens the panel is always
 * visible and this is only reachable deliberately; below that the header's
 * cart button is the only way in.
 */
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
        onCheckout={() => {
          onClose();
          onCheckout?.();
        }}
      />
    </Modal>
  );
}

export default CartModal;
