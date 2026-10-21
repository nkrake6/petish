/** Hosted Pet Club checks. All writes are confined to two newly owned staging users. */
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { deflateSync } from 'node:zlib';

const ORIGIN = 'https://staging.petish.biz';
const ROOT = '.petish-passport.petish-club';
const SAFETY = ['environment_staging', 'staging_database_separate', 'guard_runtime_verified',
  'owned_fixture_only', 'suppliers_blocked', 'mail_blocked', 'payments_blocked'];
export const CHECK_KEYS = Object.freeze([
  'browser_started', 'owner_login_verified', 'member_login_verified', 'owned_passport_displayed',
  'mobile_390_verified', 'mobile_320_verified', 'desktop_layout_verified', 'account_menu_verified',
  'tabs_mouse_verified', 'tabs_keyboard_verified', 'private_preview_verified',
  'lightbox_verified', 'lightbox_escape_focus_verified', 'edit_form_verified',
  'real_photo_upload_verified', 'owner_private_photo_http_verified',
  'anonymous_private_photos_denied', 'foreign_private_photos_denied',
  'no_js_fallback_verified', 'dark_mode_readable', 'explicit_publish_verified',
  'public_profile_http_verified', 'public_projection_verified', 'public_photo_http_verified',
  'public_responses_uncacheable', 'member_heart_post_verified', 'member_heart_remove_post_verified',
  'unpublish_verified', 'revoked_profile_http_denied',
  'revoked_photo_http_denied', 'unsafe_requests_blocked',
]);
export const FORM_CATEGORIES = Object.freeze([
  'form_identity', 'form_values', 'variety_default', 'variety_enhancement', 'variety_hidden', 'checkbox_spacing',
  'variety_other', 'variety_species_reset', 'variety_selection', 'form_saved',
]);
const CATEGORIES = new Set(['input', 'browser', 'login', 'owner', 'mobile', 'desktop', 'navigation',
  'tabs', 'preview', 'lightbox', 'form', 'upload', 'private_photos', 'no_js', 'dark_mode',
  'publish', 'public_profile', 'public_photo', 'hearts', 'unpublish', 'revocation', 'network', 'complete',
  ...FORM_CATEGORIES]);

export function validateInput(value) {
  if (!value || value.format !== 'petish-club-browser-v1' || value.url !== ORIGIN
      || !/^[1-9][0-9]{0,31}-[1-9][0-9]{0,8}$/.test(value.run ?? '')
      || !['pet_id', 'photo_moment_id', 'journal_moment_id'].every(key => Number.isSafeInteger(value[key]) && value[key] > 0)
      || value.photo_moment_id === value.journal_moment_id || value.name !== 'Petish Club Test Rabbit'
      || !SAFETY.every(key => value.safety?.[key] === true)) throw new Error('input');
  for (const role of ['owner', 'member']) {
    const user = value[role];
    if (!user || !Number.isSafeInteger(user.id) || user.id < 1
        || !new RegExp(`^petish-club-${role}-[a-f0-9]{18}$`).test(user.login ?? '')
        || user.email !== `${user.login}@petish.invalid` || !/^[a-f0-9]{48}$/.test(user.password ?? '')) throw new Error('input');
  }
  if (value.owner.id === value.member.id) throw new Error('input');
  const photo = new URL(value.private_photo_url), moment = new URL(value.private_moment_url);
  if (photo.href !== `${ORIGIN}/wp-admin/admin-post.php?action=petish_pet_photo&pet_id=${value.pet_id}`
      || moment.href !== `${ORIGIN}/wp-admin/admin-post.php?action=petish_pet_moment_photo&moment_id=${value.photo_moment_id}`) throw new Error('input');
  return value;
}

function fieldsFor(body, contentType) {
  if (!contentType.startsWith('multipart/form-data;')) return new URLSearchParams(body);
  const boundary = /boundary=(?:"([^"]+)"|([^;\s]+))/.exec(contentType);
  if (!boundary) throw new Error('network');
  const parts = body.split(`--${boundary[1] || boundary[2]}`), fields = new URLSearchParams();
  for (const part of parts) {
    const match = /^\r\nContent-Disposition: form-data; name="([^"\r\n]+)"(?:; filename="[^"\r\n]*")?\r\n/i.exec(part);
    if (!match || /; filename=/.test(match[0])) continue;
    const separator = part.indexOf('\r\n\r\n');
    if (separator < 0 || !part.endsWith('\r\n')) throw new Error('network');
    fields.append(match[1], part.slice(separator + 4, -2));
  }
  return fields;
}

