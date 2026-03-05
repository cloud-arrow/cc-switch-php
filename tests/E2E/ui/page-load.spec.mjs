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
      document.querySelector('main') !== null &&
      (document.body.textContent.includes('Providers') ||
       document.body.textContent.includes('No providers')),
    { timeout: 10000 });
  });

  test('top navbar has navigation elements', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    // Check for CC Switch title
    const title = await page.textContent('nav h1');
    expect(title).toContain('CC Switch');
    // Check for tool buttons
    const toolBtns = await page.$$eval('nav button[title]', els => els.map(e => e.title));
    expect(toolBtns).toContain('Settings');
  });

  test('app switcher shows 5 app buttons', async ({ page }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    // App switcher pills in navbar
    const count = await page.$$eval('nav .rounded-xl button', els => els.length);
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
