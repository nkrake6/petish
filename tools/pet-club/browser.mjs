/** Hosted Pet Club checks. All writes are confined to two newly owned staging users. */
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { deflateSync } from 'node:zlib';
import { createHash } from 'node:crypto';

const ORIGIN = 'https://staging.petish.biz';
const ROOT = '.petish-passport.petish-club';
const SAFETY = ['environment_staging', 'staging_database_separate', 'guard_runtime_verified',
  'owned_fixture_only', 'suppliers_blocked', 'mail_blocked', 'payments_blocked'];
export const CHECK_KEYS = Object.freeze([
  'browser_started', 'owner_login_verified', 'member_login_verified', 'owned_passport_displayed',
  'mobile_390_verified', 'mobile_320_verified', 'desktop_layout_verified', 'account_menu_verified',
  'account_menu_colour_track_verified', 'account_menu_height_preserved',
  'tabs_mouse_verified', 'tabs_keyboard_verified', 'private_preview_verified',
  'lightbox_verified', 'lightbox_escape_focus_verified', 'edit_form_verified',
  'real_photo_upload_verified', 'owner_private_photo_http_verified',
  'anonymous_private_photos_denied', 'foreign_private_photos_denied',
  'no_js_fallback_verified', 'dark_mode_readable', 'explicit_publish_verified',
  'public_profile_http_verified', 'public_projection_verified', 'public_photo_http_verified',
  'public_responses_uncacheable', 'member_heart_post_verified', 'member_heart_remove_post_verified',
  'unpublish_verified', 'revoked_profile_http_denied',
  'revoked_photo_http_denied', 'unsafe_requests_blocked',
  'directory_filters_verified', 'directory_return_preserved', 'favourites_session_isolation_verified',
  'favourites_responses_uncacheable', 'video_client_limits_verified', 'video_server_limits_verified',
  'real_video_upload_verified', 'private_video_playback_verified', 'private_video_http_verified',
  'private_video_unauthorized_denied', 'public_video_http_verified', 'video_ranges_verified', 'revoked_video_http_denied',
]);
export const MENU_DIAGNOSTIC_KEYS = Object.freeze([
  'menu_fonts_ready', 'menu_stylesheet_seen', 'menu_stylesheet_bytes_verified',
  ...['cyan', 'purple', 'pink', 'gold'].map(group => `menu_palette_${group}`),
  ...[320, 390].flatMap(width => ['visible', 'receives_pointer'].map(fact => `menu_${width}_toggle_${fact}`)),
  ...[320, 390, 992, 1440].flatMap(width => ['light', 'dark'].flatMap(theme =>
    ['icons', 'height'].map(fact => `menu_${width}_${theme}_${fact}`))),
]);
export const MENU_CATEGORIES = Object.freeze(['menu_ready', 'menu_fonts', 'menu_stylesheet', 'menu_icons', 'menu_height', 'menu_restore',
  ...[320, 390, 992, 1440].flatMap(width => [`menu_viewport_${width}`, `menu_measure_${width}_light`, `menu_measure_${width}_dark`,
    ...(width < 992 ? [`menu_open_${width}`, `menu_close_${width}`] : [])])]);
export const DIAGNOSTIC_KEYS = Object.freeze([...MENU_DIAGNOSTIC_KEYS, ...['member', 'owner', 'guest'].flatMap(actor =>
  ['status_200', 'status_404', 'status_redirect', 'status_403', 'status_other',
    'no_store_header', 'litespeed_hit', 'redirect_to_staging_login'].map(fact => `favourites_${actor}_${fact}`))]);
export const MENU_GEOMETRY_VIEWS = Object.freeze(['current_light', 'current_dark', 'baseline_light', 'baseline_dark']);
export const MENU_GEOMETRY_ENDPOINTS = Object.freeze(['dashboard', 'orders', 'downloads', 'edit-address', 'payment-methods',
  'edit-account', 'my-pets', 'for-my-pet', 'customer-logout']);
export const MENU_GEOMETRY_FIELDS = Object.freeze(['row_width', 'row_height', 'text_width', 'text_height', 'line_height',
  'padding_top', 'padding_bottom', 'padding_left', 'padding_right']);