/** POST windows allow only the submitted native form for the owned fixture pet. */
export function allowedRequest(raw, method = 'GET', state = {}, body = '', type = 'document', contentType = '') {
  try {
    const url = new URL(raw), input = state.input;
    if (url.origin !== ORIGIN || url.username || url.password || (url.port && url.port !== '443')) return false;
    if (/\/(?:checkout|cart|customer-logout|logout|wc-api)(?:\/|$)/i.test(url.pathname)
        || /\/wp-json\/(?:wc|wc-admin|wc-analytics)(?:\/|$)/i.test(url.pathname)
        || [...url.searchParams.keys()].some(key => /^(?:add-to-cart|remove_item|undo_item|wc-ajax|wc-api|_method|delete|logout)$/i.test(key))) return false;
    if (method === 'GET' || method === 'HEAD') {
      if (!url.pathname.startsWith('/wp-admin')) return !url.searchParams.has('action');
      if (url.pathname !== '/wp-admin/admin-post.php' || !input) return false;
      const action = url.searchParams.get('action');
      return (action === 'petish_pet_photo' && url.searchParams.get('pet_id') === String(input.pet_id)
          && [...url.searchParams.keys()].every(key => ['action', 'pet_id', 'v'].includes(key)))
        || (action === 'petish_pet_moment_photo' && url.searchParams.get('moment_id') === String(input.photo_moment_id)
          && [...url.searchParams.keys()].every(key => ['action', 'moment_id', 'v'].includes(key)))
        || (action === 'petish_club_photo' && /^[a-f0-9]{32}$/.test(state.token ?? '')
          && url.searchParams.get('token') === state.token && [...url.searchParams.keys()].every(key => ['action', 'token', 'moment'].includes(key)));
    }
    if (method !== 'POST' || type !== 'document' || !state.operation || state.used || url.search) return false;
    const fields = fieldsFor(body, contentType);
    if (state.operation === 'login') return url.pathname === '/wp-login.php'
      && fields.get('log') === state.user?.login && fields.get('pwd') === state.user?.password
      && fields.get('redirect_to') === ORIGIN + '/my-account/' && !fields.has('action');
    if (url.pathname !== '/wp-admin/admin-post.php' || !input || fields.get('action') !== state.operation
        || fields.getAll('action').length !== 1) return false;
    if (state.operation === 'petish_club_heart') return /^[a-f0-9]{32}$/.test(state.token ?? '')
      && fields.getAll('token').length === 1 && fields.get('token') === state.token
      && ['0', '1'].includes(state.desiredHeart) && fields.getAll('hearted').length === 1
      && fields.get('hearted') === state.desiredHeart && !fields.has('pet_id')
      && /^[a-zA-Z0-9]{10}$/.test(fields.get('petish_club_nonce') ?? '');
    if (fields.getAll('pet_id').length !== 1
        || fields.get('pet_id') !== String(input.pet_id)) return false;
    if (state.operation === 'petish_passport_update') return /^[a-zA-Z0-9]{10}$/.test(fields.get('petish_passport_nonce') ?? '')
      && fields.get('name') === input.name && fields.get('species') === 'rabbit'
      && fields.getAll('variety').length === 1 && fields.get('variety') === 'mini-lop'
      && fields.getAll('variety_other').length <= 1 && (fields.get('variety_other') ?? '') === '';
    if (!/^[a-zA-Z0-9]{10}$/.test(fields.get('petish_club_nonce') ?? '')) return false;
    if (state.operation === 'petish_club_unpublish') return true;
    return state.operation === 'petish_club_publish' && fields.get('confirm_share') === '1'
      && !fields.has('share_age') && fields.getAll('moment_ids[]').length === 1
      && fields.get('moment_ids[]') === String(input.photo_moment_id);
  } catch { return false; }
}

