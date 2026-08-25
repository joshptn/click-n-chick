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
import Checkout from './pages/customer/Checkout'
import Orders from './pages/customer/Orders'
import OrderTracking from './pages/customer/OrderTracking'
import Profile from './pages/account/Profile'
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
        <Route path="/verify-phone" element={<VerifyCode channel={CHANNELS.SMS} />} />
        <Route path="/verify-email" element={<VerifyCode channel={CHANNELS.EMAIL} />} />
        <Route path="/two-factor" element={<TwoFactorChallenge />} />
        <Route path="/forgot-password" element={<ForgotPassword />} />
        <Route path="/reset-password" element={<ResetPassword />} />
        <Route path="/" element={<LandingPage />} />
        <Route path="/unauthorized" element={<Unauthorized />} />

        <Route element={<PrivateRoutes allowedRoles={[ROLES.CUSTOMER, ROLES.ADMIN, ROLES.SUPER_ADMIN]} />} >
          <Route path="/home" element={<Home />} />
          <Route path="/checkout" element={<Checkout />} />
          <Route path="/orders" element={<Orders />} />
          <Route path="/orders/:orderId" element={<OrderTracking />} />
          <Route path="/account/profile" element={<Profile />} />
          <Route path="/account/security" element={<Security />} />
          <Route path="/account/devices" element={<Navigate to="/account/security" replace />} />
        </Route>

        <Route element={<PrivateRoutes allowedRoles={[ROLES.ADMIN, ROLES.SUPER_ADMIN]} />} >
          <Route element={<AdminRoutes/>}>
            <Route path="/admin" element={<AdminDashboard />} />
          </Route>
        </Route>
        
        <Route element={<PrivateRoutes allowedRoles={[ROLES.SUPER_ADMIN]} />} >
          <Route path="/superadmin" element={<SuperAdminDashboard />} />
          <Route path="/superadmin/users" element={<UserManagement />} />
        </Route>

      </Routes>
    </>
  )
}

export default App
