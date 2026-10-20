/** Private native WooPayments TEST checkout. Never fabricates paid status. */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ORIGIN = 'https://staging.petish.biz';
const THIRD_PARTY_HOSTS = new Set([
  'js.stripe.com', 'api.stripe.com', 'm.stripe.network', 'q.stripe.com',
  'm.stripe.com', 'b.stripecdn.com', 'r.stripe.com',
  'hcaptcha.com', 'www.hcaptcha.com', 'newassets.hcaptcha.com', 'imgs.hcaptcha.com',
]);
const SAFETY = {
  environment_staging: true,
  woocommerce_test_mode: true,
  wcpay_dev_mode: true,
  account_is_live: false,
  guard_runtime_verified: true,
  suppliers_blocked: true,
  mail_capture_verified: true,
  webhooks_blocked: true,
};
const STAGES = new Set(['input', 'browser', 'login', 'cart', 'checkout', 'address',
  'shipping', 'test_key', 'decline', 'retry', 'receipt', 'complete', 'network']);
const CART_STEPS = new Set(['open', 'empty', 'add', 'verify', 'api']);
const CART_CODES = new Set(['none', 'rest_not_logged_in', 'rest_cookie_invalid_nonce',
  'rest_forbidden', 'rest_cannot_view', 'woocommerce_rest_authentication_error',
  'woocommerce_rest_missing_session', 'woocommerce_rest_cart_error',
  'woocommerce_rest_cart_item_error', 'woocommerce_rest_missing_nonce',
  'woocommerce_rest_invalid_nonce', 'wp_rest_nonce_unavailable', 'network',
  'invalid_response', 'other']);

export function validateInput(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)
      || value.url !== ORIGIN || value.variation_id !== 246 || value.parent_id !== 242
      || (value.attribute_color !== undefined && value.attribute_color !== 'Blue')
      || (value.attribute_pa_color !== undefined && value.attribute_pa_color !== 'blue')
      || (value.attribute_color !== 'Blue' && value.attribute_pa_color !== 'blue')
      || typeof value.run_id !== 'string' || !/^[A-Za-z0-9_-]{1,80}$/.test(value.run_id)
      || typeof value.login !== 'string' || !value.login || value.login.length > 200
      || typeof value.password !== 'string' || !value.password || value.password.length > 2048
      || !value.safety || typeof value.safety !== 'object'
      || Object.entries(SAFETY).some(([key, expected]) => value.safety[key] !== expected)
      || typeof value.shipping_method_id !== 'string'
      || !/^[A-Za-z0-9:_-]{1,150}$/.test(value.shipping_method_id)) {
    throw new Error('input');
  }
  const email = value.customer_email ?? `rehearsal-${value.run_id}@petish.invalid`;
  if (typeof email !== 'string' || email.length > 254
      || !/^[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9.-]+\.(?:invalid|test)$/.test(email)) {
    throw new Error('input');
  }
  return { ...value, customer_email: email };
}

export function allowedRequest(raw) {
  try {
    const url = new URL(raw);
    if (url.protocol !== 'https:' || url.username || url.password
        || (url.port && url.port !== '443')) return false;
    return url.origin === ORIGIN || THIRD_PARTY_HOSTS.has(url.hostname);
  } catch { return false; }
}

export function publicReport(value) {
  const fields = ['browser_started', 'customer_login_verified', 'cart_prepared',
    'checkout_rendered', 'shipping_verified', 'test_publishable_key_verified',
    'decline_verified', 'paid_receipt_verified', 'native_checkout_used',
    'unsafe_request_blocked', 'same_order_retried'];
  fields.push('cart_page_loaded', 'cart_api_available', 'cart_empty_verified',
    'variation_add_request_completed', 'expected_variation_in_cart');
  const result = {};
  for (const key of fields) if (typeof value[key] === 'boolean') result[key] = value[key];
  if (Number.isInteger(value.payment_attempts) && value.payment_attempts >= 0 && value.payment_attempts <= 2) {
    result.payment_attempts = value.payment_attempts;
  }
  if (STAGES.has(value.failure_stage)) result.failure_stage = value.failure_stage;
  if (CART_STEPS.has(value.cart_failure_step)) result.cart_failure_step = value.cart_failure_step;
  if (CART_CODES.has(value.cart_api_error_code)) result.cart_api_error_code = value.cart_api_error_code;
  for (const key of ['cart_page_http_status', 'cart_api_http_status']) {
    if (Number.isInteger(value[key]) && (value[key] === 0 || (value[key] >= 100 && value[key] <= 599))) {
      result[key] = value[key];
    }
  }
  return result;
}

