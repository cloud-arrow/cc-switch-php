/**
 * Shared helpers for UI E2E tests.
 *
 * All UI interaction uses page.evaluate() to bypass Alpine.js / Pico CSS
 * dialog visibility quirks with Playwright's built-in locators.
 */

/** Navigate to a sidebar tab by name */
export async function navTo(page, tabName) {
  await page.evaluate((name) => {
    for (const a of document.querySelectorAll('nav.sidebar a')) {
      if (a.textContent.trim() === name) { a.click(); return; }
    }
  }, tabName);
  await page.waitForTimeout(800);
}

/** Click a button inside the currently visible section */
export async function clickInSection(page, text) {
  return page.evaluate((txt) => {
    for (const sec of document.querySelectorAll('main.content > section')) {
      if (sec.offsetParent === null || sec.style.display === 'none') continue;
      const btn = Array.from(sec.querySelectorAll('button'))
        .find(b => b.textContent.includes(txt));
      if (btn) { btn.click(); return true; }
    }
    return false;
  }, text);
}

/** Fill an input/textarea inside the currently open dialog (JS-based, Alpine-compatible) */
export async function dialogFill(page, selector, value) {
  await page.evaluate(({ sel, val }) => {
    const el = document.querySelector(`dialog[open] ${sel}`);
    if (!el) throw new Error(`Element not found: dialog[open] ${sel}`);
    el.value = val;
    el.dispatchEvent(new Event('input', { bubbles: true }));
  }, { sel: selector, val: value });
}

/** Click a button inside the currently open dialog */
export async function dialogClick(page, selector) {
  await page.evaluate((sel) => {
    const el = document.querySelector(`dialog[open] ${sel}`);
    if (!el) throw new Error(`Button not found: dialog[open] ${sel}`);
    el.click();
  }, selector);
}

/** Close any leftover open dialogs (error recovery) */
export async function closeDialogs(page) {
  await page.evaluate(() => {
    document.querySelectorAll('dialog[open]').forEach(d => d.removeAttribute('open'));
  }).catch(() => {});
}

/** Check if a text value exists in any first-column table cell */
export async function tableHasRow(page, text) {
  return page.evaluate((t) =>
    Array.from(document.querySelectorAll('table tbody td:first-child'))
      .some(c => c.textContent === t), text);
}

/** Click a row action button (Edit / Del / Switch) for a given row name */
export async function clickRowAction(page, rowName, actionText) {
  return page.evaluate(({ name, action }) => {
    for (const row of document.querySelectorAll('table tbody tr')) {
      if (row.querySelector('td')?.textContent === name) {
        const btn = Array.from(row.querySelectorAll('button'))
          .find(b => b.textContent.includes(action));
        if (btn) { btn.click(); return true; }
      }
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
