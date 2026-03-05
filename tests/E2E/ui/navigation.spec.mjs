import { test, expect } from '@playwright/test';
import { navTo } from './helpers.mjs';

test.describe('Navigation', () => {
  test('main views are navigable', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });

    for (const view of ['Providers', 'MCP', 'Skills', 'Prompts', 'Settings']) {
      await navTo(page, view);

      const visible = await page.evaluate(() => {
        const main = document.querySelector('main');
        if (!main) return false;
        for (const div of main.querySelectorAll(':scope > div'))
          if (div.offsetParent !== null && div.style.display !== 'none') return true;
        return false;
      });
      expect(visible).toBe(true);
    }
  });

  test('rapid view switching does not crash', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });

    for (const view of ['MCP', 'Settings', 'Prompts', 'Skills', 'Providers']) {
      await navTo(page, view);
      await page.waitForTimeout(100);
    }

    // Should still be functional - navbar still present
    const title = await page.textContent('nav h1');
    expect(title).toContain('CC Switch');
  });

  test('Skills view shows content', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    await navTo(page, 'Skills');

    const ok = await page.evaluate(() => {
      return document.body.textContent.includes('Skills');
    });
    expect(ok).toBe(true);
  });
});
