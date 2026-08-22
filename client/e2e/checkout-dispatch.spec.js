import { execFileSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { expect, test } from "@playwright/test";

/**
 * The Dispatch step of checkout, in a real browser (UC-ORD-001..004, UC-DEL-*).
 *
 * The Laravel suite already proves the arithmetic. What only a browser can
 * prove is the part that lives between the two: that Leaflet's tiles survive
 * the Content-Security-Policy, that the map, the search box and the fee badge
 * agree with each other, and that a destination outside the service area stops
 * the flow rather than quietly pricing it.
 *
 * Like realtime.spec.js, this needs the API and Vite already running - see
 * that file's header. It also needs reCAPTCHA off (RECAPTCHA_ENABLED=false),
 * since a scripted sign-in cannot solve a live challenge.
 */

const SERVER_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../server");

const CUSTOMER = { email: "customer@chicknclick.test", password: "Password123!" };

function artisan(args) {
  return execFileSync("php", ["artisan", ...args], {
    cwd: SERVER_DIR,
    encoding: "utf8",
    timeout: 30_000,
  });
}

/** Force the store open, so a test run at 3am is not a test about opening hours. */
function openTheStore() {
  artisan([
    "tinker",
    "--execute",
    "App\\Models\\Setting::put(App\\Models\\Setting::STORE_ORDERING_OVERRIDE, 'open');",
  ]);
}

function restoreStoreHours() {
  artisan([
    "tinker",
    "--execute",
    "App\\Models\\Setting::put(App\\Models\\Setting::STORE_ORDERING_OVERRIDE, 'auto');",
  ]);
}

async function signIn(page, { email, password }) {
  await page.goto("/login");
  await page.getByPlaceholder("Phone number or email address").fill(email);
  await page.getByPlaceholder("Password").fill(password);
  await page.getByRole("button", { name: /sign in/i }).click();
  await page.waitForURL(/\/(home|admin|superadmin)/, { timeout: 20_000 });
}

/** Put one item in the cart the way a customer would, then open checkout. */
async function startCheckout(page) {
  await signIn(page, CUSTOMER);

  const addButton = page.getByRole("button", { name: /add/i }).first();
  await addButton.click();
  await expect(page.getByRole("button", { name: /^checkout$/i }).first()).toBeEnabled();

  await page.getByRole("button", { name: /^checkout$/i }).first().click();
  await page.waitForURL(/\/checkout/, { timeout: 20_000 });
  await expect(page.getByRole("list", { name: "Checkout progress" })).toBeVisible();
}

test.describe("checkout dispatch", () => {
  test.beforeAll(() => openTheStore());
  test.afterAll(() => restoreStoreHours());

  test("pickup asks for a collection time and prices without a delivery fee", async ({ page }) => {
    await startCheckout(page);

    await page.getByRole("radio", { name: "Pick up" }).click();

    await expect(page.locator('input[type="time"]')).toBeVisible();

    const summary = page.getByLabel("Order summary");
    await expect(summary).not.toContainText("Delivery Fee");
  });

  test("the delivery map renders under the content security policy", async ({ page }) => {
    // A CSP refusal is silent in the UI - the tiles simply never appear - so
    // it is captured directly rather than inferred from a blank map.
    const violations = [];
    await page.addInitScript(() => {
      document.addEventListener("securitypolicyviolation", (event) => {
        window.__cspViolations = window.__cspViolations || [];
        window.__cspViolations.push(`${event.violatedDirective} <- ${event.blockedURI}`);
      });
    });

    await startCheckout(page);
    await page.getByRole("radio", { name: "Delivery" }).click();

    await expect(page.locator(".leaflet-container")).toBeVisible();
    // OpenStreetMap tiles are a third-party origin; img-src has to allow them.
    await expect(page.locator("img.leaflet-tile-loaded").first()).toBeVisible({ timeout: 20_000 });

    violations.push(...(await page.evaluate(() => window.__cspViolations ?? [])));

    expect(violations, "the delivery map must not need a CSP exception it does not have").toEqual([]);
  });

  test("pinning the map prices the delivery server-side", async ({ page }) => {
    await startCheckout(page);
    await page.getByRole("radio", { name: "Delivery" }).click();

    const map = page.locator(".leaflet-container");
    await expect(map).toBeVisible();

    const box = await map.boundingBox();
    await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);

    // The distance and the fee are both the server's answer; the browser only
    // supplied a coordinate.
    await expect(page.getByText(/Distance from restaurant/)).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText(/Delivery Fee: ₱/)).toBeVisible();

    await expect(page.getByLabel("Order summary")).toContainText("Delivery Fee");
  });

  test("an address search returns results through the API, never Nominatim directly", async ({ page }) => {
    const upstream = [];
    page.on("request", (request) => {
      if (request.url().includes("nominatim")) upstream.push(request.url());
    });

    await startCheckout(page);
    await page.getByRole("radio", { name: "Delivery" }).click();

    await page.getByLabel(/delivery address/i).fill("Apalit Pampanga");

    await expect(page.getByRole("listitem").first()).toBeVisible({ timeout: 25_000 });

    expect(upstream, "the browser must never call Nominatim itself").toEqual([]);
  });

  test("a destination outside the service area stops the step", async ({ page }) => {
    await startCheckout(page);
    await page.getByRole("radio", { name: "Delivery" }).click();

    await page.getByLabel(/delivery address/i).fill("Manila City Hall");
    await page.getByRole("listitem").first().click();

    await expect(page.getByText(/outside our .* km delivery area/)).toBeVisible({ timeout: 25_000 });
    await expect(page.getByRole("button", { name: /Continue to Payment/ })).toBeDisabled();

    // And nothing was charged for the delivery that will not happen.
    await expect(page.getByLabel("Order summary")).toContainText("₱0.00");
  });
});
