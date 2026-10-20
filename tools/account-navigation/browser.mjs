/** Read-only, synthetic-customer account-navigation checks on fixed staging. */
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const ORIGIN = 'https://staging.petish.biz';
const NAV = '.woocommerce-MyAccount-navigation';
const TOGGLE = '.pp-account-navigation-toggle';
const CURRENT = '.pp-account-navigation-current';
const ENDPOINTS = ['my-pets', 'orders', 'edit-account'];
const SAFETY = {
  environment_staging: true, woocommerce_test_mode: true, wcpay_dev_mode: true,
  account_is_live: false, guard_runtime_verified: true, suppliers_blocked: true,
  mail_capture_verified: true, webhooks_blocked: true,
};
export const CHECK_KEYS = Object.freeze([
  'browser_started', 'customer_login_verified', 'native_navigation_links_present',
  'native_hrefs_unchanged', 'my_pets_390_verified', 'my_pets_320_verified',
  'orders_390_verified', 'orders_320_verified', 'edit_account_390_verified',
  'edit_account_320_verified', 'current_label_verified', 'mobile_compact_closed',
  'toggle_opens', 'escape_closes_focus_returns', 'outside_closes', 'focusout_closes',
  'desktop_visible', 'resize_visible', 'no_js_fallback_visible', 'dark_mode_readable',
  'unsafe_requests_blocked',
]);
export const DIAGNOSTIC_KEYS = Object.freeze(['login_page_http_ok', 'login_form_present',
  'login_submit_clicked', 'login_post_permitted', 'login_post_blocked', 'login_cookie_present',
  'login_redirect_account', 'account_email_field_present', 'qa_email_matches',
  'endpoint_page_http_ok', 'native_navigation_present', 'script_tag_present',
  'script_origin_staging', 'script_request_seen', 'script_request_blocked', 'script_http_ok',
  'expected_script_hash_available', 'script_body_matches_expected_hash', 'script_pageerror_seen',
  'toggle_present', 'enhanced_class_present', 'actual_native_hrefs_match',
  'script_type_executable', 'script_deferred', 'script_delay_attribute_present']);
const FAILURE_STAGES = new Set(['input', 'browser', 'login_page', 'login_form', 'login_submit',
  'login_cookie', 'qa_identity', 'no_js', 'navigation', 'nav_load', 'nav_visible',
  'toggle_wait', 'links', 'mobile', 'desktop', 'dark_mode', 'complete']);
const CATEGORIES = new Set(['complete', 'input', 'browser', 'login', 'navigation',
  'links', 'mobile', 'keyboard', 'desktop', 'resize', 'no_js', 'dark_mode', 'network']);

export function validateInput(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)
      || value.url !== ORIGIN || !Number.isSafeInteger(value.customer_id) || value.customer_id < 1
      || typeof value.login !== 'string' || !value.login || value.login.length > 200
      || typeof value.password !== 'string' || !value.password || value.password.length > 2048
      || typeof value.customer_email !== 'string' || value.customer_email.length > 254
      || !/^[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9.-]+\.invalid$/.test(value.customer_email)
      || !value.safety || typeof value.safety !== 'object'
      || Object.entries(SAFETY).some(([name, expected]) => value.safety[name] !== expected)) {
    throw new Error('input');
  }
  return value;
}

/** Never permits a state-changing GET or a POST other than the one login form. */
export function allowedRequest(raw, method = 'GET', loginAllowed = false, body = '', resourceType = 'document') {
  try {
    const url = new URL(raw);
    if (url.origin !== ORIGIN || url.protocol !== 'https:' || url.username || url.password
        || (url.port && url.port !== '443')) return false;
    if (/\/(?:checkout|cart|customer-logout|logout|wc-api)(?:\/|$)/i.test(url.pathname)
        || /\/wp-admin(?:\/|$)/i.test(url.pathname)
        || /\/wp-json\/(?:wc|wc-admin|wc-analytics)(?:\/|$)/i.test(url.pathname)
        || [...url.searchParams.keys()].some(key => /^(?:add-to-cart|remove_item|undo_item|wc-ajax|wc-api|_method|delete|logout)$/i.test(key))
        || /^(?:logout|delete|remove|save|update|add_to_cart)/i.test(url.searchParams.get('action') ?? '')) return false;
    if (method === 'GET' || method === 'HEAD') return true;
    if (method !== 'POST' || !loginAllowed || resourceType !== 'document'
        || !['/my-account/', '/my-account', '/wp-login.php'].includes(url.pathname)
        || url.search) return false;
    const fields = new URLSearchParams(body);
    const nativeLogin = fields.has('username') && fields.has('password') && fields.has('login');
    const wpLogin = fields.has('log') && fields.has('pwd');
    if (fields.has('redirect_to') && ![ORIGIN + '/my-account/', '/my-account/'].includes(fields.get('redirect_to'))) return false;
    return (nativeLogin || wpLogin)
      && !['register', 'save_account_details', 'save_address', 'add-to-cart', 'place_order', 'action']
        .some(name => fields.has(name));
  } catch { return false; }
}