export function publicReport(value) {
  const checks = Object.fromEntries(CHECK_KEYS.map(key => [key, value?.checks?.[key] === true]));
  const success = value?.status === 'success' && value?.category === 'complete' && Object.values(checks).every(Boolean);
  return { status: success ? 'success' : 'failure', category: success ? 'complete'
    : (CATEGORIES.has(value?.category) && value.category !== 'complete' ? value.category : 'input'), checks };
}
function ensure(value, category) { if (!value) throw new Error(category); }
async function guard(context, state) {
  await context.route('**/*', async route => {
    const request = route.request();
    if (!allowedRequest(request.url(), request.method(), state, request.postData() ?? '', request.resourceType(), request.headers()['content-type'] ?? '')) {
      await route.abort('blockedbyclient'); return;
    }
    if (request.method() === 'POST') state.used = true;
    await route.continue();
  });
  if (context.routeWebSocket) await context.routeWebSocket('**/*', socket => socket.close());
}
async function login(browser, input, role) {
  const context = await browser.newContext({ viewport: { width: 390, height: 900 }, serviceWorkers: 'block',
    locale: 'en-AU', reducedMotion: 'reduce', acceptDownloads: false });
  const state = { input, user: input[role], operation: '', used: false, token: '' };
  await guard(context, state);
  const page = await context.newPage(); page.setDefaultTimeout(15000); page.setDefaultNavigationTimeout(45000);
  const response = await page.goto(`${ORIGIN}/wp-login.php?redirect_to=${encodeURIComponent(ORIGIN + '/my-account/')}`, { waitUntil: 'domcontentloaded' });
  ensure(response?.ok(), 'login'); await page.locator('#user_login').fill(state.user.login);
  await page.locator('#user_pass').fill(state.user.password);
  ensure(await page.locator('input[name="redirect_to"]').inputValue() === ORIGIN + '/my-account/', 'login');
  state.operation = 'login';
  await Promise.all([page.waitForURL(url => url.origin === ORIGIN && url.pathname.startsWith('/my-account/'), { waitUntil: 'domcontentloaded' }), page.locator('#wp-submit').click()]);
  ensure(state.used, 'login'); state.operation = '';
  await page.goto(ORIGIN + '/my-account/edit-account/', { waitUntil: 'domcontentloaded' });
  ensure(await page.locator('input[name="account_email"]').inputValue() === state.user.email, 'login');
  return { context, page, state };
}
async function dashboard(page) {
  const response = await page.goto(ORIGIN + '/my-account/my-pets/', { waitUntil: 'domcontentloaded' });
  ensure(response?.ok(), 'owner'); await page.locator(ROOT).waitFor({ state: 'visible' });
}
async function settled(page) { await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))); }
async function fit(page, width) {
  await page.setViewportSize({ width, height: 1000 }); await settled(page);
  return page.locator(ROOT).evaluate(node => {
    const box = node.getBoundingClientRect();
    return box.width > 200 && box.x >= -1 && box.right <= innerWidth + 1
      && document.documentElement.scrollWidth <= innerWidth + 1;
  });
}
function noStore(headers) { return /(?:^|,)\s*no-store(?:\s*,|$)/i.test(headers['cache-control'] ?? '')
  && !/(?:^|[, ])hit(?:[, ]|$)/i.test(headers['x-litespeed-cache'] ?? ''); }
async function safeGet(context, state, raw) {
  ensure(allowedRequest(raw, 'GET', state), 'network');
  return context.request.get(raw, { maxRedirects: 0, timeout: 45000 });
}
function syntheticPng() {
  const crc = data => { let n = 0xffffffff; for (const byte of data) { n ^= byte; for (let i = 0; i < 8; i++) n = (n >>> 1) ^ (0xedb88320 & -(n & 1)); } return (n ^ 0xffffffff) >>> 0; };
  const chunk = (name, data) => { const kind = Buffer.from(name), size = Buffer.alloc(4), sum = Buffer.alloc(4); size.writeUInt32BE(data.length); sum.writeUInt32BE(crc(Buffer.concat([kind, data]))); return Buffer.concat([size, kind, data, sum]); };
  const head = Buffer.alloc(13); head.writeUInt32BE(32, 0); head.writeUInt32BE(32, 4); head[8] = 8; head[9] = 2;
  const pixels = Buffer.alloc(32 * 97); for (let y = 0; y < 32; y++) for (let x = 0; x < 32; x++) { const p = y * 97 + 1 + x * 3; pixels[p] = 211; pixels[p + 1] = 190; pixels[p + 2] = 239; }
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', head), chunk('IDAT', deflateSync(pixels)), chunk('IEND', Buffer.alloc(0))]);
}

