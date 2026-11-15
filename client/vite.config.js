import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'
import contentSecurityPolicy from './vite-csp.js'

/** Brand orange (status bar, icon background) and the cream page background. */
const THEME_COLOR = '#f58c2f'
const BACKGROUND_COLOR = '#fff7ed'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')

  return {
    server: {
      allowedHosts: [env.VITE_HOST_URL],
      host: '0.0.0.0',
    },
    plugins: [
      react(),
      tailwindcss(),
      contentSecurityPolicy(env),
      /**
       * Installable PWA (NFR-05): customer module only, no offline features.
       *
       * The service worker pre-caches the app's own built files and nothing
       * else. API responses are never cached: they come from another origin and
       * no runtime caching is configured, so one account's data cannot be served
       * to the next (the bug fixed on 2026-11-13). A new version takes over in
       * the background without reloading the page; a tab still on the old one
       * recovers through lib/newVersion.js as before.
       */
      VitePWA({
        registerType: 'autoUpdate',
        injectRegister: 'script-defer',
        pwaAssets: { config: true, overrideManifestIcons: true },
        manifest: {
          id: '/',
          name: 'Click n Chick',
          short_name: 'Click n Chick',
          description: 'Order fried chicken from BES House of Chicken for pickup or delivery.',
          lang: 'en',
          start_url: '/home',
          scope: '/',
          display: 'standalone',
          orientation: 'portrait',
          theme_color: THEME_COLOR,
          background_color: BACKGROUND_COLOR,
        },
        workbox: {
          globPatterns: ['**/*.{js,css,html,png,svg,ico,woff2}'],
          // iOS fetches its splash screen itself; pre-caching every size would
          // only make each install download all of them.
          globIgnores: ['**/apple-splash-*', 'pwa-source.png', 'vite.svg'],
          navigateFallback: '/index.html',
          cleanupOutdatedCaches: true,
          // Shows pushes and opens the right page when one is tapped (PRD §9).
          importScripts: ['push-sw.js'],
        },
      }),
    ],
     base: "/",
  }
})