function readPrivateInput(filename) {
  const resolved = path.resolve(filename);
  const stat = fs.lstatSync(resolved);
  if (!stat.isFile() || stat.isSymbolicLink() || stat.nlink !== 1
      || (stat.mode & 0o7777) !== 0o600 || stat.size < 1 || stat.size > 16384
      || fs.realpathSync(resolved) !== resolved) throw new Error('input');
  return validateInput(JSON.parse(fs.readFileSync(resolved, 'utf8')));
}

function writePrivateReport(filename, value) {
  const resolved = path.resolve(filename);
  if (fs.realpathSync(path.dirname(resolved)) !== path.dirname(resolved)) throw new Error('input');
  const descriptor = fs.openSync(resolved,
    fs.constants.O_WRONLY | fs.constants.O_CREAT | fs.constants.O_EXCL | fs.constants.O_NOFOLLOW, 0o600);
  try { fs.writeFileSync(descriptor, JSON.stringify(value, null, 2) + '\n'); }
  finally { fs.closeSync(descriptor); }
}

async function visibleFirst(locators) {
  for (const locator of locators) {
    if (await locator.count() && await locator.first().isVisible().catch(() => false)) return locator.first();
  }
  return null;
}

async function fillVisible(page, selectors, value) {
  const control = await visibleFirst(selectors.map(selector => page.locator(selector)));
  if (!control) return false;
  await control.fill(value);
  await control.blur();
  return true;
}

async function countryOrState(page, selectors, value, text) {
  for (const selector of selectors) {
    const control = page.locator(selector).first();
    if (!await control.count() || !await control.isVisible().catch(() => false)) continue;
    if (await control.evaluate(element => element.tagName === 'SELECT')) await control.selectOption(value);
    else await control.fill(text);
    await control.blur();
  }
}

async function cartData(page) {
  return page.evaluate(async () => {
    const safeError = (value, status = 0) => ({ value: null, nonce: null, wpNonce: null,
      http_status: Number.isInteger(status) ? status : 0,
      error_code: typeof value?.code === 'string' ? value.code : 'invalid_response' });
    if (typeof window.wp?.apiFetch === 'function') {
      try {
        const response = await window.wp.apiFetch({ path: '/wc/store/v1/cart', parse: false });
        const value = typeof response?.json === 'function' ? await response.json() : response;
        if (Array.isArray(value?.items)) return { value,
          nonce: response?.headers?.get?.('Nonce') ?? null,
          wpNonce: window.__petishRehearsalWpNonce ?? null,
          http_status: response?.status ?? 200, error_code: 'none' };
      } catch {}
    }
    // Core admin-ajax authenticates the cookie session without REST's nonce
    // middleware. Its nonce is wp_rest; Store API's separate Nonce is not one.
    let wpNonce = window.__petishRehearsalWpNonce;
    if (!/^[A-Za-z0-9]{10}$/.test(wpNonce ?? '')) {
      try {
        const response = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce', {
          credentials: 'same-origin', cache: 'no-store',
        });
        wpNonce = (await response.text()).trim();
        if (!response.ok || !/^[A-Za-z0-9]{10}$/.test(wpNonce)) {
          return safeError({ code: 'wp_rest_nonce_unavailable' }, response.status);
        }
        window.__petishRehearsalWpNonce = wpNonce;
      } catch { return safeError({ code: 'network' }); }
    }
    try {
      const response = await fetch('/wp-json/wc/store/v1/cart', {
        credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': wpNonce },
      });
      const value = await response.json().catch(() => null);
      if (!response.ok || !Array.isArray(value?.items)) return safeError(value, response.status);
      return { value, nonce: response.headers.get('Nonce'), wpNonce,
        http_status: response.status, error_code: 'none' };
    } catch { return safeError({ code: 'network' }); }
  }).catch(() => ({ value: null, nonce: null, wpNonce: null, http_status: 0, error_code: 'network' }));
}

