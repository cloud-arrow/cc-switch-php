import { defineConfig } from 'playwright/test';

export default defineConfig({
  testDir: './tests/E2E/ui',
  timeout: 15000,
  retries: 0,
  use: {
    baseURL: process.env.BASE_URL || 'http://127.0.0.1:8090',
    headless: true,
    viewport: { width: 1280, height: 800 },
    screenshot: 'only-on-failure',
    trace: 'off',
  },
  projects: [
    { name: 'chromium', use: { browserName: 'chromium' } },
  ],
  outputDir: './tests/E2E/ui/results',
});
