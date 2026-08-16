import { defineConfig, devices } from "@playwright/test";

/**
 * End-to-end verification for GATE-3.
 *
 * These tests need four processes already running - Reverb, the Laravel API,
 * a queue worker, and Vite - because the point is to prove a real browser
 * receives a real broadcast over a real websocket. Spawning them from here
 * would hide exactly the wiring being verified. See e2e/README.md.
 */
export default defineConfig({
  testDir: "./e2e",
  // A websocket round trip plus a queue worker pickup is not instant.
  timeout: 60_000,
  expect: { timeout: 20_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [["list"]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? "http://127.0.0.1:5173",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  projects: [
    {
      name: "chromium",
      use: { ...devices["Desktop Chrome"] },
    },
  ],
});