function recordCart(result, cart) {
  result.cart_api_http_status = cart.http_status;
  result.cart_api_available = Array.isArray(cart.value?.items);
  result.cart_api_error_code = CART_CODES.has(cart.error_code) ? cart.error_code : 'other';
}

async function emptyCart(page, result) {
  result.cart_failure_step = 'open';
  const response = await page.goto(ORIGIN + '/cart/', { waitUntil: 'domcontentloaded' });
  result.cart_page_http_status = response?.status() ?? 0;
  result.cart_page_loaded = new URL(page.url()).origin === ORIGIN && (response?.ok() ?? false);
  if (!result.cart_page_loaded) throw new Error('cart');
  result.cart_failure_step = 'empty';
  for (let attempt = 0; attempt < 30; attempt++) {
    const cart = await cartData(page);
    recordCart(result, cart);
    if (!result.cart_api_available) { result.cart_failure_step = 'api'; throw new Error('cart'); }
    if (cart.value.items.length === 0) { result.cart_empty_verified = true; return; }
    const remove = await visibleFirst([
      page.locator('.wc-block-cart-item__remove-link'), page.locator('a.remove'),
      page.getByRole('button', { name: /remove item|remove this item/i }),
    ]);
    if (!remove) throw new Error('cart');
    await remove.click();
    await page.waitForTimeout(700);
  }
  throw new Error('cart');
}

async function prepareCart(page, input, result) {
  await emptyCart(page, result);
  result.cart_failure_step = 'add';
  const url = new URL('/cart/', ORIGIN);
  url.searchParams.set('add-to-cart', String(input.parent_id));
  url.searchParams.set('variation_id', String(input.variation_id));
  url.searchParams.set('attribute_color', 'Blue');
  url.searchParams.set('quantity', '1');
  const response = await page.goto(url.href, { waitUntil: 'domcontentloaded' });
  result.variation_add_request_completed = new URL(page.url()).origin === ORIGIN && (response?.ok() ?? false);
  if (!result.variation_add_request_completed) throw new Error('cart');
  result.cart_failure_step = 'verify';
  for (let attempt = 0; attempt < 20; attempt++) {
    const cart = await cartData(page);
    recordCart(result, cart);
    const items = cart?.value?.items;
    if (Array.isArray(items) && items.length === 1
        && items[0].id === input.variation_id && items[0].quantity === 1) {
      result.expected_variation_in_cart = true;
      delete result.cart_failure_step;
      return;
    }
    if (!result.cart_api_available) { result.cart_failure_step = 'api'; throw new Error('cart'); }
    await page.waitForTimeout(500);
  }
  throw new Error('cart');
}

async function fillAddress(page, input) {
  for (const prefix of ['shipping', 'billing']) {
    await countryOrState(page, [`#${prefix}-country`, `#${prefix}_country`], 'AU', 'Australia');
    await fillVisible(page, [`#${prefix}-first_name`, `#${prefix}_first_name`], 'Petish');
    await fillVisible(page, [`#${prefix}-last_name`, `#${prefix}_last_name`], `Rehearsal ${input.run_id}`);
    await fillVisible(page, [`#${prefix}-address_1`, `#${prefix}_address_1`], 'Staging test address');
    await fillVisible(page, [`#${prefix}-city`, `#${prefix}_city`], 'Bundoora');
    await countryOrState(page, [`#${prefix}-state`, `#${prefix}_state`], 'VIC', 'Victoria');
    await fillVisible(page, [`#${prefix}-postcode`, `#${prefix}_postcode`], '3083');
    await fillVisible(page, [`#${prefix}-phone`, `#${prefix}_phone`], '0400000000');
  }
  if (!await fillVisible(page, ['#email', '#billing-email', '#billing_email', 'input[type=email]'], input.customer_email)) {
    throw new Error('address');
  }
}

