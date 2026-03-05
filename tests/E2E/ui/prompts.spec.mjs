import { test, expect } from '@playwright/test';
import { navTo, clickInSection, dialogFill, closeDialogs, tableHasRow, clickRowAction, withConfirm } from './helpers.mjs';

test.describe('Prompts View', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    await navTo(page, 'Prompts');
  });

  test.afterEach(async ({ page }) => {
    await closeDialogs(page);
  });

  test('prompts page renders', async ({ page }) => {
    const ok = await page.evaluate(() =>
      document.body.textContent.includes('Prompts'));
    expect(ok).toBe(true);
  });

  test('add prompt', async ({ page }) => {
    await clickInSection(page, '+ Add');
    await page.waitForTimeout(400);

    await dialogFill(page, 'input[placeholder="Prompt title"]', 'PW Prompt');
    await dialogFill(page, 'textarea[placeholder="Prompt content..."]', 'PW test body');

    // Click the form's save button (uses x-text for label)
    await page.evaluate(() => {
      const btn = document.querySelector('button[x-text*="editingPrompt"]');
      if (btn) btn.click();
    });
    await page.waitForTimeout(1000);

    expect(await tableHasRow(page, 'PW Prompt')).toBe(true);

    // Cleanup
    await withConfirm(page, () => clickRowAction(page, 'PW Prompt', 'Delete'));
  });

  test('edit prompt', async ({ page }) => {
    // Add
    await clickInSection(page, '+ Add');
    await page.waitForTimeout(400);
    await dialogFill(page, 'input[placeholder="Prompt title"]', 'PW Edit Prompt');
    await dialogFill(page, 'textarea[placeholder="Prompt content..."]', 'original body');
    await page.evaluate(() => {
      const btn = document.querySelector('button[x-text*="editingPrompt"]');
      if (btn) btn.click();
    });
    await page.waitForTimeout(1000);

    // Edit
    await clickRowAction(page, 'PW Edit Prompt', 'Edit');
    await page.waitForTimeout(500);
    await dialogFill(page, 'input[placeholder="Prompt title"]', 'PW Edited Prompt');
    // The button now shows "Update" since editingPrompt is set
    await page.evaluate(() => {
      const btn = document.querySelector('button[x-text*="editingPrompt"]');
      if (btn) btn.click();
    });
    await page.waitForTimeout(1000);

    expect(await tableHasRow(page, 'PW Edited Prompt')).toBe(true);

    // Cleanup
    await withConfirm(page, () => clickRowAction(page, 'PW Edited Prompt', 'Delete'));
  });
});
