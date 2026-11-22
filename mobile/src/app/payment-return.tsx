import { useEffect } from "react";
import { router } from "expo-router";

import { readPendingCheckout } from "../lib/guestOrders";

/**
 * Where "Open BES App" on the web's payment return page lands
 * (clicknchick://payment-return, decided 2026-11-19). It steps back to the
 * screen that opened Xendit (the payment screen or the order), which checks
 * the payment as the app comes back to the front.
 *
 * Started cold - Android closed the app while the customer was paying - there
 * is nothing to step back to, so it resumes the payment screen for the
 * checkout remembered when it was placed (decided 2026-11-22, for customers
 * too 2026-11-23). No payment page is reopened: the screen only checks.
 * Nothing remembered: Home.
 */
export default function PaymentReturn() {
  useEffect(() => {
    if (router.canGoBack()) {
      router.back();
      return;
    }

    let live = true;

    void readPendingCheckout().then((pending) => {
      if (!live) return;

      if (pending?.token) router.replace({ pathname: "/payment", params: { token: pending.token } });
      else if (pending?.orderId) router.replace({ pathname: "/payment", params: { order: pending.orderId } });
      else router.replace("/home");
    });

    return () => {
      live = false;
    };
  }, []);

  return null;
}