async function verifyShipping(page, input) {
  for (let attempt = 0; attempt < 40; attempt++) {
    const cart = await cartData(page);
    const packages = cart?.value?.shipping_rates;
    if (Array.isArray(packages) && packages.length === 1) {
      const rates = packages[0].shipping_rates;
      const desired = Array.isArray(rates) && rates.find(rate => rate.rate_id === input.shipping_method_id);
      if (desired?.selected === true) return;
      if (desired) {
        const radio = page.locator(`input[type=radio][value="${input.shipping_method_id}"]`).first();
        if (await radio.count()) {
          await radio.check({ force: true }).catch(() => {});
        } else if (cart.nonce) {
          await page.evaluate(async ({ nonce, wpNonce, rate, packageId }) => {
            if (typeof window.wp?.apiFetch === 'function') {
              try {
                await window.wp.apiFetch({ path: '/wc/store/v1/cart/select-shipping-rate', method: 'POST',
                  headers: { Nonce: nonce }, data: { package_id: packageId, rate_id: rate } });
                return true;
              } catch { return false; }
            }
            const response = await fetch('/wp-json/wc/store/v1/cart/select-shipping-rate', {
              method: 'POST', credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json', Nonce: nonce, 'X-WP-Nonce': wpNonce },
              body: JSON.stringify({ package_id: packageId, rate_id: rate }),
            });
            return response.ok;
          }, { nonce: cart.nonce, wpNonce: cart.wpNonce, rate: input.shipping_method_id, packageId: packages[0].package_id });
        }
      }
    }
    await page.waitForTimeout(500);
  }
  throw new Error('shipping');
}

function keyFlags(text, state) {
  if (/pk_live_[A-Za-z0-9]+/.test(text)) state.liveKeyObserved = true;
  if (/pk_test_[A-Za-z0-9]+/.test(text)) state.testKeyObserved = true;
}

async function checkTestKey(page, state) {
  for (let attempt = 0; attempt < 30; attempt++) {
    for (const frame of page.frames()) keyFlags(frame.url(), state);
    const flags = await page.evaluate(() => {
      const roots = [window.wcpayConfig, window.wcpaySettings];
      try { roots.push(window.wc?.wcSettings?.getSetting?.('woocommerce_payments_data')); } catch {}
      let test = false, live = false;
      const visit = (value, depth = 0) => {
        if (depth > 6 || value === null || value === undefined) return;
        if (typeof value === 'string') {
          test ||= /pk_test_[A-Za-z0-9]+/.test(value);
          live ||= /pk_live_[A-Za-z0-9]+/.test(value);
        } else if (typeof value === 'object') {
          for (const item of Object.values(value).slice(0, 200)) visit(item, depth + 1);
        }
      };
      for (const root of roots) visit(root);
      return { test, live };
    });
    state.testKeyObserved ||= flags.test;
    state.liveKeyObserved ||= flags.live;
    if (state.liveKeyObserved) throw new Error('test_key');
    if (state.testKeyObserved) return;
    await page.waitForTimeout(500);
  }
  throw new Error('test_key');
}

async function cardControl(page, selectors) {
  for (const frame of page.frames()) {
    const found = await visibleFirst(selectors.map(selector => frame.locator(selector)));
    if (found) return found;
  }
  return null;
}

async function fillCard(page, number) {
  const expiry = `12${String(new Date().getUTCFullYear() + 4).slice(-2)}`;
  for (let attempt = 0; attempt < 30; attempt++) {
    const card = await cardControl(page, ['input[name="number"]', 'input[name="cardnumber"]',
      'input[name="cardNumber"]', 'input[autocomplete="cc-number"]', 'input[data-elements-stable-field-name="cardNumber"]']);
    if (card) {
      await card.fill(number);
      const expiration = await cardControl(page, ['input[name="expiry"]', 'input[name="exp-date"]',
        'input[autocomplete="cc-exp"]', 'input[data-elements-stable-field-name="cardExpiry"]']);
      const cvc = await cardControl(page, ['input[name="cvc"]', 'input[name="cvv"]',
        'input[autocomplete="cc-csc"]', 'input[data-elements-stable-field-name="cardCvc"]']);
      if (!expiration || !cvc) throw new Error('checkout');
      await expiration.fill(expiry);
      await cvc.fill('123');
      const postal = await cardControl(page, ['input[name="postalCode"]', 'input[autocomplete="postal-code"]']);
      if (postal) await postal.fill('3083');
      return;
    }
    await page.waitForTimeout(500);
  }
  throw new Error('checkout');
}

