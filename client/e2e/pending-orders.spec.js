import { execFileSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { expect, test } from "@playwright/test";

import { CUSTOMER, MANAGER, STAFF } from "./accounts.js";

const SERVER_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../server");

function tinker(code) {
  return execFileSync("php", ["artisan", "tinker", "--execute", code], {
    cwd: SERVER_DIR,
    encoding: "utf8",
    timeout: 30_000,
  }).trim();
}

async function signIn(page, { email, password }) {
  await page.goto("/login");
  await page.getByPlaceholder("Phone number or email address").fill(email);
  await page.getByPlaceholder("Password").fill(password);
  await page.getByRole("button", { name: /sign in/i }).click();
  await page.waitForURL(/\/(home|admin|superadmin)/, { timeout: 20_000 });
}

function createConfirmedOrder({ broadcast = false } = {}) {
  return tinker(
    [
      `$u = App\\Models\\User::where("email","${CUSTOMER.email}")->first();`,
      '$o = App\\Models\\Order::create(["user_id"=>$u->id,"order_type"=>"delivery","status"=>"confirmed","subtotal"=>250,"total_price"=>250,"total_amount"=>250,"queue_date"=>now("Asia/Manila")->toDateString(),"queue_number"=>rand(5000,9000),"queued_at"=>now()]);',
      broadcast ? 'event(new App\\Events\\OrderBroadcast($o,"update"));' : "",
      'echo "ID".$o->id;',
    ].join(" ")
  ).match(/ID(\d+)/)[1];
}

function createYesterdaysOrders() {
  const [regular, advance] = tinker(
    [
      `$u = App\\Models\\User::where("email","${CUSTOMER.email}")->first();`,
      '$r = App\\Models\\Order::create(["user_id"=>$u->id,"order_type"=>"pickup","status"=>"preparing","subtotal"=>250,"total_price"=>250,"total_amount"=>250,"queue_date"=>now("Asia/Manila")->subDay()->toDateString(),"queue_number"=>rand(5000,9000),"queued_at"=>now()->subDay()]);',
      '$a = App\\Models\\Order::create(["user_id"=>$u->id,"order_type"=>"pickup","status"=>"scheduled","payment_status"=>"paid","subtotal"=>250,"total_price"=>250,"total_amount"=>250,"scheduled_for"=>now()->subDay()]);',
      'echo "IDS".$r->id."-".$a->id;',
    ].join(" ")
  ).match(/IDS(\d+)-(\d+)/).slice(1);

  return { regular, advance };
}

test.describe("Pending Orders", () => {
  test("Unsettled Orders lists yesterday's immediate orders, not advance orders, and they can be moved on", async ({ page }) => {
    const { regular, advance } = createYesterdaysOrders();

    await signIn(page, STAFF);
    await page.getByRole("link", { name: /Pending Orders/ }).click();

    const group = page.getByTestId("pending-unsettled");
    const row = group.locator(`[data-order="${regular}"]`);

    await expect(row).toBeVisible();
    await expect(page.locator(`[data-testid="pending-row"][data-order="${advance}"]`)).toHaveCount(0);

    await row.getByTestId("pending-move").click();
    await expect(row).toContainText("Ready for pickup", { timeout: 15_000 });
  });

  test("a new pending order arrives live, and the row's control moves it to Being Prepared", async ({ page }) => {
    await signIn(page, STAFF);
    await page.getByRole("link", { name: /Pending Orders/ }).click();
    await expect(page.getByTestId("summary-total")).toBeVisible();
    await expect
      .poll(() => page.evaluate(() => window.__rtSubscribed?.includes("admin.orders") ?? false), { timeout: 30_000 })
      .toBe(true);

    const id = createConfirmedOrder({ broadcast: true });
    const row = page.locator(`[data-testid="pending-row"][data-order="${id}"]`);

    await expect(row).toBeVisible({ timeout: 15_000 });
    await expect(row).toContainText("ASAP");

    await expect(page.getByTestId("pending-now").locator(`[data-order="${id}"]`)).toBeVisible();

    await row.getByTestId("pending-move").click();

    await expect(page.getByTestId("pending-preparing").locator(`[data-order="${id}"]`)).toBeVisible({ timeout: 15_000 });
    await expect(row.getByTestId("pending-move")).toContainText("Send Out for Delivery");
  });

  test("the Store Manager can read it but not move an order", async ({ page }) => {
    const id = createConfirmedOrder();

    await signIn(page, MANAGER);
    await page.getByRole("link", { name: /Pending Orders/ }).click();
    await expect(page.getByTestId("read-only-notice")).toBeVisible();

    const row = page.locator(`[data-testid="pending-row"][data-order="${id}"]`);

    await expect(row.getByTestId("pending-move")).toHaveCount(0);
    await row.click();
    await expect(page.getByRole("dialog")).toBeVisible();
    await expect(page.getByRole("dialog").getByRole("button", { name: /prepar/i })).toHaveCount(0);
  });
});
