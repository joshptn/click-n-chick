import { execFileSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { expect, test } from "@playwright/test";

import { CUSTOMER } from "./accounts.js";

const SERVER_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../server");

function artisan(args) {
  return execFileSync("php", ["artisan", ...args], {
    cwd: SERVER_DIR,
    encoding: "utf8",
    timeout: 30_000,
  });
}

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

  const checked = page.waitForResponse(
    (response) => response.url().endsWith("/api/user") && response.request().method() === "GET"
  );

  await page.getByRole("button", { name: /sign in/i }).click();
  await page.waitForURL(/\/(home|admin|superadmin)/, { timeout: 20_000 });
  await checked;
}

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

    await expect(page.getByText(/Delivery Fee: ₱/)).toBeVisible({ timeout: 20_000 });
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
    await page.getByRole("listitem").filter({ hasText: /Manila/ }).first().click();

    await expect(page.getByText(/Beyond .* km by road/)).toBeVisible({ timeout: 25_000 });
    await expect(page.getByRole("button", { name: /Place Order/ })).toBeDisabled();
  });
});