export function publicReport(value) {
  const checks = Object.fromEntries(CHECK_KEYS.map(key => [key, value?.checks?.[key] === true]));
  const successful = value?.status === 'success' && value?.category === 'complete'
    && Object.values(checks).every(Boolean);
  return { status: successful ? 'success' : 'failure',
    category: successful ? 'complete' : (CATEGORIES.has(value?.category) && value.category !== 'complete' ? value.category : 'input'),
    failure_stage: successful ? 'complete' : (FAILURE_STAGES.has(value?.failure_stage) ? value.failure_stage : 'input'),
    diagnostics: Object.fromEntries(DIAGNOSTIC_KEYS.map(key => [key, value?.diagnostics?.[key] === true])),
    checks };
}

function readPrivateInput(filename) {
  const resolved = path.resolve(filename), stat = fs.lstatSync(resolved);
  if (!stat.isFile() || stat.isSymbolicLink() || stat.nlink !== 1 || stat.size < 1 || stat.size > 32768
      || (stat.mode & 0o7777) !== 0o600 || fs.realpathSync(resolved) !== resolved) throw new Error('input');
  return validateInput(JSON.parse(fs.readFileSync(resolved, 'utf8')));
}

function writeReport(filename, result) {
  const resolved = path.resolve(filename);
  if (fs.realpathSync(path.dirname(resolved)) !== path.dirname(resolved)) throw new Error('input');
  const fd = fs.openSync(resolved, fs.constants.O_WRONLY | fs.constants.O_CREAT
    | fs.constants.O_EXCL | fs.constants.O_NOFOLLOW, 0o600);
  try { fs.writeFileSync(fd, JSON.stringify(publicReport(result), null, 2) + '\n'); }
  finally { fs.closeSync(fd); }
}

function requireCheck(condition, category) { if (!condition) throw new Error(category); }

function isNavigationScript(raw) {
  try { return /\/petish-passport\/assets\/account-navigation\.js$/.test(new URL(raw).pathname); }
  catch { return false; }
}

