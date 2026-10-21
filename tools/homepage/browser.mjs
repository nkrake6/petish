/** Read-only, synthetic-account homepage checks against fixed staging. */
import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { inflateSync, deflateSync } from 'node:zlib';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { validateInput, allowedRequest } from '../account-navigation/browser.mjs';

const ORIGIN = 'https://staging.petish.biz';
const THEME = new URL('../../wordpress/wp-content/themes/dt-the7-child/', import.meta.url);
const CSS_PATH = '/wp-content/themes/dt-the7-child/assets/css/home.css';
const BADGE_PATH = '/wp-content/themes/dt-the7-child/assets/images/ui/free-delivery-van.png';
const THEME_SCRIPT_PATH = '/wp-content/themes/dt-the7-child/assets/js/theme.js';
const DESIGN_CSS_PATH = '/wp-content/themes/dt-the7-child/assets/css/petish.css';
const MARKET_SCRIPT_PATH = '/wp-content/themes/dt-the7-child/assets/js/market-selector.js';
const MARKET_CSS_PATH = '/wp-content/themes/dt-the7-child/assets/css/market-selector.css';
const FLAG_PATH = '/wp-content/themes/dt-the7-child/assets/images/ui/country-flags.svg';
const THEME_SWITCH = '[data-petish-theme-toggle]';
const PERSONAL_SCRIPT_PATH = '/wp-content/themes/dt-the7-child/assets/js/for-your-pet.js';
const PERSONAL_ENDPOINT = `${ORIGIN}/wp-admin/admin-ajax.php?action=petish_for_your_pet`;
const PERSONAL = '[data-petish-for-your-pet]';
const SECTION = '#petish-free-delivery';
export const IMAGE_DIMENSION_KEYS = Object.freeze(['expected_width', 'expected_height', 'response_width', 'response_height']);
export const THEME_VIEWS = Object.freeze(['320_light', '320_dark', '390_light', '390_dark', '1280_light', '1280_dark']);
export const THEME_GEOMETRY_KEYS = Object.freeze([...new Set(['header_height', 'baseline_height', 'header_width', 'viewport_width',
  'switch_left', 'switch_right', 'search_right', 'market_left', 'market_right', 'logo_right', 'logo_width', 'baseline_logo_width',
  'logo_count', 'logo_parent_width', 'baseline_logo_parent_width', 'visible_count', 'overflow', 'search_left',
  'candidate_logo_width', 'candidate_header_height', 'candidate_baseline_height', 'candidate_fit',
  ...['header', 'branding', 'widgets', 'menu'].flatMap(node => ['left', 'right', 'width', 'height', 'client_width', 'scroll_width',
    'padding_left', 'padding_right', 'margin_left', 'margin_right', 'flex_basis', 'flex_grow', 'flex_shrink', 'min_width', 'max_width', 'gap'].map(metric => `${node}_${metric}`)),
  ...['search', 'switch', 'market', 'other'].flatMap(node => ['width', 'margin_left', 'margin_right', 'count'].map(metric => `${node}_${metric}`))])]);
const CATEGORIES = new Set(['complete', 'input', 'browser', 'login', 'homepage', 'subtitle', 'assets', 'layout', 'network', 'personalisation', 'personalisation_error', 'personalisation_history', 'theme_assets', 'theme_layout', 'theme_interaction', 'theme_persistence', 'theme_system', 'market_flag_assets', 'market_flag_layout']);
export const DIAGNOSTIC_KEYS = Object.freeze(['badge_response_content_type_png',
  'badge_response_content_type_webp', 'badge_response_content_type_avif', 'badge_response_content_type_html',
  'badge_response_signature_png', 'badge_response_signature_webp', 'badge_response_signature_avif',
  'badge_response_bytes_match', 'badge_response_size_bounded', 'badge_fetch_versioned',
  'badge_expected_decode_ok', 'badge_response_decode_ok', 'badge_canvas_context_available',
  'badge_dimensions_match', 'badge_pixels_match', 'badge_comparison_completed', 'badge_versioned_image_matches',
  ...[320, 390, 1280].flatMap(width => ['light', 'dark'].flatMap(mode => ['order', 'height', 'fit'].map(fact => `theme_${width}_${mode}_${fact}`)))]);
export const CHECK_KEYS = Object.freeze(['browser_started', 'customer_login_verified',
  'homepage_http_ok', 'hot_subtitle_absent', 'home_css_http_ok', 'home_css_hash_matches',
  'badge_png_http_ok', 'badge_png_image_matches', 'conditional_section_valid',
  'section_320_valid', 'section_390_valid', 'section_1280_valid',
  'badges_bottom_right', 'badges_nonoverlap_hot', 'readonly_requests_enforced',
  'personalisation_script_http_verified', 'personalisation_shell_private_verified',
  'personalisation_ajax_verified', 'personalisation_no_store_verified', 'personalisation_layout_verified',
  'personalisation_error_retry_verified', 'personalisation_history_reset_verified',
  'theme_assets_http_verified', 'theme_switch_order_verified', 'theme_switch_geometry_verified',
  'theme_switch_pointer_verified', 'theme_switch_keyboard_verified', 'theme_switch_persistence_verified',
  'theme_switch_select_sync_verified', 'theme_switch_system_verified', 'theme_switch_clones_verified',
  'market_flag_assets_verified', 'market_flag_header_verified', 'market_flag_cart_verified', 'market_flag_webkit_verified']);

