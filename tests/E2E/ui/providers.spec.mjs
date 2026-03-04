import { test, expect } from '@playwright/test';
import { navTo, clickInSection, dialogFill, dialogClick, closeDialogs, tableHasRow, clickRowAction, withConfirm } from './helpers.mjs';

test.describe('Providers Tab', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
  });

  test.afterEach(async ({ page }) => {
    await closeDialogs(page);
  });

  test('provider list loads', async ({ page }) => {
    const ok = await page.evaluate(() =>
      document.querySelector('table tbody tr') !== null ||
      document.body.textContent.includes('No providers'));
    expect(ok).toBe(true);
  });

  test('switch between apps (Claude → Gemini → Claude)', async ({ page }) => {
    // Switch to Gemini
    await page.evaluate(() => {
      for (const b of document.querySelectorAll('.app-selector button'))
        if (b.textContent.trim() === 'gemini') b.click();
    });
    await page.waitForTimeout(800);
    let active = await page.$eval('.app-selector button.contrast', el => el.textContent.trim());
    expect(active).toBe('gemini');

    // Switch back to Claude
    await page.evaluate(() => {
      for (const b of document.querySelectorAll('.app-selector button'))
        if (b.textContent.trim() === 'claude') b.click();
    });
    await page.waitForTimeout(800);
    active = await page.$eval('.app-selector button.contrast', el => el.textContent.trim());
    expect(active).toBe('claude');
  });

  test('add provider via dialog', async ({ page }) => {
    await clickInSection(page, '+ Add');
    await page.waitForTimeout(400);
    const hasDialog = await page.evaluate(() => !!document.querySelector('dialog[open]'));
    expect(hasDialog).toBe(true);

    await dialogFill(page, 'input[placeholder="Provider name"]', 'PW Test Provider');
    await dialogFill(page, 'input[placeholder*="official"]', 'pw-test');
    await dialogFill(page, 'textarea[placeholder*="ANTHROPIC"]', '{"env":{"ANTHROPIC_API_KEY":"sk-pw"}}');
    await dialogClick(page, 'footer button:not(.secondary)');
    await page.waitForTimeout(1000);

    expect(await tableHasRow(page, 'PW Test Provider')).toBe(true);
  });

  test('edit provider name', async ({ page }) => {
    // Prerequisite: add a provider
    await clickInSection(page, '+ Add');
    await page.waitForTimeout(400);
    await dialogFill(page, 'input[placeholder="Provider name"]', 'PW Edit Me');
    await dialogFill(page, 'textarea[placeholder*="ANTHROPIC"]', '{"env":{}}');
    await dialogClick(page, 'footer button:not(.secondary)');
    await page.waitForTimeout(1000);

    // Edit it
    await clickRowAction(page, 'PW Edit Me', 'Edit');
    await page.waitForTimeout(500);
    const prefilled = await page.evaluate(() =>
      document.querySelector('dialog[open] input[placeholder="Provider name"]')?.value);
    expect(prefilled).toBe('PW Edit Me');

    await dialogFill(page, 'input[placeholder="Provider name"]', 'PW Edited');
    await dialogClick(page, 'footer button:not(.secondary)');
    await page.waitForTimeout(1000);

    expect(await tableHasRow(page, 'PW Edited')).toBe(true);

    // Cleanup
    await withConfirm(page, () => clickRowAction(page, 'PW Edited', 'Del'));
  });

  test('switch active provider', async ({ page }) => {
    // Add provider
    await clickInSection(page, '+ Add');
    await page.waitForTimeout(400);
    await dialogFill(page, 'input[placeholder="Provider name"]', 'PW Switch Target');
    await dialogFill(page, 'textarea[placeholder*="ANTHROPIC"]', '{"env":{}}');
    await dialogClick(page, 'footer button:not(.secondary)');
    await page.waitForTimeout(1000);

    // Switch to it
    await clickRowAction(page, 'PW Switch Target', 'Switch');
    await page.waitForTimeout(1000);

    // Cleanup
    await withConfirm(page, () => clickRowAction(page, 'PW Switch Target', 'Del'));
  });

  test('delete provider', async ({ page }) => {
    // Add provider
    await clickInSection(page, '+ Add');
    await page.waitForTimeout(400);
    await dialogFill(page, 'input[placeholder="Provider name"]', 'PW Delete Me');
    await dialogFill(page, 'textarea[placeholder*="ANTHROPIC"]', '{"env":{}}');
    await dialogClick(page, 'footer button:not(.secondary)');
    await page.waitForTimeout(1000);
    expect(await tableHasRow(page, 'PW Delete Me')).toBe(true);

    // Delete it
    await withConfirm(page, () => clickRowAction(page, 'PW Delete Me', 'Del'));
    expect(await tableHasRow(page, 'PW Delete Me')).toBe(false);
  });
});