async function collectPageDiagnostics(page, diagnostics) {
  if (!page || page.isClosed()) return;
  const current = await page.evaluate(({ navigation, toggle, origin }) => {
    const nav = document.querySelector(navigation);
    const scripts = [...document.querySelectorAll('script')].filter(script =>
      script.id === 'petish-account-navigation-js' || /account-navigation\.js(?:[?#]|$)/.test(script.src));
    return { native_navigation_present: !!nav, toggle_present: !!nav?.querySelector(toggle),
      enhanced_class_present: nav?.classList.contains('pp-account-navigation') ?? false,
      script_tag_present: scripts.length > 0, script_origin_staging: scripts.length > 0
        && scripts.every(script => { try { return new URL(script.src || script.getAttribute('data-src')
          || script.getAttribute('data-rocket-src'), location.href).origin === origin; } catch { return false; } }),
      script_type_executable: scripts.length > 0 && scripts.every(script =>
        ['', 'module', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript'].includes(script.type.toLowerCase())),
      script_deferred: scripts.some(script => script.defer),
      script_delay_attribute_present: scripts.some(script => script.hasAttribute('data-src')
        || script.hasAttribute('data-rocket-src') || /(?:javascript-delayed|rocketlazyloadscript|text\/(?:plain|delay))/.test(script.type.toLowerCase())) };
  }, { navigation: NAV, toggle: TOGGLE, origin: ORIGIN }).catch(() => null);
  if (current) Object.assign(diagnostics, current);
}

async function installReadOnlyGuard(context, loginState, diagnostics = null) {
  await context.route('**/*', async route => {
    const request = route.request();
    const allowed = allowedRequest(request.url(), request.method(), loginState.allowed && !loginState.used,
      request.postData() ?? '', request.resourceType());
    if (!allowed) {
      if (diagnostics && isNavigationScript(request.url())) diagnostics.script_request_blocked = true;
      if (request.method() === 'POST' && ['/wp-login.php', '/my-account/', '/my-account'].includes(new URL(request.url()).pathname)) loginState.postBlocked = true;
      await route.abort('blockedbyclient'); return;
    }
    if (request.method() === 'POST') { loginState.used = true; loginState.postPermitted = true; }
    await route.continue();
  });
  if (typeof context.routeWebSocket === 'function') {
    await context.routeWebSocket('**/*', socket => socket.close());
  }
}

async function goAccount(page, endpoint, diagnostics = null, stage = () => {}) {
  stage('nav_load');
  const response = await page.goto(`${ORIGIN}/my-account/${endpoint}/`, { waitUntil: 'domcontentloaded' });
  if (diagnostics) diagnostics.endpoint_page_http_ok = response?.ok() ?? false;
  requireCheck(response?.ok() && new URL(page.url()).origin === ORIGIN
    && new URL(page.url()).pathname === `/my-account/${endpoint}/`, 'navigation');
  stage('nav_visible');
  await page.locator(NAV).waitFor({ state: 'visible' });
}

async function linkSnapshot(page) {
  return page.locator(`${NAV} ul a`).evaluateAll(links => links.map(link => ({
    text: link.textContent.trim(), href: link.getAttribute('href'),
    active: !!link.closest('li.is-active') || link.getAttribute('aria-current') === 'page',
  })));
}

function expectedLinks(links) {
  const paths = links.map(link => { try { return new URL(link.href, ORIGIN).pathname; } catch { return ''; } });
  return links.length >= 5 && paths.includes('/my-account/my-pets/')
    && paths.includes('/my-account/for-my-pet/') && paths.includes('/my-account/orders/')
    && paths.includes('/my-account/edit-account/')
    && paths.some(value => /^\/my-account\/customer-logout\/?$/.test(value));
}

function sameLinks(a, b) {
  return a.length === b.length && a.every((link, index) => link.href === b[index].href && link.text === b[index].text);
}

async function nativeListVisible(page) {
  if (!await page.locator(`${NAV} > ul`).isVisible()) return false;
  const links = page.locator(`${NAV} ul a`);
  for (let i = 0; i < await links.count(); i++) if (!await links.nth(i).isVisible()) return false;
  return true;
}

async function focusOutside(page) {
  return page.evaluate(navSelector => {
    const navigation = document.querySelector(navSelector);
    const outside = [...document.querySelectorAll('a[href],button,input,select,textarea,[tabindex]')]
      .find(node => !navigation.contains(node) && !node.disabled && node.tabIndex >= 0
        && node.getClientRects().length && getComputedStyle(node).visibility !== 'hidden');
    if (!outside) return false;
    outside.focus({ preventScroll: true });
    return document.activeElement === outside;
  }, NAV);
}

async function verifyMobile(page, width, nativeLinks) {
  const navigation = page.locator(NAV), toggle = navigation.locator(TOGGLE), list = navigation.locator(':scope > ul');
  await page.setViewportSize({ width, height: 900 });
  await page.waitForFunction(selector => {
    const toggle = document.querySelector(selector);
    return toggle && getComputedStyle(toggle).display !== 'none' && toggle.getAttribute('aria-expanded') === 'false';
  }, `${NAV} ${TOGGLE}`);
  const current = nativeLinks.find(link => link.active)?.text;
  requireCheck(!!current && (await navigation.locator(CURRENT).textContent())?.trim() === current, 'mobile');
  const box = await navigation.boundingBox(), buttonBox = await toggle.boundingBox();
  requireCheck(box && buttonBox && box.height <= 110 && buttonBox.height >= 44
    && box.x >= -1 && box.x + box.width <= width + 1 && !await list.isVisible(), 'mobile');
  await toggle.click();
  requireCheck(await toggle.getAttribute('aria-expanded') === 'true' && await nativeListVisible(page), 'mobile');
  await navigation.locator('ul a').first().focus();
  await page.keyboard.press('Escape');
  requireCheck(!await list.isVisible() && await toggle.evaluate(node => document.activeElement === node), 'keyboard');
  await toggle.click();
  const outsidePoint = await page.evaluate(selector => {
    const box = document.querySelector(selector).getBoundingClientRect();
    return { x: 1, y: Math.max(1, Math.min(innerHeight - 1, box.top > 8 ? box.top - 5 : box.bottom + 5)) };
  }, NAV);
  await page.mouse.click(outsidePoint.x, outsidePoint.y);
  requireCheck(!await list.isVisible() && await toggle.getAttribute('aria-expanded') === 'false', 'keyboard');
  await toggle.click();
  await navigation.locator('ul a').first().focus();
  requireCheck(await focusOutside(page), 'keyboard');
  await page.waitForFunction(selector => document.querySelector(selector)?.getAttribute('aria-expanded') === 'false', `${NAV} ${TOGGLE}`);
  requireCheck(!await list.isVisible(), 'keyboard');
  requireCheck(sameLinks(nativeLinks, await linkSnapshot(page)), 'links');
}

async function verifyDesktopResize(page) {
  const navigation = page.locator(NAV), toggle = navigation.locator(TOGGLE);
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.waitForFunction(selector => getComputedStyle(document.querySelector(selector)).display === 'none', `${NAV} ${TOGGLE}`);
  requireCheck(await nativeListVisible(page) && !await toggle.isVisible(), 'desktop');
  const current = navigation.locator('li.is-active a').first();
  await current.focus();
  await page.setViewportSize({ width: 390, height: 900 });
  await page.waitForFunction(selector => document.activeElement === document.querySelector(selector), `${NAV} ${TOGGLE}`);
  requireCheck(await toggle.isVisible() && !await navigation.locator(':scope > ul').isVisible(), 'resize');
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.waitForFunction(selector => document.activeElement === document.querySelector(selector), `${NAV} li.is-active a`);
  requireCheck(await nativeListVisible(page) && !await toggle.isVisible(), 'resize');
}

async function verifyDarkReadability(page) {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' });
  const readable = await page.locator(`${NAV} ${TOGGLE}`).evaluate(button => {
    const parse = value => {
      const parts = value.match(/[\d.]+/g)?.map(Number);
      return parts?.length >= 3 ? [...parts.slice(0, 3), parts[3] ?? 1] : null;
    };
    const luminance = color => color.slice(0, 3).map(value => {
      value /= 255; return value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4;
    }).reduce((sum, value, index) => sum + value * [.2126, .7152, .0722][index], 0);
    const style = getComputedStyle(button), text = parse(style.color);
    let background = null, node = button;
    while (node && !background) {
      const parsed = parse(getComputedStyle(node).backgroundColor);
      if (parsed && parsed[3] >= .99) background = parsed;
      node = node.parentElement;
    }
    if (!text || text[3] < .99 || !background) return false;
    const a = luminance(text), b = luminance(background);
    return (Math.max(a, b) + .05) / (Math.min(a, b) + .05) >= 4.5;
  });
  requireCheck(readable && await page.locator(`${NAV} ${TOGGLE}`).isVisible(), 'dark_mode');
  await page.emulateMedia({ colorScheme: 'light', reducedMotion: 'reduce' });
}

export async function run(input, chromium) {
  const checks = Object.fromEntries(CHECK_KEYS.map(key => [key, false]));
  const diagnostics = Object.fromEntries(DIAGNOSTIC_KEYS.map(key => [key, false]));
  const loginState = { allowed: false, used: false, postPermitted: false, postBlocked: false };
  const report = (status, category, failure_stage) => {
    diagnostics.login_post_permitted = loginState.postPermitted;
    diagnostics.login_post_blocked = loginState.postBlocked;
    return publicReport({ status, category, failure_stage, diagnostics, checks });
  };
  let category = 'input', failureStage = 'input', browser, page;
  const scriptTasks = [];
  let expectedScriptHash = null;
  try {
    expectedScriptHash = createHash('sha256').update(fs.readFileSync(new URL(
      '../../wordpress/wp-content/plugins/petish-passport/assets/account-navigation.js', import.meta.url))).digest('hex');
    diagnostics.expected_script_hash_available = true;
  } catch {}
  try {
    validateInput(input);
    category = 'browser'; failureStage = 'browser';
    const executable = process.env.PETISH_BROWSER_EXECUTABLE
      || (fs.existsSync(chromium.executablePath()) ? undefined : (fs.existsSync('/usr/bin/chromium') ? '/usr/bin/chromium' : undefined));
    if (executable && (!path.isAbsolute(executable) || !fs.statSync(executable).isFile())) throw new Error('browser');
    browser = await chromium.launch({ headless: true, ...(executable ? { executablePath: executable } : {}), args: ['--no-sandbox'] });
    checks.browser_started = true;
    const context = await browser.newContext({ viewport: { width: 390, height: 900 },
      ignoreHTTPSErrors: false, locale: 'en-AU', timezoneId: 'Australia/Sydney',
      reducedMotion: 'reduce', serviceWorkers: 'block', acceptDownloads: false });
    await installReadOnlyGuard(context, loginState, diagnostics);
    checks.unsafe_requests_blocked = !allowedRequest('https://example.invalid/', 'GET')
      && !allowedRequest(`${ORIGIN}/checkout/`, 'GET')
      && !allowedRequest(`${ORIGIN}/my-account/edit-account/`, 'POST', true)
      && !allowedRequest(`${ORIGIN}/?add-to-cart=1`, 'GET');
    page = await context.newPage();
    page.on('request', request => {
      if (isNavigationScript(request.url())) diagnostics.script_request_seen = true;
    });
    page.on('pageerror', () => { diagnostics.script_pageerror_seen = true; });
    page.on('response', response => {
      if (!isNavigationScript(response.url())) return;
      diagnostics.script_http_ok = response.ok();
      const task = response.body().then(body => {
        diagnostics.script_body_matches_expected_hash = !!expectedScriptHash
          && createHash('sha256').update(body).digest('hex') === expectedScriptHash;
      }).catch(() => {});
      scriptTasks.push(task);
    });
    page.setDefaultTimeout(15000); page.setDefaultNavigationTimeout(45000);
    category = 'login'; failureStage = 'login_page';
    // Anonymous staging front pages may be protected by the staging guard.
    // The same native WP login used by the checkout rehearsal remains available.
    const loginResponse = await page.goto(`${ORIGIN}/wp-login.php?redirect_to=${encodeURIComponent(ORIGIN + '/my-account/')}`,
      { waitUntil: 'domcontentloaded' });
    diagnostics.login_page_http_ok = loginResponse?.ok() ?? false;
    requireCheck(diagnostics.login_page_http_ok, 'login');
    failureStage = 'login_form';
    diagnostics.login_form_present = await page.locator('#user_login').count() === 1
      && await page.locator('#user_pass').count() === 1 && await page.locator('#wp-submit').count() === 1;
    requireCheck(diagnostics.login_form_present, 'login');
    await page.locator('#user_login').fill(input.login);
    await page.locator('#user_pass').fill(input.password);
    // Preserve native nonce/test-cookie handling while pinning the post-login
    // destination to the account page, never wp-admin or an external redirect.
    requireCheck(await page.locator('input[name="redirect_to"]').inputValue() === ORIGIN + '/my-account/', 'login');
    loginState.allowed = true;
    failureStage = 'login_submit';
    diagnostics.login_submit_clicked = true;
    await Promise.all([page.waitForURL(url => url.origin === ORIGIN && url.pathname.startsWith('/my-account/'),
      { waitUntil: 'domcontentloaded', timeout: 45000 }), page.locator('#wp-submit').click()]);
    loginState.allowed = false;
    failureStage = 'login_cookie';
    diagnostics.login_cookie_present = (await context.cookies(ORIGIN)).some(cookie => cookie.name.startsWith('wordpress_logged_in_'));
    diagnostics.login_redirect_account = new URL(page.url()).origin === ORIGIN
      && new URL(page.url()).pathname.startsWith('/my-account/');
    requireCheck(loginState.used && diagnostics.login_cookie_present && diagnostics.login_redirect_account, 'login');
    failureStage = 'qa_identity';
    await goAccount(page, 'edit-account');
    diagnostics.account_email_field_present = await page.locator('input[name="account_email"]').count() === 1;
    requireCheck(diagnostics.account_email_field_present, 'login');
    diagnostics.qa_email_matches = await page.locator('input[name="account_email"]').inputValue() === input.customer_email;
    requireCheck(diagnostics.qa_email_matches, 'login');
    checks.customer_login_verified = true;
    const storageState = await context.storageState(); // In-memory only; never written or reported.
    const noJS = await browser.newContext({ storageState, javaScriptEnabled: false,
      viewport: { width: 390, height: 900 }, ignoreHTTPSErrors: false,
      locale: 'en-AU', serviceWorkers: 'block', acceptDownloads: false });
    await installReadOnlyGuard(noJS, { allowed: false, used: true });
    const fallback = await noJS.newPage();
    fallback.setDefaultTimeout(15000); fallback.setDefaultNavigationTimeout(45000);
    const baselines = new Map();
    category = 'no_js'; failureStage = 'no_js';
    for (const endpoint of ENDPOINTS) {
      await goAccount(fallback, endpoint);
      const links = await linkSnapshot(fallback);
      requireCheck(expectedLinks(links), 'links');
      requireCheck(await nativeListVisible(fallback)
        && await fallback.locator(`${NAV} ${TOGGLE}`).count() === 0, 'no_js');
      await fallback.setViewportSize({ width: 320, height: 900 });
      requireCheck(await nativeListVisible(fallback), 'no_js');
      await fallback.setViewportSize({ width: 1280, height: 900 });
      requireCheck(await nativeListVisible(fallback), 'no_js');
      await fallback.setViewportSize({ width: 390, height: 900 });
      baselines.set(endpoint, links);
    }
    checks.no_js_fallback_visible = true;
    checks.native_navigation_links_present = true;
    category = 'navigation'; failureStage = 'navigation';
    for (const endpoint of ENDPOINTS) {
      for (const key of ['script_request_seen', 'script_request_blocked', 'script_http_ok',
        'script_body_matches_expected_hash', 'script_pageerror_seen']) diagnostics[key] = false;
      await page.setViewportSize({ width: 390, height: 900 });
      await goAccount(page, endpoint, diagnostics, stage => { failureStage = stage; });
      await collectPageDiagnostics(page, diagnostics);
      failureStage = 'links';
      diagnostics.actual_native_hrefs_match = sameLinks(baselines.get(endpoint), await linkSnapshot(page));
      requireCheck(diagnostics.actual_native_hrefs_match, 'links');
      failureStage = 'toggle_wait';
      await page.locator(`${NAV}.pp-account-navigation ${TOGGLE}`).waitFor({ state: 'visible' });
      await collectPageDiagnostics(page, diagnostics);
      for (const width of [390, 320]) {
        category = 'mobile'; failureStage = 'mobile';
        await verifyMobile(page, width, baselines.get(endpoint));
        checks[`${endpoint.replaceAll('-', '_')}_${width}_verified`] = true;
      }
      category = 'desktop'; failureStage = 'desktop'; await verifyDesktopResize(page);
      category = 'dark_mode'; failureStage = 'dark_mode'; await verifyDarkReadability(page);
    }
    for (const name of ['native_hrefs_unchanged', 'current_label_verified', 'mobile_compact_closed',
      'toggle_opens', 'escape_closes_focus_returns', 'outside_closes', 'focusout_closes',
      'desktop_visible', 'resize_visible', 'dark_mode_readable']) checks[name] = true;
    requireCheck(Object.values(checks).every(Boolean), 'navigation');
    await Promise.allSettled(scriptTasks);
    return report('success', 'complete', 'complete');
  } catch (error) {
    await collectPageDiagnostics(page, diagnostics);
    await Promise.allSettled(scriptTasks);
    return report('failure', CATEGORIES.has(error?.message) ? error.message : category, failureStage);
  } finally { if (browser) await browser.close().catch(() => {}); }
}

async function main() {
  let result = publicReport({ category: 'input' });
  try {
    if (process.argv.length !== 4) throw new Error('input');
    const input = readPrivateInput(process.argv[2]);
    const require = createRequire(new URL('../staging-rehearsal/package.json', import.meta.url));
    const { chromium } = require('playwright');
    result = await run(input, chromium);
  } catch {}
  try {
    if (process.argv.length !== 4) throw new Error('input');
    writeReport(process.argv[3], result);
  } catch { result = publicReport({ category: 'input' }); }
  process.stdout.write(JSON.stringify(result) + '\n');
  if (result.status !== 'success') process.exitCode = 1;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) await main();