async function preparePayment(page) {
  const method = await visibleFirst([
    page.locator('#payment_method_woocommerce_payments'),
    page.locator('input[type=radio][value="woocommerce_payments"]'),
    page.locator('#radio-control-wc-payment-method-options-woocommerce_payments'),
  ]);
  if (method) await method.check({ force: true });
  for (const locator of [page.locator('#terms'), page.getByRole('checkbox', { name: /agree.*terms|terms.*conditions/i })]) {
    if (await locator.count() && await locator.first().isVisible().catch(() => false)) await locator.first().check();
  }
  for (const locator of [page.locator('#wc-woocommerce_payments-new-payment-method'),
    page.getByRole('checkbox', { name: /save.*payment|save.*card/i })]) {
    if (await locator.count() && await locator.first().isVisible().catch(() => false)) await locator.first().uncheck();
  }
}

async function submit(page) {
  const button = await visibleFirst([
    page.locator('button.wc-block-components-checkout-place-order-button'), page.locator('#place_order'),
    page.getByRole('button', { name: /^(place order|pay now|pay for order)/i }),
  ]);
  if (!button) throw new Error('checkout');
  await button.click();
}

function receipt(url) {
  try {
    const parsed = new URL(url);
    const match = parsed.pathname.match(/\/order-received\/([1-9][0-9]*)\/?$/);
    const key = parsed.searchParams.get('key');
    if (parsed.origin !== ORIGIN || !match || !key || !/^wc_order_[A-Za-z0-9]+$/.test(key)) return null;
    return { order_id: Number(match[1]), order_key: key };
  } catch { return null; }
}

