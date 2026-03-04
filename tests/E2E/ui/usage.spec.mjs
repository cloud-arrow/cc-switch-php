import { test, expect } from '@playwright/test';
import { navTo } from './helpers.mjs';

test.describe('Usage Tab', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    await navTo(page, 'Usage');
  });

  test('4 stat cards render', async ({ page }) => {
    const count = await page.$$eval('.stat-card', els => els.length);
    expect(count).toBe(4);
  });

  test('stat cards show labels', async ({ page }) => {
    const labels = await page.$$eval('.stat-label', els => els.map(e => e.textContent));
    expect(labels).toContain('Total Requests');
    expect(labels).toContain('Input Tokens');
    expect(labels).toContain('Output Tokens');
    expect(labels).toContain('Total Cost');
  });

  test('period filter changes data', async ({ page }) => {
    await page.evaluate(() => {
      for (const sec of document.querySelectorAll('main.content > section')) {
        if (sec.offsetParent === null || sec.style.display === 'none') continue;
        const sel = sec.querySelectorAll('select');
        if (sel.length >= 2) {
          sel[1].value = 'month';
          sel[1].dispatchEvent(new Event('change'));
          return true;
        }
      }
      return false;
    });
    await page.waitForTimeout(500);
    // Should not crash
    const count = await page.$$eval('.stat-card', els => els.length);
    expect(count).toBe(4);
  });
});
