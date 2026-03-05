import { test, expect } from '@playwright/test';
import { navTo } from './helpers.mjs';

test.describe('Usage (Settings > Usage)', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    await navTo(page, 'Usage'); // Goes to Settings > Usage tab
  });

  test('4 stat cards render', async ({ page }) => {
    // Stats are in Settings > Usage tab
    const ok = await page.evaluate(() =>
      document.body.textContent.includes('Requests') &&
      document.body.textContent.includes('Input Tokens') &&
      document.body.textContent.includes('Output Tokens') &&
      document.body.textContent.includes('Total Cost'));
    expect(ok).toBe(true);
  });

  test('usage logs section exists', async ({ page }) => {
    const ok = await page.evaluate(() =>
      document.body.textContent.includes('Recent Logs'));
    expect(ok).toBe(true);
  });

  test('period filter exists', async ({ page }) => {
    const hasSelect = await page.evaluate(() => {
      const selects = document.querySelectorAll('select');
      for (const sel of selects) {
        const options = Array.from(sel.options).map(o => o.value);
        if (options.includes('today') && options.includes('month')) return true;
      }
      return false;
    });
    expect(hasSelect).toBe(true);
  });
});
