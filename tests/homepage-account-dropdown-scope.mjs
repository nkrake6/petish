/** No network: fixed read-only fragment scope and bounded private observations. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import { allowedHomepageRequest, accountDropdownFragmentFacts, inspectAccountDropdownKeyboard } from '../tools/homepage/browser.mjs';

const origin = 'https://staging.petish.biz', endpoint = origin + '/wp-admin/admin-ajax.php';
const body = 'action=petish_account_dropdown';
let checks = 0;
const check = condition => { assert.equal(condition, true); checks++; };
for (const type of ['fetch', 'xhr']) check(allowedHomepageRequest(endpoint, 'POST', false, body, type));
for (const method of ['GET', 'HEAD', 'PUT', 'DELETE']) check(!allowedHomepageRequest(endpoint, method, false, body, 'fetch'));
for (const value of ['', 'action=other', body + '&user_id=1', body + '&nonce=private', body + '&' + body,
  'action=petish%5faccount_dropdown', 'action=petish_account_dropdown&', 'user_id=1&' + body]) {
  check(!allowedHomepageRequest(endpoint, 'POST', false, value, 'fetch'));
}
for (const url of [endpoint + '?action=petish_account_dropdown', endpoint + '?', endpoint + '#fragment',
  endpoint.replace('staging.petish.biz', 'petish.biz'), endpoint.replace('admin-ajax.php', 'options.php')]) {
  check(!allowedHomepageRequest(url, 'POST', false, body, 'fetch'));
}
for (const type of ['document', 'script', 'image', 'other', undefined]) check(!allowedHomepageRequest(endpoint, 'POST', false, body, type));
check(allowedHomepageRequest(endpoint + '?action=petish_for_your_pet', 'GET'));
check(!allowedHomepageRequest(endpoint + '?action=petish_for_your_pet&user_id=1', 'GET'));
check(allowedHomepageRequest(origin + '/cart/', 'GET', false, '', 'document'));
check(!allowedHomepageRequest(origin + '/cart/', 'GET', false, '', 'fetch'));
check(!allowedHomepageRequest(origin + '/cart/?remove_item=1', 'GET'));
check(!allowedHomepageRequest(origin + '/wp-json/wc/store/v1/cart', 'GET'));

const headers = { 'cache-control': 'private, no-store, max-age=0, must-revalidate', vary: 'Accept-Encoding, Cookie',
  'x-content-type-options': 'nosniff' };
const account = origin + '/my-account/';
const input = { login: 'synthetic-login-secret', password: 'synthetic-password-secret', customer_email: 'private@example.invalid' };
const payload = { success: true, data: { signed_in: true, avatar: '', initial: 'S', items: [
  { key: 'dashboard', label: 'Dashboard', url: account },
  { key: 'orders', label: 'Orders', url: account + 'orders/' },
  { key: 'my-reviews', label: 'My reviews', url: account + '?my-reviews=1' },
  { key: 'customer-logout', label: 'Log out', url: account + 'customer-logout/?_wpnonce=synthetic-only' },
] } };
const facts = (custom = payload, customHeaders = headers, url = account, ok = true) =>
  accountDropdownFragmentFacts(customHeaders, ok, custom, url, input);
check(Object.values(facts()).every(value => value === true));
for (const [mutate, key] of [
  [p => { p.private = input.password; }, 'exact_shape'],
  [p => { p.data.user_id = 42; }, 'exact_shape'],
  [p => { p.data.signed_in = false; }, 'signed_in'],
  [p => { p.data.avatar = 'https://foreign.invalid/photo.jpg'; }, 'identity_bounded'],
  [p => { p.data.initial = input.password; }, 'identity_bounded'],
  [p => { p.data.items[0].label = input.customer_email; }, 'identity_bounded'],
  [p => { p.data.items[1].url = origin + '/wp-admin/'; }, 'native_links'],
  [p => { p.data.items[1].url = 'https://foreign.invalid/orders/'; }, 'native_links'],
  [p => { p.data.items[1].url = origin + '/my-account/../../wp-admin/'; }, 'native_links'],
  [p => { p.data.items[1].url = account + 'orders/?private=%250A'; }, 'native_links'],
  [p => { p.data.items[1].extra = 'private'; }, 'native_links'],
  [p => { p.data.items[1].key = 'dashboard'; }, 'native_links'],
  [p => { p.data.items[1].label = '<b>Orders</b>'; }, 'native_links'],
  [p => { p.data.items[2].url = account + 'my-reviews/'; }, 'native_reviews_query'],
  [p => { p.data.items[2].url += '&user_id=1'; }, 'native_reviews_query'],
  [p => { p.data.items[3].url = account + 'customer-logout/'; }, 'native_links'],
]) {
  const value = structuredClone(payload); mutate(value);
  check(facts(value)[key] === false);
  check(Object.values(facts(value)).every(value => typeof value === 'boolean'));
  check(!JSON.stringify(facts(value)).includes(input.password));
}
for (const [name, value, key] of [['cache-control', 'private, max-age=0', 'no_store'], ['vary', 'Accept-Encoding', 'vary_cookie'],
  ['x-content-type-options', '', 'nosniff'], ['x-litespeed-cache', 'hit', 'no_litespeed_hit']]) {
  check(facts(payload, { ...headers, [name]: value })[key] === false);
}
check(facts(payload, headers, 'https://petish.biz/my-account/').account_url === false);
check(facts(payload, headers, account, false).status_ok === false);
check(Object.values(facts(null)).every(value => typeof value === 'boolean'));

if (process.argv.includes('--browser')) {
  const require = createRequire(new URL('../tools/staging-rehearsal/package.json', import.meta.url));
  const { chromium } = require('playwright');
  const theme = new URL('../wordpress/wp-content/themes/dt-the7-child/', import.meta.url);
  const script = fs.readFileSync(new URL('assets/js/account-dropdown.js', theme), 'utf8');
  const css = fs.readFileSync(new URL('assets/css/account-dropdown.css', theme), 'utf8');
  const browser = await chromium.launch({ headless: true, executablePath: '/usr/bin/chromium', args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    let privateReads = 0;
    await page.route('**/*', route => {
      const request = route.request();
      if (request.url() === endpoint) {
        check(request.method() === 'POST' && request.postData() === body); privateReads++;
        return route.fulfill({ status: 200, contentType: 'application/json', headers, body: JSON.stringify(payload) });
      }
      if (request.url() !== origin + '/') return route.abort('blockedbyclient');
      return route.fulfill({ status: 200, contentType: 'text/html', body: `<!doctype html><html><head><style>
        html{font-size:16px}body{margin:0}.main-nav{display:flex;list-style:none;padding:20px}.main-nav>li{display:flex}
        .main-nav>li>a{padding:0 15px;color:#14213d;text-decoration:none}${css}</style></head>
        <body id="the7-body" class="petish-site"><ul class="main-nav"><li class="petish-account-menu"><a href="${account}"><span class="menu-text">My account</span></a></li>
        <li><a href="${origin}/cart/"><span class="menu-text">Cart</span><span class="existing-cart-count">2</span></a></li></ul>
        <script>window.petishAccountDropdown=${JSON.stringify({ endpoint, action: 'petish_account_dropdown', accountUrl: account, openLabel: 'Open account navigation', closeLabel: 'Close account navigation' })};</script><script>${script}</script></body></html>` });
    });
    await page.goto(origin + '/');
    check(await inspectAccountDropdownKeyboard(page, payload.data.items));
    check(privateReads >= 1);
    check(await page.locator('.existing-cart-count').textContent() === '2');
  } finally { await browser.close(); }
}
process.stdout.write(`Account dropdown request scope and fragment checks passed (${checks}).\n`);
