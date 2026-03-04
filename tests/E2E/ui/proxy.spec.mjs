import { test, expect } from '@playwright/test';
import { navTo, clickInSection } from './helpers.mjs';

test.describe('Proxy Tab', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    await navTo(page, 'Proxy');
  });

  test('status panel renders', async ({ page }) => {
    const panel = await page.$('.proxy-status-panel');
    expect(panel).not.toBeNull();
    const text = await panel.textContent();
    expect(text).toContain('Status');
  });

  test('config checkboxes render', async ({ page }) => {
    const checks = await page.$$('input[type="checkbox"][role="switch"]');
    expect(checks.length).toBeGreaterThanOrEqual(2);
  });

  test('config number inputs render', async ({ page }) => {
    const inputs = await page.$$('input[type="number"]');
    expect(inputs.length).toBeGreaterThanOrEqual(3);
  });

  test('save proxy config', async ({ page }) => {
    await clickInSection(page, 'Save');
    await page.waitForTimeout(500);
    // No crash = pass
  });
});