export function publicReport(value) {
  const checks = Object.fromEntries(CHECK_KEYS.map(key => [key, value?.checks?.[key] === true]));
  const success = value?.status === 'success' && value?.category === 'complete'
    && Object.values(checks).every(Boolean);
  return { status: success ? 'success' : 'failure',
    category: success ? 'complete' : (CATEGORIES.has(value?.category) && value.category !== 'complete' ? value.category : 'input'),
    section_present: value?.section_present === true,
    image_dimensions: Object.fromEntries(IMAGE_DIMENSION_KEYS.map(key => [key,
      Number.isInteger(value?.image_dimensions?.[key]) && value.image_dimensions[key] >= 0
        && value.image_dimensions[key] <= 4096 ? value.image_dimensions[key] : 0])),
    theme_geometry: Object.fromEntries(THEME_VIEWS.map(view => [view, Object.fromEntries(THEME_GEOMETRY_KEYS.map(key => [key,
      Number.isFinite(value?.theme_geometry?.[view]?.[key]) && Math.abs(value.theme_geometry[view][key]) <= 4096
        ? Math.round(value.theme_geometry[view][key] * 100) / 100 : 0]))])),
    diagnostics: Object.fromEntries(DIAGNOSTIC_KEYS.map(key => [key, value?.diagnostics?.[key] === true])), checks };
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

function pngDimensions(bytes) {
  const unknown = { width: 0, height: 0 };
  if (bytes.length < 33 || bytes.subarray(0, 8).toString('hex') !== '89504e470d0a1a0a'
      || bytes.readUInt32BE(8) !== 13 || bytes.subarray(12, 16).toString('ascii') !== 'IHDR') return unknown;
  const width = bytes.readUInt32BE(16), height = bytes.readUInt32BE(20);
  return width >= 1 && width <= 4096 && height >= 1 && height <= 4096 ? { width, height } : unknown;
}

/** Save only an explicitly authorised fixed badge diagnostic, never page data. */
export function saveBadgeResponse(filename, bytes, mime, decoded) {
  if (decoded !== true || !/^image\/png(?:;|$)/i.test(mime) || bytes.length > 8388608) return false;
  const size = pngDimensions(bytes);
  if (!size.width || !size.height) return false;
  const resolved = path.resolve(filename);
  if (path.basename(resolved) !== 'badge-response.png'
      || fs.realpathSync(path.dirname(resolved)) !== path.dirname(resolved)) return false;
  const fd = fs.openSync(resolved, fs.constants.O_WRONLY | fs.constants.O_CREAT
    | fs.constants.O_EXCL | fs.constants.O_NOFOLLOW, 0o600);
  try { fs.writeFileSync(fd, bytes); }
  finally { fs.closeSync(fd); }
  return true;
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
  // One read-only, same-origin fragment endpoint is needed. No other admin,
  // supplier, identity override or mutation request is admitted by this exception.
  // Fixed empty Cart document only. All cart query actions and methods stay blocked.
  if (raw === `${ORIGIN}/cart/` && (options[0] ?? 'GET') === 'GET'
      && (options[4] ?? 'document') === 'document') return true;
  if (raw === PERSONAL_ENDPOINT && (options[0] ?? 'GET') === 'GET') return true;
  if (!allowedRequest(raw, ...options)) return false;
  try {
    const url = new URL(raw);
    // The homepage/layout test needs no REST calls, supplier endpoints, or
    // store APIs. Keep these closed even when a page script tries a GET.
    return !url.pathname.includes('/wp-json/') && !url.searchParams.has('rest_route');
  } catch { return false; }
}

async function readOnlyContext(browser, storageState) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 },
    ignoreHTTPSErrors: false, locale: 'en-AU', timezoneId: 'Australia/Sydney',
    reducedMotion: 'reduce', serviceWorkers: 'block', acceptDownloads: false,
    ...(storageState ? { storageState } : {}) });
  const login = { allowed: false, used: false, blockPersonal: false };
  await context.route('**/*', async route => {
    const request = route.request();
    if (login.blockPersonal && request.url() === PERSONAL_ENDPOINT) return route.abort('blockedbyclient');
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

/** Exact decoded dimensions and RGBA bytes; never perceptual or approximate. */
async function inspectPNGImages(page, expectedBytes, actualBytes) {
  const diagnostics = Object.fromEntries(DIAGNOSTIC_KEYS.map(key => [key, false]));
  diagnostics.badge_response_size_bounded = !!expectedBytes?.length && !!actualBytes?.length
    && expectedBytes.length <= 16777216 && actualBytes.length <= 16777216;
  if (!diagnostics.badge_response_size_bounded) return { matches: false, diagnostics };
  const signature = [137, 80, 78, 71, 13, 10, 26, 10];
  const expectedPNG = signature.every((value, index) => expectedBytes[index] === value);
  diagnostics.badge_response_signature_png = signature.every((value, index) => actualBytes[index] === value);
  diagnostics.badge_response_signature_webp = actualBytes.subarray(0, 4).toString('ascii') === 'RIFF'
    && actualBytes.subarray(8, 12).toString('ascii') === 'WEBP';
  diagnostics.badge_response_signature_avif = actualBytes.subarray(4, 8).toString('ascii') === 'ftyp'
    && /^(?:avif|avis)$/.test(actualBytes.subarray(8, 12).toString('ascii'));
  diagnostics.badge_response_bytes_match = hash(expectedBytes) === hash(actualBytes);
  if (!expectedPNG || !diagnostics.badge_response_signature_png) return { matches: false, diagnostics };
  if (diagnostics.badge_response_bytes_match) {
    diagnostics.badge_comparison_completed = true;
    return { matches: true, diagnostics };
  }
  const outcome = await page.evaluate(async ({ expected, actual }) => {
    const result = { badge_expected_decode_ok: false, badge_response_decode_ok: false,
      badge_canvas_context_available: false, badge_dimensions_match: false,
      badge_pixels_match: false, badge_comparison_completed: false };
    try {
      result.badge_canvas_context_available = typeof OffscreenCanvas === 'function'
        && !!new OffscreenCanvas(1, 1).getContext('2d', { willReadFrequently: true, colorSpace: 'srgb' });
      if (!result.badge_canvas_context_available) return result;
      const decode = async (bytes, key) => {
        let bitmap;
        try {
          bitmap = await createImageBitmap(new Blob([new Uint8Array(bytes)], { type: 'image/png' }),
            { colorSpaceConversion: 'none', premultiplyAlpha: 'none' });
          result[key] = true;
          if (bitmap.width < 1 || bitmap.height < 1 || bitmap.width * bitmap.height > 16777216) return null;
          const canvas = new OffscreenCanvas(bitmap.width, bitmap.height);
          const context = canvas.getContext('2d', { willReadFrequently: true, colorSpace: 'srgb' });
          if (!context) { result.badge_canvas_context_available = false; return null; }
          context.drawImage(bitmap, 0, 0);
          return { width: bitmap.width, height: bitmap.height,
            rgba: context.getImageData(0, 0, bitmap.width, bitmap.height).data };
        } catch { return null; }
        finally { bitmap?.close(); }
      };
      const left = await decode(expected, 'badge_expected_decode_ok');
      const right = await decode(actual, 'badge_response_decode_ok');
      if (!left || !right) return result;
      result.badge_dimensions_match = left.width === right.width && left.height === right.height
        && left.rgba.length === right.rgba.length;
      result.badge_comparison_completed = true;
      if (!result.badge_dimensions_match) return result;
      for (let offset = 0; offset < left.rgba.length; offset += 65536) {
        const end = Math.min(offset + 65536, left.rgba.length);
        for (let index = offset; index < end; index++) if (left.rgba[index] !== right.rgba[index]) return result;
      }
      result.badge_pixels_match = true;
      return result;
    } catch { return result; }
  }, { expected: Array.from(expectedBytes), actual: Array.from(actualBytes) }).catch(() => null);
  if (outcome) Object.assign(diagnostics, outcome);
  return { matches: !!outcome?.badge_dimensions_match && !!outcome?.badge_pixels_match, diagnostics };
}

export async function pngImagesMatch(page, expectedBytes, actualBytes) {
  return (await inspectPNGImages(page, expectedBytes, actualBytes)).matches;
}

/** Recompress IDAT bytes in memory without touching decoded pixel/filter data. */
function recompressPNG(bytes) {
  const chunks = []; let idat = [], offset = 8;
  while (offset + 12 <= bytes.length) {
    const length = bytes.readUInt32BE(offset), end = offset + length + 12;
    if (end > bytes.length) throw new Error('input');
    const type = bytes.subarray(offset + 4, offset + 8).toString('ascii');
    const chunk = bytes.subarray(offset, end);
    chunks.push({ type, chunk });
    if (type === 'IDAT') idat.push(bytes.subarray(offset + 8, offset + 8 + length));
    offset = end;
  }
  if (!idat.length || offset !== bytes.length) throw new Error('input');
  const compressed = deflateSync(inflateSync(Buffer.concat(idat)), { level: 1 });
  const payload = Buffer.concat([Buffer.from('IDAT'), compressed]);
  let crc = 0xffffffff;
  for (const value of payload) {
    crc ^= value;
    for (let bit = 0; bit < 8; bit++) crc = (crc >>> 1) ^ ((crc & 1) ? 0xedb88320 : 0);
  }
  const header = Buffer.alloc(4), trailer = Buffer.alloc(4);
  header.writeUInt32BE(compressed.length); trailer.writeUInt32BE((crc ^ 0xffffffff) >>> 0);
  const replacement = Buffer.concat([header, payload, trailer]);
  let replaced = false;
  return Buffer.concat([bytes.subarray(0, 8), ...chunks.flatMap(({ type, chunk }) => {
    if (type !== 'IDAT') return [chunk];
    if (replaced) return [];
    replaced = true; return [replacement];
  })]);
}

async function decodeSectionBadges(page) {
  await page.locator(`${SECTION} .petish-free-delivery-badge img`).evaluateAll(async images => {
    for (const image of images) { image.loading = 'eager'; await image.decode(); }
  });
}

function noStore(headers) {
  return /(?:^|,)\s*no-store(?:\s*,|$)/i.test(headers['cache-control'] ?? '')
    && !/(?:^|[, ])hit(?:[, ]|$)/i.test(headers['x-litespeed-cache'] ?? '');
}
function privateFragmentHeaders(headers) {
  return noStore(headers) && /(?:^|,)\s*Cookie\s*(?:,|$)/i.test(headers.vary ?? '')
    && (headers['x-content-type-options'] ?? '').toLowerCase() === 'nosniff';
}
async function checkPersonalisation(page, context, login, input, homeHTML, initialResponse, checks, setCategory) {
  setCategory('personalisation');
  const fallback = await page.evaluate(html => {
    const document = new DOMParser().parseFromString(html, 'text/html');
    const section = document.querySelector('[data-petish-for-your-pet]');
    const body = section?.querySelector('[data-petish-for-your-pet-body]');
    return body && document.querySelectorAll('[data-petish-for-your-pet]').length === 1
      && !body.querySelector('.ph-product') ? body.innerHTML : null;
  }, homeHTML);
  checks.personalisation_shell_private_verified = typeof fallback === 'string' && fallback.length > 50
    && !fallback.includes(input.customer_email) && !fallback.includes(input.login)
    && fallback.includes('Sign in and add a Petish Passport');
  requireCheck(checks.personalisation_shell_private_verified, 'personalisation');
  const response = await initialResponse; requireCheck(!!response, 'personalisation');
  const payload = await response.json();
  requireCheck(response.ok() && payload?.success === true && typeof payload.data?.html === 'string'
    && payload.data.html.length > 30 && payload.data.html.length < 1048576, 'personalisation');
  await page.waitForFunction(() => {
    const section = document.querySelector('[data-petish-for-your-pet]');
    return section && !section.querySelector('[data-petish-for-your-pet-body]').hasAttribute('aria-busy')
      && section.querySelector('[data-petish-for-your-pet-error]').hidden
      && section.querySelector('[data-petish-for-your-pet-status]').textContent.trim();
  });
  checks.personalisation_ajax_verified = await page.locator(`${PERSONAL} [data-petish-for-your-pet-body]`).evaluate((body, html) => {
    const comparison = document.createElement('div'); comparison.innerHTML = html;
    return body.innerHTML === comparison.innerHTML && body.textContent.trim().length > 20
      && !body.querySelector('script,iframe,form');
  }, payload.data.html);
  requireCheck(checks.personalisation_ajax_verified, 'personalisation');
  // Guest endpoint access is intentionally public but never cacheable. The
  // separate request context carries no member authentication cookies.
  const guest = await context.browser().newContext({ serviceWorkers: 'block', acceptDownloads: false });
  try {
    const guestResponse = await guest.request.get(PERSONAL_ENDPOINT, { maxRedirects: 0, timeout: 45000 });
    const guestPayload = await guestResponse.json();
    checks.personalisation_no_store_verified = privateFragmentHeaders(await response.allHeaders())
      && privateFragmentHeaders(guestResponse.headers())
      && guestResponse.ok() && guestPayload?.success === true && typeof guestPayload.data?.html === 'string'
      && guestPayload.data.html.includes('Sign in and choose your pet')
      && !guestPayload.data.html.includes('class="ph-product"')
      && !guestPayload.data.html.includes(input.customer_email) && !guestPayload.data.html.includes(input.login);
  } finally { await guest.close(); }
  requireCheck(checks.personalisation_no_store_verified, 'personalisation');
  let fits = true;
  for (const width of [320, 390, 1280]) {
    await page.setViewportSize({ width, height: 1000 });
    fits = fits && await page.locator(PERSONAL).evaluate(section => {
      const box = section.getBoundingClientRect();
      return box.width > 200 && box.left >= -1 && box.right <= innerWidth + 1
        && [...section.querySelectorAll('a,button,.ph-product')].every(node => {
          const rect = node.getBoundingClientRect();
          return !rect.width || (rect.left >= box.left - 1 && rect.right <= box.right + 1);
        });
    });
  }
  checks.personalisation_layout_verified = fits; requireCheck(fits, 'personalisation');
  setCategory('personalisation_error'); login.blockPersonal = true;
  await page.reload({ waitUntil: 'load' });
  await page.locator('[data-petish-for-your-pet-error]').waitFor({ state: 'visible' });
  requireCheck(await page.locator('[data-petish-for-your-pet-body]').innerHTML() === fallback, 'personalisation_error');
  login.blockPersonal = false;
  const retried = page.waitForResponse(value => value.url() === PERSONAL_ENDPOINT);
  await page.locator('[data-petish-for-your-pet-retry]').click();
  const retryResponse = await retried;
  await page.locator('[data-petish-for-your-pet-error]').waitFor({ state: 'hidden' });
  await page.waitForFunction(() => !document.querySelector('[data-petish-for-your-pet-body]').hasAttribute('aria-busy'));
  checks.personalisation_error_retry_verified = retryResponse.ok()
    && (await page.locator('[data-petish-for-your-pet-status]').textContent()).trim().length > 0;
  requireCheck(checks.personalisation_error_retry_verified, 'personalisation_error');
  setCategory('personalisation_history');
  await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true })));
  requireCheck(await page.locator('[data-petish-for-your-pet-body]').innerHTML() === fallback, 'personalisation_history');
  const restored = page.waitForResponse(value => value.url() === PERSONAL_ENDPOINT);
  await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
  const restoredResponse = await restored;
  await page.waitForFunction(() => !document.querySelector('[data-petish-for-your-pet-body]').hasAttribute('aria-busy'));
  checks.personalisation_history_reset_verified = restoredResponse.ok()
    && (await page.locator('[data-petish-for-your-pet-status]').textContent()).trim().length > 0;
  requireCheck(checks.personalisation_history_reset_verified, 'personalisation_history');
}

