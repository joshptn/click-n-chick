import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig(({ mode }) => {
  // load variables from .env files
  const env = loadEnv(mode, process.cwd(), '')

  return {
    server: {
      allowedHosts: [env.VITE_HOST_URL], // 👈 use env here
      host: '0.0.0.0',
    },
    plugins: [react(), tailwindcss()],
     base: "/",
  }
})