function publicMenuGeometry(value) {
  return Object.fromEntries(MENU_GEOMETRY_VIEWS.map(view => [view, Object.fromEntries(MENU_GEOMETRY_ENDPOINTS.map(endpoint =>
    [endpoint, Object.fromEntries(MENU_GEOMETRY_FIELDS.map(field => {
      const number = value?.[view]?.[endpoint]?.[field];
      return [field, typeof number === 'number' && Number.isFinite(number) && number >= 0 && number <= 2000 ? Math.round(number * 100) / 100 : 0];
    }))]))]));
}
/** Response classification only: never publish headers, URLs, or response bodies. */
export function favouritesHttpFacts(actor, status, headers) {
  if (!['member', 'owner', 'guest'].includes(actor)) throw new Error('input');
  const redirect = Number.isInteger(status) && status >= 300 && status < 400;
  let login = false;
  try { const url = new URL(headers?.location ?? '', ORIGIN);
    login = redirect && url.origin === ORIGIN && url.pathname === '/wp-login.php' && !url.username && !url.password;
  } catch {}
  const facts = [status === 200, status === 404, redirect, status === 403,
    ![200, 404, 403].includes(status) && !redirect,
    /\bno-store\b/i.test(headers?.['cache-control'] ?? ''), /hit/i.test(headers?.['x-litespeed-cache'] ?? ''), login];
  return Object.fromEntries(DIAGNOSTIC_KEYS.filter(key => key.startsWith(`favourites_${actor}_`)).map((key, index) => [key, facts[index]]));
}
/** Parse without running response scripts; filter URLs may legitimately echo a search term. */
export function guestFavouriteMarkup(html) {
  const document = new DOMParser().parseFromString(html, 'text/html');
  return { no_cards: document.querySelectorAll('.pc-community-card, .pc-heart-form').length === 0,
    sign_in_prompt: [...document.querySelectorAll('.pc-welcome a')].some(link => link.textContent.trim() === 'Sign in to see favourites') };
}
export const FORM_CATEGORIES = Object.freeze([
  'form_identity', 'form_values', 'variety_default', 'variety_enhancement', 'variety_hidden', 'checkbox_spacing',
  'variety_other', 'variety_species_reset', 'variety_selection', 'form_saved',
]);
export const MOMENT_POST_DENIALS = Object.freeze([
  'body_unavailable', 'multipart', 'request', 'window', 'action', 'identity', 'nonce', 'kind', 'caption', 'unknown',
]);
export const VIDEO_LIMIT_CATEGORIES = Object.freeze([
  'video_nojs_context',
  ...['duration', 'size'].flatMap(limit => ['form', 'form_invalid', 'submit', 'click', 'navigation', 'post_blocked',
    ...MOMENT_POST_DENIALS.map(reason => `post_${reason}`), 'response', 'notice_missing', 'notice_content', 'unchanged']
    .map(step => `video_${limit}_${step}`)),
]);
const CATEGORIES = new Set(['input', 'browser', 'login', 'owner', 'mobile', 'desktop', 'navigation',
  'tabs', 'preview', 'lightbox', 'form', 'upload', 'private_photos', 'no_js', 'dark_mode',
  'publish', 'public_profile', 'public_photo', 'hearts', 'unpublish', 'revocation', 'network', 'complete',
  'directory', 'favourites', 'video_input', 'video_limits', 'video_upload', 'video_http', 'video_playback',
  ...FORM_CATEGORIES, ...VIDEO_LIMIT_CATEGORIES, ...MENU_CATEGORIES]);

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

/** Finite diagnostics only; never returns request values or upload contents. */
export function momentPostDenial(raw, method, state, body, type, contentType) {
  if (typeof body !== 'string' || !body) return 'body_unavailable';
  let fields;
  try { fields = fieldsFor(body, contentType); } catch { return 'multipart'; }
  let url;
  try { url = new URL(raw); } catch { return 'request'; }
  if (url.origin !== ORIGIN || url.pathname !== '/wp-admin/admin-post.php' || url.search
      || method !== 'POST' || type !== 'document') return 'request';
  if (state.operation !== 'petish_passport_create_moment' || state.used) return 'window';
  if (fields.getAll('action').length !== 1 || fields.get('action') !== state.operation) return 'action';
  if (!state.input || fields.getAll('pet_id').length !== 1
      || fields.get('pet_id') !== String(state.input.pet_id)) return 'identity';
  if (!/^[a-zA-Z0-9]{10}$/.test(fields.get('petish_passport_nonce') ?? '')) return 'nonce';
  if (fields.get('kind') !== 'video' || fields.getAll('kind').length !== 1 || fields.get('moment_id') !== '0') return 'kind';
  if (fields.get('caption') !== 'Synthetic browser video.') return 'caption';
  return 'unknown';
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
        || (action === 'petish_pet_moment_video' && Number.isSafeInteger(state.videoId) && state.videoId > 0
          && url.searchParams.get('moment_id') === String(state.videoId)
          && [...url.searchParams.keys()].every(key => ['action', 'moment_id', 'v'].includes(key)))
        || (action === 'petish_club_video' && /^[a-f0-9]{32}$/.test(state.token ?? '')
          && url.searchParams.get('token') === state.token && /^[a-f0-9]{32}$/.test(url.searchParams.get('moment') ?? '')
          && [...url.searchParams.keys()].every(key => ['action', 'token', 'moment'].includes(key)))
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
      && /^[a-zA-Z0-9]{10}$/.test(fields.get('petish_club_nonce') ?? '')
      && (state.directoryFilters ? fields.get('return_to') === 'club'
        && Object.entries(state.directoryFilters).every(([key, value]) => fields.get(`club_${key}`) === value)
        : !fields.has('return_to') && ![...fields.keys()].some(key => key.startsWith('club_')));
    if (fields.getAll('pet_id').length !== 1
        || fields.get('pet_id') !== String(input.pet_id)) return false;
    if (state.operation === 'petish_passport_create_moment') return /^[a-zA-Z0-9]{10}$/.test(fields.get('petish_passport_nonce') ?? '')
      && fields.get('kind') === 'video' && fields.getAll('kind').length === 1 && fields.get('moment_id') === '0'
      && fields.get('caption') === 'Synthetic browser video.';
    if (state.operation === 'petish_passport_update') return /^[a-zA-Z0-9]{10}$/.test(fields.get('petish_passport_nonce') ?? '')
      && fields.get('name') === input.name && fields.get('species') === 'rabbit'
      && fields.getAll('variety').length === 1 && fields.get('variety') === 'mini-lop'
      && fields.getAll('variety_other').length <= 1 && (fields.get('variety_other') ?? '') === '';
    if (!/^[a-zA-Z0-9]{10}$/.test(fields.get('petish_club_nonce') ?? '')) return false;
    if (state.operation === 'petish_club_unpublish') return true;
    const selected = [String(input.photo_moment_id), ...(state.videoId ? [String(state.videoId)] : [])].sort();
    return state.operation === 'petish_club_publish' && fields.get('confirm_share') === '1'
      && !fields.has('share_age') && JSON.stringify(fields.getAll('moment_ids[]').sort()) === JSON.stringify(selected);
  } catch { return false; }
}

