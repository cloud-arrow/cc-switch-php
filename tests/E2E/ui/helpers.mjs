/**
 * Shared helpers for UI E2E tests (Tailwind UI redesign).
 *
 * The new UI uses top navbar navigation, card-based layouts, and full-screen panels
 * instead of sidebar + dialog + table.
 */

/** Navigate to a view by clicking the appropriate nav/tool button */
export async function navTo(page, viewName) {
  await page.evaluate((name) => {
    // Map old tab names to new view triggers
    const navMap = {
      'Providers': () => {
        // Click CC Switch title area or just set view
        const el = document.querySelector('[x-data]');
        if (el && el.__x) el.__x.$data.currentView = 'providers';
        else Alpine.evaluate(document.querySelector('[x-data]'), 'currentView = "providers"');
      },
      'MCP': () => {
        const btns = document.querySelectorAll('nav button[title="MCP Servers"]');
        if (btns.length) btns[0].click();
        else { const el = document.querySelector('[x-data]'); if (el?.__x) el.__x.$data.currentView = 'mcp'; }
      },
      'Proxy': () => {
        // Proxy is now in Settings > Advanced
        const el = document.querySelector('[x-data]');
        if (el?.__x) { el.__x.$data.currentView = 'settings'; el.__x.$data.settingsTab = 'advanced'; }
      },
      'Skills': () => {
        const btns = document.querySelectorAll('nav button[title="Skills"]');
        if (btns.length) btns[0].click();
        else { const el = document.querySelector('[x-data]'); if (el?.__x) el.__x.$data.currentView = 'skills'; }
      },
      'Prompts': () => {
        const btns = document.querySelectorAll('nav button[title="Prompts"]');
        if (btns.length) btns[0].click();
        else { const el = document.querySelector('[x-data]'); if (el?.__x) el.__x.$data.currentView = 'prompts'; }
      },
      'Settings': () => {
        const btn = document.querySelector('nav button[title="Settings"]');
        if (btn) btn.click();
        else { const el = document.querySelector('[x-data]'); if (el?.__x) el.__x.$data.currentView = 'settings'; }
      },
      'Usage': () => {
        // Usage is now in Settings > Usage tab
        const el = document.querySelector('[x-data]');
        if (el?.__x) { el.__x.$data.currentView = 'settings'; el.__x.$data.settingsTab = 'usage'; }
      },
    };
    if (navMap[name]) navMap[name]();
  }, viewName);
  await page.waitForTimeout(800);
}

/** Click a button with matching text in the visible content area */
export async function clickInSection(page, text) {
  return page.evaluate((txt) => {
    const main = document.querySelector('main');
    if (!main) return false;
    // Find visible divs (views)
    for (const div of main.querySelectorAll(':scope > div')) {
      if (div.offsetParent === null || div.style.display === 'none') continue;
      const btn = Array.from(div.querySelectorAll('button'))
        .find(b => b.textContent.includes(txt));
      if (btn) { btn.click(); return true; }
    }
    return false;
  }, text);
}

/** Fill an input/textarea in the current view (not in a dialog anymore - in panels) */
export async function dialogFill(page, selector, value) {
  await page.evaluate(({ sel, val }) => {
    // Try in current view panels first, then modals
    const el = document.querySelector(sel) ||
               document.querySelector(`main ${sel}`);
    if (!el) throw new Error(`Element not found: ${sel}`);
    el.value = val;
    el.dispatchEvent(new Event('input', { bubbles: true }));
  }, { sel: selector, val: value });
}

/** Click a button matching selector in current view */
export async function dialogClick(page, selector) {
  await page.evaluate((sel) => {
    // For the new UI, buttons are directly in the view panels
    const el = document.querySelector(sel) ||
               document.querySelector(`main ${sel}`);
    if (!el) throw new Error(`Button not found: ${sel}`);
    el.click();
  }, selector);
}

/** Close any open overlays/panels */
export async function closeDialogs(page) {
  await page.evaluate(() => {
    // Close any fixed overlays
    const overlays = document.querySelectorAll('.fixed.inset-0');
    overlays.forEach(o => o.style.display = 'none');
    // Reset view to providers
    const el = document.querySelector('[x-data]');
    if (el?.__x) el.__x.$data.currentView = 'providers';
  }).catch(() => {});
}

/** Check if a provider name exists in the card list */
export async function tableHasRow(page, text) {
  return page.evaluate((t) => {
    // Check in provider cards or any card list
    const cards = document.querySelectorAll('main .bg-gray-900');
    for (const card of cards) {
      if (card.textContent.includes(t)) return true;
    }
    return false;
  }, text);
}

/** Click an action button for a given card/row name */
export async function clickRowAction(page, rowName, actionText) {
  return page.evaluate(({ name, action }) => {
    // Find card containing the name, then find action button
    const cards = document.querySelectorAll('main .bg-gray-900');
    for (const card of cards) {
      if (!card.textContent.includes(name)) continue;
      // For "Switch", "Edit", "Del"/"Delete" buttons
      const btn = Array.from(card.querySelectorAll('button'))
        .find(b => b.textContent.includes(action) || b.title?.includes(action));
      if (btn) { btn.click(); return true; }
    }
    return false;
  }, { name: rowName, action: actionText });
}

/** Auto-accept browser confirm() dialogs during a callback */
export async function withConfirm(page, fn) {
  const handler = d => d.accept();
  page.on('dialog', handler);
  await fn();
  await page.waitForTimeout(1000);
  page.removeListener('dialog', handler);
}