export async function run(input, chromium) {
  const result = { browser_started: false, customer_login_verified: false, cart_prepared: false,
    checkout_rendered: false, shipping_verified: false, test_publishable_key_verified: false,
    decline_verified: false, paid_receipt_verified: false, native_checkout_used: true,
    unsafe_request_blocked: false, payment_attempts: 0 };
  const state = { testKeyObserved: false, liveKeyObserved: false, declined: false, orderIds: [] };
  let stage = 'browser', browser;
  try {
    browser = await chromium.launch({ headless: true });
    result.browser_started = true;
    const context = await browser.newContext({ ignoreHTTPSErrors: false, locale: 'en-AU',
      timezoneId: 'Australia/Sydney', serviceWorkers: 'block', acceptDownloads: false });
    await context.route('**/*', async route => {
      const request = route.request();
      const url = request.url();
      const body = request.postData() ?? '';
      keyFlags(url + '\n' + body, state);
      if (!allowedRequest(url) || state.liveKeyObserved) {
        if (state.liveKeyObserved) result.unsafe_request_blocked = true;
        await route.abort('blockedbyclient');
        return;
      }
      if (new URL(url).origin === ORIGIN && request.method() === 'POST'
          && (/\/wc\/store\/v1\/checkout(?:[/?]|$)/.test(url) || /[?&]wc-ajax=checkout(?:&|$)/.test(url))) {
        let method;
        try { method = JSON.parse(body).payment_method; } catch { method = new URLSearchParams(body).get('payment_method'); }
        if (method !== 'woocommerce_payments' || !state.testKeyObserved) {
          result.unsafe_request_blocked = true;
          await route.abort('blockedbyclient');
          return;
        }
      }
      await route.continue();
    });
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    page.setDefaultNavigationTimeout(45000);
    page.on('response', async response => {
      try {
        const url = new URL(response.url());
        if (url.hostname === 'api.stripe.com') {
          const data = await response.json();
          if (data?.livemode === true || data?.payment_intent?.livemode === true || data?.error?.payment_intent?.livemode === true) {
            state.liveKeyObserved = true;
            result.unsafe_request_blocked = true;
          }
          if (data?.error?.code === 'card_declined') state.declined = true;
        } else if (url.origin === ORIGIN && /\/wc\/store\/v1\/checkout(?:[/?]|$)/.test(url.href)) {
          const data = await response.json();
          if (Number.isSafeInteger(data?.order_id) && data.order_id > 0 && !state.orderIds.includes(data.order_id)) {
            state.orderIds.push(data.order_id);
          }
          if (['card_declined', 'woocommerce_rest_checkout_process_payment_error'].includes(data?.code)
              && /declin/i.test(String(data?.message ?? ''))) state.declined = true;
        }
      } catch {}
    });
    stage = 'login';
    await page.goto(ORIGIN + '/wp-login.php', { waitUntil: 'domcontentloaded' });
    await page.locator('#user_login').fill(input.login);
    await page.locator('#user_pass').fill(input.password);
    await Promise.all([page.waitForURL(url => url.origin === ORIGIN && !url.pathname.endsWith('/wp-login.php'), { timeout: 45000 }),
      page.locator('#wp-submit').click()]);
    const cookies = await context.cookies(ORIGIN);
    if (!cookies.some(cookie => cookie.name.startsWith('wordpress_logged_in_'))) throw new Error('login');
    result.customer_login_verified = true;
    stage = 'cart';
    await prepareCart(page, input, result);
    result.cart_prepared = true;
    stage = 'checkout';
    await page.goto(ORIGIN + '/checkout/', { waitUntil: 'domcontentloaded' });
    if (!await visibleFirst([page.locator('.wc-block-checkout'), page.locator('form.checkout')])) throw new Error('checkout');
    result.checkout_rendered = true;
    if (state.liveKeyObserved || result.unsafe_request_blocked) throw new Error('network');
    stage = 'address';
    await fillAddress(page, input);
    if (state.liveKeyObserved || result.unsafe_request_blocked) throw new Error('network');
    stage = 'shipping';
    await verifyShipping(page, input);
    result.shipping_verified = true;
    await preparePayment(page);
    stage = 'test_key';
    await checkTestKey(page, state);
    result.test_publishable_key_verified = true;
    stage = 'decline';
    await fillCard(page, '4000000000000002');
    await verifyShipping(page, input);
    if (state.liveKeyObserved) throw new Error('network');
    result.payment_attempts++;
    await submit(page);
    for (let attempt = 0; attempt < 100; attempt++) {
      if (receipt(page.url())) throw new Error('decline');
      if (state.liveKeyObserved || result.unsafe_request_blocked) throw new Error('network');
      if (state.declined || await page.getByText(/your card was declined|card has been declined|card was declined/i).count()) {
        result.decline_verified = true;
        break;
      }
      await page.waitForTimeout(500);
    }
    if (!result.decline_verified) throw new Error('decline');
    if (state.orderIds.length > 0) result.declined_order_id = state.orderIds[0];
    stage = 'retry';
    await preparePayment(page);
    await checkTestKey(page, state);
    await fillCard(page, '4242424242424242');
    await verifyShipping(page, input);
    if (state.liveKeyObserved) throw new Error('network');
    result.payment_attempts++;
    await submit(page);
    stage = 'receipt';
    let received;
    for (let attempt = 0; attempt < 120; attempt++) {
      if (state.liveKeyObserved || result.unsafe_request_blocked) throw new Error('network');
      received = receipt(page.url());
      if (received) break;
      await page.waitForTimeout(500);
    }
    if (!received) throw new Error('receipt');
    result.order_id = received.order_id;
    result.order_key = received.order_key;
    result.created_order_ids = state.orderIds;
    result.paid_receipt_verified = true;
    if (Number.isSafeInteger(result.declined_order_id)) {
      result.same_order_retried = result.declined_order_id === received.order_id;
    }
    stage = 'complete';
  } catch {
    result.failure_stage = result.unsafe_request_blocked || state.liveKeyObserved ? 'network' : stage;
    result.created_order_ids = state.orderIds;
  } finally {
    if (browser) await browser.close().catch(() => {});
  }
  return result;
}

async function main() {
  let result = { failure_stage: 'input', payment_attempts: 0 };
  try {
    if (process.argv.length !== 4) throw new Error('input');
    const input = readPrivateInput(process.argv[2]);
    const { chromium } = await import('playwright');
    result = await run(input, chromium);
  } catch {}
  try {
    if (process.argv.length === 4) writePrivateReport(process.argv[3], result);
  } catch {
    result = { failure_stage: 'input', payment_attempts: result.payment_attempts ?? 0 };
  }
  process.stdout.write(JSON.stringify(publicReport(result)) + '\n');
  if (!result.paid_receipt_verified || result.failure_stage) process.exitCode = 1;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) await main();
