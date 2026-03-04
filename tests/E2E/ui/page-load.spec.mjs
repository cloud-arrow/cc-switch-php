import { test, expect } from '@playwright/test';

test.describe('Page Load', () => {
  test('index page returns 200', async ({ page }) => {
    const resp = await page.goto('/', { waitUntil: 'networkidle' });
    expect(resp.status()).toBe(200);
  });

  test('title is CC Switch', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    await expect(page).toHaveTitle('CC Switch');
  });

  test('Alpine.js initializes and renders data', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    await page.waitForFunction(() =>
      document.querySelector('table tbody tr') !== null ||
      document.body.textContent.includes('No providers'),
    { timeout: 10000 });
  });

  test('sidebar has 7 navigation tabs', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    const tabs = await page.$$eval('nav.sidebar li a', els => els.map(e => e.textContent.trim()));
    for (const t of ['Providers', 'MCP', 'Proxy', 'Skills', 'Prompts', 'Settings', 'Usage']) {
      expect(tabs).toContain(t);
    }
  });

  test('app selector shows 5 app buttons', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    const count = await page.$$eval('.app-selector button', els => els.length);
    expect(count).toBe(5);
  });

  test('CSS and JS assets load', async ({ page }) => {
    const failed = [];
    page.on('requestfailed', req => failed.push(req.url()));
    await page.goto('/', { waitUntil: 'networkidle' });
    const assetFails = failed.filter(u => u.includes('/assets/'));
    expect(assetFails).toHaveLength(0);
  });
});
