import { test, expect } from '@playwright/test';
import { navTo, clickInSection } from './helpers.mjs';

test.describe('Settings View', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    await navTo(page, 'Settings');
  });

  test('settings page renders with tabs', async ({ page }) => {
    const ok = await page.evaluate(() =>
      document.body.textContent.includes('General') &&
      document.body.textContent.includes('Advanced') &&
      document.body.textContent.includes('Usage'));
    expect(ok).toBe(true);
  });

  test('theme buttons exist', async ({ page }) => {
    const ok = await page.evaluate(() =>
      document.body.textContent.includes('Light') &&
      document.body.textContent.includes('Dark') &&
      document.body.textContent.includes('Auto'));
    expect(ok).toBe(true);
  });

  test('language selector has options', async ({ page }) => {
    const options = await page.evaluate(() => {
      // Use the specific x-model attribute to find the language select
      const sel = document.querySelector('select[x-model="settingsData.language"]');
      return sel ? Array.from(sel.options).map(o => o.value) : [];
    });
    expect(options).toContain('en');
    expect(options).toContain('zh');
  });

  test('save settings works', async ({ page }) => {
    await clickInSection(page, 'Save Settings');
    await page.waitForTimeout(500);
  });
});
