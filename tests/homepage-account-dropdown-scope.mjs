/** No network: fixed read-only fragment scope and bounded private observations. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import { allowedHomepageRequest, accountDropdownFragmentFacts, inspectAccountDropdownKeyboard,
  accountDropdownTransportFacts, probeAccountDropdown, ACCOUNT_TRANSPORT_FACT_KEYS,
  publicReport, DIAGNOSTIC_KEYS, CHECK_KEYS } from '../tools/homepage/browser.mjs';

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
const paymentProduct = origin + '/product/rainbow-habitat-hideout/';
check(allowedHomepageRequest(paymentProduct,'GET',false,'','document'));
for (const value of [paymentProduct+'?attribute_color=Blue',paymentProduct+'?add-to-cart=1',origin+'/product/other-product/'])
  check(!allowedHomepageRequest(value,'GET',false,'','document'));
check(!allowedHomepageRequest(paymentProduct,'POST',false,'','document'));
check(!allowedHomepageRequest(paymentProduct,'GET',false,'','fetch'));

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
const fragmentFacts = ['no_store', 'no_litespeed_hit', 'vary_cookie', 'nosniff', 'status_ok', 'exact_shape',
  'signed_in', 'account_url', 'identity_bounded', 'native_links', 'native_reviews_query'];
check(Object.keys(facts()).sort().join(',') === [...fragmentFacts].sort().join(','));
check(fragmentFacts.every(key => DIAGNOSTIC_KEYS.includes('account_dropdown_' + key)));
const privateChecks = Object.fromEntries(CHECK_KEYS.map(key => [key, true]));
privateChecks.account_dropdown_private_fragment_verified = false;
const diagnosticReport = publicReport({ status: 'failure', category: 'account_dropdown', checks: privateChecks,
  diagnostics: { ...Object.fromEntries(Object.entries(facts()).map(([key, value]) => ['account_dropdown_' + key, value])),
    private_payload: payload, private_email: input.customer_email, private_password: input.password } });
check(diagnosticReport.status === 'failure' && diagnosticReport.category === 'account_dropdown');
check(fragmentFacts.every(key => diagnosticReport.diagnostics['account_dropdown_' + key] === true));
check(Object.values(diagnosticReport.diagnostics).every(value => typeof value === 'boolean'));
check(!JSON.stringify(diagnosticReport).includes('synthetic-only') && !JSON.stringify(diagnosticReport).includes(input.customer_email)
  && !JSON.stringify(diagnosticReport).includes(input.password));

const transportKeys = ['http_2xx', 'http_3xx', 'http_4xx', 'http_5xx', 'http_400', 'http_401', 'http_403', 'http_404',
  'http_429', 'http_500', 'content_type_json', 'content_type_html', 'redirected', 'final_same_origin',
  'final_admin_ajax_path', 'final_login_path', 'body_bounded', 'body_empty', 'body_login_marker',
  'body_php_error_marker', 'body_waf_marker', 'body_wp_zero', 'body_wp_minus_one', 'response_finished_error',
  'body_read_failed', 'request_aborted'];
check([...ACCOUNT_TRANSPORT_FACT_KEYS].sort().join(',') === [...transportKeys].sort().join(','));
check(transportKeys.every(key => DIAGNOSTIC_KEYS.includes('account_dropdown_' + key)));
const transport = (status = 200, url = endpoint, value = JSON.stringify(payload), type = 'application/json; charset=UTF-8', redirected = false) =>
  accountDropdownTransportFacts({ ...headers, 'content-type': type }, status, url, redirected, value);
check(Object.values(transport()).every(value => typeof value === 'boolean'));
check(transport().http_2xx && transport().content_type_json && transport().final_same_origin && transport().final_admin_ajax_path && transport().body_bounded);
for (const status of [400, 401, 403, 404, 429, 500]) check(transport(status)['http_' + status]);
check(transport(302).http_3xx && transport(302).redirected);
check(transport(403).http_4xx && transport(503).http_5xx);
check(transport(200, endpoint, 'valid', 'application/problem+json').content_type_json);
check(transport(200, origin + '/wp-login.php', '<form id="loginform"><input name="log"></form>', 'text/html', true).body_login_marker);
check(transport(200, origin + '/wp-login.php').final_login_path);
check(transport(403, endpoint, 'Request blocked: Access denied (ModSecurity).', 'text/html').body_waf_marker);
check(transport(500, endpoint, 'Fatal error: private-server-path', 'text/html').body_php_error_marker);
check(transport(200, endpoint, ' \n0\n').body_wp_zero && transport(403, endpoint, '-1').body_wp_minus_one);
check(transport(200, endpoint, ' \n').body_empty);
check(!transport(200, endpoint, 'x'.repeat(131073)).body_bounded);
check(!transport(200, endpoint, '😺'.repeat(32769)).body_bounded);
check(!transport(200, endpoint, null).body_bounded);
check(!transport(200, 'https://foreign.invalid/wp-admin/admin-ajax.php').final_same_origin);
check(!JSON.stringify(transport(500, endpoint, 'Fatal error: private-server-path ' + input.password)).includes(input.password));

async function mockedProbe({ value = JSON.stringify(payload), status = 200, readFails = false, fetchFails = false } = {}) {
  const previousFetch = globalThis.fetch;
  const responseHeaders = new Headers({ ...headers, 'content-type': status === 200 ? 'application/json' : 'text/html',
    'x-private-value': input.password });
  try {
    globalThis.fetch = async (url, options) => {
      check(url === endpoint && options.method === 'POST' && options.body === body);
      check(options.credentials === 'same-origin' && options.cache === 'no-store');
      check(options.headers['Content-Type'] === 'application/x-www-form-urlencoded;charset=UTF-8');
      check(options.signal instanceof AbortSignal);
      if (fetchFails) throw new TypeError('Fetch unavailable');
      return { headers: responseHeaders, ok: status >= 200 && status < 300, status, url: endpoint, redirected: false,
        text: async () => { if (readFails) throw new Error('private-server-path'); return value; } };
    };
    return await probeAccountDropdown({ evaluate: async (callback, args) => {
      check(JSON.stringify(args) === JSON.stringify({ endpoint, body }));
      return callback(args);
    } }, 'https://foreign.invalid/', 'action=other&user_id=1');
  } finally { globalThis.fetch = previousFetch; }
}
const probe = await mockedProbe();
check(probe.ok && probe.json_parsed && probe.body_bounded && !probe.body_read_failed && probe.payload.success === true);
check(Object.keys(probe.headers).sort().join(',') === ['cache-control', 'content-type', 'vary', 'x-content-type-options', 'x-litespeed-cache'].sort().join(','));
check(!('body' in probe) && !('text' in probe) && !JSON.stringify(probe.headers).includes(input.password));
check(Object.values(accountDropdownFragmentFacts(probe.headers, probe.ok, probe.payload, account, input)).every(value => value === true));
const deniedProbe = await mockedProbe({ status: 403, value: '<html>Access denied by security check</html>' });
check(!deniedProbe.ok && deniedProbe.body_waf_marker && !deniedProbe.json_parsed && deniedProbe.payload === null);
const largeProbe = await mockedProbe({ value: '😺'.repeat(32769) });
check(!largeProbe.body_bounded && !largeProbe.json_parsed && largeProbe.payload === null);
const unreadableProbe = await mockedProbe({ readFails: true });
check(unreadableProbe.body_read_failed && !unreadableProbe.json_parsed && !unreadableProbe.body_bounded);
let fetchRejected = false;
try { await mockedProbe({ fetchFails: true }); } catch (error) { fetchRejected = error instanceof TypeError; }
check(fetchRejected);


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
        html{font-size:16px}body{margin:0}.mini-widgets{display:flex;align-items:center;gap:8px}.main-nav{display:flex;list-style:none;padding:20px}.main-nav>li{display:flex}
        .main-nav>li>a{padding:0 15px;color:#14213d;text-decoration:none}${css}</style></head>
        <body id="the7-body" class="petish-site"><ul class="main-nav"><li><a href="${origin}/cart/"><span class="menu-text">Cart</span><span class="existing-cart-count">2</span></a></li></ul>
        <div class="mini-widgets"><span class="mini-search">Search</span><span data-petish-theme-switch>Moon</span><span data-petish-market>AUD</span>
        <div class="petish-account-menu petish-account-widget" data-petish-account><a href="${account}"><span class="menu-text">My account</span></a></div></div>
        <script>window.petishAccountDropdown=${JSON.stringify({ endpoint, action: 'petish_account_dropdown', accountUrl: account, openLabel: 'Open account navigation', closeLabel: 'Close account navigation' })};</script><script>${script}</script></body></html>` });
    });
    await page.goto(origin + '/');
    const browserProbe = await probeAccountDropdown(page);
    check(browserProbe.ok && browserProbe.json_parsed && browserProbe.body_bounded && !browserProbe.body_read_failed);
    check(Object.values(accountDropdownFragmentFacts(browserProbe.headers, browserProbe.ok, browserProbe.payload, account, input)).every(value => value === true));
    check(await inspectAccountDropdownKeyboard(page, browserProbe.payload.data.items));
    check(privateReads >= 2);
    check(await page.locator('.existing-cart-count').textContent() === '2');
  } finally { await browser.close(); }
}
process.stdout.write(`Account dropdown request scope and fragment checks passed (${checks}).\n`);
