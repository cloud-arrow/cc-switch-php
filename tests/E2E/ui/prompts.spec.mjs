import { test, expect } from '@playwright/test';
import { navTo, clickInSection, dialogFill, dialogClick, closeDialogs, tableHasRow, clickRowAction, withConfirm } from './helpers.mjs';

test.describe('Prompts Tab', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    await navTo(page, 'Prompts');
  });

  test.afterEach(async ({ page }) => {
    await closeDialogs(page);
  });

  test('prompts page renders with app selector', async ({ page }) => {
    const btns = await page.$$('.app-selector button');
    expect(btns.length).toBe(5);
  });

  test('add prompt', async ({ page }) => {
    await clickInSection(page, '+ Add');
    await page.waitForTimeout(400);
    await dialogFill(page, 'input[placeholder="Prompt title"]', 'PW Prompt');
    await dialogFill(page, 'textarea[placeholder*="Prompt content"]', 'PW test body');
    await dialogClick(page, 'footer button:not(.secondary)');
    await page.waitForTimeout(1000);

    expect(await tableHasRow(page, 'PW Prompt')).toBe(true);

    // Cleanup
    await withConfirm(page, () => clickRowAction(page, 'PW Prompt', 'Del'));
  });

  test('edit prompt', async ({ page }) => {
    // Add
    await clickInSection(page, '+ Add');
    await page.waitForTimeout(400);
    await dialogFill(page, 'input[placeholder="Prompt title"]', 'PW Edit Prompt');
    await dialogFill(page, 'textarea[placeholder*="Prompt content"]', 'original body');
    await dialogClick(page, 'footer button:not(.secondary)');
    await page.waitForTimeout(1000);

    // Edit
    await clickRowAction(page, 'PW Edit Prompt', 'Edit');
    await page.waitForTimeout(500);
    await dialogFill(page, 'input[placeholder="Prompt title"]', 'PW Edited Prompt');
    await dialogClick(page, 'footer button:not(.secondary)');
    await page.waitForTimeout(1000);

    expect(await tableHasRow(page, 'PW Edited Prompt')).toBe(true);

    // Cleanup
    await withConfirm(page, () => clickRowAction(page, 'PW Edited Prompt', 'Del'));
  });
});
