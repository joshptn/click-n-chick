import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.jsx'
import '@mantine/core/styles.css';

import { MantineProvider } from '@mantine/core';
import { Notifications } from "@mantine/notifications";
import { BrowserRouter } from 'react-router-dom';
import AuthContext, { AuthProvider } from './Contexts/AuthContext.jsx';

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <MantineProvider>
      <BrowserRouter>
        <AuthProvider>
          <Notifications position="top-right"  />
          <App />
        </AuthProvider>
      </BrowserRouter>
    </MantineProvider>
  </StrictMode>,
)
