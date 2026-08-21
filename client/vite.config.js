import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import contentSecurityPolicy from './vite-csp.js'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')

  return {
    server: {
      allowedHosts: [env.VITE_HOST_URL], 
      host: '0.0.0.0',
    },
    plugins: [react(), tailwindcss(), contentSecurityPolicy(env)],
     base: "/",
  }
})