/** Only bounded header measurements leave the page; never labels, URLs or account data. */
export async function inspectThemeGeometry(page, probeCandidate = false) {
  const result = await page.evaluate(() => {
    const wrappers = [...document.querySelectorAll('[data-petish-theme-switch]')];
    const visible = node => {
      const rect = node.getBoundingClientRect(), css = getComputedStyle(node);
      return rect.width > 0 && rect.height > 0 && rect.bottom > 0 && rect.top < innerHeight
        && rect.right > 0 && rect.left < innerWidth
        && css.visibility !== 'hidden' && css.display !== 'none' && Number(css.opacity) > 0;
    };
    const measure = node => {
      if (!node) return {};
      const box = node.getBoundingClientRect(), css = getComputedStyle(node);
      const metric = key => Number.isFinite(parseFloat(css[key])) ? parseFloat(css[key]) : -1;
      return { left: box.left, right: box.right, width: box.width, height: box.height,
        client_width: node.clientWidth, scroll_width: node.scrollWidth, padding_left: metric('paddingLeft'), padding_right: metric('paddingRight'),
        margin_left: metric('marginLeft'), margin_right: metric('marginRight'), flex_basis: metric('flexBasis'),
        flex_grow: metric('flexGrow'), flex_shrink: metric('flexShrink'), min_width: metric('minWidth'), max_width: metric('maxWidth'), gap: metric('columnGap') };
    };
    const current = wrappers.filter(node => visible(node) && visible(node.querySelector('[data-petish-theme-toggle]')));
    const records = current.map(wrapper => {
      const button = wrapper.querySelector('[data-petish-theme-toggle]');
      const header = wrapper.closest('.header-bar,.mobile-header-bar') || wrapper.closest('#phantom,.masthead');
      const siblings = [...wrapper.parentElement.children], index = siblings.indexOf(wrapper);
      const search = siblings.slice(0, index).reverse().find(node => node.matches('.mini-search'));
      const market = siblings.slice(index + 1).find(node => node.matches('[data-petish-market]'));
      const logos = header ? [...header.querySelectorAll('.branding img,.mobile-branding img,[data-petish-logo]')].filter(visible) : [];
      const logo = logos[0];
      const branding = logo?.closest('.branding,.mobile-branding') || header?.querySelector('.mobile-branding,.branding');
      const menu = header && [...header.querySelectorAll('.dt-mobile-menu-icon,.mobile-navigation,.menu-toggle')].find(visible);
      const layout = Object.fromEntries(Object.entries({ header, branding, widgets: wrapper.parentElement, menu }).flatMap(([name, node]) =>
        Object.entries(measure(node)).map(([metric, value]) => [`${name}_${metric}`, value])));
      for (const name of ['search', 'switch', 'market', 'other']) {
        const children = [...wrapper.parentElement.children].filter(node => {
          const category = node.matches('.mini-search') ? 'search' : node.matches('[data-petish-theme-switch]') ? 'switch' : node.matches('[data-petish-market]') ? 'market' : 'other';
          return category === name;
        });
        layout[`${name}_count`] = children.length;
        for (const metric of ['width', 'margin_left', 'margin_right']) layout[`${name}_${metric}`] = children.reduce((sum, node) =>
          sum + (getComputedStyle(node).display === 'none' ? 0 : (measure(node)[metric] ?? 0)), 0);
      }
      return { wrapper, button, header, search, market, logo, box: header?.getBoundingClientRect(),
        layout, menuBox: menu?.getBoundingClientRect(),
        logoCount: logos.length, logoParentWidth: (logo?.closest('.branding,.mobile-branding') || logo?.parentElement)?.getBoundingClientRect().width,
        buttonBox: button.getBoundingClientRect(), searchBox: search?.getBoundingClientRect(),
        marketBox: market?.getBoundingClientRect(), logoBox: logo?.getBoundingClientRect(),
        controls: header ? [...header.querySelectorAll('.dt-mobile-menu-icon,.mobile-navigation,.menu-toggle,.mobile-mini-widgets,.mini-search,[data-petish-theme-switch],[data-petish-market]')].filter(visible).map(node => node.getBoundingClientRect()) : [] };
    });
    let order = records.length >= 1 && records.length <= 4, fit = order, height = order;
    const styles = wrappers.map(node => node.getAttribute('style'));
    try {
      wrappers.forEach(node => node.style.setProperty('display', 'none', 'important'));
      records.forEach(record => {
        record.baseline = record.header?.getBoundingClientRect().height;
        const logo = record.header && [...record.header.querySelectorAll('.branding img,.mobile-branding img,[data-petish-logo]')].find(visible);
        record.baselineLogo = logo?.getBoundingClientRect().width;
        record.baselineLogoParent = (logo?.closest('.branding,.mobile-branding') || logo?.parentElement)?.getBoundingClientRect().width;
      });
    } finally {
      wrappers.forEach((node, index) => styles[index] === null ? node.removeAttribute('style') : node.setAttribute('style', styles[index]));
    }
    const within = rect => rect && rect.width > 0 && rect.height > 0 && rect.left >= -1 && rect.right <= innerWidth + 1;
    for (const record of records) {
      const { button, box, buttonBox: b, searchBox: s, marketBox: m, logoBox: l, baseline } = record;
      order = order && !!s && !!m && s.right <= b.left + 1 && b.right <= m.left + 1;
      height = height && !!box && Number.isFinite(baseline) && box.height <= baseline + 1;
      fit = fit && within(box) && within(b) && within(s) && within(m) && b.width >= 43 && b.height >= 43
        && b.top >= box.top - 1 && b.bottom <= box.bottom + 1 && !!l && within(l) && l.width >= 48 && l.right <= s.left + 1
        && (innerWidth > 767 || !!record.menuBox)
        && (!record.menuBox || (within(record.menuBox) && m.right <= record.menuBox.left + 1))
        && record.controls.every(within)
        && button.type === 'button' && button.getAttribute('role') === 'switch'
        && button.getAttribute('aria-label') === 'Dark mode' && button.querySelectorAll('svg[aria-hidden="true"]').length === 2;
    }
    const first = records[0], number = value => Number.isFinite(value) ? Math.round(Math.max(-4096, Math.min(4096, value)) * 100) / 100 : 0;
    return { order: !!order, fit: !!fit, height: !!height, geometry: {
      ...Object.fromEntries(Object.entries(first?.layout ?? {}).map(([key, value]) => [key, number(value)])),
      header_height: number(first?.box?.height), baseline_height: number(first?.baseline), header_width: number(first?.box?.width),
      viewport_width: innerWidth, switch_left: number(first?.buttonBox?.left), switch_right: number(first?.buttonBox?.right),
      search_left: number(first?.searchBox?.left), search_right: number(first?.searchBox?.right), market_left: number(first?.marketBox?.left), market_right: number(first?.marketBox?.right),
      logo_right: number(first?.logoBox?.right), logo_width: number(first?.logoBox?.width), baseline_logo_width: number(first?.baselineLogo),
      logo_count: first?.logoCount ?? 0, logo_parent_width: number(first?.logoParentWidth),
      baseline_logo_parent_width: number(first?.baselineLogoParent), visible_count: current.length,
      overflow: number(Math.max(0, ...records.flatMap(record => [record.box?.right - innerWidth, record.buttonBox?.right - innerWidth,
        record.marketBox?.right - innerWidth, record.logoBox?.right - innerWidth, ...record.controls.map(rect => rect.right - innerWidth)]).filter(Number.isFinite))) } };
  });
  // Diagnostic only: the candidate is removed before the mandatory original
  // layout gates are evaluated. No server or application file is changed.
  if (probeCandidate && page.viewportSize()?.width <= 767) {
    const candidateStyle = await page.addStyleTag({ content: '@media(max-width:767px){body#the7-body.petish-site .mobile-header-bar .mobile-mini-widgets{flex:0 0 auto;max-width:none;}body#the7-body.petish-site .mobile-header-bar .mobile-branding{flex:1 1 0;min-width:0;}body#the7-body.petish-site .mobile-header-bar .mobile-branding>a{min-width:0;max-width:100%;}body#the7-body.petish-site .mobile-header-bar .mobile-navigation{flex:0 0 auto;}}' });
    try {
      await settleHeader(page);
      const candidate = await inspectThemeGeometry(page);
      Object.assign(result.geometry, { candidate_logo_width: candidate.geometry.logo_width,
        candidate_header_height: candidate.geometry.header_height, candidate_baseline_height: candidate.geometry.baseline_height,
        candidate_fit: Number(candidate.fit && candidate.order && candidate.height
          && candidate.geometry.header_height <= result.geometry.header_height + 1) });
    } finally { await candidateStyle.evaluate(node => node.remove()); await settleHeader(page); }
  }
  return result;
}

