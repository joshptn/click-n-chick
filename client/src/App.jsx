import { Routes, Route, Navigate } from 'react-router-dom'
import Login from './pages/auth/Login'
import Register from './pages/auth/Register'
import VerifyCode from './pages/auth/VerifyCode'
import TwoFactorChallenge from './pages/auth/TwoFactorChallenge'
import ForgotPassword from './pages/auth/ForgotPassword'
import ResetPassword from './pages/auth/ResetPassword'
import { CHANNELS } from './lib/verificationChannels'
import PrivateRoutes from './providers/PrivateRoutes'
import { ROLES } from './lib/roles'
import AdminRoutes from './providers/AdminRoutes'
import LandingPage from './pages/LandingPage'
import Unauthorized from './pages/Unauthorized'
import Home from './pages/customer/Home'
import Security from './pages/account/Security'
import AdminDashboard from './pages/admin/Dashboard'
import SuperAdminDashboard from './pages/superadmin/Dashboard'
import UserManagement from './pages/superadmin/UserManagement'

function App() {

  return (
    <>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/register" element={<Register />} />
        {/* One component, two routes - the channel is the only difference. */}
        <Route path="/verify-phone" element={<VerifyCode channel={CHANNELS.SMS} />} />
        <Route path="/verify-email" element={<VerifyCode channel={CHANNELS.EMAIL} />} />
        {/* Second factor at login. Reached only with a challenge token. */}
        <Route path="/two-factor" element={<TwoFactorChallenge />} />
        {/* Password recovery. Public: the whole point is being unable to sign in. */}
        <Route path="/forgot-password" element={<ForgotPassword />} />
        <Route path="/reset-password" element={<ResetPassword />} />
        <Route path="/" element={<LandingPage />} />
        <Route path="/unauthorized" element={<Unauthorized />} />

        {/* Signed-in customer area. Staff roles may view it too. */}
        <Route element={<PrivateRoutes allowedRoles={[ROLES.CUSTOMER, ROLES.ADMIN, ROLES.SUPER_ADMIN]} />} >
          <Route path="/home" element={<Home />} />
          {/* Account security: two-step verification (UC-PROF-006) and known
              devices (UC-AUTH-013). Every authenticated role manages its own. */}
          <Route path="/account/security" element={<Security />} />
          {/* The screen was devices-only before 2FA moved in. Kept so an open
              tab or a bookmark from that build still lands somewhere. */}
          <Route path="/account/devices" element={<Navigate to="/account/security" replace />} />
        </Route>

        {/* Staff area. PrivateRoutes gates on the localStorage role for UX;
            AdminRoutes then re-validates the role against /api/user. */}
        <Route element={<PrivateRoutes allowedRoles={[ROLES.ADMIN, ROLES.SUPER_ADMIN]} />} >
          <Route element={<AdminRoutes/>}>
            <Route path="/admin" element={<AdminDashboard />} />
          </Route>
        </Route>

        {/* Super admin only. */}
        <Route element={<PrivateRoutes allowedRoles={[ROLES.SUPER_ADMIN]} />} >
          <Route path="/superadmin" element={<SuperAdminDashboard />} />
          <Route path="/superadmin/users" element={<UserManagement />} />
        </Route>

      </Routes>
    </>
  )
}

export default App
