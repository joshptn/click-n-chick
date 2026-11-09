import { createElement, lazy } from "react";

import { loadOrReload } from "./newVersion";

/**
 * Every page, loaded on demand.
 *
 * Each page is its own chunk, so a visitor downloads the code for the pages
 * they open: a guest never loads the staff portal, the Manager's catalogue or
 * the delivery map. `preload()` starts a page's download early - on boot for
 * the page the URL is already on, and for a likely next page once the browser
 * is idle or the visitor reaches for its link - and the later render reuses
 * the same request.
 *
 * A page whose code has already arrived renders straight away instead of
 * suspending. Suspending would show a fallback first, and React then holds the
 * real page back for up to 300ms so fallbacks do not flicker - time in which
 * the page's own data requests have not started.
 */
function lazyPage(load) {
  let loaded = null;

  const preload = () =>
    load().then((module) => {
      loaded = module.default;

      return module;
    });

  // Rendering needs the code: if it cannot be had, reload once for the new
  // version. Warming it early does not: a failed early download is left for
  // the render to try again.
  const LazyPage = lazy(loadOrReload(preload));

  function Page(props) {
    return createElement(loaded ?? LazyPage, props);
  }

  Page.preload = () => preload().catch(() => {});

  return Page;
}

export const pages = {
  // Signing in and recovering an account.
  Login: lazyPage(() => import("../pages/auth/Login")),
  Register: lazyPage(() => import("../pages/auth/Register")),
  VerifyCode: lazyPage(() => import("../pages/auth/VerifyCode")),
  TwoFactorChallenge: lazyPage(() => import("../pages/auth/TwoFactorChallenge")),
  ForgotPassword: lazyPage(() => import("../pages/auth/ForgotPassword")),
  ResetPassword: lazyPage(() => import("../pages/auth/ResetPassword")),

  // Customer-facing.
  LandingPage: lazyPage(() => import("../pages/LandingPage")),
  Unauthorized: lazyPage(() => import("../pages/Unauthorized")),
  Home: lazyPage(() => import("../pages/customer/Home")),
  Checkout: lazyPage(() => import("../pages/customer/Checkout")),
  OrderTracking: lazyPage(() => import("../pages/customer/OrderTracking")),
  AdvanceOrder: lazyPage(() => import("../pages/customer/AdvanceOrder")),
  AdvanceCheckout: lazyPage(() => import("../pages/customer/AdvanceCheckout")),
  AdvanceSubmitted: lazyPage(() => import("../pages/customer/AdvanceSubmitted")),
  Orders: lazyPage(() => import("../pages/customer/Orders")),

  // The customer's account.
  Profile: lazyPage(() => import("../pages/account/Profile")),
  Security: lazyPage(() => import("../pages/account/Security")),

  // Store Agent (and the pages the Store Manager shares).
  AdminDashboard: lazyPage(() => import("../pages/admin/Dashboard")),
  DiscountRequests: lazyPage(() => import("../pages/admin/DiscountRequests")),
  AdvanceOrders: lazyPage(() => import("../pages/admin/AdvanceOrders")),
  ProductAvailability: lazyPage(() => import("../pages/admin/ProductAvailability")),
  OrderRecords: lazyPage(() => import("../pages/admin/OrderRecords")),

  // Store Manager.
  SuperAdminDashboard: lazyPage(() => import("../pages/superadmin/Dashboard")),
  UserManagement: lazyPage(() => import("../pages/superadmin/UserManagement")),
  MenuCatalog: lazyPage(() => import("../pages/superadmin/MenuCatalog")),
  ProductForm: lazyPage(() => import("../pages/superadmin/ProductForm")),
  DailySalesReport: lazyPage(() => import("../pages/superadmin/DailySalesReport")),
};

/**
 * Download pages the visitor is likely to open next, once the browser is idle,
 * so the current page's own work is never competed with. Returns a cancel
 * function for an effect's cleanup.
 */
export function preloadWhenIdle(list) {
  const run = () => list.forEach((page) => page?.preload?.());

  if (typeof window.requestIdleCallback === "function") {
    const id = window.requestIdleCallback(run, { timeout: 4000 });

    return () => window.cancelIdleCallback(id);
  }

  const id = window.setTimeout(run, 1500);

  return () => window.clearTimeout(id);
}
