import { Suspense, useEffect, useState } from 'react'
import { Routes, Route, Navigate, createRoutesFromElements, matchRoutes } from 'react-router-dom'
import { CHANNELS } from './lib/verificationChannels'
import PrivateRoutes from './providers/PrivateRoutes'
import { ROLES } from './lib/roles'
import AdminRoutes from './providers/AdminRoutes'
import PageFallback from './components/app/PageFallback'
import { pages } from './lib/pages'

const {
  Login,
  Register,
  VerifyCode,
  TwoFactorChallenge,
  ForgotPassword,
  ResetPassword,
  LandingPage,
  Unauthorized,
  Home,
  Checkout,
  OrderTracking,
  AdvanceOrder,
  AdvanceCheckout,
  AdvanceSubmitted,
  Orders,
  Profile,
  Security,
  AdminDashboard,
  DiscountRequests,
  AdvanceOrders,
  ProductAvailability,
  OrderRecords,
  SuperAdminDashboard,
  UserManagement,
  MenuCatalog,
  ProductForm,
  DailySalesReport,
} = pages

const routes = (
  <>
    <Route path="/login" element={<Login />} />
    <Route path="/register" element={<Register />} />
    <Route path="/verify-phone" element={<VerifyCode channel={CHANNELS.SMS} />} />
    <Route path="/verify-email" element={<VerifyCode channel={CHANNELS.EMAIL} />} />
    <Route path="/two-factor" element={<TwoFactorChallenge />} />
    <Route path="/forgot-password" element={<ForgotPassword />} />
    <Route path="/reset-password" element={<ResetPassword />} />
    <Route path="/" element={<LandingPage />} />
    <Route path="/unauthorized" element={<Unauthorized />} />
    <Route path="/home" element={<Home />} />
    <Route path="/checkout" element={<Checkout />} />
    <Route path="/track/:token" element={<OrderTracking guest />} />

    <Route element={<PrivateRoutes allowedRoles={[ROLES.CUSTOMER, ROLES.ADMIN, ROLES.SUPER_ADMIN]} />} >
      <Route path="/advance-order" element={<AdvanceOrder />} />
      <Route path="/advance-order/schedule" element={<AdvanceCheckout />} />
      <Route path="/advance-order/sent/:orderId" element={<AdvanceSubmitted />} />
      <Route path="/orders" element={<Orders />} />
      <Route path="/orders/:orderId" element={<OrderTracking />} />
      <Route path="/account/profile" element={<Profile />} />
      <Route path="/account/security" element={<Security />} />
      <Route path="/account/devices" element={<Navigate to="/account/security" replace />} />
    </Route>

    <Route element={<PrivateRoutes allowedRoles={[ROLES.ADMIN, ROLES.SUPER_ADMIN]} />} >
      <Route element={<AdminRoutes/>}>
        <Route path="/admin" element={<AdminDashboard />} />
        <Route path="/admin/discount-requests" element={<DiscountRequests />} />
        <Route path="/admin/advance-orders" element={<AdvanceOrders />} />
        <Route path="/admin/products" element={<ProductAvailability />} />
        <Route path="/admin/records" element={<OrderRecords />} />
      </Route>
    </Route>

    <Route element={<PrivateRoutes allowedRoles={[ROLES.SUPER_ADMIN]} />} >
      <Route path="/superadmin" element={<SuperAdminDashboard />} />
      <Route path="/superadmin/users" element={<UserManagement />} />
      <Route path="/superadmin/menu" element={<MenuCatalog />} />
      <Route path="/superadmin/menu/new" element={<ProductForm />} />
      <Route path="/superadmin/menu/:id/edit" element={<ProductForm />} />
      <Route path="/superadmin/reports/daily" element={<DailySalesReport />} />
    </Route>
  </>
)

// Start downloading the page this URL opens on now, alongside the app's own
// boot (and the /api/user check a protected page waits for), instead of only
// once the route renders.
const bootPage = Promise.all(
  (matchRoutes(createRoutesFromElements(routes), window.location.pathname) ?? [])
    .map((match) => match.route.element?.type?.preload?.())
    .filter(Boolean)
).catch(() => {})

function App() {
  // Until the first page's code is in, a plain placeholder - not a Suspense
  // fallback, which would make React hold the page back once it arrives. The
  // providers around App (the session check, realtime, the cart) are already
  // running meanwhile.
  const [booted, setBooted] = useState(false)

  useEffect(() => {
    let live = true

    bootPage.then(() => live && setBooted(true))

    return () => { live = false }
  }, [])

  if (!booted) return <PageFallback />

  // Navigations run as transitions (React Router), so moving between pages
  // keeps the current one on screen while the next one's code arrives.
  return (
    <Suspense fallback={<PageFallback />}>
      <Routes>{routes}</Routes>
    </Suspense>
  )
}

export default App
