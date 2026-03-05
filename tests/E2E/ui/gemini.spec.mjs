import { test, expect } from '@playwright/test';
import { navTo, clickInSection, dialogFill, closeDialogs, tableHasRow, clickRowAction, withConfirm } from './helpers.mjs';

/** Get Alpine data property from the root component */
async function getAlpineData(page, expr) {
  return page.evaluate((e) => {
    const el = document.querySelector('[x-data]');
    if (!el) return undefined;
    return Alpine.evaluate(el, e);
  }, expr);
}

/** Set Alpine data or call a method */
async function evalAlpine(page, expr) {
  return page.evaluate((e) => {
    const el = document.querySelector('[x-data]');
    if (el) Alpine.evaluate(el, e);
  }, expr);
}

test.describe('Gemini App E2E', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    // Switch to Gemini app
    await evalAlpine(page, 'switchApp("gemini")');
    await page.waitForTimeout(1500);
  });

  test.afterEach(async ({ page }) => {
    await closeDialogs(page);
  });

  test('Gemini app is selected', async ({ page }) => {
    const app = await getAlpineData(page, 'currentApp');
    expect(app).toBe('gemini');
  });

  test('Gemini pill is highlighted', async ({ page }) => {
    const active = await page.evaluate(() => {
      const pills = document.querySelectorAll('nav .rounded-xl button');
      for (const b of pills) {
        if (b.textContent.toLowerCase().includes('gemini') && b.classList.contains('bg-blue-600')) return true;
      }
      return false;
    });
    expect(active).toBe(true);
  });

  test('providers load for Gemini', async ({ page }) => {
    const count = await getAlpineData(page, 'providers.length');
    expect(count).toBeGreaterThanOrEqual(0);
  });

  test('add Gemini provider with specific fields', async ({ page }) => {
    // Open add form
    await evalAlpine(page, 'openAddProvider()');
    await page.waitForTimeout(500);

    const view = await getAlpineData(page, 'currentView');
    expect(view).toBe('providerForm');

    // Check Gemini-specific fields are present
    const hasFields = await page.evaluate(() => {
      const body = document.body.textContent;
      return body.includes('API Key') && body.includes('Model');
    });
    expect(hasFields).toBe(true);

    // Fill Gemini provider fields
    await evalAlpine(page, 'providerForm.name = "PW Gemini Test"');
    await evalAlpine(page, 'providerForm.gemini.apiKey = "test-gemini-key"');
    await evalAlpine(page, 'providerForm.gemini.baseUrl = "https://generativelanguage.googleapis.com"');
    await evalAlpine(page, 'providerForm.gemini.model = "gemini-2.0-flash"');

    // Save
    await page.evaluate(() => {
      const btn = document.querySelector('button[x-text*="editingProvider"]');
      if (btn) btn.click();
    });
    await page.waitForTimeout(1500);

    // Verify saved
    expect(await tableHasRow(page, 'PW Gemini Test')).toBe(true);

    // Cleanup
    await withConfirm(page, () => clickRowAction(page, 'PW Gemini Test', 'Delete'));
  });

  test('MCP view works for Gemini', async ({ page }) => {
    await navTo(page, 'MCP');
    const ok = await page.evaluate(() =>
      document.body.textContent.includes('MCP Servers'));
    expect(ok).toBe(true);
  });

  test('Prompts view works for Gemini', async ({ page }) => {
    await navTo(page, 'Prompts');
    const ok = await page.evaluate(() =>
      document.body.textContent.includes('Prompts'));
    expect(ok).toBe(true);
  });

  test('Settings view works from Gemini', async ({ page }) => {
    await navTo(page, 'Settings');
    const ok = await page.evaluate(() =>
      document.body.textContent.includes('General') &&
      document.body.textContent.includes('Advanced') &&
      document.body.textContent.includes('Usage'));
    expect(ok).toBe(true);
  });
});
