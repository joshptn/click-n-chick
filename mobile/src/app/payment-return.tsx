import { useEffect } from "react";
import { router } from "expo-router";

/**
 * Where "Open BES App" on the web's payment return page lands
 * (clicknchick://payment-return, decided 2026-11-19). It only steps back to
 * the screen that opened Xendit (the payment screen or the order), which
 * checks the payment as the app comes back to the front. Started cold, it
 * opens Home.
 */
export default function PaymentReturn() {
  useEffect(() => {
    if (router.canGoBack()) router.back();
    else router.replace("/home");
  }, []);

  return null;
}
