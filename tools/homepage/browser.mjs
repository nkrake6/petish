/** Read-only, synthetic-account homepage checks against fixed staging. */
import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { validateInput, allowedRequest } from '../account-navigation/browser.mjs';

const ORIGIN = 'https://staging.petish.biz';
const THEME = new URL('../../wordpress/wp-content/themes/dt-the7-child/', import.meta.url);
const CSS_PATH = '/wp-content/themes/dt-the7-child/assets/css/home.css';
const BADGE_PATH = '/wp-content/themes/dt-the7-child/assets/images/ui/free-delivery-van.png';
const SECTION = '#petish-free-delivery';
const CATEGORIES = new Set(['complete', 'input', 'browser', 'login', 'homepage', 'subtitle', 'assets', 'layout', 'network']);
export const CHECK_KEYS = Object.freeze(['browser_started', 'customer_login_verified',
  'homepage_http_ok', 'hot_subtitle_absent', 'home_css_http_ok', 'home_css_hash_matches',
  'badge_png_http_ok', 'badge_png_hash_matches', 'conditional_section_valid',
  'section_320_valid', 'section_390_valid', 'section_1280_valid',
  'badges_bottom_right', 'badges_nonoverlap_hot', 'readonly_requests_enforced']);

export function publicReport(value) {
  const checks = Object.fromEntries(CHECK_KEYS.map(key => [key, value?.checks?.[key] === true]));
  const success = value?.status === 'success' && value?.category === 'complete'
    && Object.values(checks).every(Boolean);
  return { status: success ? 'success' : 'failure',
    category: success ? 'complete' : (CATEGORIES.has(value?.category) && value.category !== 'complete' ? value.category : 'input'),
    section_present: value?.section_present === true, checks };
}

function privateInput(filename) {
  const resolved = path.resolve(filename), stat = fs.lstatSync(resolved);
  if (!stat.isFile() || stat.isSymbolicLink() || stat.nlink !== 1 || stat.size < 1 || stat.size > 32768
      || (stat.mode & 0o7777) !== 0o600 || fs.realpathSync(resolved) !== resolved) throw new Error('input');
  return validateInput(JSON.parse(fs.readFileSync(resolved, 'utf8')));
}

function writeReport(filename, value) {
  const resolved = path.resolve(filename);
  if (fs.realpathSync(path.dirname(resolved)) !== path.dirname(resolved)) throw new Error('input');
  const fd = fs.openSync(resolved, fs.constants.O_WRONLY | fs.constants.O_CREAT | fs.constants.O_EXCL | fs.constants.O_NOFOLLOW, 0o600);
  try { fs.writeFileSync(fd, JSON.stringify(publicReport(value), null, 2) + '\n'); }
  finally { fs.closeSync(fd); }
}

function requireCheck(condition, category) { if (!condition) throw new Error(category); }
const hash = bytes => createHash('sha256').update(bytes).digest('hex');

function launchOptions(chromium) {
  const executable = process.env.PETISH_BROWSER_EXECUTABLE || (fs.existsSync(chromium.executablePath())
    ? undefined : (fs.existsSync('/usr/bin/chromium') ? '/usr/bin/chromium' : undefined));
  if (executable && (!path.isAbsolute(executable) || !fs.statSync(executable).isFile())) throw new Error('browser');
  return { headless: true, ...(executable ? { executablePath: executable } : {}), args: ['--no-sandbox'] };
}

export function allowedHomepageRequest(raw, ...options) {
  if (!allowedRequest(raw, ...options)) return false;
  try {
    const url = new URL(raw);
    // The homepage/layout test needs no REST calls, supplier endpoints, or
    // store APIs. Keep these closed even when a page script tries a GET.
    return !url.pathname.includes('/wp-json/') && !url.searchParams.has('rest_route');
  } catch { return false; }
}

async function readOnlyContext(browser) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 },
    ignoreHTTPSErrors: false, locale: 'en-AU', timezoneId: 'Australia/Sydney',
    reducedMotion: 'reduce', serviceWorkers: 'block', acceptDownloads: false });
  const login = { allowed: false, used: false };
  await context.route('**/*', async route => {
    const request = route.request();
    if (!allowedHomepageRequest(request.url(), request.method(), login.allowed && !login.used,
      request.postData() ?? '', request.resourceType())) return route.abort('blockedbyclient');
    if (request.method() === 'POST') login.used = true;
    return route.continue();
  });
  if (typeof context.routeWebSocket === 'function') await context.routeWebSocket('**/*', socket => socket.close());
  return { context, login };
}

