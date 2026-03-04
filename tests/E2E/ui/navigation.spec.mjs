import { test, expect } from '@playwright/test';
import { navTo } from './helpers.mjs';

test.describe('Navigation', () => {
  test('all 7 tabs are navigable', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });

    for (const tab of ['Providers', 'MCP', 'Proxy', 'Skills', 'Prompts', 'Settings', 'Usage']) {
      await navTo(page, tab);

      const visible = await page.evaluate(() => {
        for (const s of document.querySelectorAll('main.content > section'))
          if (s.offsetParent !== null && s.style.display !== 'none') return true;
        return false;
      });
      expect(visible).toBe(true);
    }
  });

  test('rapid tab switching does not crash', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });

    for (const tab of ['MCP', 'Settings', 'Usage', 'Proxy', 'Prompts', 'Skills', 'Providers']) {
      await navTo(page, tab);
      await page.waitForTimeout(100);
    }

    // Should still be functional
    const tabs = await page.$$eval('nav.sidebar li a', els => els.length);
    expect(tabs).toBe(7);
  });

  test('Skills tab shows content', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    await navTo(page, 'Skills');

    const ok = await page.evaluate(() => {
      for (const s of document.querySelectorAll('main.content > section'))
        if (s.offsetParent !== null && s.style.display !== 'none')
          return s.textContent.includes('Skills');
      return false;
    });
    expect(ok).toBe(true);
  });
});
