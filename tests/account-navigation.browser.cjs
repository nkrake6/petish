/* Native account-link disclosure against Chromium, without hosted account data. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('../tools/staging-rehearsal/node_modules/playwright');

const root = path.resolve(__dirname, '..');
const css = fs.readFileSync(path.join(root, 'wordpress/wp-content/plugins/petish-passport/assets/passport.css'), 'utf8');
const script = fs.readFileSync(path.join(root, 'wordpress/wp-content/plugins/petish-passport/assets/account-navigation.js'), 'utf8');
const entries = [
  ['dashboard', 'Dashboard'], ['orders', 'Orders'], ['downloads', 'Downloads'],
  ['edit-address', 'Addresses'], ['payment-methods', 'Payment methods'],
  ['edit-account', 'Account details'], ['my-pets', 'My Pets'],
  ['for-my-pet', 'For my pet'], ['customer-logout', 'Log out'],
];
function fixture(dark = false) {
  const items = entries.map(([key, label]) => `<li class="woocommerce-MyAccount-navigation-link${key === 'my-pets' ? ' is-active' : ''}"><a href="https://petish.invalid/my-account/${key}/?${key === 'customer-logout' ? '_wpnonce=SYNTHETIC-LOGOUT-NONCE' : 'fixture=1'}"${key === 'my-pets' ? ' aria-current="page"' : ''}>${label}</a></li>`).join('');
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>
    *{box-sizing:border-box}body{margin:16px;font-family:Arial,sans-serif;--color-surface:${dark ? '#172234' : '#fff'};--color-text:${dark ? '#edf2fa' : '#14213d'};--color-text-muted:${dark ? '#b9c5d8' : '#526077'};--color-border:${dark ? '#526077' : '#e3e7ef'};--color-primary:#6532a1;background:${dark ? '#0b1426' : '#f8f9fc'}}
    ${css}</style></head><body class="woocommerce-account"><main class="woocommerce"><nav class="woocommerce-MyAccount-navigation" aria-label="Account pages"><ul>${items}</ul></nav><section class="woocommerce-MyAccount-content"><h1>Little pets. Big personalities.</h1><button id="outside">Add a pet</button></section></main></body></html>`;
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
  const checks = [];
  async function setup(width, options = {}) {
    const context = await browser.newContext({ viewport: { width, height: 844 }, javaScriptEnabled: options.javascript !== false, colorScheme: options.dark ? 'dark' : 'light' });
    const page = await context.newPage();
    await page.setContent(fixture(options.dark));
    if (options.javascript !== false) {
      await page.evaluate(() => { window.petishAccountNavigation = { menuLabel: 'Account menu', currentSection: 'Current section: %s' }; });
      await page.addScriptTag({ content: script });
    }
    return { context, page };
  }
  try {
    for (const width of [320, 390]) {
      const { context, page } = await setup(width);
      const toggle = page.getByRole('button', { name: 'Account menu. Current section: My Pets' });
      const list = page.locator('.woocommerce-MyAccount-navigation > ul');
      assert(await toggle.isVisible());
      assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
      assert(!(await list.isVisible()));
      const box = await toggle.boundingBox();
      assert(box.height >= 44 && box.width <= width);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
      const before = await page.locator('.woocommerce-MyAccount-navigation a').evaluateAll(links => links.map(link => [link.textContent, link.href]));
      await toggle.focus();
      await page.keyboard.press('Enter');
      assert(await list.isVisible());
      assert.equal(await toggle.getAttribute('aria-expanded'), 'true');
      await page.keyboard.press('Tab');
      assert.equal(await page.evaluate(() => document.activeElement.textContent), 'Dashboard');
      await page.keyboard.press('Escape');
      assert(!(await list.isVisible()));
      assert(await toggle.evaluate(button => document.activeElement === button));
      await page.keyboard.press('Space');
      assert(await list.isVisible());
      await page.mouse.click(width - 8, 820);
      assert(!(await list.isVisible()));
      await toggle.click();
      await page.evaluate(() => {
        document.addEventListener('click', event => {
          const link = event.target.closest('.woocommerce-MyAccount-navigation a');
          if (!link) return;
          window.linkProof = { href: link.href, defaultPrevented: event.defaultPrevented };
          event.preventDefault(); // Fixture only: do not leave the offline page.
        });
      });
      await page.getByRole('link', { name: 'Log out', exact: true }).click();
      const proof = await page.evaluate(() => window.linkProof);
      assert.equal(proof.defaultPrevented, false);
      assert(proof.href.endsWith('customer-logout/?_wpnonce=SYNTHETIC-LOGOUT-NONCE'));
      assert(!(await list.isVisible()));
      const after = await page.locator('.woocommerce-MyAccount-navigation a').evaluateAll(links => links.map(link => [link.textContent, link.href]));
      assert.deepEqual(after, before);
      await page.addScriptTag({ content: script });
      assert.equal(await page.locator('.pp-account-navigation-toggle').count(), 1);
      checks.push(`${width}px: collapsed/current label/44px/overflow/Enter/Space/Tab/Escape/outside/native logout/links/idempotence`);
      await context.close();
    }
    {
      const { context, page } = await setup(1200);
      const toggle = page.locator('.pp-account-navigation-toggle');
      const list = page.locator('.woocommerce-MyAccount-navigation > ul');
      assert(!(await toggle.isVisible()));
      assert(await list.isVisible());
      assert.equal(await page.locator('.woocommerce-MyAccount-navigation').evaluate(element => getComputedStyle(element).float), 'left');
      await page.getByRole('link', { name: 'Orders', exact: true }).focus();
      await page.setViewportSize({ width: 390, height: 844 });
      await page.waitForFunction(() => document.activeElement.classList.contains('pp-account-navigation-toggle'));
      assert(await toggle.isVisible());
      assert(!(await list.isVisible()));
      assert(await toggle.evaluate(button => document.activeElement === button));
      await page.setViewportSize({ width: 1200, height: 844 });
      await page.waitForFunction(() => document.activeElement.textContent === 'My Pets');
      assert(!(await toggle.isVisible()));
      assert(await list.isVisible());
      assert.equal(await page.evaluate(() => document.activeElement.textContent), 'My Pets');
      checks.push('desktop sidebar preserved; both resize directions keep focus visible');
      await context.close();
    }
    {
      const { context, page } = await setup(390, { dark: true });
      const toggle = page.locator('.pp-account-navigation-toggle');
      assert.equal(await toggle.evaluate(button => getComputedStyle(button).color), 'rgb(237, 242, 250)');
      assert.equal(await toggle.evaluate(button => getComputedStyle(button).backgroundColor), 'rgb(23, 34, 52)');
      await toggle.click();
      assert(await page.getByRole('link', { name: 'My Pets', exact: true }).isVisible());
      checks.push('dark theme tokens and expanded links');
      await context.close();
    }
    {
      const { context, page } = await setup(320, { javascript: false });
      assert.equal(await page.locator('.pp-account-navigation-toggle').count(), 0);
      for (const [, label] of entries) assert(await page.getByRole('link', { name: label, exact: true }).isVisible());
      checks.push('JavaScript disabled: all nine native links remain visible');
      await context.close();
    }
    console.log(JSON.stringify({ status: 'success', checks }, null, 2));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
