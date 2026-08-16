import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.jsx'
import '@mantine/core/styles.css';

import { MantineProvider } from '@mantine/core';
import { Notifications } from "@mantine/notifications";
import { BrowserRouter } from 'react-router-dom';
import { QueryClientProvider } from '@tanstack/react-query';
import AuthContext, { AuthProvider } from './context/AuthContext.jsx';
import { CartProvider } from './context/CartContext.jsx';
import { queryClient } from './lib/queryClient.js';

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <MantineProvider>
        <BrowserRouter>
          <AuthProvider>
            {/* Inside AuthProvider: the cart is keyed on the signed-in user,
                so it needs the token from above it. */}
            <CartProvider>
              <Notifications position="top-right"  />
              <App />
            </CartProvider>
          </AuthProvider>
        </BrowserRouter>
      </MantineProvider>
    </QueryClientProvider>
  </StrictMode>,
)
