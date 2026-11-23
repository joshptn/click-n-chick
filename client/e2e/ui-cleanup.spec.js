import { execFileSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { expect, test } from "@playwright/test";

import { CUSTOMER, MANAGER, STAFF } from "./accounts.js";

const SERVER_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../server");

/** Where the screenshots go, for looking at the pages by eye. Optional. */
const SHOTS = process.env.E2E_SHOTS ?? null;

const shot = async (page, name) => {
  if (SHOTS) await page.screenshot({ path: path.join(SHOTS, `${name}.png`) });
};

function tinker(code) {
  return execFileSync("php", ["artisan", "tinker", "--execute", code], { cwd: SERVER_DIR, encoding: "utf8", timeout: 60_000 }).trim();
}

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
 * Moves inside the app without a reload. The test API is a single-threaded PHP
 * server, so a reload behind a busy page can time out its boot /api/user call,
 * and a failed boot call signs the user out (on purpose).
 */
async function go(page, to) {
  await page.evaluate((path) => {
    window.history.pushState({}, "", path);
    window.dispatchEvent(new PopStateEvent("popstate"));
  }, to);
}

/** Scrolls the page well down and gives the pinned row a frame to settle. */
async function scrollDown(page) {
  await page.mouse.move(700, 500);
  await page.mouse.wheel(0, 1600);
  await page.waitForTimeout(300);
}

const CATEGORIES = ["Breakfast", "Combos", "Drinks", "Group", "Party", "Sides", "Ala Carte", "Desserts", "Snacks", "Family Meals", "Seasonal"];

/**
 * The UI clean-up of 2026-11-24: category tabs wrap beside Manage categories,
 * staff tables keep their column row at the top, the dish dialog keeps its
 * Add to Cart bar at the bottom, mobile numbers take 11 digits only, the
 * password fields show what was typed, sign-out asks "Cancel / Sign out", and
 * a guest's checkout has a joined-up two-step rail and one Pickup Person field.
 */
test.describe("UI clean-up", () => {
  test.describe.configure({ mode: "serial" });

  test.beforeAll(() => {
    // Enough categories to fill a row, and enough dishes to scroll; none has add-ons.
    tinker(
      `foreach (${JSON.stringify(CATEGORIES).replaceAll('"', "'")} as $n) { $c = App\\Models\\Category::firstOrCreate(['name' => $n]); ` +
        `foreach (range(1, 2) as $i) { $f = new App\\Models\\Food; $f->forceFill(['food_name' => "$n Dish $i", 'price' => 100 + $i, 'description' => 'Short.', ` +
        `'thumbnail' => 'data:image/gif;base64,R0lGODlhAQABAAAAACw=', 'stock_quantity' => 20, 'is_available' => true, 'category_id' => $c->id])->save(); } }`
    );
  });

  test("category tabs wrap beside Manage categories, and the column row stays on top", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await signIn(page, MANAGER);
    await page.goto("/superadmin/menu");

    const tabs = page.getByRole("group", { name: "Filter by category" });
    const manage = page.getByTestId("manage-categories");
    await expect(tabs).toContainText("Seasonal");
    await expect(page.getByTestId("catalog-row")).toHaveCount(25);

    const tabsBox = await tabs.boundingBox();
    const manageBox = await manage.boundingBox();

    expect(tabsBox.height, "the tabs wrap onto a second row").toBeGreaterThan(50);
    expect(manageBox.x, "Manage categories sits to the right of the tabs").toBeGreaterThanOrEqual(tabsBox.x + tabsBox.width - 1);
    expect(manageBox.y, "on the tabs' rows, not pushed below them").toBeLessThan(tabsBox.y + tabsBox.height);

    // The second row starts beside ALL, not under it.
    const all = await page.getByTestId("category-all").boundingBox();
    const lastTab = await tabs.getByRole("button").last().boundingBox();
    expect(lastTab.y, "the last tab is on a later row").toBeGreaterThan(all.y + all.height);
    const secondRowStarts = await tabs.getByRole("button").evaluateAll((buttons) => {
      const firstTop = buttons[0].getBoundingClientRect().top;

      return Math.min(...buttons.map((button) => button.getBoundingClientRect()).filter((box) => box.top > firstTop + 5).map((box) => box.left));
    });
    expect(secondRowStarts, "rows after the first start beside ALL").toBeGreaterThanOrEqual(all.x + all.width - 1);
    await shot(page, "cleanup-category-wrap");
    await page.setViewportSize({ width: 1024, height: 800 });
    await shot(page, "cleanup-category-wrap-1024");
    await page.setViewportSize({ width: 390, height: 844 });
    await shot(page, "cleanup-category-phone");
    await page.setViewportSize({ width: 1280, height: 800 });

    await scrollDown(page);
    const head = await page.getByTestId("table-head").boundingBox();
    expect(Math.abs(head.y), "the column row is pinned to the top").toBeLessThan(2);
    await shot(page, "cleanup-menu-scrolled");

    await signOut(page);
  });

  test("the Store Agent's ticked-dishes bar travels with the pinned column row", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await signIn(page, STAFF);
    await page.goto("/admin/products");

    await page.getByTestId("stock-pick").first().check();
    const head = page.getByTestId("table-head");
    await expect(head.getByTestId("stock-selection")).toContainText("1 dish selected");

    await scrollDown(page);
    expect(Math.abs((await head.boundingBox()).y)).toBeLessThan(2);
    await expect(head.getByTestId("stock-selection")).toBeInViewport();
    await shot(page, "cleanup-agent-scrolled");

    await signOut(page);
  });

  test("the customer's dish dialog, phone fields, password eye and sign-out dialog", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await signIn(page, CUSTOMER);
    await page.goto("/home");

    // No add-ons and a one-word description: the bar still sits at the bottom.
    await page.getByRole("button", { name: "View Desserts Dish 1" }).first().click();
    const dialog = page.getByRole("dialog");
    const add = dialog.getByRole("button", { name: /Add to Cart/ });
    await expect(add).toBeVisible();
    // Mantine puts the dialog role on the content box itself.
    const dialogBox = await dialog.boundingBox();
    const addBox = await add.boundingBox();
    expect(dialogBox.y + dialogBox.height - (addBox.y + addBox.height), "the Add to Cart bar is at the bottom").toBeLessThan(40);
    await shot(page, "cleanup-dish-no-addons");
    await add.click();
    await expect(dialog).toBeHidden();

    // Checkout: three steps for an account, and the phone keeps 11 digits.
    await go(page, "/checkout");
    await expect(page.getByRole("list", { name: "Checkout progress" }).getByRole("listitem")).toHaveCount(3);
    const phone = page.getByPlaceholder("09123456789");
    await phone.fill("");
    await phone.pressSequentially("ab0917-123 4567999");
    await expect(phone).toHaveValue("09171234567");
    await shot(page, "cleanup-checkout-account");

    // Profile: digits only, 11 at most.
    await go(page, "/account/profile");
    const profilePhone = page.locator("#phone");

    if (await profilePhone.isEnabled()) {
      await profilePhone.fill("");
      await profilePhone.pressSequentially("abc0918765432199");
      await expect(profilePhone).toHaveValue("09187654321");
    }

    // The password fields show what was typed on request.
    const current = page.locator("#current-password");
    await current.fill("Secret123");
    await expect(current).toHaveAttribute("type", "password");
    await page.getByTestId("current-password-reveal").click();
    await expect(current).toHaveAttribute("type", "text");
    await shot(page, "cleanup-profile");

    // Signing a device out: Cancel and a red Sign out.
    await go(page, "/account/security");
    await page.getByRole("button", { name: "Sign out", exact: true }).first().click();
    const confirm = page.getByRole("dialog");
    await expect(confirm.getByRole("button", { name: "Cancel" })).toBeVisible();
    await expect(confirm.getByRole("button", { name: "Sign out", exact: true })).toBeVisible();
    await expect(confirm.getByText(/^Yes|^No/)).toHaveCount(0);
    await shot(page, "cleanup-signout-dialog");
    await confirm.getByRole("button", { name: "Cancel" }).click();

    await signOut(page);
  });

  test("a guest's checkout: two joined-up steps and one Pickup Person field", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto("/home");

    await page.getByRole("button", { name: "View Snacks Dish 1" }).first().click();
    await page.getByRole("dialog").getByRole("button", { name: /Add to Cart/ }).click();
    await expect(page.getByRole("dialog")).toBeHidden();
    await page.getByRole("button", { name: /^Checkout$/ }).first().click();
    await page.waitForURL(/\/checkout/);

    const steps = page.getByRole("list", { name: "Checkout progress" }).getByRole("listitem");
    await expect(steps).toHaveCount(2);

    // The line from step 1 runs up to step 2.
    const line = steps.nth(0).locator("span.h-px");
    const lineBox = await line.boundingBox();
    const secondBox = await steps.nth(1).boundingBox();
    expect(secondBox.x - (lineBox.x + lineBox.width)).toBeLessThan(20);

    await expect(page.getByTestId("pickup-person")).toBeVisible();
    await expect(page.getByText("First Name")).toHaveCount(0);
    await page.getByTestId("pickup-person").fill("Julia Veneese");

    const phone = page.getByPlaceholder("09123456789");
    await phone.pressSequentially("09171234567123");
    await expect(phone).toHaveValue("09171234567");
    await shot(page, "cleanup-guest-checkout");

    await page.setViewportSize({ width: 390, height: 844 });
    await shot(page, "cleanup-guest-checkout-phone");
  });
});
