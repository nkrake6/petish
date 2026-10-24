/** Fixed live guest-cart inspection. No checkout, orders, login or payment actions. */
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
const require = createRequire(new URL('./staging-rehearsal/package.json', import.meta.url));
const ORIGIN = 'https://petish.biz';
const PRODUCT = '/product/rainbow-habitat-hideout/';
export const PUBLIC_SCHEMA = {"checks":["browser_started","fresh_guest_context","initial_cart_empty","public_variation_selected","guest_cart_created","cart_quantity_verified","cart_page_loaded","passive_sdk_inspected","network_barrier_enforced","created_guest_cart_cleared"],"observations":["home_http_ok","product_http_ok","cart_http_ok","home_account_in_navigation","home_account_avatar_present","home_native_cart_present","home_cart_badge_present","product_afterpay_present","product_paypal_present","cart_block_present","cart_sidebar_present","cart_express_in_sidebar","cart_paypal_in_express","cart_afterpay_in_express","cart_afterpay_in_sidebar","cart_wcpay_google_in_express","cart_paypal_google_in_express","cart_paylater_in_express","cart_paypal_google_v5_present","cart_paypal_google_v6_present","cart_paypal_v6_present","cart_paypal_message_in_sidebar","cart_paypal_message_in_express","cart_paypal_message_shadow_host_present","cart_paypal_message_shadow_host_visible","cart_paypal_message_direct_sidebar","cart_paypal_message_bare_sidebar_wrapper","cart_paypal_message_block_wrapper","cart_stripe_message_in_sidebar","cart_google_visible","cart_paypal_visible","cart_afterpay_visible","afterpay_frame_present","afterpay_frame_inspected","afterpay_logo_present","afterpay_see_plans_present","afterpay_see_plans_below","checkout_requests_blocked","other_requests_blocked","provider_get_blocked","provider_post_blocked","store_api_blocked","payment_action_blocked","provider_bootstrap_blocked","stripe_session_initialization_allowed","account_fragment_read_allowed","cart_count_fragment_read_allowed","home_account_after_currency","afterpay_brand_badge_present","afterpay_message_below_brand_badge"],"metrics":["home_account_count","home_native_cart_count","home_badge_count","cart_row_count","cart_express_count","cart_paypal_count","cart_wcpay_google_count","cart_paypal_google_count","cart_paylater_count","cart_afterpay_count","cart_paypal_message_count","cart_paypal_message_shadow_host_count","cart_paypal_message_shadow_host_width_px","cart_paypal_message_shadow_host_height_px","cart_afterpay_frame_count","afterpay_logo_count","afterpay_logo_width_px","afterpay_logo_height_px","afterpay_font_px","afterpay_link_font_px","afterpay_message_width_px","afterpay_message_height_px","blocked_request_count","blocked_provider_get_count","blocked_provider_post_count","blocked_store_api_count","blocked_payment_action_count","stripe_session_initialization_count","account_fragment_read_count","cart_count_fragment_read_count","home_account_left_px","home_market_right_px","afterpay_brand_badge_width_px","afterpay_brand_badge_height_px"],"categories":["input","browser","home","product","cart","providers","cleanup","complete"]};
const DOCUMENTS = new Set(['/', PRODUCT, '/cart/']);
const PAYMENT_ACTION = /\/(?:checkout|checkoutnow|orders?|payments?|payment_intents|setup_intents|confirm|capture|authorize|approve|refunds?|customers)(?:\/|$)/i;
const PROVIDER_HOST = /(?:^|\.)(?:paypal\.com|paypalobjects\.com|stripe\.com|afterpay\.com|squarecdn\.com|cash\.app)$/i;
const METRIC_MAX = 4096;
export function emptyReport() {
  return { status: 'failure', category: 'input', checks: Object.fromEntries(PUBLIC_SCHEMA.checks.map(key => [key, false])),
    observations: Object.fromEntries(PUBLIC_SCHEMA.observations.map(key => [key, false])),
    metrics: Object.fromEntries(PUBLIC_SCHEMA.metrics.map(key => [key, 0])) };
}
export function screenReport(value) {
  const fields = ['status', 'category', 'checks', 'observations', 'metrics'];
  if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).sort().join() !== fields.sort().join()
      || !['success', 'failure'].includes(value.status) || !PUBLIC_SCHEMA.categories.includes(value.category)) throw new Error('input');
  for (const group of ['checks', 'observations', 'metrics']) {
    if (!value[group] || typeof value[group] !== 'object' || Array.isArray(value[group])
        || Object.keys(value[group]).sort().join() !== [...PUBLIC_SCHEMA[group]].sort().join()) throw new Error('input');
    for (const item of Object.values(value[group])) {
      if (group === 'metrics' ? !Number.isFinite(item) || item < 0 || item > METRIC_MAX : typeof item !== 'boolean') throw new Error('input');
    }
  }
  if (value.status === 'success' && (value.category !== 'complete' || !Object.values(value.checks).every(Boolean))) throw new Error('input');
  return value;
}
/** Exact audited own-session reads; no extra query or POST fields are accepted. */
export function auditedSessionRead(raw, method, body = '') {
  let url; try { url = new URL(raw); } catch { return null; }
  if (url.protocol !== 'https:' || url.origin !== ORIGIN || url.username || url.password || url.port || url.hash || method !== 'POST') return null;
  if (url.pathname === '/wp-admin/admin-ajax.php' && !url.search && body === 'action=petish_account_dropdown') return 'account';
  if (url.pathname === '/' && url.search === '?wc-ajax=petish_cart_count' && body === '') return 'cart_count';
  return null;
}
/** Native Stripe Elements display sessions are read-only widget initialization. */
export function publicStripeSession(raw, method, body) {
  let url; try { url = new URL(raw); } catch { return false; }
  if (url.href !== 'https://api.stripe.com/v1/elements/sessions' || method !== 'POST' || typeof body !== 'string' || body.length < 1 || body.length > 65536) return false;
  const entries = [...new URLSearchParams(body)];
  if (entries.length < 1 || entries.length > 200 || entries.filter(([key]) => key === 'key').length !== 1) return false;
  if (entries.some(([key]) => !/^[a-zA-Z0-9_\[\]-]{1,160}$/.test(key) || /(?:payment_intent|setup_intent|client_secret)/i.test(key))) return false;
  return /^pk_(?:live|test)_[a-zA-Z0-9_]{8,240}$/.test(new URLSearchParams(body).get('key') || '');
}
/** Passive public SDK assets are allowed; all action endpoints and arbitrary writes are denied. */
export function allowedRequest(raw, method = 'GET', type = 'fetch', body = '', permit = null) {
  let url; try { url = new URL(raw); } catch { return false; }
  if (url.protocol !== 'https:' || url.username || url.password || url.port || PAYMENT_ACTION.test(url.pathname)) return false;
  if (publicStripeSession(raw, method, body) || auditedSessionRead(raw, method, body)) return true;
  if (url.origin === ORIGIN) {
    if (permit && !permit.used && method === permit.method && url.pathname === permit.path && !url.search && body === permit.body) {
      if (method === 'DELETE') return permit.path === '/wp-json/wc/store/v1/cart/items' && body === '';
      if (method === 'POST' && permit.path === '/wp-json/wc/store/v1/cart/add-item') {
        try { const item = JSON.parse(body); return Object.keys(item).sort().join() === 'id,quantity' && Number.isSafeInteger(item.id) && item.id > 0 && item.quantity === 2; } catch { return false; }
      }
      return false;
    }
    if (!['GET', 'HEAD'].includes(method) || /(?:add-to-cart|remove_item|wc-ajax|_wpnonce|rest_route)/i.test(url.search)) return false;
    if (type === 'document') return DOCUMENTS.has(url.pathname) && !url.search;
    if (url.pathname === '/wp-json/wc/store/v1/cart') return method === 'GET' && !url.search;
    return /^\/(?:wp-content|wp-includes)\/.*\.(?:css|js|mjs|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot|json)$/i.test(url.pathname);
  }
  return ['GET', 'HEAD'].includes(method) && (PROVIDER_HOST.test(url.hostname)
    || ['pay.google.com', 'fonts.googleapis.com', 'fonts.gstatic.com'].includes(url.hostname));
}
function reportDestination(filename) {
  if (typeof filename !== 'string' || !path.isAbsolute(filename)) throw new Error('input');
  const resolved = path.resolve(filename), parent = path.dirname(resolved);
  if (fs.realpathSync(parent) !== parent || (fs.statSync(parent).mode & 0o7777) !== 0o700) throw new Error('input');
  try { fs.lstatSync(resolved); throw new Error('input'); } catch (error) { if (error.code !== 'ENOENT') throw error; }
  return resolved;
}
function writeReport(filename, report) {
  const resolved = reportDestination(filename);
  const descriptor = fs.openSync(resolved, fs.constants.O_WRONLY | fs.constants.O_CREAT | fs.constants.O_EXCL | fs.constants.O_NOFOLLOW, 0o600);
  try { fs.writeFileSync(descriptor, JSON.stringify(screenReport(report), null, 2) + '\n'); fs.fsyncSync(descriptor); }
  finally { fs.closeSync(descriptor); }
}
const bounded = value => Number.isFinite(value) ? Math.max(0, Math.min(METRIC_MAX, Math.round(value * 100) / 100)) : 0;
async function pageFacts(page, location) {
  return page.evaluate(where => {
    const all = selector => [...document.querySelectorAll(selector)];
    const visible = el => { const box = el.getBoundingClientRect(), style = getComputedStyle(el); return box.width > 0 && box.height > 0 && style.display !== 'none' && style.visibility !== 'hidden'; };
    const count = selector => Math.min(99, all(selector).length);
    const inside = (selector, parent) => all(selector).some(el => el.closest(parent));
    const selectors = {
      express: '.wc-block-components-express-payment', paypal: '#express-payment-method-ppcp-gateway-paypal, #ppc-button-ppcp-gateway, #ppc-button-ppcp-gateway-v6',
      wooGoogle: '#express-payment-method-woocommerce_payments_express_checkout_googlePay',
      paypalGoogle: '#express-payment-method-ppcp-googlepay, #ppc-button-ppcp-googlepay, #ppc-button-googlepay-container, #ppc-button-ppcp-googlepay-v6, .ppcp-button-googlepay',
      paylater: '#express-payment-method-ppcp-gateway-paylater',
      afterpay: '.afterpay-paragraph, .afterpay-logo, .payment_method_afterpay_clearpay, [data-afterpay-widget]',
      paypalMessage: '#ppcp-cart-paylater-messages, .ppcp-messages',
    };
    if (where === 'home') {
      const account = all('[data-petish-account], .petish-account-menu').find(visible), market = all('.petish-market-selector').find(visible);
      const accountBox = account?.getBoundingClientRect(), marketBox = market?.getBoundingClientRect();
      return {
      observations: { home_account_in_navigation: inside('.petish-account-menu', '.main-nav, .mobile-main-nav, nav'),
        home_account_avatar_present: count('.petish-account-avatar') > 0,
        home_native_cart_present: count('.shopping-cart, .wc-shopping-cart, .mini-woocommerce-cart, .wc-ico-cart') > 0,
        home_cart_badge_present: count('.petish-cart-badge') > 0,
        home_account_after_currency: !!(accountBox && marketBox && accountBox.left >= marketBox.right - 1 && Math.abs(accountBox.top - marketBox.top) < 48) },
      metrics: { home_account_count: count('.petish-account-menu'), home_native_cart_count: count('.shopping-cart, .wc-shopping-cart, .mini-woocommerce-cart, .wc-ico-cart'), home_badge_count: count('.petish-cart-badge'), home_account_left_px: accountBox?.left || 0, home_market_right_px: marketBox?.right || 0 } };
    }
    if (where === 'product') return { observations: { product_afterpay_present: count(selectors.afterpay) > 0, product_paypal_present: count('.petish-product-paypal, #ppc-button-ppcp-gateway') > 0 }, metrics: {} };
    const express = '.wc-block-components-express-payment', sidebar = '.wc-block-cart__sidebar';
    const host = all('paypal-message').find(visible), hostBox = host?.getBoundingClientRect();
    return { observations: {
      cart_block_present: count('.wc-block-cart, .wp-block-woocommerce-cart') > 0, cart_sidebar_present: count(sidebar) > 0,
      cart_express_in_sidebar: inside(express, sidebar), cart_paypal_in_express: inside(selectors.paypal, express),
      cart_afterpay_in_express: inside(selectors.afterpay, express), cart_afterpay_in_sidebar: inside(selectors.afterpay, sidebar),
      cart_wcpay_google_in_express: inside(selectors.wooGoogle, express), cart_paypal_google_in_express: inside(selectors.paypalGoogle, express),
      cart_paylater_in_express: inside(selectors.paylater, express),
      cart_paypal_google_v5_present: count('#ppc-button-ppcp-googlepay, #ppc-button-googlepay-container') > 0,
      cart_paypal_google_v6_present: count('#ppc-button-ppcp-googlepay-v6') > 0, cart_paypal_v6_present: count('#ppc-button-ppcp-gateway-v6') > 0,
      cart_paypal_message_in_sidebar: inside(selectors.paypalMessage + ', paypal-message', sidebar),
      cart_paypal_message_in_express: inside(selectors.paypalMessage + ', paypal-message', express),
      cart_paypal_message_shadow_host_present: count('paypal-message') > 0, cart_paypal_message_shadow_host_visible: !!host,
      cart_paypal_message_direct_sidebar: all(selectors.paypalMessage + ', paypal-message').some(el => el.parentElement?.matches(sidebar)),
      cart_paypal_message_bare_sidebar_wrapper: all(selectors.paypalMessage + ', paypal-message').some(el => el.parentElement?.tagName === 'DIV' && !el.parentElement.id && !el.parentElement.className && el.parentElement.parentElement?.matches(sidebar)),
      cart_paypal_message_block_wrapper: all(selectors.paypalMessage + ', paypal-message').some(el => el.closest('[data-block-name="woocommerce-paypal-payments/cart-paylater-messages"]')),
      cart_stripe_message_in_sidebar: inside('#payment-method-message, .wc-block-components-bnpl-wrapper', sidebar), cart_google_visible: all(selectors.wooGoogle + ', ' + selectors.paypalGoogle).some(visible),
      cart_paypal_visible: all(selectors.paypal).some(visible), cart_afterpay_visible: all(selectors.afterpay).some(visible),
    }, metrics: { cart_row_count: count('.wc-block-cart-items__row, .cart_item'), cart_express_count: count(express),
      cart_paypal_count: count(selectors.paypal), cart_wcpay_google_count: count(selectors.wooGoogle), cart_paypal_google_count: count(selectors.paypalGoogle),
      cart_paylater_count: count(selectors.paylater), cart_afterpay_count: count(selectors.afterpay), cart_paypal_message_count: count(selectors.paypalMessage), cart_paypal_message_shadow_host_count: count('paypal-message'), cart_paypal_message_shadow_host_width_px: hostBox?.width || 0, cart_paypal_message_shadow_host_height_px: hostBox?.height || 0 } };
  }, location);
}
function merge(report, facts) {
  for (const [key, value] of Object.entries(facts.observations)) if (PUBLIC_SCHEMA.observations.includes(key) && typeof value === 'boolean') report.observations[key] = value;
  for (const [key, value] of Object.entries(facts.metrics)) if (PUBLIC_SCHEMA.metrics.includes(key)) report.metrics[key] = bounded(value);
}
async function inspectAfterpay(page, report) {
  const brand = await page.evaluate(() => {
    const el = document.querySelector('.petish-afterpay-brand-heading'), frame = el?.querySelector('iframe');
    if (!el || !frame) return { present: false, below: false, width: 0, height: 0 };
    const before = getComputedStyle(el, '::before'), style = getComputedStyle(el), box = el.getBoundingClientRect(), iframe = frame.getBoundingClientRect();
    const width = parseFloat(before.width) || 0, height = parseFloat(before.height) || 0;
    return { present: width > 0 && height > 0 && before.backgroundImage !== 'none' && style.display !== 'none' && box.width > 0 && iframe.height > 0,
      below: iframe.top >= box.top + (parseFloat(style.paddingTop) || 0) + height - 1, width, height };
  });
  report.observations.afterpay_brand_badge_present = brand.present; report.observations.afterpay_message_below_brand_badge = brand.present && brand.below;
  report.metrics.afterpay_brand_badge_width_px = bounded(brand.width); report.metrics.afterpay_brand_badge_height_px = bounded(brand.height);
  const frames = page.frames().filter(frame => { try { const url = new URL(frame.url()); return frame !== page.mainFrame() && /(?:^|\.)(?:afterpay|stripe)\.com$/i.test(url.hostname); } catch { return false; } });
  report.metrics.cart_afterpay_frame_count = 0;
  for (const frame of [page.mainFrame(), ...frames]) {
    try {
      const facts = await frame.evaluate(isProviderFrame => {
        const visible = el => { const r = el.getBoundingClientRect(), s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.display !== 'none' && s.visibility !== 'hidden'; };
        const logos = [...document.querySelectorAll('.afterpay-logo, [class*="afterpay" i][class*="logo" i], img[alt*="Afterpay" i], svg[aria-label*="Afterpay" i]')].filter(visible);
        const paragraphs = [...document.querySelectorAll('.afterpay-paragraph, [class*="afterpay-paragraph" i], [class*="afterpay-message" i], [class*="AfterpayClearpayMessage"]')].filter(visible);
        const logo = logos[0], message = paragraphs.find(el => logo && el.contains(logo)) || paragraphs[0] || (isProviderFrame && logo ? document.body : logo?.parentElement);
        const links = message ? [...message.querySelectorAll('a, button')].filter(el => visible(el) && /see\s+plans/i.test(el.textContent || '')) : [];
        const link = links[0];
        const box = el => el?.getBoundingClientRect();
        const l = box(logo), m = box(message), a = box(link);
        return { observations: { afterpay_logo_present: !!logo, afterpay_see_plans_present: !!link,
          afterpay_see_plans_below: !!(a && l && a.top >= l.bottom - 1) },
          metrics: { afterpay_logo_count: Math.min(99, logos.length), afterpay_logo_width_px: l?.width || 0, afterpay_logo_height_px: l?.height || 0,
            afterpay_font_px: message ? parseFloat(getComputedStyle(message).fontSize) || 0 : 0,
            afterpay_link_font_px: link ? parseFloat(getComputedStyle(link).fontSize) || 0 : 0,
            afterpay_message_width_px: m?.width || 0, afterpay_message_height_px: m?.height || 0 } };
      }, frame !== page.mainFrame());
      if (frame !== page.mainFrame() && (facts.observations.afterpay_logo_present || /afterpay/i.test(frame.url()))) {
        report.observations.afterpay_frame_present = true; report.observations.afterpay_frame_inspected = true;
        report.metrics.cart_afterpay_frame_count = Math.min(99, report.metrics.cart_afterpay_frame_count + 1);
      }
      for (const [key, value] of Object.entries(facts.observations)) report.observations[key] ||= value;
      for (const [key, value] of Object.entries(facts.metrics)) report.metrics[key] = Math.max(report.metrics[key], bounded(value));
    } catch { /* A detached or blocked provider frame is an observation, never fabricated content. */ }
  }
}
async function cartAPI(page, method, pathname, nonce, body = '') {
  return page.evaluate(async args => {
    const response = await fetch(args.pathname, { method: args.method, credentials: 'same-origin', redirect: 'error',
      signal: AbortSignal.timeout(20000), headers: { Accept: 'application/json', ...(args.nonce ? { Nonce: args.nonce } : {}), ...(args.body ? { 'Content-Type': 'application/json' } : {}) }, ...(args.body ? { body: args.body } : {}) });
    const raw = await response.text(); if (raw.length > 1048576) throw new Error('cart');
    const value = JSON.parse(raw);
    return { ok: response.ok, nonce: response.headers.get('Nonce') || args.nonce,
      items: Array.isArray(value.items) ? value.items.map(item => ({ id: item.id, quantity: item.quantity })) : null };
  }, { method, pathname, nonce, body });
}
export async function inspectLive() {
  const report = emptyReport(); let browser, context, page, permit = null, nonce = '', selected = 0, ownedCart = false, blocked = 0;
  try {
    report.category = 'browser'; const { chromium } = require('playwright');
    const installed = chromium.executablePath();
    browser = await chromium.launch({ headless: true, ...(fs.existsSync(installed) ? {} : fs.existsSync('/usr/bin/chromium') ? { executablePath: '/usr/bin/chromium' } : {}), args: ['--no-sandbox'] });
    report.checks.browser_started = true;
    context = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'en-AU', serviceWorkers: 'block' });
    report.checks.fresh_guest_context = (await context.cookies()).length === 0;
    await context.route('**/*', async route => {
      const request = route.request();
      if (allowedRequest(request.url(), request.method(), request.resourceType(), request.postData() || '', permit)) {
        const ownRead = auditedSessionRead(request.url(), request.method(), request.postData() || '');
        if (ownRead) { const kind = ownRead === 'account' ? 'account_fragment_read' : 'cart_count_fragment_read'; report.observations[kind + '_allowed'] = true; report.metrics[kind + '_count'] = Math.min(METRIC_MAX, report.metrics[kind + '_count'] + 1); }
        if (publicStripeSession(request.url(), request.method(), request.postData() || '')) { report.observations.stripe_session_initialization_allowed = true; report.metrics.stripe_session_initialization_count = Math.min(METRIC_MAX, report.metrics.stripe_session_initialization_count + 1); }
        if (permit && request.method() === permit.method && new URL(request.url()).origin === ORIGIN && new URL(request.url()).pathname === permit.path) permit.used = true;
        return route.continue();
      }
      blocked++; report.observations.other_requests_blocked = true;
      try {
        const url = new URL(request.url()), method = request.method();
        if (PAYMENT_ACTION.test(url.pathname)) { report.observations.checkout_requests_blocked = true; report.observations.payment_action_blocked = true; report.metrics.blocked_payment_action_count = Math.min(METRIC_MAX, report.metrics.blocked_payment_action_count + 1); }
        if (PROVIDER_HOST.test(url.hostname) || url.hostname === 'pay.google.com') {
          const key = method === 'GET' || method === 'HEAD' ? 'get' : 'post'; report.observations['provider_' + key + '_blocked'] = true;
          if (key === 'post' && !PAYMENT_ACTION.test(url.pathname)) report.observations.provider_bootstrap_blocked = true;
          report.metrics['blocked_provider_' + key + '_count'] = Math.min(METRIC_MAX, report.metrics['blocked_provider_' + key + '_count'] + 1);
        }
        if (url.origin === ORIGIN && url.pathname.startsWith('/wp-json/wc/store/')) { report.observations.store_api_blocked = true; report.metrics.blocked_store_api_count = Math.min(METRIC_MAX, report.metrics.blocked_store_api_count + 1); }
      } catch {}
      return route.abort('blockedbyclient');
    });
    report.checks.network_barrier_enforced = true; page = await context.newPage(); page.setDefaultTimeout(20000);
    const navigate = async (route, category) => { report.category = category; const response = await page.goto(ORIGIN + route, { waitUntil: 'domcontentloaded', timeout: 45000 }); if (!response || response.status() !== 200 || page.url() !== ORIGIN + route) throw new Error(category); return response; };
    await navigate('/', 'home'); report.observations.home_http_ok = true; await page.waitForTimeout(1500); merge(report, await pageFacts(page, 'home'));
    const initial = await cartAPI(page, 'GET', '/wp-json/wc/store/v1/cart', nonce);
    if (!initial.ok || !Array.isArray(initial.items) || initial.items.length !== 0 || typeof initial.nonce !== 'string' || !/^[a-zA-Z0-9]{6,64}$/.test(initial.nonce)) throw new Error('cart');
    nonce = initial.nonce; report.checks.initial_cart_empty = true;
    await navigate(PRODUCT, 'product'); report.observations.product_http_ok = true; await page.waitForTimeout(1500); merge(report, await pageFacts(page, 'product'));
    selected = await page.locator('form.variations_form').evaluate(form => {
      const values = JSON.parse(form.getAttribute('data-product_variations') || 'null');
      if (!Array.isArray(values)) return 0;
      const value = values.find(v => Number.isSafeInteger(v.variation_id) && v.variation_id > 0 && v.is_purchasable === true && v.is_in_stock === true && v.variation_is_active !== false && v.variation_is_visible !== false && (!v.max_qty || Number(v.max_qty) >= 2) && (!v.min_qty || Number(v.min_qty) <= 2));
      return value?.variation_id || 0;
    });
    if (!Number.isSafeInteger(selected) || selected < 1) throw new Error('product'); report.checks.public_variation_selected = true;
    report.category = 'cart'; const body = JSON.stringify({ id: selected, quantity: 2 }); permit = { method: 'POST', path: '/wp-json/wc/store/v1/cart/add-item', body, used: false }; ownedCart = true;
    let cart; try { cart = await cartAPI(page, 'POST', permit.path, nonce, body); } finally { permit = null; }
    if (!cart.ok || !Array.isArray(cart.items) || cart.items.length !== 1 || cart.items[0].id !== selected || cart.items[0].quantity !== 2) throw new Error('cart');
    nonce = cart.nonce; report.checks.guest_cart_created = true; report.checks.cart_quantity_verified = true;
    await navigate('/cart/', 'cart'); report.observations.cart_http_ok = true;
    await page.locator('.wc-block-cart-items__row, .cart_item').first().waitFor({ state: 'visible', timeout: 30000 });
    report.checks.cart_page_loaded = true; report.category = 'providers';
    await page.waitForTimeout(8000); merge(report, await pageFacts(page, 'cart')); await inspectAfterpay(page, report); report.checks.passive_sdk_inspected = true;
  } catch { /* Report only the finite stage and observations; never exception text, page data or SDK URLs. */ }
  finally {
    if (page && report.checks.initial_cart_empty) {
      for (let attempt = 0; attempt < 2 && !report.checks.created_guest_cart_cleared; attempt++) {
        try {
          if (ownedCart) { permit = { method: 'DELETE', path: '/wp-json/wc/store/v1/cart/items', body: '', used: false }; try { const cleared = await cartAPI(page, 'DELETE', permit.path, nonce); nonce = cleared.nonce; } finally { permit = null; } }
          const after = await cartAPI(page, 'GET', '/wp-json/wc/store/v1/cart', nonce);
          report.checks.created_guest_cart_cleared = after.ok && Array.isArray(after.items) && after.items.length === 0;
        } catch { permit = null; }
      }
    }
    report.metrics.blocked_request_count = Math.min(METRIC_MAX, blocked);
    if (ownedCart && !report.checks.created_guest_cart_cleared) report.category = 'cleanup';
    if (context) await context.close().catch(() => {}); if (browser) await browser.close().catch(() => {});
  }
  if (Object.values(report.checks).every(Boolean)) { report.status = 'success'; report.category = 'complete'; }
  return screenReport(report);
}
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try { if (process.argv.length !== 3) throw new Error('input'); reportDestination(process.argv[2]); const report = await inspectLive(); writeReport(process.argv[2], report); process.exitCode = report.status === 'success' ? 0 : 1; }
  catch { process.stderr.write('The fixed live storefront inspection could not be completed.\n'); process.exitCode = 1; }
}