export function publicReport(value) {
  const checks = Object.fromEntries(CHECK_KEYS.map(key => [key, value?.checks?.[key] === true]));
  const diagnostics = Object.fromEntries(DIAGNOSTIC_KEYS.map(key => [key, value?.diagnostics?.[key] === true]));
  const menu_geometry = publicMenuGeometry(value?.menu_geometry);
  const success = value?.status === 'success' && value?.category === 'complete' && Object.values(checks).every(Boolean);
  return { status: success ? 'success' : 'failure', category: success ? 'complete'
    : (CATEGORIES.has(value?.category) && value.category !== 'complete' ? value.category : 'input'), checks, diagnostics, menu_geometry };
}
function ensure(value, category) { if (!value) throw new Error(category); }
async function guard(context, state) {
  await context.route('**/*', async route => {
    const request = route.request();
    let body;
    try { body = request.postData() ?? ''; } catch { body = null; }
    const contentType = request.headers()['content-type'] ?? '';
    if (body === null || !allowedRequest(request.url(), request.method(), state, body, request.resourceType(), contentType)) {
      if (request.method() === 'POST') {
        state.denied = true;
        state.postDenial = momentPostDenial(request.url(), request.method(), state, body, request.resourceType(), contentType);
      }
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
async function safeGet(context, state, raw, range = null) {
  ensure(allowedRequest(raw, 'GET', state), 'network');
  ensure(range === null || ['bytes=0-31', 'bytes=-8', 'bytes=999999999-', 'bytes=0-1,3-4'].includes(range), 'network');
  return context.request.get(raw, { maxRedirects: 0, timeout: 45000, ...(range ? { headers: { Range: range } } : {}) });
}
export function videoFixture(name) {
  const hashes = { 'synthetic-valid.mp4': '729c392a8f77dea6990329f71c6f8e8e7e832f232910efe6b0c5e8a23a16013d',
    'synthetic-over-duration.mp4': 'ac91bd681120bd0dfb3830c45ed2d800abbdf8b147131db3b1ff5ecb7ce4b8fc' };
  ensure(Object.hasOwn(hashes, name), 'video_input');
  const filename = fileURLToPath(new URL(`./fixtures/${name}`, import.meta.url));
  const stat = fs.lstatSync(filename); ensure(stat.isFile() && !stat.isSymbolicLink() && stat.size <= 262144, 'video_input');
  const bytes = fs.readFileSync(filename); ensure(createHash('sha256').update(bytes).digest('hex') === hashes[name], 'video_input');
  return bytes;
}
function oversizedVideo(valid) {
  const padding = Buffer.alloc(20971521 - valid.length);
  padding.writeUInt32BE(padding.length); padding.write('free', 4, 'ascii');
  return Buffer.concat([valid, padding]);
}
/** Native server-validation submit only; this does not prove pointer usability. */
export function requestNativeVideoLimit(form) {
  const submitter = form.querySelector('button[type="submit"]');
  if (location.origin !== 'https://staging.petish.biz' || form.getAttribute('method')?.toUpperCase() !== 'POST'
      || form.getAttribute('enctype') !== 'multipart/form-data'
      || new URL(form.getAttribute('action'), location.href).href !== `${location.origin}/wp-admin/admin-post.php`
      || !submitter || submitter.disabled || !HTMLFormElement.prototype.checkValidity.call(form)) {
    throw new Error('Native limit form is not ready.');
  }
  submitter.scrollIntoView({ block: 'center', inline: 'center' });
  const box = submitter.getBoundingClientRect();
  const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);
  const style = getComputedStyle(submitter);
  const observations = {
    native_form_valid: true,
    submitter_visible: box.width > 0 && box.height > 0 && style.visibility === 'visible' && style.display !== 'none',
    submitter_receives_pointer: !!hit && (hit === submitter || submitter.contains(hit)),
  };
  HTMLFormElement.prototype.requestSubmit.call(form, submitter);
  return observations;
}
/** Inspect actual native links. Decorative icons must not enlarge a row. */
export function accountColourTrackFacts(navigation) {
  const dark = document.documentElement.dataset.theme === 'dark', mobile = innerWidth <= 991;
  const groups = [
    [['dashboard', 'orders', 'downloads'], dark ? 'rgb(32, 198, 247)' : 'rgb(15, 76, 92)'],
    [['edit-address', 'payment-methods', 'edit-account'], dark ? 'rgb(233, 213, 255)' : 'rgb(101, 50, 161)'],
    [['my-pets', 'for-my-pet'], dark ? 'rgb(255, 91, 167)' : 'rgb(178, 45, 105)'],
    [['customer-logout'], dark ? 'rgb(249, 168, 37)' : 'rgb(139, 91, 5)'],
  ];
  let icons = true, height = true;
  const palette = { cyan: true, purple: true, pink: true, gold: true };
  for (const [endpoints, colour] of groups) for (const endpoint of endpoints) {
    const item = navigation.querySelector(`.woocommerce-MyAccount-navigation-link--${endpoint}`), link = item?.querySelector('a');
    if (!link) return { icons: false, height: false, palette: { cyan: false, purple: false, pink: false, gold: false } };
    const active = item.classList.contains('is-active'), icon = getComputedStyle(link, '::after');
    const circle = getComputedStyle(link, '::before'), rail = getComputedStyle(item, '::before');
    const group = ['cyan', 'purple', 'pink', 'gold'][groups.findIndex(entry => entry[0] === endpoints)];
    palette[group] = palette[group] && rail.backgroundColor === colour
      && icon.backgroundColor === (active ? (dark ? 'rgb(11, 20, 38)' : 'rgb(248, 249, 252)') : colour)
      && (!active || circle.backgroundColor === colour);
    icons = icons && icon.display === 'block' && icon.position === 'absolute' && icon.pointerEvents === 'none'
      && icon.width === '20px' && icon.height === '20px' && icon.maskImage.includes('data:image/svg+xml')
      && rail.backgroundColor === colour && icon.backgroundColor === (active ? (dark ? 'rgb(11, 20, 38)' : 'rgb(248, 249, 252)') : colour)
      && (active ? circle.display === 'block' && circle.width === '26px' && circle.height === '26px'
        && circle.backgroundColor === colour && icon.maskSize === '17px 17px' : circle.display === 'none');
    const style = getComputedStyle(link), box = link.getBoundingClientRect(), range = document.createRange();
    range.selectNodeContents(link); const text = range.getBoundingClientRect();
    height = height && Math.abs(box.height - (mobile ? 44 : 45)) < 1
      && style.paddingTop === (mobile ? '11px' : '12px') && style.paddingBottom === style.paddingTop
      && text.height <= parseFloat(style.lineHeight) + 1 && text.right <= box.right - parseFloat(style.paddingRight) + 1;
  }
  if (mobile) {
    const toggle = navigation.querySelector('.pp-account-navigation-toggle'), style = toggle && getComputedStyle(toggle);
    const box = toggle?.getBoundingClientRect();
    height = height && !!style && style.paddingTop === '12px' && style.paddingBottom === '12px'
      && style.paddingLeft === '16px' && style.paddingRight === '16px' && style.minHeight === '64px'
      && box.height >= 64 && box.height <= 68;
  }
  return { icons, height, palette };
}
export function observeMenuStylesheet(page, diagnostics) {
  const pending = [];
  page.on('response', response => {
    const url = new URL(response.url());
    if (url.origin !== ORIGIN || url.pathname !== '/wp-content/plugins/petish-passport/assets/passport.css') return;
    diagnostics.menu_stylesheet_seen = true;
    pending.push(response.body().then(bytes => {
      diagnostics.menu_stylesheet_bytes_verified = response.status() === 200
        && createHash('sha256').update(bytes).digest('hex') === '7bfaba1d23ea4ca07fbdb009aceec36efbefb194e1bdafff60c46f012c8a237d';
    }).catch(() => { diagnostics.menu_stylesheet_bytes_verified = false; }));
  });
  return pending;
}
export function menuToggleFacts(toggle) {
  const box = toggle.getBoundingClientRect(), style = getComputedStyle(toggle);
  const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);
  return { visible: box.width > 0 && box.height > 0 && style.display !== 'none' && style.visibility === 'visible',
    receives_pointer: !!hit && (hit === toggle || toggle.contains(hit)) };
}
/** Only known menu endpoints and numeric layout data can leave the browser. */
export function menuGeometryFacts(navigation, endpoints) {
  return Object.fromEntries(endpoints.map(endpoint => {
    const link = navigation.querySelector(`.woocommerce-MyAccount-navigation-link--${endpoint} a`);
    if (!link) throw new Error('menu_height');
    const box = link.getBoundingClientRect(), style = getComputedStyle(link), range = document.createRange();
    range.selectNodeContents(link); const text = range.getBoundingClientRect();
    return [endpoint, Object.fromEntries(Object.entries({ row_width: box.width, row_height: box.height,
      text_width: text.width, text_height: text.height, line_height: parseFloat(style.lineHeight),
      padding_top: parseFloat(style.paddingTop), padding_bottom: parseFloat(style.paddingBottom),
      padding_left: parseFloat(style.paddingLeft), padding_right: parseFloat(style.paddingRight) })
      .map(([key, value]) => [key, Number.isFinite(value) && value >= 0 && value <= 2000 ? Math.round(value * 100) / 100 : 0]))];
  }));
}
export function menuBaselineStyles() {
  const current = fs.readFileSync(new URL('../../wordpress/wp-content/plugins/petish-passport/assets/passport.css', import.meta.url), 'utf8');
  const marker = current.indexOf('/* Colour track:');
  const baseline = marker > 0 ? current.slice(0, marker - 1) : '';
  if (createHash('sha256').update(baseline).digest('hex') !== 'e5c41cec8708f7d0e6ccddf8de76e43b807f66a612e3eac95871ab6c8a581076') throw new Error('menu_height');
  return baseline;
}
async function settledMenuGeometry(page) {
  const deadline = performance.now() + 2000; let previous = '', equal = 0, result;
  do {
    result = await page.locator('.woocommerce-MyAccount-navigation').evaluate(menuGeometryFacts, MENU_GEOMETRY_ENDPOINTS);
    const snapshot = JSON.stringify(result), ready = await page.evaluate(() => !document.fonts || document.fonts.status === 'loaded');
    equal = ready && snapshot === previous ? equal + 1 : 0; previous = snapshot;
    if (equal >= 2 || performance.now() >= deadline) return result;
    await page.waitForTimeout(50);
  } while (performance.now() < deadline);
  return result;
}
export async function compareMenuBaseline(page, theme, geometry) {
  geometry[`current_${theme}`] = await settledMenuGeometry(page);
  const baseline = menuBaselineStyles(); let installed = false;
  try {
    await page.evaluate(css => {
      if (window.__petishMenuBaseline || document.getElementById('petish-proof-menu-baseline')) throw new Error('menu_height');
      const links = [...document.querySelectorAll('link[rel="stylesheet"]')].filter(link => {
        const url = new URL(link.href); return url.origin === location.origin
          && url.pathname === '/wp-content/plugins/petish-passport/assets/passport.css';
      });
      if (links.length !== 1 || links[0].disabled) throw new Error('menu_height');
      const style = document.createElement('style'); style.id = 'petish-proof-menu-baseline'; style.textContent = css;
      window.__petishMenuBaseline = { link: links[0], style };
      links[0].after(style); links[0].disabled = true;
    }, baseline); installed = true;
    await settled(page); geometry[`baseline_${theme}`] = await settledMenuGeometry(page);
  } finally {
    if (installed) await page.evaluate(() => {
      const owned = window.__petishMenuBaseline;
      if (!owned || owned.style.id !== 'petish-proof-menu-baseline') throw new Error('menu_height');
      owned.link.disabled = false; owned.style.remove(); delete window.__petishMenuBaseline;
    });
    await settled(page);
  }
}
/** Measure settled styles without disabling the theme's animations or changing expectations. */
export async function settledAccountColourTrackFacts(page) {
  const navigation = page.locator('.woocommerce-MyAccount-navigation');
  const deadline = performance.now() + 2000;
  let iconsReady = 0, heightReady = 0, facts;
  do {
    const fontsReady = await page.evaluate(() => !document.fonts || document.fonts.status === 'loaded');
    facts = await navigation.evaluate(accountColourTrackFacts);
    iconsReady = fontsReady && facts.icons ? iconsReady + 1 : 0;
    heightReady = fontsReady && facts.height ? heightReady + 1 : 0;
    if (iconsReady >= 3 && heightReady >= 3) return facts;
    if (performance.now() >= deadline) break;
    await page.waitForTimeout(50);
  } while (performance.now() < deadline);
  // Preserve the actual failing measurements for the finite diagnostic report.
  return { ...facts, icons: facts.icons && iconsReady >= 3, height: facts.height && heightReady >= 3 };
}
export async function checkAccountColourTrack(page, diagnostics = {}, setCategory = () => {}, geometry = null) {
  const viewport = page.viewportSize(), original = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
  let icons = true, height = true;
  const palette = { cyan: true, purple: true, pink: true, gold: true };
  try {
    setCategory('menu_fonts');
    await page.waitForFunction(() => !document.fonts || document.fonts.status === 'loaded');
    diagnostics.menu_fonts_ready = true;
    for (const width of [320, 390, 992, 1440]) {
      setCategory(`menu_viewport_${width}`);
      await page.setViewportSize({ width, height: 900 });
      await settled(page);
      const toggle = page.locator('.pp-account-navigation-toggle');
      if (width <= 991 && await toggle.getAttribute('aria-expanded') !== 'true') {
        setCategory(`menu_open_${width}`);
        const observe = async () => { const facts = await toggle.evaluate(menuToggleFacts);
          for (const key of ['visible', 'receives_pointer']) diagnostics[`menu_${width}_toggle_${key}`] = facts[key]; };
        await observe();
        try { await toggle.click(); } catch (error) { await observe().catch(() => {}); throw error; }
      }
      for (const theme of ['light', 'dark']) {
        setCategory(`menu_measure_${width}_${theme}`);
        await page.evaluate(value => { document.documentElement.dataset.theme = value; }, theme);
        await settled(page);
        const facts = await settledAccountColourTrackFacts(page);
        if (width === 992 && geometry) await compareMenuBaseline(page, theme, geometry);
        diagnostics[`menu_${width}_${theme}_icons`] = facts.icons;
        diagnostics[`menu_${width}_${theme}_height`] = facts.height;
        for (const group of Object.keys(palette)) {
          palette[group] = palette[group] && facts.palette[group]; diagnostics[`menu_palette_${group}`] = palette[group];
        }
        icons = icons && facts.icons; height = height && facts.height;
      }
      if (width <= 991) { setCategory(`menu_close_${width}`); await page.keyboard.press('Escape'); }
    }
  } finally {
    await page.evaluate(value => { if (value === null) document.documentElement.removeAttribute('data-theme'); else document.documentElement.setAttribute('data-theme', value); }, original);
    await page.setViewportSize(viewport);
  }
  return { icons, height };
}
async function videoUploads(browser, owner, input, mark, setCategory) {
  const { page, state } = owner, valid = videoFixture('synthetic-valid.mp4'), long = videoFixture('synthetic-over-duration.mp4');
  const oversized = oversizedVideo(valid), createURL = `${ORIGIN}/my-account/my-pets/?passport=moment-create&pet_id=${input.pet_id}&kind=video`;
  setCategory('video_limits'); await page.goto(createURL, { waitUntil: 'load' });
  const upload = page.locator('#pp-video'), feedback = page.locator('[data-pp-video-feedback]');
  await upload.setInputFiles({ name: 'synthetic-oversize.mp4', mimeType: 'video/mp4', buffer: oversized });
  await page.waitForFunction(() => document.querySelector('#pp-video')?.getAttribute('aria-invalid') === 'true');
  ensure((await feedback.textContent()).includes('20 MB') && await upload.evaluate(node => !node.checkValidity()), 'video_limits');
  await upload.setInputFiles({ name: 'synthetic-over-duration.mp4', mimeType: 'video/mp4', buffer: long });
  await page.waitForFunction(() => document.querySelector('[data-pp-video-feedback]')?.textContent.includes('20 seconds'));
  mark('video_client_limits_verified', await upload.evaluate(node => !node.checkValidity()));
  // Exercise the PHP gate with site scripts disabled. Use the native form
  // algorithm, preserving validity/multipart, without claiming pointer usability.
  setCategory('video_nojs_context');
  const nojs = await browser.newContext({ storageState: await owner.context.storageState(), javaScriptEnabled: false, serviceWorkers: 'block' });
  const nojsState = { input, operation: '', used: false }; await guard(nojs, nojsState);
  try {
    const errorPage = await nojs.newPage(); errorPage.setDefaultTimeout(15000); errorPage.setDefaultNavigationTimeout(45000);
    for (const [limit, bytes, errorText] of [['duration', long, '20 seconds'], ['size', oversized, '20 MB']]) {
      let step = `video_${limit}_form`; setCategory(step);
      const formResponse = await errorPage.goto(createURL, { waitUntil: 'domcontentloaded' });
      ensure(formResponse?.ok() && await errorPage.locator('form[data-pp-form]').count() === 1, step);
      await errorPage.locator('#pc-moment-caption').fill('Synthetic browser video.');
      await errorPage.locator('#pp-video').setInputFiles({ name: 'synthetic-rejected.mp4', mimeType: 'video/mp4', buffer: bytes });
      step = `video_${limit}_form_invalid`; setCategory(step);
      ensure(await errorPage.locator('form[data-pp-form]').evaluate(form => form.checkValidity()), step);
      nojsState.operation = 'petish_passport_create_moment'; nojsState.used = false; nojsState.denied = false; nojsState.postDenial = '';
      step = `video_${limit}_submit`; setCategory(step);
      let posted = null;
      const onResponse = response => {
        if (response.url() === `${ORIGIN}/wp-admin/admin-post.php` && response.request().method() === 'POST') posted = response;
      };
      errorPage.on('response', onResponse);
      try {
        const navigated = errorPage.waitForNavigation({ waitUntil: 'domcontentloaded' }).then(response => ({ response }), error => ({ error }));
        const observations = await errorPage.locator('form[data-pp-form]').evaluate(requestNativeVideoLimit);
        // Fixed booleans only; this stderr stays private and is not release evidence.
        process.stderr.write(JSON.stringify({ phase: `video_${limit}_submit`, ...observations }) + '\n');
        step = `video_${limit}_navigation`; setCategory(step);
        const navigation = await navigated; if (navigation.error) throw navigation.error;
      } catch (error) {
        if (nojsState.denied) {
          const deniedCategory = `video_${limit}_post_${MOMENT_POST_DENIALS.includes(nojsState.postDenial) ? nojsState.postDenial : 'blocked'}`;
          setCategory(deniedCategory); throw new Error(deniedCategory);
        }
        throw error;
      } finally { errorPage.off('response', onResponse); }
      step = `video_${limit}_response`; setCategory(step);
      ensure(nojsState.used && posted?.status() === 303, step);
      step = `video_${limit}_notice_missing`; setCategory(step);
      ensure(await errorPage.locator('.woocommerce-error').count() === 1, step);
      step = `video_${limit}_notice_content`; setCategory(step);
      ensure((await errorPage.locator('.woocommerce-error').textContent()).includes(errorText), step);
      nojsState.operation = ''; step = `video_${limit}_unchanged`; setCategory(step);
      await dashboard(errorPage);
      ensure(await errorPage.locator('.pc-video-moment').count() === 0, step);
    }
    mark('video_server_limits_verified');
  } finally { await nojs.close(); }
  setCategory('video_upload'); await page.goto(createURL, { waitUntil: 'load' });
  await page.locator('#pc-moment-caption').fill('Synthetic browser video.');
  await page.locator('#pp-video').setInputFiles({ name: 'synthetic-valid.mp4', mimeType: 'video/mp4', buffer: valid });
  await page.locator('[data-pp-video-preview]').waitFor({ state: 'visible' });
  ensure(await page.locator('#pp-video').evaluate(node => node.checkValidity()), 'video_upload');
  state.operation = 'petish_passport_create_moment'; state.used = false;
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('form[data-pp-form] button[type="submit"]').click()]);
  ensure(state.used && await page.locator('.woocommerce-error').count() === 0 && await page.locator('.pc-video-moment').count() === 1, 'video_upload');
  state.operation = ''; const video = page.locator('.pc-video-moment video');
  const raw = await video.locator('source').getAttribute('src'), url = new URL(raw);
  ensure(url.origin === ORIGIN && url.pathname === '/wp-admin/admin-post.php' && url.searchParams.get('action') === 'petish_pet_moment_video'
    && /^[1-9][0-9]*$/.test(url.searchParams.get('moment_id') ?? ''), 'video_upload');
  state.videoId = Number(url.searchParams.get('moment_id')); ensure(Number.isSafeInteger(state.videoId), 'video_upload');
  mark('real_video_upload_verified'); setCategory('video_playback');
  await video.evaluate(node => node.load());
  await page.waitForFunction(() => { const node = document.querySelector('.pc-video-moment video'); return node?.readyState >= 1 && Number.isFinite(node.duration); });
  mark('private_video_playback_verified', await video.evaluate(async node => {
    if (!node.controls || !node.playsInline || node.autoplay || Math.abs(node.duration - 1) > 0.01) return false;
    await node.play(); const playing = !node.paused; node.pause(); return playing;
  }));
  return { privateURL: url.href, bytes: valid, id: state.videoId };
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
  const diagnostics = Object.fromEntries(DIAGNOSTIC_KEYS.map(key => [key, false]));
  const menu_geometry = publicMenuGeometry();
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
    const menuResponses = observeMenuStylesheet(page, diagnostics);
    category = 'owner'; await dashboard(page);
    mark('owned_passport_displayed', (await page.locator('#pp-pet-name').textContent()) === input.name
      && (await page.locator('.pc-status').textContent()).includes('Private'));
    category = 'mobile'; mark('mobile_390_verified', await fit(page, 390)); mark('mobile_320_verified', await fit(page, 320));
    category = 'menu_ready'; const menu = page.locator('.pp-account-navigation-toggle'); await menu.waitFor({ state: 'visible' });
    const menuFacts = await checkAccountColourTrack(page, diagnostics, value => { category = value; }, menu_geometry);
    category = 'menu_stylesheet'; await Promise.all(menuResponses);
    ensure(diagnostics.menu_stylesheet_seen && diagnostics.menu_stylesheet_bytes_verified, category);
    category = 'menu_icons'; mark('account_menu_colour_track_verified', menuFacts.icons);
    // Collect independent fixture/privacy results even if this visual check fails.
    // The final report still requires this check and all other required checks.
    checks.account_menu_height_preserved = menuFacts.height; category = 'navigation';
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
    const clip = await videoUploads(browser, owner, input, mark, value => { category = value; });
    member.state.videoId = clip.id; anonymousState.videoId = clip.id;
    category = 'video_http';
    const privateVideo = await safeGet(owner.context, state, clip.privateURL);
    mark('private_video_http_verified', privateVideo.status() === 200 && noStore(privateVideo.headers())
      && privateVideo.headers()['content-type']?.startsWith('video/mp4')
      && privateVideo.headers()['x-content-type-options'] === 'nosniff'
      && (await privateVideo.body()).equals(clip.bytes));
    for (const actor of [{ context: anonymous, state: anonymousState }, member]) {
      for (const range of [null, 'bytes=0-31', 'bytes=999999999-']) {
        const denied = await safeGet(actor.context, actor.state, clip.privateURL, range);
        ensure(denied.status() === 404 && noStore(denied.headers()) && !denied.headers()['content-range'], category);
      }
    }
    mark('private_video_unauthorized_denied');
    category = 'publish'; await page.locator('#pc-sharing summary').click();
    await page.locator('.pc-share-form [name="confirm_share"]').check();
    await page.locator(`.pc-share-form [name="moment_ids[]"][value="${input.photo_moment_id}"]`).check();
    await page.locator(`.pc-share-form [name="moment_ids[]"][value="${clip.id}"]`).check();
    ensure(await page.locator('.pc-video-privacy').isVisible(), category);
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
    category = 'video_http';
    const publicVideoURL = await member.page.locator('.pc-video-moment video source').getAttribute('src');
    for (const actor of [{ context: member.context, state: member.state }, { context: anonymous, state: anonymousState }]) {
      const videoResponse = await safeGet(actor.context, actor.state, publicVideoURL);
      ensure(videoResponse.status() === 200 && noStore(videoResponse.headers())
        && videoResponse.headers()['content-type']?.startsWith('video/mp4')
        && videoResponse.headers()['x-content-type-options'] === 'nosniff'
        && (await videoResponse.body()).equals(clip.bytes), category);
    }
    mark('public_video_http_verified');
    for (const [context, actorState, url] of [[owner.context, state, clip.privateURL], [member.context, member.state, publicVideoURL]]) {
      for (const [range, start, end] of [['bytes=0-31', 0, 31], ['bytes=-8', clip.bytes.length - 8, clip.bytes.length - 1]]) {
        const partial = await safeGet(context, actorState, url, range);
        ensure(partial.status() === 206 && noStore(partial.headers())
          && partial.headers()['content-range'] === `bytes ${start}-${end}/${clip.bytes.length}`
          && (await partial.body()).equals(clip.bytes.subarray(start, end + 1)), category);
      }
      for (const range of ['bytes=999999999-', 'bytes=0-1,3-4']) {
        const invalid = await safeGet(context, actorState, url, range);
        ensure(invalid.status() === 416 && noStore(invalid.headers())
          && invalid.headers()['content-range'] === `bytes */${clip.bytes.length}` && (await invalid.body()).length === 0, category);
      }
    }
    mark('video_ranges_verified');
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
      if (desired === '1') {
        category = 'directory';
        await member.page.goto(`${ORIGIN}/?petish_club=1`, { waitUntil: 'load' });
        const filters = member.page.locator('[data-pc-directory-filters]');
        await filters.locator('#pc-club-species').selectOption('rabbit');
        await filters.locator('#pc-club-variety').fill('Mini Lop');
        await filters.locator('#pc-club-name').fill(input.name);
        await Promise.all([member.page.waitForNavigation({ waitUntil: 'domcontentloaded' }), filters.getByRole('button', { name: 'Apply filters' }).click()]);
        mark('directory_filters_verified', await member.page.locator('.pc-community-card').count() === 1
          && (await member.page.locator('.pc-community-card h2').textContent()).trim() === input.name
          && (await member.page.locator('.pc-community-card .pc-pet-details').textContent()).trim() === 'Mini Lop'
          && await fit(member.page, 320));
        await member.page.locator('.pc-directory-nav').getByRole('link', { name: 'My favourites' }).click();
        category = 'favourites'; const favouriteURL = member.page.url();
        const memberResponse = await safeGet(member.context, member.state, favouriteURL);
        const ownerResponse = await page.goto(favouriteURL + `&club_uid=${input.member.id}`, { waitUntil: 'domcontentloaded' });
        const guestResponse = await safeGet(anonymous, anonymousState, favouriteURL + `&club_uid=${input.member.id}`);
        const ownerHeaders = ownerResponse ? await ownerResponse.allHeaders() : {};
        Object.assign(diagnostics, favouritesHttpFacts('member', memberResponse.status(), memberResponse.headers()),
          favouritesHttpFacts('owner', ownerResponse?.status(), ownerHeaders),
          favouritesHttpFacts('guest', guestResponse.status(), guestResponse.headers()));
        const guestMarkup = await page.evaluate(guestFavouriteMarkup, await guestResponse.text());
        mark('favourites_session_isolation_verified', await member.page.locator('.pc-community-card').count() === 1
          && await page.locator('.pc-community-card').count() === 0
          && guestMarkup.no_cards && (guestResponse.status() === 404 || (guestResponse.status() === 200 && guestMarkup.sign_in_prompt)));
        mark('favourites_responses_uncacheable', memberResponse.status() === 200 && noStore(memberResponse.headers())
          && ownerResponse?.ok() && noStore(ownerHeaders) && noStore(guestResponse.headers())
          && [200, 404].includes(guestResponse.status()));
        // Remove via the rendered directory form, checking its native return target.
        member.state.directoryFilters = { view: 'favourites', species: 'rabbit', variety: 'Mini Lop', name: input.name, page: '1' };
        member.state.operation = 'petish_club_heart'; member.state.desiredHeart = '0'; member.state.used = false;
        await Promise.all([member.page.waitForNavigation({ waitUntil: 'domcontentloaded' }), member.page.locator('.pc-heart-form button').click()]);
        const returned = new URL(member.page.url());
        mark('directory_return_preserved', member.state.used && returned.origin === ORIGIN && returned.pathname === '/'
          && Object.entries(member.state.directoryFilters).every(([key, value]) => (returned.searchParams.get(`club_${key}`) ?? (key === 'page' ? '1' : '')) === value)
          && await member.page.locator('.pc-community-card').count() === 0);
        member.state.operation = ''; delete member.state.directoryFilters;
        // Re-add on the public profile so the original remove-heart proof remains intact.
        await member.page.goto(publicURL, { waitUntil: 'domcontentloaded' });
        member.state.operation = 'petish_club_heart'; member.state.desiredHeart = '1'; member.state.used = false;
        await Promise.all([member.page.waitForNavigation({ waitUntil: 'domcontentloaded' }), member.page.locator('.pc-heart-form button').click()]);
        ensure(member.state.used, category); member.state.operation = ''; await dashboard(page); category = 'hearts';
      }
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
    for (const actor of [{ context: member.context, state: member.state }, { context: anonymous, state: anonymousState }]) {
      for (const range of [null, 'bytes=0-31', 'bytes=999999999-']) {
        const denied = await safeGet(actor.context, actor.state, publicVideoURL, range);
        ensure(denied.status() === 404 && noStore(denied.headers()) && !denied.headers()['content-range'], category);
      }
    }
    mark('revoked_video_http_denied');
    category = 'network'; mark('unsafe_requests_blocked', !allowedRequest('https://petish.biz/', 'GET', state)
      && !allowedRequest(ORIGIN + '/checkout/', 'GET', state)
      && !allowedRequest(ORIGIN + '/?add-to-cart=1', 'GET', state)
      && !allowedRequest(ORIGIN + '/wp-admin/admin-post.php', 'POST', state, 'action=petish_club_publish')
      && !allowedRequest(ORIGIN + '/my-account/edit-account/', 'POST', state));
    ensure(checks.account_menu_height_preserved, 'menu_height');
    return publicReport({ status: 'success', category: 'complete', checks, diagnostics, menu_geometry });
  } catch (error) { return publicReport({ status: 'failure', category: CATEGORIES.has(error.message) ? error.message : category, checks, diagnostics, menu_geometry }); }
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