export async function run(input, chromium) {
  const checks = Object.fromEntries(CHECK_KEYS.map(key => [key, false])); let browser, category = 'input';
  const mark = (key, value = true) => { checks[key] = !!value; ensure(checks[key], category); };
  try {
    validateInput(input); category = 'browser';
    const executable = process.env.PETISH_BROWSER_EXECUTABLE || (fs.existsSync(chromium.executablePath()) ? undefined : '/usr/bin/chromium');
    browser = await chromium.launch({ headless: true, ...(executable ? { executablePath: executable } : {}), args: ['--no-sandbox'] }); mark('browser_started');
    category = 'login'; const owner = await login(browser, input, 'owner'); mark('owner_login_verified');
    const member = await login(browser, input, 'member'); mark('member_login_verified');
    const anonymous = await browser.newContext({ serviceWorkers: 'block', acceptDownloads: false });
    const anonymousState = { input }; await guard(anonymous, anonymousState);
    const { page, state } = owner;
    category = 'owner'; await dashboard(page);
    mark('owned_passport_displayed', (await page.locator('#pp-pet-name').textContent()) === input.name
      && (await page.locator('.pc-status').textContent()).includes('Private'));
    category = 'mobile'; mark('mobile_390_verified', await fit(page, 390)); mark('mobile_320_verified', await fit(page, 320));
    category = 'navigation'; const menu = page.locator('.pp-account-navigation-toggle'); await menu.waitFor({ state: 'visible' });
    await menu.click(); ensure(await menu.getAttribute('aria-expanded') === 'true', category);
    await page.keyboard.press('Escape'); const menuClosed = await menu.getAttribute('aria-expanded') === 'false';
    const collection = await page.goto(ORIGIN + '/my-account/for-my-pet/', { waitUntil: 'domcontentloaded' });
    mark('account_menu_verified', menuClosed && collection?.ok()
      && await page.locator('.petish-collection .pc-collection-reset').evaluate(node => {
        const box = node.getBoundingClientRect(), previous = node.previousElementSibling?.getBoundingClientRect();
        return parseFloat(getComputedStyle(node).marginTop) === 28 && !!previous
          && box.top >= previous.bottom + 24 && box.left >= -1 && box.right <= innerWidth + 1;
      }));
    await dashboard(page);
    category = 'tabs'; await page.locator('[data-pc-tab="about"]').click();
    ensure(await page.locator('#pc-panel-about').isVisible() && !await page.locator('#pc-panel-moments').isVisible(), category);
    await page.locator('[data-pc-tab="journal"]').click();
    mark('tabs_mouse_verified', await page.locator('#pc-panel-journal').isVisible()
      && (await page.locator('#pc-panel-journal').textContent()).includes('Private synthetic journal entry.')
      && await page.locator('[data-pc-tabs]').evaluate(node => {
        const style = getComputedStyle(node), original = node.scrollLeft;
        node.scrollLeft = node.scrollWidth; const horizontallyUsable = node.scrollWidth <= node.clientWidth || node.scrollLeft > 0;
        node.scrollLeft = original;
        return style.overflowY === 'hidden' && style.overflowX === 'auto' && horizontallyUsable
          && (style.scrollbarWidth === 'none' || getComputedStyle(node, '::-webkit-scrollbar').display === 'none');
      }));
    await page.locator('[data-pc-tab="journal"]').focus(); await page.keyboard.press('Home');
    mark('tabs_keyboard_verified', await page.locator('[data-pc-tab="moments"]').getAttribute('aria-selected') === 'true'
      && await page.locator('[data-pc-tab="moments"]').evaluate(node => node === document.activeElement));
    category = 'lightbox'; const photo = page.locator('[data-pc-photo]').first(); await photo.click();
    const dialog = page.locator('[data-pc-lightbox]'); await dialog.waitFor({ state: 'visible' });
    mark('lightbox_verified', await dialog.evaluate(node => node.open && !!node.getAttribute('aria-labelledby'))
      && await dialog.locator('[data-pc-close]').isVisible());
    await page.keyboard.press('Escape'); await settled(page);
    mark('lightbox_escape_focus_verified', !await dialog.isVisible() && await photo.evaluate(node => node === document.activeElement));
    category = 'preview'; await page.locator('#pc-sharing summary').click();
    const share = page.locator('.pc-share-form');
    mark('private_preview_verified', await page.locator('[data-pc-share-preview]').isVisible()
      && !(await share.locator('[name="confirm_share"]').isChecked())
      && await share.locator('[name="confirm_share"]').getAttribute('required') !== null
      && !(await share.locator('[name="share_age"]').isChecked())
      && await share.locator('[name="moment_ids[]"]:checked').count() === 0);
    category = 'desktop'; mark('desktop_layout_verified', await fit(page, 1440)
      && await page.locator('.pc-sidebar').evaluate(node => {
        const side = node.getBoundingClientRect(), main = document.querySelector('.pc-main').getBoundingClientRect();
        return side.width > 200 && main.width > 200 && (side.left >= main.right - 1 || side.top >= main.bottom - 1);
      }));
    category = 'dark_mode'; await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' }); await fit(page, 390);
    mark('dark_mode_readable', await page.locator('.pc-profile-info').evaluate(node => {
      const parse = value => value.match(/[\d.]+/g)?.map(Number); const lum = parts => parts.slice(0, 3).map(c => c / 255).map(c => c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4).reduce((v, c, i) => v + c * [.2126, .7152, .0722][i], 0);
      const text = parse(getComputedStyle(node.querySelector('h3')).color); let bg, cursor = node;
      while (cursor && !bg) { const c = parse(getComputedStyle(cursor).backgroundColor); if (c?.length >= 3 && (c[3] ?? 1) >= .99) bg = c; cursor = cursor.parentElement; }
      if (!text || !bg) return false; const a = lum(text), b = lum(bg); return (Math.max(a, b) + .05) / (Math.min(a, b) + .05) >= 4.5;
    })); await page.emulateMedia({ colorScheme: 'light' });
    category = 'no_js'; const fallback = await browser.newContext({ storageState: await owner.context.storageState(), javaScriptEnabled: false, serviceWorkers: 'block' });
    await guard(fallback, { input }); const nojsPage = await fallback.newPage(); await dashboard(nojsPage);
    mark('no_js_fallback_verified', await nojsPage.locator('#pc-panel-moments').isVisible()
      && await nojsPage.locator('#pc-panel-about').isVisible() && await nojsPage.locator('#pc-panel-journal').isVisible()); await fallback.close();
    category = 'form'; await page.getByRole('link', { name: 'Edit Passport', exact: false }).click();
    const form = page.locator('form[data-pp-form]');
    category = 'form_identity';
    ensure(await form.locator('[name="pet_id"]').inputValue() === String(input.pet_id)
      && await form.locator('[name="action"]').inputValue() === 'petish_passport_update', category);
    category = 'form_values';
    ensure(await form.locator('[name="bio"]').inputValue() === 'A curious synthetic rabbit.'
      && await form.locator('[name="traits"]').inputValue() === 'Curious, Gentle', category);
    category = 'variety_default'; ensure(await form.locator('#pp-variety').inputValue() === '', category);
    // The no-JS form deliberately exposes the custom field. Wait for the footer
    // script to enhance it before checking computed visibility; isVisible does
    // not retry while navigation assets are still loading.
    category = 'variety_enhancement';
    await form.locator('[data-pp-variety-other][hidden] #pp-variety-other:disabled').waitFor({ state: 'attached', timeout: 15000 });
    category = 'variety_hidden'; ensure(!await form.locator('#pp-variety-other').isVisible(), category);
    category = 'checkbox_spacing';
    ensure(await form.locator('.pp-photo-field>label.pp-checkbox').evaluate(node => {
      const style = getComputedStyle(node);
      return style.display === 'flex' && parseFloat(style.columnGap) === 11;
    }), category);
    category = 'variety_other'; await form.locator('#pp-variety').selectOption('other');
    ensure(await form.locator('#pp-variety-other').isVisible()
      && await form.locator('#pp-variety-other').getAttribute('maxlength') === '60', category);
    await form.locator('#pp-variety-other').fill('Temporary private variety');
    category = 'variety_species_reset'; await form.locator('#pp-species').selectOption('hamster');
    ensure(await form.locator('#pp-variety').inputValue() === ''
      && await form.locator('#pp-variety option[value="mini-lop"]').count() === 0
      && await form.locator('#pp-variety-other').inputValue() === ''
      && await form.locator('#pp-variety-other').isDisabled(), category);
    category = 'variety_selection'; await form.locator('#pp-species').selectOption('rabbit');
    await form.locator('#pp-variety').selectOption('mini-lop');
    ensure(await form.locator('#pp-variety-other').isDisabled(), category);
    category = 'upload'; await form.locator('input[type="file"]').setInputFiles({ name: 'synthetic-passport.png', mimeType: 'image/png', buffer: syntheticPng() });
    state.operation = 'petish_passport_update'; state.used = false;
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), form.locator('button[type="submit"]').click()]);
    ensure(state.used && await page.locator('#pp-pet-name').isVisible() && await page.locator('.woocommerce-error').count() === 0
      && (await page.locator('.pc-profile-info .pc-pet-details').textContent()).includes('Mini Lop'), category); state.operation = '';
    category = 'form_saved'; await page.getByRole('link', { name: 'Edit Passport', exact: false }).click();
    mark('edit_form_verified', await page.locator('#pp-species').inputValue() === 'rabbit'
      && await page.locator('#pp-variety').inputValue() === 'mini-lop'
      && await page.locator('#pp-variety-other').inputValue() === '');
    await dashboard(page);
    category = 'private_photos';
    for (const url of [input.private_photo_url, input.private_moment_url]) {
      const own = await safeGet(owner.context, state, url); ensure(own.ok() && /^image\//.test(own.headers()['content-type'] ?? '') && noStore(own.headers()), category);
      if (url === input.private_photo_url) {
        const bytes = await own.body();
        mark('real_photo_upload_verified', bytes.length > 24 && bytes.subarray(1, 4).toString() === 'PNG'
          && bytes.readUInt32BE(16) === 32 && bytes.readUInt32BE(20) === 32);
      }
      const anon = await safeGet(anonymous, anonymousState, url); ensure(anon.status() === 404, category);
      const other = await safeGet(member.context, member.state, url); ensure(other.status() === 404, category);
    }
    mark('owner_private_photo_http_verified'); mark('anonymous_private_photos_denied'); mark('foreign_private_photos_denied');
    category = 'publish'; await page.locator('#pc-sharing summary').click();
    await page.locator('.pc-share-form [name="confirm_share"]').check();
    await page.locator(`.pc-share-form [name="moment_ids[]"][value="${input.photo_moment_id}"]`).check();
    state.operation = 'petish_club_publish'; state.used = false;
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('.pc-share-form button[type="submit"]').click()]);
    mark('explicit_publish_verified', state.used && (await page.locator('.pc-status').textContent()).includes('Shared'));
    state.operation = ''; const publicURL = await page.getByRole('link', { name: 'View published profile' }).getAttribute('href');
    state.token = new URL(publicURL).searchParams.get('petish_pet'); ensure(/^[a-f0-9]{32}$/.test(state.token ?? ''), category);
    member.state.token = state.token; anonymousState.token = state.token;
    category = 'public_profile'; const profile = await member.page.goto(publicURL, { waitUntil: 'domcontentloaded' });
    mark('public_profile_http_verified', profile?.ok() && await member.page.locator(ROOT).isVisible());
    const text = await member.page.locator(ROOT).textContent();
    mark('public_projection_verified', text.includes(input.name) && text.includes('A curious synthetic rabbit.')
      && text.includes('Mini Lop')
      && !text.includes('Private synthetic journal entry.') && !text.includes(input.owner.email)
      && !text.includes('1–3 years') && !text.includes('Just browsing'));
    category = 'public_photo'; const publicPhotos = [
      `${ORIGIN}/wp-admin/admin-post.php?action=petish_club_photo&token=${state.token}`,
      await member.page.locator('[data-pc-photo]').first().getAttribute('href'),
    ];
    let publicUncacheable = noStore(await profile.allHeaders());
    for (const publicPhoto of publicPhotos) for (const actor of [{ context: member.context, state: member.state }, { context: anonymous, state: anonymousState }]) {
      const image = await safeGet(actor.context, actor.state, publicPhoto);
      ensure(image.ok() && /^image\//.test(image.headers()['content-type'] ?? ''), category);
      publicUncacheable = publicUncacheable && noStore(image.headers());
    }
    mark('public_photo_http_verified'); mark('public_responses_uncacheable', publicUncacheable);
    category = 'hearts';
    for (const desired of ['1', '0']) {
      const heart = member.page.locator('.pc-heart-form');
      ensure(await heart.locator('[name="token"]').inputValue() === state.token
        && await heart.locator('[name="hearted"]').inputValue() === desired, category);
      member.state.operation = 'petish_club_heart'; member.state.desiredHeart = desired; member.state.used = false;
      await Promise.all([member.page.waitForNavigation({ waitUntil: 'domcontentloaded' }), heart.locator('button[type="submit"]').click()]);
      const button = member.page.locator('.pc-heart-form button[type="submit"]');
      mark(desired === '1' ? 'member_heart_post_verified' : 'member_heart_remove_post_verified',
        member.state.used && await button.getAttribute('aria-pressed') === (desired === '1' ? 'true' : 'false')
        && (await button.locator('span').first().textContent()).trim() === desired);
      member.state.operation = '';
    }
    category = 'unpublish'; state.operation = 'petish_club_unpublish'; state.used = false;
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('.pc-unpublish-form button[type="submit"]').click()]);
    mark('unpublish_verified', state.used && (await page.locator('.pc-status').textContent()).includes('Private')); state.operation = '';
    category = 'revocation'; const revoked = await member.page.goto(publicURL, { waitUntil: 'domcontentloaded' });
    mark('revoked_profile_http_denied', revoked?.status() === 404 && noStore(await revoked.allHeaders())
      && !(await member.page.locator('body').textContent()).includes(input.name));
    for (const publicPhoto of publicPhotos) for (const actor of [{ context: member.context, state: member.state }, { context: anonymous, state: anonymousState }]) {
      const image = await safeGet(actor.context, actor.state, publicPhoto);
      ensure(image.status() === 404 && noStore(image.headers()) && !/^image\//.test(image.headers()['content-type'] ?? ''), category);
    }
    mark('revoked_photo_http_denied');
    category = 'network'; mark('unsafe_requests_blocked', !allowedRequest('https://petish.biz/', 'GET', state)
      && !allowedRequest(ORIGIN + '/checkout/', 'GET', state)
      && !allowedRequest(ORIGIN + '/?add-to-cart=1', 'GET', state)
      && !allowedRequest(ORIGIN + '/wp-admin/admin-post.php', 'POST', state, 'action=petish_club_publish')
      && !allowedRequest(ORIGIN + '/my-account/edit-account/', 'POST', state));
    return publicReport({ status: 'success', category: 'complete', checks });
  } catch (error) { return publicReport({ status: 'failure', category: CATEGORIES.has(error.message) ? error.message : category, checks }); }
  finally { if (browser) await browser.close().catch(() => {}); }
}

