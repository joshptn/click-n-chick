import path from "node:path";

import { expect, test } from "@playwright/test";

import { CUSTOMER, MANAGER, STAFF } from "./accounts.js";

/** Where the screenshots go, for looking at the pages by eye. Optional. */
const SHOTS = process.env.E2E_SHOTS ?? null;

const shot = async (page, name) => {
  if (SHOTS) await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: true });
};

async function signIn(page, { email, password }) {
  await page.goto("/login");
  await page.getByPlaceholder("Phone number or email address").fill(email);
  await page.getByPlaceholder("Password").fill(password);
  await page.getByRole("button", { name: /sign in/i }).click();
  await page.waitForURL(/\/(home|admin|superadmin)/, { timeout: 20_000 });
}

async function signOut(page) {
  await page.context().clearCookies();
  await page.evaluate(() => window.localStorage.clear());
}

/**
 * Add-ons (decided 2026-11-24), end to end: the Store Manager adds a sized
 * add-on and links it to a dish, the Store Agent switches a size off, and the
 * customer is offered only the sizes left and orders one.
 */
test.describe("Add-ons", () => {
  test.describe.configure({ mode: "serial" });

  test("the Store Manager adds an add-on in sizes and picks its dish", async ({ page }) => {
    await signIn(page, MANAGER);
    await page.goto("/superadmin/menu");

    await page.getByTestId("menu-view-addons").click();
    await expect(page).toHaveURL(/view=addons/);
    await expect(page.getByTestId("addon-empty")).toBeVisible();
    await shot(page, "manager-addons-empty");

    await page.getByTestId("add-addon").click();
    const form = page.getByTestId("addon-form");

    await form.getByTestId("addon-name").fill("Iced Tea");
    await form.getByRole("button", { name: "Drinks", exact: true }).click();
    await form.getByTestId("addon-by-size").click();
    await form.getByTestId("addon-size-small").check();
    await form.getByTestId("addon-size-price-small").fill("45");
    await form.getByTestId("addon-size-large").check();
    await form.getByTestId("addon-size-price-large").fill("65");
    await form.getByTestId("addon-dishes").getByText("Chicken Inasal").click();
    await shot(page, "manager-addon-form");

    await form.getByTestId("addon-save").click();

    const row = page.getByTestId("addon-row").filter({ hasText: "Iced Tea" });

    await expect(row).toBeVisible();
    await expect(row).toContainText("₱45.00 – ₱65.00");
    await expect(row).toContainText("Small");
    await expect(row).toContainText("Large");
    await expect(row).toContainText("1 dish");
    await shot(page, "manager-addons-list");

    await page.setViewportSize({ width: 390, height: 844 });
    await expect(row).toBeVisible();
    await shot(page, "manager-addons-list-phone");
  });

  test("a price change on it asks for the password", async ({ page }) => {
    await signIn(page, MANAGER);
    await page.goto("/superadmin/menu?view=addons");

    const row = page.getByTestId("addon-row").filter({ hasText: "Iced Tea" });

    await row.getByTestId("addon-edit").click();
    await page.getByTestId("addon-size-price-large").fill("70");
    await expect(page.getByTestId("addon-price-change-note")).toBeVisible();
    await page.getByTestId("addon-save").click();

    await expect(page.getByText("Confirm the price change")).toBeVisible();
    await shot(page, "manager-addon-password");
    await page.getByPlaceholder(/password/i).fill(MANAGER.password);
    await page.getByRole("button", { name: /confirm and save/i }).click();

    await expect(row).toContainText("₱45.00 – ₱70.00");
  });

  test("the Store Agent switches a size off", async ({ page }) => {
    await signIn(page, STAFF);
    await page.goto("/admin/products");
    await page.getByTestId("menu-view-addons").click();

    const row = page.getByTestId("addon-stock-row").filter({ hasText: "Iced Tea" });
    const large = row.getByTestId("addon-size-large");

    await expect(large).toBeChecked();
    await large.uncheck();
    await expect(page.getByText("Iced Tea (Large) is switched off.")).toBeVisible();
    await expect(large).not.toBeChecked();

    // The ticked add-ons switch together, after a confirmation.
    await row.getByTestId("addon-pick").check();
    const bar = page.getByTestId("addon-selection");
    await expect(bar).toContainText("1 add-on selected");
    // Mantine's switch input has no size of its own; its label is what is clicked.
    await bar.getByText("On Menu", { exact: true }).click();
    await page.getByTestId("confirm-submit").click();
    await expect(page.getByText("1 add-on switched off.")).toBeVisible();
    await expect(row.getByTestId("addon-availability-switch")).not.toBeChecked();
    await bar.getByText("Switched off", { exact: true }).click();
    await page.getByTestId("confirm-submit").click();
    await expect(page.getByText("1 add-on back on the menu.")).toBeVisible();

    // Iced Tea is the only add-on here, so ticking it ticks "every add-on shown".
    await expect(page.getByTestId("addon-pick-all")).toBeChecked();
    await shot(page, "agent-addons");
    await page.getByTestId("addon-pick-all").uncheck();
    await expect(bar).toBeHidden();

    await page.setViewportSize({ width: 390, height: 844 });
    await expect(row).toBeVisible();
    await shot(page, "agent-addons-phone");
  });

  test("the customer is offered the sizes left and orders one", async ({ page }) => {
    await signIn(page, CUSTOMER);
    await page.goto("/home");

    await page.getByRole("button", { name: "View Chicken Inasal" }).first().click();

    // A tile like the others; its sizes show once it is tapped.
    const tea = page.getByTestId(/^addon-\d+$/).filter({ hasText: "Iced Tea" });
    await expect(tea).toContainText("From +₱45.00");
    await expect(page.getByTestId(/^addon-\d+-small$/)).toHaveCount(0);

    await tea.click();
    await expect(page.getByTestId(/^addon-\d+-small$/)).toBeVisible();
    await expect(page.getByTestId(/^addon-\d+-large$/)).toHaveCount(0);
    await shot(page, "customer-dish-size-picker");

    await page.getByTestId(/^addon-\d+-small$/).click();
    await expect(tea).toContainText("Small");
    await expect(page.getByRole("button", { name: /Add to Cart — ₱205\.00/ })).toBeVisible();
    await shot(page, "customer-dish-sizes");

    await page.getByRole("button", { name: /Add to Cart/ }).click();
    await expect(page.getByText("Iced Tea (Small)").first()).toBeVisible();
    await shot(page, "customer-cart");

    await signOut(page);
  });
});