async function themeState(page, mode, preference = mode) {
  return page.evaluate(({ mode, preference }) => {
    const root = document.documentElement, switches = [...document.querySelectorAll('[data-petish-theme-toggle]')];
    const logos = [...document.querySelectorAll('[data-petish-logo]')];
    return root.dataset.theme === mode && root.dataset.themePreference === preference && switches.length >= 1 && switches.length <= 20
      && switches.every(button => button.getAttribute('aria-checked') === String(mode === 'dark')
        && button.title === `Switch to ${mode === 'dark' ? 'light' : 'dark'} mode` && !button.closest('[data-petish-theme-switch]').hidden)
      && logos.length >= 1 && logos.every(logo => logo.getAttribute('src') === logo.dataset[mode])
      && [...document.querySelectorAll('[data-petish-theme]')].every(select => select.value === preference);
  }, { mode, preference });
}

async function settleHeader(page) {
  await page.evaluate(async () => {
    await document.fonts.ready;
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  });
}

/** Browser-only appearance choices; no forms are submitted and no market endpoint is opened. */
export async function checkThemeSwitch(page, checks, diagnostics, setCategory, themeGeometry = {}) {
  const button = () => page.locator(`${THEME_SWITCH}:visible`).first();
  setCategory('theme_layout');
  let order = true, fit = true, height = true;
  for (const width of [320, 390, 1280]) {
    await page.setViewportSize({ width, height: 900 });
    await page.evaluate(() => window.scrollTo(0, 0));
    await settleHeader(page);
    for (const mode of ['light', 'dark']) {
      if (await page.evaluate(() => document.documentElement.dataset.theme) !== mode) await button().click();
      await settleHeader(page);
      const result = await inspectThemeGeometry(page, true);
      diagnostics[`theme_${width}_${mode}_order`] = result.order;
      diagnostics[`theme_${width}_${mode}_height`] = result.height;
      diagnostics[`theme_${width}_${mode}_fit`] = result.fit;
      themeGeometry[`${width}_${mode}`] = result.geometry;
      order = order && result.order; height = height && result.height; fit = fit && result.fit;
    }
  }
  checks.theme_switch_order_verified = order;
  checks.theme_switch_geometry_verified = height && fit;
  requireCheck(order && height && fit, 'theme_layout');
  setCategory('theme_interaction');
  await button().click();
  const pointerLight = await themeState(page, 'light');
  await button().click();
  checks.theme_switch_pointer_verified = pointerLight && await themeState(page, 'dark');
  await button().focus(); await button().press('Enter');
  const enterLight = await themeState(page, 'light');
  await button().press('Space');
  checks.theme_switch_keyboard_verified = enterLight && await themeState(page, 'dark')
    && await button().evaluate(node => document.activeElement === node && getComputedStyle(node).outlineStyle !== 'none');
  requireCheck(checks.theme_switch_pointer_verified && checks.theme_switch_keyboard_verified, 'theme_interaction');
  setCategory('theme_persistence');
  const stored = await page.evaluate(() => localStorage.getItem('petish-theme') === 'dark');
  await page.reload({ waitUntil: 'load' });
  checks.theme_switch_persistence_verified = stored && await themeState(page, 'dark');
  requireCheck(checks.theme_switch_persistence_verified, 'theme_persistence');
  // Use the existing account Appearance select; navigation is read-only.
  await page.goto(ORIGIN + '/my-account/edit-account/', { waitUntil: 'load' });
  const select = page.locator('.petish-account-appearance [data-petish-theme]');
  await select.selectOption('light');
  const selectedLight = await themeState(page, 'light');
  await page.evaluate(() => window.scrollTo(0, 0)); await button().click();
  checks.theme_switch_select_sync_verified = selectedLight && await themeState(page, 'dark') && await select.inputValue() === 'dark';
  requireCheck(checks.theme_switch_select_sync_verified, 'theme_interaction');
  setCategory('theme_system');
  await page.emulateMedia({ colorScheme: 'dark' });
  await select.selectOption('system');
  const systemDark = await themeState(page, 'dark', 'system');
  await page.emulateMedia({ colorScheme: 'light' });
  await page.waitForFunction(() => document.documentElement.dataset.theme === 'light');
  const systemLight = await themeState(page, 'light', 'system');
  await page.evaluate(() => localStorage.removeItem('petish-theme'));
  await page.reload({ waitUntil: 'load' });
  checks.theme_switch_system_verified = systemDark && systemLight && await themeState(page, 'light', 'system');
  requireCheck(checks.theme_switch_system_verified, 'theme_system');
  setCategory('theme_interaction');
  // Exercise a late The7-style copy in the actual page; remove this inert DOM fixture before leaving.
  try {
    await page.evaluate(() => {
      const source = document.querySelector('[data-petish-theme-switch]');
      const clone = source.cloneNode(true); clone.id = 'petish-theme-proof-clone'; clone.hidden = true;
      clone.style.cssText = 'position:fixed!important;left:4px!important;bottom:4px!important;z-index:2147483647!important';
      clone.querySelector('[data-petish-theme-toggle]').setAttribute('aria-checked', 'true');
      document.body.append(clone);
    });
    const cloneButton = page.locator('#petish-theme-proof-clone [data-petish-theme-toggle]');
    await cloneButton.waitFor({ state: 'visible' });
    const clonedState = await themeState(page, 'light', 'system');
    await cloneButton.click();
    checks.theme_switch_clones_verified = clonedState && await themeState(page, 'dark')
      && await page.evaluate(() => localStorage.getItem('petish-theme') === 'dark');
  } finally { await page.locator('#petish-theme-proof-clone').evaluateAll(nodes => nodes.forEach(node => node.remove())); }
  requireCheck(checks.theme_switch_clones_verified, 'theme_interaction');
}

