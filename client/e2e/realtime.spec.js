import { execFileSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { expect, test } from "@playwright/test";

const SERVER_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../server");

const CUSTOMER = { email: "customer@chicknclick.test", password: "Password123!" };
const STAFF = { email: "admin@chicknclick.test", password: "Password123!" };

/** Run an artisan command against the same database the app is serving. */
function artisan(args) {
  return execFileSync("php", ["artisan", ...args], {
    cwd: SERVER_DIR,
    encoding: "utf8",
    timeout: 30_000,
  });
}

/**
 * Open the notification menu by keyboard.
 *
 * A toast can sit over the bell and swallow a pointer click - and a forced
 * click just lands on the toast. Key events are not intercepted by overlays,
 * so this works whether or not a notification is on screen.
 */
async function openBell(page) {
  await page.getByTestId("notification-bell").focus();
  await page.keyboard.press("Enter");
}

async function signIn(page, { email, password }) {
  await page.goto("/login");
  await page.getByPlaceholder("Phone number or email address").fill(email);
  await page.getByPlaceholder("Password").fill(password);
  await page.getByRole("button", { name: /sign in/i }).click();

  // Landing on the app means the token is in localStorage, which is what the
  // Echo authorizer reads.
  await page.waitForURL(/\/(home|admin|superadmin)/, { timeout: 20_000 });
}

/**
 * Wait until the browser is not merely connected but SUBSCRIBED.
 *
 * These are different states and conflating them makes this test lie. A
 * private channel needs an auth round trip after the socket opens; anything
 * broadcast in that window is missed outright, because Reverb does not replay.
 * Waiting on `data-connected` alone passed intermittently for exactly that
 * reason. Both attributes come from pusher-js/Echo, not from the test.
 */
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
  test("a signed-in customer receives a real notification broadcast over Reverb", async ({ page }) => {
    const wsFrames = [];

    // Recording the raw frames proves delivery came over the websocket rather
    // than a poll or a refetch that happened to coincide.
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

    // A REAL broadcast: the same event Utils\Notification dispatches, through
    // the queue, through Reverb. Nothing is faked or injected into the page.
    const output = artisan([
      "realtime:ping",
      "--title=Order update",
      `--body=${body}`,
    ]);

    expect(output).toContain("Dispatched NotificationBroadcast");

    // The browser must show it without any navigation or manual refresh.
    await expect(page.getByText(body)).toBeVisible({ timeout: 30_000 });

    await openBell(page);
    await expect(page.getByTestId("notification-list")).toContainText(body);

    const sawOnSocket = wsFrames.some((frame) => String(frame).includes(body));
    expect(sawOnSocket, "the payload should have arrived on the websocket").toBe(true);

    expect(consoleErrors.join("\n")).not.toMatch(/broadcasting\/auth|Channel authorization failed/i);
  });

  test("an order status change reaches the staff order channel", async ({ page }) => {
    const wsFrames = [];

    // Registered before sign-in: the socket opens during sign-in, and a
    // listener attached afterwards never sees an already-open connection.
    page.on("websocket", (ws) => {
      ws.on("framereceived", (frame) => wsFrames.push(frame.payload));
    });

    await signIn(page, STAFF);
    await waitForRealtime(page, { channel: "admin.orders" });

    // Create an order owned by the customer, then change its status the way
    // updateOrderStatus does. Staff subscribe to private-admin.orders.
    const orderId = artisan(["tinker", "--execute", [
      "$u = App\\Models\\User::where('email','customer@chicknclick.test')->first();",
      "$o = App\\Models\\Order::create(['user_id'=>$u->id,'total_price'=>250,'status'=>'pending']);",
      "echo $o->id;",
    ].join(" ")]).trim().split(/\s+/).pop();

    expect(Number(orderId)).toBeGreaterThan(0);

    artisan(["realtime:ping", `--order=${orderId}`, "--status=preparing"]);

    // The staff dashboard renders the last order event it received live.
    await expect(page.getByTestId("last-order-event")).toContainText("preparing", { timeout: 30_000 });

    await expect
      .poll(() => wsFrames.some((f) => String(f).includes("preparing")), { timeout: 30_000 })
      .toBe(true);
  });

  test("channel authorization is still enforced from the browser", async ({ page }) => {
    await signIn(page, CUSTOMER);

    // A customer asking for the staff firehose must be refused by
    // routes/channels.php. Asserted through the real endpoint with the real
    // token, so this covers the client's authorizer as well as the rule.
    // import.meta.env is resolved at build time and does not exist in the page
    // context, so the API origin is passed in.
    const status = await page.evaluate(async (apiUrl) => {
      const token = JSON.parse(localStorage.getItem("token"));

      const response = await fetch(`${apiUrl}/api/broadcasting/auth`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ socket_id: "123.456", channel_name: "private-admin.orders" }),
      });

      return response.status;
    }, process.env.E2E_API_URL ?? "http://127.0.0.1:8000");

    expect(status).toBe(403);
  });
});
