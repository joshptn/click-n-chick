
import './App.css'
import { BrowserRouter as Router, Routes, Route } from 'react-router-dom'
import Login from './pages/auth/Login'
import Register from './pages/auth/Register'
import Home from './pages/HomePage'
import PrivateRoutes from './providers/PrivateRoutes'
import { FoodProvider } from './context/FoodProvider'
import Admin from './pages/admin/Dashboard'
import Unauthorized from './pages/Unauthorized'
import Loading from './pages/Loading'
import Cart from './pages/Cart'
import CheckoutSuccess from './pages/CheckoutSuccess'
import AdminRoutes from './providers/AdminRoutes'
import LandingPage from './pages/LandingPage'
import { OrderProvider } from './context/Orderprovider'
import { CartProvider } from './context/CartProvider'
import Settings from './pages/Profile'
import CheckoutPage from './pages/CheckoutPage'
import { AddOnProvider } from './context/AddOnContext'


function App() {
  

  
  
  return (
    <>
      
     
        <CartProvider>
          <OrderProvider>
            <FoodProvider>
              <AddOnProvider>
                <Routes>
                  <Route path="/login" element={<Login />} />
                  <Route path="/register" element={<Register />} />
                  <Route path="/" element={<LandingPage />} />
                  <Route path="/home" element={<Home /> } />
                    
                  <Route element={<PrivateRoutes allowedRoles={["user","admin"]} />} >

                    
                        
                    <Route path="/cart" element={<Cart />} />
                    <Route path="/checkout" element={<CheckoutPage />} />

                    <Route path="/checkout/success/:order_id" element={<CheckoutSuccess />} />

                    <Route path="/settings" element={<Settings />} />
                  </Route>

                  <Route element={<PrivateRoutes allowedRoles={["admin"]} />} >
                    <Route element={<AdminRoutes/>}>
                      <Route path="/admin" element={<Admin />} />
                    </Route>
                  </Route>

                  <Route path="/loading" element={<Loading />} />
                  <Route path="/unauthorized" element={<Unauthorized />} />

                  
                    
                </Routes>
              </AddOnProvider>
            </FoodProvider>
          </OrderProvider>
          </CartProvider>
        
      
      
      
    </>
  )
}

export default App