/** Only section/card/badge geometry is checked; unrelated page overflow is outside scope. */
export async function inspectSectionGeometry(page) {
  return page.evaluate(selector => {
    const section = document.querySelector(selector);
    if (!section) return { valid: true, bottom_right: true, nonoverlap: true };
    const within = (box, parent) => box.width > 0 && box.height > 0
      && box.left >= parent.left - 1 && box.right <= parent.right + 1
      && box.top >= parent.top - 1 && box.bottom <= parent.bottom + 1;
    const intersects = (a, b) => Math.min(a.right, b.right) > Math.max(a.left, b.left) + 1
      && Math.min(a.bottom, b.bottom) > Math.max(a.top, b.top) + 1;
    const cards = [...section.querySelectorAll('.ph-product')];
    const sectionBox = section.getBoundingClientRect();
    let valid = section.getClientRects().length > 0 && cards.length >= 1 && cards.length <= 4
      && sectionBox.left >= -1 && sectionBox.right <= innerWidth + 1;
    let bottomRight = true, nonoverlap = true;
    for (const card of cards) {
      const box = card.getBoundingClientRect();
      valid = valid && within(box, sectionBox) && box.left >= -1 && box.right <= innerWidth + 1;
      const image = card.querySelector('.ph-product-image');
      const badges = card.querySelectorAll('.petish-free-delivery-badge');
      if (!image || badges.length !== 1) { valid = false; bottomRight = false; continue; }
      const imageBox = image.getBoundingClientRect(), badge = badges[0], badgeBox = badge.getBoundingClientRect();
      const png = badge.querySelector('img');
      valid = valid && !!png?.complete && png.naturalWidth > 0 && within(badgeBox, imageBox);
      bottomRight = bottomRight && within(badgeBox, imageBox)
        && Math.abs(imageBox.right - badgeBox.right - 8) <= 2
        && Math.abs(imageBox.bottom - badgeBox.bottom - 8) <= 2;
      for (const hot of image.querySelectorAll('.petish-hot-badge')) {
        nonoverlap = nonoverlap && !intersects(badgeBox, hot.getBoundingClientRect());
      }
    }
    return { valid, bottom_right: bottomRight, nonoverlap };
  }, SECTION);
}

async function decodeSectionBadges(page) {
  await page.locator(`${SECTION} .petish-free-delivery-badge img`).evaluateAll(async images => {
    for (const image of images) { image.loading = 'eager'; await image.decode(); }
  });
}

