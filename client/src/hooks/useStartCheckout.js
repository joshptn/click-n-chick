import { useCallback } from "react";
import { useNavigate } from "react-router-dom";

import toast from "../components/app/Toast";
import { useStoreStatus } from "../lib/store";

/**
 * From the cart to checkout, for Home's cart and the phone's Cart tab. The
 * ticked lines travel with the navigation (BR-25, partial checkout) so
 * checkout prices exactly what the customer selected, not the whole cart.
 */
export function useStartCheckout() {
  const navigate = useNavigate();
  const { status } = useStoreStatus();

  return useCallback(
    (ids) => {
      if (!status?.accepting_orders) {
        toast.info(status?.blockers?.pickup?.message ?? "The store is not taking orders right now.", "Store closed");

        return;
      }

      navigate("/checkout", { state: { selectedIds: ids ?? null } });
    },
    [navigate, status]
  );
}

export default useStartCheckout;
