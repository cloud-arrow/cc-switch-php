import { test, expect } from '@playwright/test';
import { navTo, clickInSection, dialogFill, dialogClick, closeDialogs, tableHasRow, clickRowAction, withConfirm } from './helpers.mjs';

test.describe('MCP Tab', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    await navTo(page, 'MCP');
  });

  test.afterEach(async ({ page }) => {
    await closeDialogs(page);
  });

  test('MCP page renders', async ({ page }) => {
    const ok = await page.evaluate(() => {
      for (const s of document.querySelectorAll('main.content > section'))
        if (s.offsetParent !== null && s.style.display !== 'none')
          return s.textContent.includes('MCP');
      return false;
    });
    expect(ok).toBe(true);
  });

  test('add MCP server', async ({ page }) => {
    await clickInSection(page, '+ Add');
    await page.waitForTimeout(300);
    await dialogFill(page, 'input[placeholder="Server name"]', 'PW MCP Server');
    await dialogFill(page, 'input[placeholder*="npx"]', 'echo');
    await dialogClick(page, 'footer button:not(.secondary)');
    await page.waitForTimeout(1000);

    expect(await tableHasRow(page, 'PW MCP Server')).toBe(true);

    // Cleanup
    await withConfirm(page, () => clickRowAction(page, 'PW MCP Server', 'Del'));
  });

  test('sync MCP servers', async ({ page }) => {
    await clickInSection(page, 'Sync');
    await page.waitForTimeout(1000);
    // Should not crash — toast may appear
  });
});