export async function run(input, chromium) {
  const checks = Object.fromEntries(CHECK_KEYS.map(key => [key, false]));
  let browser, category = 'input', sectionPresent = false;
  const report = (status, category) => publicReport({ status, category, section_present: sectionPresent, checks });
  try {
    validateInput(input);
    if (input.expected_section_present !== undefined && typeof input.expected_section_present !== 'boolean') throw new Error('input');
    const expectedCSS = hash(fs.readFileSync(new URL('assets/css/home.css', THEME)));
    const expectedBadge = hash(fs.readFileSync(new URL('assets/images/ui/free-delivery-van.png', THEME)));
    category = 'browser';
    browser = await chromium.launch(launchOptions(chromium));
    checks.browser_started = true;
    const { context, login } = await readOnlyContext(browser);
    checks.readonly_requests_enforced = !allowedHomepageRequest(`${ORIGIN}/wp-json/petish-fulfilment/v1/suppliers`, 'GET')
      && !allowedHomepageRequest('https://petish.biz/', 'GET')
      && !allowedRequest(`${ORIGIN}/checkout/`, 'GET')
      && !allowedRequest(`${ORIGIN}/?add-to-cart=1`, 'GET')
      && !allowedRequest(`${ORIGIN}/my-account/edit-account/`, 'POST', true);
    const page = await context.newPage();
    page.setDefaultTimeout(15000); page.setDefaultNavigationTimeout(45000);
    category = 'login';
    const loginResponse = await page.goto(`${ORIGIN}/wp-login.php?redirect_to=${encodeURIComponent(ORIGIN + '/my-account/')}`,
      { waitUntil: 'domcontentloaded' });
    requireCheck(loginResponse?.ok(), 'login');
    await page.locator('#user_login').fill(input.login);
    await page.locator('#user_pass').fill(input.password);
    requireCheck(await page.locator('input[name="redirect_to"]').inputValue() === ORIGIN + '/my-account/', 'login');
    login.allowed = true;
    await Promise.all([page.waitForURL(url => url.origin === ORIGIN && url.pathname.startsWith('/my-account/'),
      { waitUntil: 'domcontentloaded', timeout: 45000 }), page.locator('#wp-submit').click()]);
    login.allowed = false;
    requireCheck(login.used && (await context.cookies(ORIGIN)).some(cookie => cookie.name.startsWith('wordpress_logged_in_')), 'login');
    await page.goto(`${ORIGIN}/my-account/edit-account/`, { waitUntil: 'domcontentloaded' });
    requireCheck(await page.locator('input[name="account_email"]').inputValue() === input.customer_email, 'login');
    checks.customer_login_verified = true;
    category = 'homepage';
    const assetRecords = new Map(), assetTasks = [];
    page.on('response', response => {
      const url = new URL(response.url());
      if (url.origin !== ORIGIN || ![CSS_PATH, BADGE_PATH].includes(url.pathname)) return;
      const task = response.body().then(body => assetRecords.set(url.pathname, { ok: response.ok(), hash: hash(body) }))
        .catch(() => assetRecords.set(url.pathname, { ok: false, hash: null }));
      assetTasks.push(task);
    });
    const homeResponse = await page.goto(ORIGIN + '/', { waitUntil: 'domcontentloaded' });
    checks.homepage_http_ok = !!homeResponse?.ok() && new URL(page.url()).origin === ORIGIN
      && new URL(page.url()).pathname === '/' && await page.locator('.petish-home').count() === 1;
    requireCheck(checks.homepage_http_ok, 'homepage');
    category = 'subtitle';
    checks.hot_subtitle_absent = await page.locator('#petish-hot-items .ph-section-heading p').count() === 0
      && !await page.locator('.petish-home').evaluate(node => node.textContent.includes('Selected by supplier-reported orders.'));
    requireCheck(checks.hot_subtitle_absent, 'subtitle');
    category = 'assets';
    const cssEnqueued = await page.locator('link[rel="stylesheet"]').evaluateAll((links, expected) =>
      links.some(link => { try { const u = new URL(link.href); return u.origin === expected.origin && u.pathname === expected.path; } catch { return false; } }),
    { origin: ORIGIN, path: CSS_PATH });
    await Promise.allSettled(assetTasks);
    const css = assetRecords.get(CSS_PATH);
    checks.home_css_http_ok = cssEnqueued && css?.ok === true;
    checks.home_css_hash_matches = cssEnqueued && css?.hash === expectedCSS;
    // Request the reviewed PNG even when no supplier products qualify, so an
    // intentionally absent section cannot hide an unreadable deployment asset.
    const png = await page.evaluate(async pathname => {
      const response = await fetch(pathname, { credentials: 'same-origin', cache: 'reload' });
      return { ok: response.ok, bytes: Array.from(new Uint8Array(await response.arrayBuffer())) };
    }, BADGE_PATH);
    checks.badge_png_http_ok = png.ok === true;
    checks.badge_png_hash_matches = png.ok && hash(Buffer.from(png.bytes)) === expectedBadge;
    requireCheck(checks.home_css_http_ok && checks.home_css_hash_matches
      && checks.badge_png_http_ok && checks.badge_png_hash_matches, 'assets');
    category = 'layout';
    const sections = page.locator(SECTION);
    sectionPresent = await sections.count() > 0;
    requireCheck(await sections.count() <= 1, 'layout');
    checks.conditional_section_valid = (input.expected_section_present === undefined
      || input.expected_section_present === sectionPresent) && (!sectionPresent || (await sections.isVisible()
      && await sections.locator('.ph-product').count() >= 1 && await sections.locator('.ph-product').count() <= 4));
    requireCheck(checks.conditional_section_valid, 'layout');
    if (sectionPresent) {
      await sections.scrollIntoViewIfNeeded();
      await decodeSectionBadges(page);
    }
    let bottomRight = true, nonoverlap = true;
    for (const width of [320, 390, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      if (sectionPresent) await sections.scrollIntoViewIfNeeded();
      const geometry = await inspectSectionGeometry(page);
      checks[`section_${width}_valid`] = geometry.valid;
      bottomRight = bottomRight && geometry.bottom_right;
      nonoverlap = nonoverlap && geometry.nonoverlap;
      requireCheck(geometry.valid && geometry.bottom_right && geometry.nonoverlap, 'layout');
    }
    checks.badges_bottom_right = bottomRight;
    checks.badges_nonoverlap_hot = nonoverlap;
    requireCheck(Object.values(checks).every(Boolean), 'layout');
    return report('success', 'complete');
  } catch (error) {
    return report('failure', CATEGORIES.has(error?.message) ? error.message : category);
  } finally { if (browser) await browser.close().catch(() => {}); }
}

/** Synthetic render only: reviewed CSS/PNG, four cards, and native Hot pills. */
export async function runLocalFixture(chromium) {
  let browser;
  const checks = { fixture_320_valid: false, fixture_390_valid: false, fixture_1280_valid: false,
    badge_bottom_right: false, hot_nonoverlap: false, absence_valid: false };
  try {
    const css = ['assets/css/petish.css', 'assets/css/supplier-popularity.css', 'assets/css/home.css']
      .map(file => fs.readFileSync(new URL(file, THEME), 'utf8')).join('\n');
    const dataImage = file => 'data:image/png;base64,' + fs.readFileSync(new URL(file, THEME)).toString('base64');
    const badge = dataImage('assets/images/ui/free-delivery-van.png');
    const flame = dataImage('assets/images/ui/hot-flame.png');
    const product = 'data:image/svg+xml;base64,' + Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400"><rect width="400" height="400" fill="#d9e9e2"/><circle cx="200" cy="200" r="110" fill="#b8d0c4"/></svg>').toString('base64');
    const cards = Array.from({ length: 4 }, () => `<article class="ph-product"><a class="ph-product-link" href="#"><span class="ph-product-image"><img src="${product}" alt="Synthetic product"><span class="petish-hot-badge petish-hot-badge--icon"><img class="petish-hot-badge__icon" src="${flame}" alt=""><span class="petish-hot-badge__label">Hot</span></span><span class="petish-free-delivery-badge"><img src="${badge}" width="2061" height="763" alt="Free delivery"></span></span><div class="ph-product-body"><h3>Synthetic fixture product</h3><span class="ph-product-price">Sample</span></div></a></article>`).join('');
    browser = await chromium.launch(launchOptions(chromium));
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, serviceWorkers: 'block' });
    await context.route('**/*', route => route.abort('blockedbyclient'));
    const page = await context.newPage();
    await page.setContent(`<!doctype html><html><head><style>${css}\nbody{margin:0}.petish-home{padding:20px;max-width:1280px;margin:auto}.ph-section{margin-top:0!important}</style></head><body class="petish-site"><main class="petish-home"><section id="petish-free-delivery" class="ph-section ph-collection ph-free-delivery"><div class="ph-section-heading"><div><h2>Free delivery favourites</h2><p>Fixture destination only</p></div></div><div class="ph-product-grid">${cards}</div></section></main></body></html>`);
    await decodeSectionBadges(page);
    let bottom = true, separate = true;
    for (const width of [320, 390, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      const geometry = await inspectSectionGeometry(page);
      checks[`fixture_${width}_valid`] = geometry.valid;
      bottom = bottom && geometry.bottom_right;
      separate = separate && geometry.nonoverlap;
    }
    checks.badge_bottom_right = bottom;
    checks.hot_nonoverlap = separate;
    await page.locator(SECTION).evaluate(node => node.remove());
    const absent = await inspectSectionGeometry(page);
    checks.absence_valid = absent.valid && absent.bottom_right && absent.nonoverlap;
    return { status: Object.values(checks).every(Boolean) ? 'success' : 'failure', category: 'fixture', checks };
  } catch { return { status: 'failure', category: 'fixture', checks }; }
  finally { if (browser) await browser.close().catch(() => {}); }
}

async function main() {
  let result = publicReport({ category: 'input' });
  try {
    if (process.argv.length !== 4) throw new Error('input');
    const input = privateInput(process.argv[2]);
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
