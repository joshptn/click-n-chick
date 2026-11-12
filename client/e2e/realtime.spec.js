import { execFileSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { expect, test } from "@playwright/test";

import { CUSTOMER, STAFF } from "./accounts.js";

const SERVER_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../server");

function artisan(args) {
  return execFileSync("php", ["artisan", ...args], {
    cwd: SERVER_DIR,
    encoding: "utf8",
    timeout: 30_000,
  });
}

async function openBell(page) {
  await page.getByTestId("notification-bell").focus();
  await page.keyboard.press("Enter");
}

async function signIn(page, { email, password }) {
  await page.goto("/login");
  await page.getByPlaceholder("Phone number or email address").fill(email);
  await page.getByPlaceholder("Password").fill(password);

  const checked = page.waitForResponse(
    (response) => response.url().endsWith("/api/login") && response.request().method() === "POST" && response.ok()
  );

  await page.getByRole("button", { name: /sign in/i }).click();

  await page.waitForURL(/\/(home|admin|superadmin)/, { timeout: 20_000 });
  await checked;
}

async function waitForRealtime(page, { channel } = {}) {
  await openBell(page);

  const status = page.getByTestId("realtime-status");
  await expect(status).toHaveAttribute("data-connected", "true", { timeout: 30_000 });
  await expect(status).toHaveAttribute("data-ready", "true", { timeout: 30_000 });

  if (channel) {
    await expect
      .poll(async () => page.evaluate((name) => window.__rtSubscribed?.includes(name) ?? false, channel), {
        timeout: 30_000,
      })
      .toBe(true);
  }

  await page.keyboard.press("Escape");
}

test.describe("GATE-3 - real-time verified in a browser", () => {
  test.beforeAll(() => artisan(["cache:clear"]));

  test("a signed-in customer receives a real notification broadcast over Reverb", async ({ page }) => {
    const wsFrames = [];

    page.on("websocket", (ws) => {
      ws.on("framereceived", (frame) => wsFrames.push(frame.payload));
    });

    const consoleErrors = [];
    page.on("console", (msg) => {
      if (msg.type() === "error") consoleErrors.push(msg.text());
    });

    await signIn(page, CUSTOMER);
    await waitForRealtime(page);

    const body = `E2E realtime check ${Date.now()}`;

    const output = artisan([
      "realtime:ping",
      "--title=Order update",
      `--body=${body}`,
    ]);

    expect(output).toContain("Dispatched NotificationBroadcast");

    await expect(page.getByText(body)).toBeVisible({ timeout: 30_000 });

    await openBell(page);
    await expect(page.getByTestId("notification-list")).toContainText(body);

    const sawOnSocket = wsFrames.some((frame) => String(frame).includes(body));
    expect(sawOnSocket, "the payload should have arrived on the websocket").toBe(true);

    expect(consoleErrors.join("\n")).not.toMatch(/broadcasting\/auth|Channel authorization failed/i);
  });

  test("an order status change reaches the staff order channel", async ({ page }) => {
    const wsFrames = [];

    page.on("websocket", (ws) => {
      ws.on("framereceived", (frame) => wsFrames.push(frame.payload));
    });

    await signIn(page, STAFF);
    await waitForRealtime(page, { channel: "admin.orders" });

    const orderId = artisan(["tinker", "--execute", [
      `$u = App\\Models\\User::where('email','${CUSTOMER.email}')->first();`,
      "$o = App\\Models\\Order::create(['user_id'=>$u->id,'total_price'=>250,'status'=>'pending']);",
      "echo $o->id;",
    ].join(" ")]).trim().split(/\s+/).pop();

    expect(Number(orderId)).toBeGreaterThan(0);

    artisan(["realtime:ping", `--order=${orderId}`, "--status=preparing"]);

    await expect(page.getByTestId("last-order-event")).toContainText("preparing", { timeout: 30_000 });

    await expect
      .poll(() => wsFrames.some((f) => String(f).includes("preparing")), { timeout: 30_000 })
      .toBe(true);
  });

  test("channel authorization is still enforced from the browser", async ({ page }) => {
    await signIn(page, CUSTOMER);

    const status = await page.evaluate(async (apiUrl) => {
      const token = JSON.parse(localStorage.getItem("token"));

      const response = await fetch(`${apiUrl}/api/broadcasting/auth`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          Authorization: `Bearer ${token}`,
          "X-Device-Id": localStorage.getItem("device_id"),
        },
        body: JSON.stringify({ socket_id: "123.456", channel_name: "private-admin.orders" }),
      });

      return response.status;
    }, process.env.E2E_API_URL ?? "http://127.0.0.1:8000");

    expect(status).toBe(403);
  });
});
