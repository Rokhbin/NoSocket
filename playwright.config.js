import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: "./tests/e2e",
  timeout: 10_000,
  use: { browserName: "chromium" },
  webServer: {
    command: "php -S 127.0.0.1:4173 -t .",
    url: "http://127.0.0.1:4173/tests/e2e/fixture.html",
    reuseExistingServer: true,
  },
});
