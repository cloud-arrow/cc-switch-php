import { test, expect } from '@playwright/test';
import { navTo, clickInSection } from './helpers.mjs';

test.describe('Proxy (Settings > Advanced)', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    await navTo(page, 'Proxy'); // Goes to Settings > Advanced
  });

  test('proxy section renders', async ({ page }) => {
    const ok = await page.evaluate(() =>
      document.body.textContent.includes('Local Proxy'));
    expect(ok).toBe(true);
  });

  test('proxy config checkboxes render', async ({ page }) => {
    const checks = await page.$$('input[type="checkbox"]');
    expect(checks.length).toBeGreaterThanOrEqual(2);
  });

  test('save proxy config', async ({ page }) => {
    await clickInSection(page, 'Save Proxy Config');
    await page.waitForTimeout(500);
    // No crash = pass
  });
});
