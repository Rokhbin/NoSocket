import { expect, test } from "@playwright/test";

for (const fallback of [false, true]) {
  test(`only one tab polls with ${fallback ? "storage fallback" : "BroadcastChannel"}`, async ({ context }) => {
    const requests = new Map();
    await context.route("**/poll-mock", async (route) => {
      const page = route.request().frame().page();
      requests.set(page, (requests.get(page) ?? 0) + 1);
      await route.fulfill({
        contentType: "application/json",
        body: JSON.stringify({ events: [], cursors: { orders: 0 }, has_more: false, resync_required: [] }),
      });
    });
    const namespace = `e2e-${Date.now()}-${fallback}`;
    const suffix = `?namespace=${namespace}${fallback ? "&fallback=1" : ""}`;
    const first = await context.newPage();
    const second = await context.newPage();
    await Promise.all([
      first.goto(`http://127.0.0.1:4173/tests/e2e/fixture.html${suffix}`),
      second.goto(`http://127.0.0.1:4173/tests/e2e/fixture.html${suffix}`),
    ]);
    await expect.poll(() => (requests.get(first) ?? 0) + (requests.get(second) ?? 0)).toBeGreaterThan(0);
    await first.waitForTimeout(250);
    requests.clear();
    await first.waitForTimeout(350);
    expect([requests.get(first) ?? 0, requests.get(second) ?? 0].filter((count) => count > 0)).toHaveLength(1);
  });
}