async function main() {
  let result = publicReport({ category: 'input' });
  try {
    if (process.argv.length !== 4) throw new Error('input');
    const filename = path.resolve(process.argv[2]), info = fs.lstatSync(filename);
    if (!info.isFile() || info.isSymbolicLink() || info.nlink !== 1 || info.size > 32768
        || (info.mode & 0o7777) !== 0o600 || fs.realpathSync(filename) !== filename) throw new Error('input');
    const input = validateInput(JSON.parse(fs.readFileSync(filename, 'utf8')));
    const require = createRequire(new URL('../staging-rehearsal/package.json', import.meta.url));
    result = await run(input, require('playwright').chromium);
  } catch {}
  try {
    const output = path.resolve(process.argv[3]);
    if (fs.realpathSync(path.dirname(output)) !== path.dirname(output)) throw new Error('input');
    const fd = fs.openSync(output, fs.constants.O_WRONLY | fs.constants.O_CREAT | fs.constants.O_EXCL | fs.constants.O_NOFOLLOW, 0o600);
    try { fs.writeFileSync(fd, JSON.stringify(publicReport(result), null, 2) + '\n'); } finally { fs.closeSync(fd); }
  } catch { process.exitCode = 1; return; }
  process.exitCode = result.status === 'success' ? 0 : 1;
}
if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) await main();