/** Public AU flag only: no page image or customer content leaves browser memory. */
export async function inspectMarketFlags(page) {
  const valid = await page.evaluate(({ origin, sprite }) => {
    const markets = [...document.querySelectorAll('[data-petish-market]')];
    return markets.length >= 1 && markets.length <= 8 && markets.every(market => {
      const flags = market.querySelectorAll('svg.petish-market__flag');
      if (flags.length !== 1 || /[\u{1F1E6}-\u{1F1FF}]/u.test(market.textContent)) return false;
      const flag = flags[0], uses = flag.querySelectorAll('use');
      if (flag.getAttribute('aria-hidden') !== 'true' || flag.getAttribute('focusable') !== 'false'
          || flag.getAttribute('viewBox') !== '0 0 512 512' || uses.length !== 1 || flag.textContent.trim()) return false;
      const url = new URL(uses[0].getAttribute('href'), location.href);
      if (url.origin !== origin || url.pathname !== sprite || url.hash !== '#flag-au'
          || !/^\?ver=\d+$/.test(url.search)) return false;
      const box = flag.getBoundingClientRect();
      if (!box.width || !box.height || getComputedStyle(flag).visibility === 'hidden') return true;
      const painted = uses[0].getBBox();
      const expected = innerWidth <= 767 ? 20 : 22;
      return Math.abs(box.width - expected) <= 1 && Math.abs(box.height - expected) <= 1
        && painted.width > 0 && painted.height > 0 && box.left >= 0 && box.right <= innerWidth;
    });
  }, { origin: ORIGIN, sprite: FLAG_PATH });
  if (!valid) return false;
  const flag = page.locator('svg.petish-market__flag:visible').first();
  if (!await flag.count()) return false;
  // Crop only the known decorative flag. Decode in the same browser to prove
  // actual external-use/mask paint, rather than accepting an empty SVG box.
  const bytes = await flag.screenshot({ type: 'png', animations: 'disabled' });
  return page.evaluate(async base64 => {
    const image = new Image(); image.src = 'data:image/png;base64,' + base64;
    await image.decode();
    if (image.width > 88 || image.height > 88 || image.width < 20 || image.height < 20) return false;
    const canvas = document.createElement('canvas'); canvas.width = image.width; canvas.height = image.height;
    const context = canvas.getContext('2d'); if (!context) return false;
    context.drawImage(image, 0, 0);
    const pixels = context.getImageData(0, 0, image.width, image.height).data;
    let blue = 0, red = 0, white = 0;
    for (let i = 0; i < pixels.length; i += 4) {
      const [r, g, b, a] = pixels.slice(i, i + 4); if (a < 200) continue;
      if (b > 70 && b > r * 1.4 && b > g * 1.25) blue++;
      if (r > 110 && r > g * 1.6 && r > b * 1.3) red++;
      if (r > 145 && g > 145 && b > 145 && Math.max(r, g, b) / Math.min(r, g, b) < 1.15) white++;
    }
    return blue >= 30 && red >= 3 && white >= 4;
  }, bytes.toString('base64'));
}

