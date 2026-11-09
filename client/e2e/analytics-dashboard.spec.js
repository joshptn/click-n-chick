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

async function waitForOrderChannel(page) {
  await expect
    .poll(() => page.evaluate(() => window.__rtSubscribed?.includes("admin.orders") ?? false), { timeout: 30_000 })
    .toBe(true);
}

const count = async (page, testId) => Number((await page.getByTestId(testId).textContent()).trim());

function placeOrder() {
  return tinker(
    [
      `$u = App\\Models\\User::where("email","${CUSTOMER.email}")->first();`,
      '$o = App\\Models\\Order::create(["user_id"=>$u->id,"order_type"=>"pickup","status"=>"placed","subtotal"=>250,"total_price"=>250,"total_amount"=>250,"queue_date"=>now("Asia/Manila")->toDateString(),"queued_at"=>now()]);',
      'foreach (range(1,5) as $i) { event(new App\\Events\\OrderBroadcast($o,"update")); }',
      'echo "ID".$o->id;',
    ].join(" ")
  ).match(/ID(\d+)/)[1];
}

function closeOrder(id) {
  tinker(
    [
      `$o = App\\Models\\Order::find(${id});`,
      '$o->status = "completed"; $o->save();',
      'event(new App\\Events\\OrderBroadcast($o,"update"));',
    ].join(" ")
  );
}

test.describe("Analytics Dashboard", () => {
  test("broadcasts refresh the cards once per burst, and sales only when an order closes", async ({ page }) => {
    const requests = { operations: 0, analytics: 0 };
    const writes = [];

    page.on("request", (request) => {
      const url = request.url();

      if (url.includes("/api/admin/reports/operations")) requests.operations += 1;
      if (url.includes("/api/admin/reports/analytics")) requests.analytics += 1;
      if (request.method() !== "GET" && request.method() !== "OPTIONS" && url.includes("/api/") && !/login|broadcasting|logout/.test(url)) writes.push(url);
    });

    await signIn(page, MANAGER);
    await expect(page.getByTestId("card-new-value")).toBeVisible();
    await expect(page.getByTestId("card-due-value")).toBeVisible();
    await expect(page.getByTestId("card-to-pay-value")).toBeVisible();
    await expect(page.getByTestId("card-delivery")).toContainText("To Deliver Today");
    await expect(page.getByTestId("card-pickup")).toContainText("For Pickup Today");
    await expect(page.getByTestId("panel-sales")).toBeVisible();
    await waitForOrderChannel(page);

    const placedBefore = await count(page, "card-new-value");

    requests.operations = 0;
    requests.analytics = 0;

    const id = placeOrder();

    await expect.poll(() => count(page, "card-new-value"), { timeout: 15_000 }).toBe(placedBefore + 1);
    await page.waitForTimeout(3000);

    expect(requests.operations).toBe(1);
    expect(requests.analytics).toBe(0);

    closeOrder(id);

    await expect(page.getByTestId("log-row").first()).toBeVisible({ timeout: 15_000 });
    await expect(page.getByTestId("log-row").first()).toContainText("Not Verified");
    await page.waitForTimeout(3000);

    expect(requests.operations).toBe(2);
    expect(requests.analytics).toBe(1);
    expect(writes).toEqual([]);
  });

  test("the chart drills from the week to a day to an hour, and steps between them", async ({ page }) => {
    await signIn(page, MANAGER);

    const title = page.getByTestId("sales-title");

    await expect(title).toHaveText("Weekly Sales");
    await expect(page.getByTestId("chart-previous")).toHaveCount(0);

    const chart = page.getByTestId("sales-chart");
    const box = await chart.boundingBox();

    await chart.click({ position: { x: box.width - 12, y: 100 } });
    await expect(title).toHaveText(/^[A-Z][a-z]+day$/);
    await expect(page.getByTestId("chart-next")).toBeDisabled();

    const today = await title.textContent();

    await page.getByTestId("chart-previous").click();
    await expect(title).not.toHaveText(today);
    await expect(page.getByTestId("chart-next")).toBeEnabled();

    await page.getByTestId("chart-next").click();
    await expect(title).toHaveText(today);

    await page.getByTestId("sales-chart").click({ position: { x: box.width / 2, y: 100 } });
    await expect(title).toHaveText(/^[A-Z][a-z]{2}, \d+[AP]M$/);

    const hour = await title.textContent();

    await page.getByTestId("chart-previous").click();
    await expect(title).not.toHaveText(hour);

    await page.getByTestId("chart-view-daily").click();
    await expect(title).toHaveText(today);

    await page.getByTestId("chart-view-weekly").click();
    await expect(title).toHaveText("Weekly Sales");
  });

  test("the Store Agent cannot open it", async ({ page }) => {
    await signIn(page, STAFF);
    await page.goto("/superadmin");
    await expect(page).not.toHaveURL(/\/superadmin$/);
  });
});
