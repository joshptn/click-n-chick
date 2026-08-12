import { Routes, Route } from 'react-router-dom'
import Login from './pages/auth/Login'
import Register from './pages/auth/Register'
import PrivateRoutes from './providers/PrivateRoutes'
import AdminRoutes from './providers/AdminRoutes'
import LandingPage from './pages/LandingPage'


function App() {

  return (
    <>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/register" element={<Register />} />
        <Route path="/" element={<LandingPage />} />

        <Route element={<PrivateRoutes allowedRoles={["user","admin"]} />} >
        </Route>

        <Route element={<PrivateRoutes allowedRoles={["admin"]} />} >
          <Route element={<AdminRoutes/>}>
          </Route>
        </Route>

      </Routes>
    </>
  )
}

export default App