/** Fixed read-only routes, synthetic account, no market or cart actions. */
export async function checkMarketFlagPage(page, pathname, widths = [320, 390, 1280]) {
  if (!['/', '/cart/'].includes(pathname)) return false;
  const response = await page.goto(ORIGIN + pathname, { waitUntil: 'load' });
  if (!response?.ok() || new URL(page.url()).pathname !== pathname) return false;
  if (pathname === '/cart/' && !(await page.locator('.cart-empty, .wc-block-cart__empty-cart__title').count())) return false;
  if (pathname === '/cart/' && await page.locator('.cart_item, .wc-block-cart-items__row').count()) return false;
  for (const width of widths) for (const mode of ['light', 'dark']) {
    await page.setViewportSize({ width, height: 900 });
    await page.evaluate(() => scrollTo(0, 0));
    if (await page.locator('html').getAttribute('data-theme') !== mode) await page.locator(`${THEME_SWITCH}:visible`).first().click();
    await page.evaluate(async () => { await document.fonts.ready; await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))); });
    const geometry = await inspectThemeGeometry(page);
    if (!geometry.order || !geometry.height || !geometry.fit || !await inspectMarketFlags(page)) return false;
  }
  return true;
}

async function checkWebKitFlags(webkit, storageState) {
  if (!webkit) return false;
  const browser = await webkit.launch({ headless: true });
  try {
    const { context } = await readOnlyContext(browser, storageState);
    const page = await context.newPage(); page.setDefaultTimeout(15000); page.setDefaultNavigationTimeout(45000);
    return await checkMarketFlagPage(page, '/', [320, 390]) && await checkMarketFlagPage(page, '/cart/', [320, 390]);
  } finally { await browser.close(); }
}

