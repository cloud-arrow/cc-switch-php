import { test, expect } from '@playwright/test';
import { navTo, clickInSection } from './helpers.mjs';

test.describe('Settings Tab', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    await navTo(page, 'Settings');
  });

  test('settings form renders with selectors', async ({ page }) => {
    const selects = await page.$$eval('select', els => els.length);
    expect(selects).toBeGreaterThanOrEqual(2);
  });

  test('theme selector has correct options', async ({ page }) => {
    const options = await page.evaluate(() => {
      const sel = document.querySelector('select');
      return sel ? Array.from(sel.options).map(o => o.value) : [];
    });
    expect(options).toContain('dark');
    expect(options).toContain('light');
  });

  test('save settings works', async ({ page }) => {
    await clickInSection(page, 'Save');
    await page.waitForTimeout(500);
  });
});
