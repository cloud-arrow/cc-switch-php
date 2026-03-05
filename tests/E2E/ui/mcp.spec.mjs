import { test, expect } from '@playwright/test';
import { navTo, clickInSection, dialogFill, closeDialogs, tableHasRow, clickRowAction, withConfirm } from './helpers.mjs';

test.describe('MCP View', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    await navTo(page, 'MCP');
  });

  test.afterEach(async ({ page }) => {
    await closeDialogs(page);
  });

  test('MCP page renders', async ({ page }) => {
    const ok = await page.evaluate(() =>
      document.body.textContent.includes('MCP Servers'));
    expect(ok).toBe(true);
  });

  test('add MCP server', async ({ page }) => {
    await clickInSection(page, '+ Add');
    await page.waitForTimeout(300);

    // In MCP form view now
    await dialogFill(page, 'input[placeholder="server-id"]', 'pw-mcp');
    await dialogFill(page, 'input[placeholder="Display name"]', 'PW MCP Server');
    await dialogFill(page, 'input[placeholder="npx"]', 'echo');

    // Click the form's save button (uses x-text for label)
    await page.evaluate(() => {
      const btn = document.querySelector('button[x-text*="editingMcp"]');
      if (btn) btn.click();
    });
    await page.waitForTimeout(1000);

    expect(await tableHasRow(page, 'PW MCP Server')).toBe(true);

    // Cleanup
    await withConfirm(page, () => clickRowAction(page, 'PW MCP Server', 'Delete'));
  });

  test('sync MCP servers', async ({ page }) => {
    await clickInSection(page, 'Sync');
    await page.waitForTimeout(1000);
    // Should not crash
  });
});
