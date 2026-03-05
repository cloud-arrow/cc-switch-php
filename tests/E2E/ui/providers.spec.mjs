import { test, expect } from '@playwright/test';
import { navTo, clickInSection, dialogFill, closeDialogs, tableHasRow, clickRowAction, withConfirm } from './helpers.mjs';

test.describe('Providers View', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
  });

  test.afterEach(async ({ page }) => {
    await closeDialogs(page);
  });

  test('provider list loads', async ({ page }) => {
    const ok = await page.evaluate(() =>
      document.body.textContent.includes('Providers') &&
      (document.body.textContent.includes('configured') ||
       document.body.textContent.includes('No providers')));
    expect(ok).toBe(true);
  });

  test('switch between apps', async ({ page }) => {
    // Click Gemini in app switcher
    await page.evaluate(() => {
      const btns = document.querySelectorAll('nav .rounded-xl button');
      for (const b of btns) {
        if (b.textContent.includes('Gemini')) { b.click(); break; }
      }
    });
    await page.waitForTimeout(800);
    // Verify Gemini is selected (has bg-blue-600 class)
    const active = await page.evaluate(() => {
      const btns = document.querySelectorAll('nav .rounded-xl button');
      for (const b of btns) {
        if (b.classList.contains('bg-blue-600') && b.textContent.includes('Gemini')) return true;
      }
      return false;
    });
    expect(active).toBe(true);
  });

  test('add provider via panel', async ({ page }) => {
    // Click Add button
    await page.click('button:has-text("Add")');
    await page.waitForTimeout(400);

    // Should be in providerForm view
    const inForm = await page.evaluate(() =>
      document.body.textContent.includes('Add New Provider') ||
      document.body.textContent.includes('Basic Information'));
    expect(inForm).toBe(true);

    // Fill name
    await dialogFill(page, 'input[placeholder="Provider name"]', 'PW Test Provider');

    // Fill Claude API key
    await dialogFill(page, 'input[placeholder="sk-ant-..."]', 'sk-pw-test');

    // Click Save
    await page.click('button:has-text("Save")');
    await page.waitForTimeout(1000);

    expect(await tableHasRow(page, 'PW Test Provider')).toBe(true);

    // Cleanup
    await withConfirm(page, () => clickRowAction(page, 'PW Test Provider', 'Delete'));
  });

  test('edit provider name', async ({ page }) => {
    // Add a provider first
    await page.click('button:has-text("Add")');
    await page.waitForTimeout(400);
    await dialogFill(page, 'input[placeholder="Provider name"]', 'PW Edit Me');
    await page.click('button:has-text("Save")');
    await page.waitForTimeout(1000);

    // Edit it
    await clickRowAction(page, 'PW Edit Me', 'Edit');
    await page.waitForTimeout(500);
    const prefilled = await page.evaluate(() => {
      const inp = document.querySelector('input[placeholder="Provider name"]');
      return inp?.value;
    });
    expect(prefilled).toBe('PW Edit Me');

    await dialogFill(page, 'input[placeholder="Provider name"]', 'PW Edited');
    await page.click('button:has-text("Update")');
    await page.waitForTimeout(1000);

    expect(await tableHasRow(page, 'PW Edited')).toBe(true);

    // Cleanup
    await withConfirm(page, () => clickRowAction(page, 'PW Edited', 'Delete'));
  });

  test('delete provider', async ({ page }) => {
    // Add
    await page.click('button:has-text("Add")');
    await page.waitForTimeout(400);
    await dialogFill(page, 'input[placeholder="Provider name"]', 'PW Delete Me');
    await page.click('button:has-text("Save")');
    await page.waitForTimeout(1000);
    expect(await tableHasRow(page, 'PW Delete Me')).toBe(true);

    // Delete
    await withConfirm(page, () => clickRowAction(page, 'PW Delete Me', 'Delete'));
    await page.waitForTimeout(500);
    expect(await tableHasRow(page, 'PW Delete Me')).toBe(false);
  });
});
