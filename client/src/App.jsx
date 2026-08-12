import { Routes, Route } from 'react-router-dom'
import Login from './pages/auth/Login'
import Register from './pages/auth/Register'
import PrivateRoutes from './providers/PrivateRoutes'
import { ROLES } from './lib/roles'
import AdminRoutes from './providers/AdminRoutes'
import LandingPage from './pages/LandingPage'
import Unauthorized from './pages/Unauthorized'
import Home from './pages/customer/Home'
import AdminDashboard from './pages/admin/Dashboard'
import SuperAdminDashboard from './pages/superadmin/Dashboard'
import UserManagement from './pages/superadmin/UserManagement'

function App() {

  return (
    <>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/register" element={<Register />} />
        <Route path="/" element={<LandingPage />} />
        <Route path="/unauthorized" element={<Unauthorized />} />

        {/* Signed-in customer area. Staff roles may view it too. */}
        <Route element={<PrivateRoutes allowedRoles={[ROLES.CUSTOMER, ROLES.ADMIN, ROLES.SUPER_ADMIN]} />} >
          <Route path="/home" element={<Home />} />
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
