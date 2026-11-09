import path from "node:path";
import { fileURLToPath } from "node:url";

import { defineConfig, devices } from "@playwright/test";

/**
 * End-to-end tests against a real browser, Reverb, Laravel and Vite.
 *
 * `npm run test:e2e` starts all three on their own ports with a throwaway SQLite
 * database (e2e/global-setup.js), so the development stack and its data are
 * never touched and nothing has to be started by hand. Set here, on
 * process.env, so the servers, the setup and the specs' artisan calls all agree.
 */
const CLIENT = path.dirname(fileURLToPath(import.meta.url));
const SERVER = path.resolve(CLIENT, "../server");

export const TMP = path.join(CLIENT, "e2e/.tmp");
export const DATABASE = path.join(TMP, "e2e.sqlite");

const API_PORT = 8011;
const REVERB_PORT = 8091;
const APP_PORT = 5191;

Object.assign(process.env, {
  DB_CONNECTION: "sqlite",
  DB_DATABASE: DATABASE,
  // Shared between requests: the password step-up and rate limits rely on it.
  CACHE_STORE: "database",
  SESSION_DRIVER: "array",
  QUEUE_CONNECTION: "sync",
  // Nothing leaves the machine.
  MAIL_MAILER: "array",
  RECAPTCHA_ENABLED: "false",
  ALLOWED_ORIGINS: `http://127.0.0.1:${APP_PORT}`,
  REVERB_HOST: "127.0.0.1",
  REVERB_PORT: String(REVERB_PORT),
  REVERB_SERVER_PORT: String(REVERB_PORT),
  VITE_API_URL: `http://127.0.0.1:${API_PORT}`,
  VITE_REVERB_HOST: "127.0.0.1",
  VITE_REVERB_PORT: String(REVERB_PORT),
  VITE_REVERB_SCHEME: "http",
  E2E_BASE_URL: `http://127.0.0.1:${APP_PORT}`,
  E2E_API_URL: `http://127.0.0.1:${API_PORT}`,
});

export default defineConfig({
  testDir: "./e2e",
  outputDir: path.join(TMP, "results"),
  globalSetup: "./e2e/global-setup.js",
  // Sign-in, channel auth and a websocket round trip are not instant.
  timeout: 60_000,
  expect: { timeout: 20_000 },
  // One database and a single-threaded PHP server.
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [["list"]],
  use: {
    baseURL: process.env.E2E_BASE_URL,
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  projects: [
    {
      name: "chromium",
      use: { ...devices["Desktop Chrome"] },
    },
  ],
  webServer: [
    {
      command: `php -S 127.0.0.1:${API_PORT} ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`,
      cwd: path.join(SERVER, "public"),
      port: API_PORT,
      reuseExistingServer: true,
    },
    {
      command: `php artisan reverb:start --host=127.0.0.1 --port=${REVERB_PORT} --no-interaction`,
      cwd: SERVER,
      port: REVERB_PORT,
      reuseExistingServer: true,
    },
    {
      command: `node node_modules/vite/bin/vite.js --host 127.0.0.1 --port ${APP_PORT} --strictPort`,
      cwd: CLIENT,
      port: APP_PORT,
      reuseExistingServer: true,
      timeout: 120_000,
    },
  ],
});
