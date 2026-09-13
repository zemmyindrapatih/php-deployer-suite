// @ts-check
const { defineConfig } = require('@playwright/test');

const PORT = 8930;

module.exports = defineConfig({
  testDir: './tests/e2e',
  timeout: 30000,
  fullyParallel: false,
  workers: 1,
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
  },
  webServer: {
    command: `node tests/e2e/prepare-fixture.js && php -S 127.0.0.1:${PORT} -t tests/e2e/.fixture`,
    url: `http://127.0.0.1:${PORT}/deploy-receiver.php`,
    reuseExistingServer: false,
    timeout: 20000,
  },
});
