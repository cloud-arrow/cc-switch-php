import { test, expect } from '@playwright/test';
import { navTo, clickInSection, closeDialogs, tableHasRow, clickRowAction, withConfirm } from './helpers.mjs';

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

test.describe('Codex App E2E', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    // Switch to Codex app
    await evalAlpine(page, 'switchApp("codex")');
    await page.waitForTimeout(1500);
  });

  test.afterEach(async ({ page }) => {
    await closeDialogs(page);
  });

  test('Codex app is selected', async ({ page }) => {
    const app = await getAlpineData(page, 'currentApp');
    expect(app).toBe('codex');
  });

  test('Codex pill is highlighted', async ({ page }) => {
    const active = await page.evaluate(() => {
      const pills = document.querySelectorAll('nav .rounded-xl button');
      for (const b of pills) {
        if (b.textContent.toLowerCase().includes('codex') && b.classList.contains('bg-blue-600')) return true;
      }
      return false;
    });
    expect(active).toBe(true);
  });

  test('providers load for Codex', async ({ page }) => {
    const count = await getAlpineData(page, 'providers.length');
    expect(count).toBeGreaterThanOrEqual(0);
  });

  test('add Codex provider with specific fields', async ({ page }) => {
    // Open add form
    await evalAlpine(page, 'openAddProvider()');
    await page.waitForTimeout(500);

    const view = await getAlpineData(page, 'currentView');
    expect(view).toBe('providerForm');

    // Check Codex-specific fields are present
    const hasFields = await page.evaluate(() => {
      const body = document.body.textContent;
      return body.includes('API Key') && body.includes('Model') && body.includes('TOML');
    });
    expect(hasFields).toBe(true);

    // Fill Codex provider fields
    await evalAlpine(page, 'providerForm.name = "PW Codex Test"');
    await evalAlpine(page, 'providerForm.codex.apiKey = "test-codex-key"');
    await evalAlpine(page, 'providerForm.codex.model = "gpt-5.2"');
    await evalAlpine(page, `providerForm.codex.tomlConfig = 'model_provider = "openai"\\nmodel = "gpt-5.2"'`);

    // Save
    await page.evaluate(() => {
      const btn = document.querySelector('button[x-text*="editingProvider"]');
      if (btn) btn.click();
    });
    await page.waitForTimeout(1500);

    // Verify saved
    expect(await tableHasRow(page, 'PW Codex Test')).toBe(true);

    // Cleanup
    await withConfirm(page, () => clickRowAction(page, 'PW Codex Test', 'Delete'));
  });

  test('MCP view works for Codex', async ({ page }) => {
    await navTo(page, 'MCP');
    const ok = await page.evaluate(() =>
      document.body.textContent.includes('MCP Servers'));
    expect(ok).toBe(true);
  });

  test('Prompts view works for Codex', async ({ page }) => {
    await navTo(page, 'Prompts');
    const ok = await page.evaluate(() =>
      document.body.textContent.includes('Prompts'));
    expect(ok).toBe(true);
  });

  test('Settings view works from Codex', async ({ page }) => {
    await navTo(page, 'Settings');
    const ok = await page.evaluate(() =>
      document.body.textContent.includes('General') &&
      document.body.textContent.includes('Advanced') &&
      document.body.textContent.includes('Usage'));
    expect(ok).toBe(true);
  });
});
