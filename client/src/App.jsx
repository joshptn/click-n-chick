
import './App.css'
import { BrowserRouter as Router, Routes, Route } from 'react-router-dom'
import Login from './Pages/Login'
import Register from './Pages/Register'
import Home from './Pages/Home'
import PrivateRoutes from './Contexts/PrivateRoutes'
import { FoodProvider } from './Contexts/FoodProvider'
import Admin from './Pages/Admin'
import Unauthorized from './Pages/Unauthorized'
import Loading from './Pages/Loading'
import Cart from './Pages/Cart'
import CheckoutSuccess from './Pages/CheckoutSuccess'
import AdminRoutes from './Contexts/AdminRoutes'
import LandingPage from './Pages/LandingPage'
import { OrderProvider } from './Contexts/Orderprovider'
import { CartProvider } from './Contexts/CartProvider'
import Settings from './Pages/Settings'
import CheckoutPage from './Pages/CheckoutPage'
import { AddOnProvider } from './Contexts/AddOnContext'


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