export async function run(input, chromium, options = {}) {
  const checks = Object.fromEntries(CHECK_KEYS.map(key => [key, false]));
  const diagnostics = Object.fromEntries(DIAGNOSTIC_KEYS.map(key => [key, false]));
  const imageDimensions = Object.fromEntries(IMAGE_DIMENSION_KEYS.map(key => [key, 0]));
  const themeGeometry = {};
  let browser, category = 'input', sectionPresent = false;
  const report = (status, category) => publicReport({ status, category, section_present: sectionPresent, image_dimensions: imageDimensions, theme_geometry: themeGeometry, diagnostics, checks });
  try {
    validateInput(input);
    if (input.expected_section_present !== undefined && typeof input.expected_section_present !== 'boolean') throw new Error('input');
    if (input.capture_badge_response !== undefined && typeof input.capture_badge_response !== 'boolean') throw new Error('input');
    const expectedCSS = hash(fs.readFileSync(new URL('assets/css/home.css', THEME)));
    const expectedThemeScript = hash(fs.readFileSync(new URL('assets/js/theme.js', THEME)));
    const expectedDesignCSS = hash(fs.readFileSync(new URL('assets/css/petish.css', THEME)));
    const expectedMarketAssets = new Map([MARKET_SCRIPT_PATH, MARKET_CSS_PATH, FLAG_PATH].map(asset =>
      [asset, hash(fs.readFileSync(new URL(asset.split('/dt-the7-child/')[1], THEME)))]));
    const expectedPersonalScript = hash(fs.readFileSync(new URL('assets/js/for-your-pet.js', THEME)));
    const expectedBadgeBytes = fs.readFileSync(new URL('assets/images/ui/free-delivery-van.png', THEME));
    const expectedBadge = hash(expectedBadgeBytes);
    const expectedDimensions = pngDimensions(expectedBadgeBytes);
    imageDimensions.expected_width = expectedDimensions.width;
    imageDimensions.expected_height = expectedDimensions.height;
    category = 'browser';
    browser = await chromium.launch(launchOptions(chromium));
    checks.browser_started = true;
    const { context, login } = await readOnlyContext(browser);
    checks.readonly_requests_enforced = !allowedHomepageRequest(`${ORIGIN}/wp-json/petish-fulfilment/v1/suppliers`, 'GET')
      && !allowedHomepageRequest('https://petish.biz/', 'GET')
      && !allowedRequest(`${ORIGIN}/checkout/`, 'GET')
      && !allowedRequest(`${ORIGIN}/?add-to-cart=1`, 'GET')
      && !allowedRequest(`${ORIGIN}/my-account/edit-account/`, 'POST', true)
      && allowedHomepageRequest(PERSONAL_ENDPOINT, 'GET')
      && !allowedHomepageRequest(PERSONAL_ENDPOINT + '&user_id=1', 'GET')
      && !allowedHomepageRequest(PERSONAL_ENDPOINT, 'POST')
      && allowedHomepageRequest(`${ORIGIN}/cart/`, 'GET')
      && !allowedHomepageRequest(`${ORIGIN}/cart/?remove_item=1`, 'GET')
      && !allowedHomepageRequest(`${ORIGIN}/cart/`, 'POST')
      && !allowedHomepageRequest(`${ORIGIN}/?wc-ajax=petish_market_state`, 'GET');
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
      if (url.origin !== ORIGIN || ![CSS_PATH, BADGE_PATH, PERSONAL_SCRIPT_PATH, THEME_SCRIPT_PATH, DESIGN_CSS_PATH, MARKET_SCRIPT_PATH, MARKET_CSS_PATH, FLAG_PATH].includes(url.pathname)) return;
      const task = response.body().then(body => assetRecords.set(url.pathname, { ok: response.ok(), hash: hash(body) }))
        .catch(() => assetRecords.set(url.pathname, { ok: false, hash: null }));
      assetTasks.push(task);
    });
    const personalResponse = page.waitForResponse(response => response.url() === PERSONAL_ENDPOINT).catch(() => null);
    const homeResponse = await page.goto(ORIGIN + '/', { waitUntil: 'domcontentloaded' });
    checks.homepage_http_ok = !!homeResponse?.ok() && new URL(page.url()).origin === ORIGIN
      && new URL(page.url()).pathname === '/' && await page.locator('.petish-home').count() === 1;
    requireCheck(checks.homepage_http_ok, 'homepage');
    const homeHTML = await homeResponse.text();
    category = 'subtitle';
    checks.hot_subtitle_absent = await page.locator('#petish-hot-items .ph-section-heading p').count() === 0
      && !await page.locator('.petish-home').evaluate(node => node.textContent.includes('Selected by supplier-reported orders.'));
    requireCheck(checks.hot_subtitle_absent, 'subtitle');
    category = 'assets';
    const cssEnqueued = await page.locator('link[rel="stylesheet"]').evaluateAll((links, expected) =>
      links.some(link => { try { const u = new URL(link.href); return u.origin === expected.origin && u.pathname === expected.path; } catch { return false; } }),
    { origin: ORIGIN, path: CSS_PATH });
    await page.waitForLoadState('load'); await Promise.allSettled(assetTasks);
    const css = assetRecords.get(CSS_PATH);
    checks.home_css_http_ok = cssEnqueued && css?.ok === true;
    checks.home_css_hash_matches = cssEnqueued && css?.hash === expectedCSS;
    checks.personalisation_script_http_verified = assetRecords.get(PERSONAL_SCRIPT_PATH)?.ok === true
      && assetRecords.get(PERSONAL_SCRIPT_PATH)?.hash === expectedPersonalScript;
    checks.theme_assets_http_verified = assetRecords.get(THEME_SCRIPT_PATH)?.ok === true
      && assetRecords.get(THEME_SCRIPT_PATH)?.hash === expectedThemeScript
      && assetRecords.get(DESIGN_CSS_PATH)?.ok === true && assetRecords.get(DESIGN_CSS_PATH)?.hash === expectedDesignCSS;
    requireCheck(checks.theme_assets_http_verified, 'theme_assets');
    checks.market_flag_assets_verified = [...expectedMarketAssets].every(([asset, expected]) =>
      assetRecords.get(asset)?.ok === true && assetRecords.get(asset)?.hash === expected);
    requireCheck(checks.market_flag_assets_verified, 'market_flag_assets');
    // Request the reviewed PNG even when no supplier products qualify, so an
    // intentionally absent section cannot hide an unreadable deployment asset.
    const fetchBadge = pathname => page.evaluate(async target => {
      const response = await fetch(target, { credentials: 'same-origin', cache: 'reload' });
      return { ok: response.ok, type: response.headers.get('content-type') ?? '',
        bytes: Array.from(new Uint8Array(await response.arrayBuffer())) };
    }, pathname);
    const png = await fetchBadge(BADGE_PATH);
    const responseBytes = Buffer.from(png.bytes);
    const responseDimensions = pngDimensions(responseBytes);
    imageDimensions.response_width = responseDimensions.width;
    imageDimensions.response_height = responseDimensions.height;
    const primary = await inspectPNGImages(page, expectedBadgeBytes, responseBytes);
    Object.assign(diagnostics, primary.diagnostics);
    diagnostics.badge_response_content_type_png = /^image\/png(?:;|$)/i.test(png.type);
    diagnostics.badge_response_content_type_webp = /^image\/webp(?:;|$)/i.test(png.type);
    diagnostics.badge_response_content_type_avif = /^image\/avif(?:;|$)/i.test(png.type);
    diagnostics.badge_response_content_type_html = /^(?:text\/html|application\/xhtml\+xml)(?:;|$)/i.test(png.type);
    checks.badge_png_http_ok = png.ok === true;
    checks.badge_png_image_matches = png.ok && primary.matches;
    if (input.capture_badge_response === true && options.badgeResponsePath
        && !checks.badge_png_image_matches) {
      saveBadgeResponse(options.badgeResponsePath, responseBytes, png.type, diagnostics.badge_response_decode_ok);
    }
    if (!checks.badge_png_image_matches) {
      // Diagnostic only: this cannot certify the unversioned customer URL.
      // A successful alternate requires an app-source version change/review.
      diagnostics.badge_fetch_versioned = true;
      try {
        const alternate = await fetchBadge(BADGE_PATH + '?ver=' + expectedBadge);
        diagnostics.badge_versioned_image_matches = alternate.ok
          && await pngImagesMatch(page, expectedBadgeBytes, Buffer.from(alternate.bytes));
      } catch {}
    }
    requireCheck(checks.home_css_http_ok && checks.home_css_hash_matches
      && checks.badge_png_http_ok && checks.badge_png_image_matches, 'assets');
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
    await checkPersonalisation(page, context, login, input, homeHTML, personalResponse, checks, value => { category = value; });
    await checkThemeSwitch(page, checks, diagnostics, value => { category = value; }, themeGeometry);
    category = 'market_flag_layout';
    checks.market_flag_header_verified = await checkMarketFlagPage(page, '/');
    requireCheck(checks.market_flag_header_verified, 'market_flag_layout');
    checks.market_flag_cart_verified = await checkMarketFlagPage(page, '/cart/');
    requireCheck(checks.market_flag_cart_verified, 'market_flag_layout');
    checks.market_flag_webkit_verified = await checkWebKitFlags(options.webkit, await context.storageState());
    requireCheck(checks.market_flag_webkit_verified, 'market_flag_layout');
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
    badge_bottom_right: false, hot_nonoverlap: false, absence_valid: false,
    lossless_recompression_accepted: false, altered_pixels_rejected: false, changed_dimensions_rejected: false };
  try {
    const css = ['assets/css/petish.css', 'assets/css/supplier-popularity.css', 'assets/css/home.css']
      .map(file => fs.readFileSync(new URL(file, THEME), 'utf8')).join('\n');
    const dataImage = file => 'data:image/png;base64,' + fs.readFileSync(new URL(file, THEME)).toString('base64');
    const badge = dataImage('assets/images/ui/free-delivery-van.png');
    const flame = dataImage('assets/images/ui/hot-flame.png');
    const product = 'data:image/svg+xml;base64,' + Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400"><rect width="400" height="400" fill="#d9e9e2"/><circle cx="200" cy="200" r="110" fill="#b8d0c4"/></svg>').toString('base64');
    const cards = Array.from({ length: 4 }, () => `<article class="ph-product"><a class="ph-product-link" href="#"><span class="ph-product-image"><img src="${product}" alt="Synthetic product"><span class="petish-hot-badge petish-hot-badge--icon"><img class="petish-hot-badge__icon" src="${flame}" alt=""><span class="petish-hot-badge__label">Hot</span></span><span class="petish-free-delivery-badge"><img src="${badge}" width="1600" height="592" alt="Free delivery"></span></span><div class="ph-product-body"><h3>Synthetic fixture product</h3><span class="ph-product-price">Sample</span></div></a></article>`).join('');
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
    const reviewedPNG = fs.readFileSync(new URL('assets/images/ui/free-delivery-van.png', THEME));
    const recompressed = recompressPNG(reviewedPNG);
    checks.lossless_recompression_accepted = hash(reviewedPNG) !== hash(recompressed)
      && await pngImagesMatch(page, reviewedPNG, recompressed);
    const tampered = await page.evaluate(async bytes => {
      const bitmap = await createImageBitmap(new Blob([new Uint8Array(bytes)], { type: 'image/png' }));
      const canvas = new OffscreenCanvas(bitmap.width, bitmap.height), context = canvas.getContext('2d');
      context.drawImage(bitmap, 0, 0); bitmap.close();
      const point = context.getImageData(0, 0, 1, 1);
      point.data[0] ^= 255; point.data[1] ^= 255; point.data[2] ^= 255; point.data[3] = 255;
      context.putImageData(point, 0, 0);
      const changed = await canvas.convertToBlob({ type: 'image/png' });
      const tinyCanvas = new OffscreenCanvas(1, 1);
      tinyCanvas.getContext('2d').fillRect(0, 0, 1, 1);
      const tiny = await tinyCanvas.convertToBlob({ type: 'image/png' });
      return { changed: Array.from(new Uint8Array(await changed.arrayBuffer())),
        tiny: Array.from(new Uint8Array(await tiny.arrayBuffer())) };
    }, Array.from(reviewedPNG));
    checks.altered_pixels_rejected = !await pngImagesMatch(page, reviewedPNG, Buffer.from(tampered.changed));
    checks.changed_dimensions_rejected = !await pngImagesMatch(page, reviewedPNG, Buffer.from(tampered.tiny));

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
    const { chromium, webkit } = require('playwright');
    result = await run(input, chromium, { webkit, badgeResponsePath: input.capture_badge_response === true
      ? path.join(path.dirname(path.resolve(process.argv[3])), 'badge-response.png') : null });
  } catch {}
  try {
    if (process.argv.length !== 4) throw new Error('input');
    writeReport(process.argv[3], result);
  } catch { result = publicReport({ category: 'input' }); }
  process.stdout.write(JSON.stringify(result) + '\n');
  if (result.status !== 'success') process.exitCode = 1;
}
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) await main();
