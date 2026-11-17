import { useContext, useEffect } from "react";
import { Navigate } from "react-router-dom";

import AuthContext from "../../context/AuthContext";
import toast from "../../components/app/Toast";
import { paymentReturnTarget, trackingPath } from "../../lib/payments";

/**
 * Where Xendit sends the customer back, paid or not.
 *
 * The address names no order (a guest's token is never given to Xendit), so the
 * order this tab left to pay for is read back from session storage and the
 * tracking page takes over: it checks the payment and shows where it stands.
 */
function PaymentReturn() {
  const { token } = useContext(AuthContext);
  const target = paymentReturnTarget();

  useEffect(() => {
    if (!target) {
      toast.info(
        token
          ? "Your orders are listed here. A paid order shows as placed."
          : "Open the tracking link for your order to see whether the payment went through.",
        "Back from payment"
      );
    }
  }, [target, token]);

  if (target) return <Navigate to={trackingPath(target)} replace />;

  return <Navigate to={token ? "/orders" : "/home"} replace />;
}

export default PaymentReturn;
