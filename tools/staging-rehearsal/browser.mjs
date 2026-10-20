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
const REQUEST_DIAGNOSTICS = {
  unsafe_reason: new Set(['live_key_observed', 'live_response_observed', 'checkout_method_unexpected', 'checkout_test_key_missing']),
  checkout_request_method: new Set(['expected', 'empty', 'missing', 'express', 'other']),
  checkout_body_format: new Set(['json', 'form', 'unparseable']),
  checkout_method_override: new Set(['none', 'put', 'patch', 'delete', 'other']),
  stripe_endpoint: new Set(['payment_methods', 'payment_intents', 'setup_intents', 'elements_sessions', 'other']),
};
const CART_CODES = new Set(['none', 'rest_not_logged_in', 'rest_cookie_invalid_nonce',
  'rest_forbidden', 'rest_cannot_view', 'woocommerce_rest_authentication_error',
  'woocommerce_rest_missing_session', 'woocommerce_rest_cart_error',
  'woocommerce_rest_cart_item_error', 'woocommerce_rest_missing_nonce',
  'woocommerce_rest_invalid_nonce', 'wp_rest_nonce_unavailable', 'network',
  'invalid_response', 'other', 'rest_invalid_param', 'woocommerce_rest_invalid_param',
  'woocommerce_rest_customer_error', 'woocommerce_rest_invalid_address']);

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
  fields.push('shipping_cart_api_available', 'shipping_one_package_verified',
    'shipping_customer_destination_verified', 'shipping_package_destination_verified',
    'shipping_expected_rate_present', 'shipping_expected_rate_selected',
    'shipping_customer_update_verified', 'checkout_currency_verified');
  fields.push('checkout_calc_totals', 'calc_totals_update_allowed');
  const result = {};
  for (const key of fields) if (typeof value[key] === 'boolean') result[key] = value[key];
  if (Number.isInteger(value.payment_attempts) && value.payment_attempts >= 0 && value.payment_attempts <= 2) {
    result.payment_attempts = value.payment_attempts;
  }
  if (STAGES.has(value.failure_stage)) result.failure_stage = value.failure_stage;
  for (const [field, allowed] of Object.entries(REQUEST_DIAGNOSTICS)) {
    if (allowed.has(value[field])) result[field] = value[field];
  }
  if (CART_STEPS.has(value.cart_failure_step)) result.cart_failure_step = value.cart_failure_step;
  if (CART_CODES.has(value.cart_api_error_code)) result.cart_api_error_code = value.cart_api_error_code;
  if (CART_CODES.has(value.shipping_api_error_code)) result.shipping_api_error_code = value.shipping_api_error_code;
  for (const [key, maximum] of [['shipping_package_count', 20], ['shipping_rate_count', 50]]) {
    if (Number.isInteger(value[key]) && value[key] >= 0 && value[key] <= maximum) result[key] = value[key];
  }
  for (const key of ['cart_page_http_status', 'cart_api_http_status', 'shipping_api_http_status']) {
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

async function cartData(page) {
  return page.evaluate(async () => {
    const safeError = (value, status = 0) => ({ value: null, nonce: null, wpNonce: null,
      http_status: Number.isInteger(status) ? status : 0,
      error_code: typeof value?.code === 'string' ? value.code : 'invalid_response' });
    // Bind both nonce types to this exact logged-in QA session before reading
    // the cart. A successful anonymous Store API GET is insufficient.
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
    if (window.wp?.apiFetch?.nonceMiddleware) window.wp.apiFetch.nonceMiddleware.nonce = wpNonce;
    else if (typeof window.wp?.apiFetch?.createNonceMiddleware === 'function'
        && typeof window.wp.apiFetch.use === 'function' && !window.__petishRehearsalNonceInstalled) {
      window.wp.apiFetch.use(window.wp.apiFetch.createNonceMiddleware(wpNonce));
      window.__petishRehearsalNonceInstalled = true;
    }
    if (window.wpApiSettings && typeof window.wpApiSettings === 'object') window.wpApiSettings.nonce = wpNonce;
    if (typeof window.wp?.apiFetch === 'function') {
      try {
        const response = await window.wp.apiFetch({ path: '/wc/store/v1/cart', parse: false,
          headers: { 'X-WP-Nonce': wpNonce } });
        const value = typeof response?.json === 'function' ? await response.json() : response;
        const nonce = response?.headers?.get?.('Nonce') ?? null;
        if (Array.isArray(value?.items) && nonce) return { value, nonce, wpNonce,
          http_status: response?.status ?? 200, error_code: 'none' };
      } catch {}
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

async function deleteCartItems(page, cart) {
  return page.evaluate(async ({ nonce, wpNonce }) => {
    const failed = (status = 0, code = 'invalid_response') => ({ completed: false,
      http_status: Number.isInteger(status) ? status : 0,
      error_code: typeof code === 'string' ? code : 'invalid_response' });
    if (!nonce) return failed(0, 'woocommerce_rest_missing_nonce');
    if (typeof window.wp?.apiFetch === 'function') {
      try {
        const response = await window.wp.apiFetch({ path: '/wc/store/v1/cart/items',
          method: 'DELETE', parse: false, headers: { Nonce: nonce } });
        if (response?.ok === true || Array.isArray(response?.items)) {
          return { completed: true, http_status: response?.status ?? 200, error_code: 'none' };
        }
      } catch {}
    }
    if (!/^[A-Za-z0-9]{10}$/.test(wpNonce ?? '')) {
      try {
        const response = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce', {
          credentials: 'same-origin', cache: 'no-store',
        });
        wpNonce = (await response.text()).trim();
        if (!response.ok || !/^[A-Za-z0-9]{10}$/.test(wpNonce)) {
          return failed(response.status, 'wp_rest_nonce_unavailable');
        }
        window.__petishRehearsalWpNonce = wpNonce;
      } catch { return failed(0, 'network'); }
    }
    try {
      const response = await fetch('/wp-json/wc/store/v1/cart/items', { method: 'DELETE',
        credentials: 'same-origin', headers: { Nonce: nonce, 'X-WP-Nonce': wpNonce } });
      if (response.ok) return { completed: true, http_status: response.status, error_code: 'none' };
      const error = await response.json().catch(() => null);
      return failed(response.status, error?.code);
    } catch { return failed(0, 'network'); }
  }, { nonce: cart.nonce, wpNonce: cart.wpNonce }).catch(() => ({ completed: false,
    http_status: 0, error_code: 'network' }));
}

async function emptyCart(page, result) {
  result.cart_failure_step = 'open';
  const response = await page.goto(ORIGIN + '/cart/', { waitUntil: 'domcontentloaded' });
  result.cart_page_http_status = response?.status() ?? 0;
  result.cart_page_loaded = new URL(page.url()).origin === ORIGIN && (response?.ok() ?? false);
  if (!result.cart_page_loaded) throw new Error('cart');
  result.cart_failure_step = 'empty';
  let cleared = false;
  let clearFailure;
  for (let attempt = 0; attempt < 30; attempt++) {
    const cart = await cartData(page);
    recordCart(result, cart);
    if (!result.cart_api_available) { result.cart_failure_step = 'api'; throw new Error('cart'); }
    if (cart.value.items.length === 0) { result.cart_empty_verified = true; return; }
    // Clear only the currently authenticated synthetic customer's cart. This
    // native, idempotent operation avoids racing the cart block's React mount.
    if (!cleared) {
      const removed = await deleteCartItems(page, cart);
      cleared = true;
      if (removed.completed) { await page.waitForTimeout(300); continue; }
      clearFailure = removed;
    }
    const remove = await visibleFirst([
      page.locator('.wc-block-cart-item__remove-link'), page.locator('a.remove'),
      page.getByRole('button', { name: /remove item|remove this item/i }),
    ]);
    if (remove) await remove.click();
    await page.waitForTimeout(500);
  }
  if (clearFailure) {
    result.cart_api_http_status = clearFailure.http_status;
    result.cart_api_error_code = CART_CODES.has(clearFailure.error_code) ? clearFailure.error_code : 'other';
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

async function verifyContactEmail(page, input) {
  const control = await visibleFirst([page.locator('#email'), page.locator('#billing-email'),
    page.locator('#billing_email'), page.locator('input[type=email]')]);
  if (!control) throw new Error('address');
  if (await control.inputValue() !== input.customer_email) {
    await control.fill(input.customer_email);
    await control.blur();
  }
  if (await control.inputValue() !== input.customer_email) throw new Error('address');
}

async function waitForCheckoutHydration(page) {
  const expires = Date.now() + 30000;
  while (Date.now() < expires) {
    const root = await visibleFirst([page.locator('.wc-block-checkout'), page.locator('form.checkout')]);
    const email = await visibleFirst([page.locator('#email'), page.locator('#billing-email'),
      page.locator('#billing_email'), page.locator('input[type=email]')]);
    // Logged-in checkout may show a saved-address summary instead of editable
    // address inputs. The authenticated Store API verifies the full address.
    if (root && email) return;
    await page.waitForTimeout(300);
  }
  throw new Error('checkout');
}

function isFixtureDestination(address) {
  return address && typeof address === 'object' && address.country === 'AU'
    && address.state === 'VIC' && String(address.postcode ?? '').replace(/\s/g, '') === '3083';
}

function shippingDiagnostics(result, cart, input) {
  result.shipping_cart_api_available = Array.isArray(cart?.value?.items);
  result.checkout_currency_verified = cart?.value?.totals?.currency_code === 'AUD';
  result.shipping_customer_destination_verified = !!isFixtureDestination(cart?.value?.shipping_address);
  const packages = cart?.value?.shipping_rates;
  result.shipping_package_count = Array.isArray(packages) ? packages.length : 0;
  result.shipping_one_package_verified = Array.isArray(packages) && packages.length === 1;
  result.shipping_package_destination_verified = result.shipping_one_package_verified
    && !!isFixtureDestination(packages[0].destination);
  const rates = result.shipping_one_package_verified && Array.isArray(packages[0].shipping_rates)
    ? packages[0].shipping_rates : [];
  result.shipping_rate_count = rates.length;
  const desired = rates.find(rate => rate.rate_id === input.shipping_method_id);
  result.shipping_expected_rate_present = !!desired;
  result.shipping_expected_rate_selected = desired?.selected === true;
}

async function prepareNativeAddress(page, input, result) {
  const cart = await cartData(page);
  shippingDiagnostics(result, cart, input);
  if (!result.checkout_currency_verified) throw new Error('shipping');
  const update = await page.evaluate(async ({ nonce, existingWpNonce, email, run }) => {
    const failed = (status = 0, code = 'invalid_response') => ({ completed: false,
      http_status: Number.isInteger(status) ? status : 0,
      error_code: typeof code === 'string' ? code : 'invalid_response' });
    if (!nonce) return failed(0, 'woocommerce_rest_missing_nonce');
    let wpNonce = existingWpNonce ?? window.__petishRehearsalWpNonce;
    if (!/^[A-Za-z0-9]{10}$/.test(wpNonce ?? '')) {
      try {
        const response = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce', {
          credentials: 'same-origin', cache: 'no-store',
        });
        wpNonce = (await response.text()).trim();
        if (!response.ok || !/^[A-Za-z0-9]{10}$/.test(wpNonce)) {
          return failed(response.status, 'wp_rest_nonce_unavailable');
        }
      } catch { return failed(0, 'network'); }
    }
    window.__petishRehearsalWpNonce = wpNonce;
    if (window.wp?.apiFetch?.nonceMiddleware) window.wp.apiFetch.nonceMiddleware.nonce = wpNonce;
    else if (typeof window.wp?.apiFetch?.createNonceMiddleware === 'function'
        && typeof window.wp.apiFetch.use === 'function' && !window.__petishRehearsalNonceInstalled) {
      window.wp.apiFetch.use(window.wp.apiFetch.createNonceMiddleware(wpNonce));
      window.__petishRehearsalNonceInstalled = true;
    }
    if (window.wpApiSettings && typeof window.wpApiSettings === 'object') window.wpApiSettings.nonce = wpNonce;
    const address = { first_name: 'Petish', last_name: `Rehearsal ${run}`,
      address_1: 'Staging test address', address_2: '', city: 'Bundoora',
      state: 'VIC', postcode: '3083', country: 'AU' };
    const data = { shipping_address: address,
      billing_address: { ...address, phone: '0400000000', email } };
    if (typeof window.wp?.apiFetch === 'function') {
      try {
        const response = await window.wp.apiFetch({ path: '/wc/store/v1/cart/update-customer',
          method: 'POST', parse: false, headers: { Nonce: nonce, 'X-WP-Nonce': wpNonce }, data });
        if (response?.ok === true || Array.isArray(response?.items)) {
          return { completed: true, http_status: response?.status ?? 200, error_code: 'none' };
        }
      } catch {}
    }
    try {
      const response = await fetch('/wp-json/wc/store/v1/cart/update-customer', { method: 'POST',
        credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Nonce: nonce,
          'X-WP-Nonce': wpNonce }, body: JSON.stringify(data) });
      if (response.ok) return { completed: true, http_status: response.status, error_code: 'none' };
      const error = await response.json().catch(() => null);
      return failed(response.status, error?.code);
    } catch { return failed(0, 'network'); }
  }, { nonce: cart?.nonce, existingWpNonce: cart?.wpNonce,
    email: input.customer_email, run: input.run_id });
  result.shipping_api_http_status = update.http_status;
  result.shipping_api_error_code = CART_CODES.has(update.error_code) ? update.error_code : 'other';
  if (!update.completed) throw new Error('shipping');
  await page.reload({ waitUntil: 'domcontentloaded' });
  await waitForCheckoutHydration(page);
  const fresh = await cartData(page);
  shippingDiagnostics(result, fresh, input);
  result.shipping_customer_update_verified = result.shipping_customer_destination_verified;
  if (!result.shipping_customer_update_verified) throw new Error('shipping');
}

async function verifyShipping(page, input, result) {
  for (let attempt = 0; attempt < 40; attempt++) {
    const cart = await cartData(page);
    shippingDiagnostics(result, cart, input);
    if (!result.checkout_currency_verified) throw new Error('shipping');
    const packages = cart?.value?.shipping_rates;
    if (Array.isArray(packages) && packages.length === 1) {
      const rates = packages[0].shipping_rates;
      const desired = Array.isArray(rates) && rates.find(rate => rate.rate_id === input.shipping_method_id);
      if (desired?.selected === true && result.shipping_customer_destination_verified
          && result.shipping_package_destination_verified) return;
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
              } catch {}
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

function stripeEndpoint(raw) {
  const pathname = new URL(raw).pathname;
  return ['payment_methods', 'payment_intents', 'setup_intents', 'elements_sessions']
    .find(endpoint => new RegExp(`^/v[0-9]+/${endpoint}(?:/|$)`).test(pathname)) ?? 'other';
}

/** Classifies private request data without returning its values. */
export function checkoutRequestPolicy(raw, body, headers = {}, testKeyObserved = false) {
  const url = new URL(raw);
  const lowerHeaders = Object.fromEntries(Object.entries(headers).map(([key, value]) => [key.toLowerCase(), value]));
  let data = null, format = 'unparseable';
  try {
    const parsed = JSON.parse(body);
    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) { data = parsed; format = 'json'; }
  } catch {
    if (body.includes('=') && !/^[\s]*[\[{]/.test(body)) {
      data = new URLSearchParams(body); format = 'form';
    }
  }
  const has = key => data instanceof URLSearchParams ? data.has(key) : !!data && Object.hasOwn(data, key);
  const get = key => data instanceof URLSearchParams ? data.get(key) : data?.[key];
  const method = !has('payment_method') ? 'missing' : get('payment_method') === '' ? 'empty'
    : get('payment_method') === 'woocommerce_payments' ? 'expected'
      : typeof get('payment_method') === 'string' && /express|payment_request|apple_pay|google_pay/i.test(get('payment_method')) ? 'express' : 'other';
  const rawOverride = lowerHeaders['x-http-method-override'];
  const override = rawOverride === undefined ? 'none' : ['PUT', 'PATCH', 'DELETE'].includes(rawOverride)
    ? rawOverride.toLowerCase() : 'other';
  const calcValues = has('__experimental_calc_totals')
    ? data instanceof URLSearchParams ? data.getAll('__experimental_calc_totals') : [get('__experimental_calc_totals')]
    : url.searchParams.getAll('__experimental_calc_totals');
  const calc = calcValues.length === 1 && [true, 1, 'true', '1'].includes(calcValues[0]);
  const diagnostics = { checkout_request_method: method, checkout_body_format: format,
    checkout_method_override: override, checkout_calc_totals: calc };
  // Gutenberg api-fetch transports native PUT/PATCH as POST. Woo's update handler
  // calculates totals only; its POST handler can take payment and stays strict.
  const updateOnly = url.origin === ORIGIN && /^\/wp-json\/wc\/store\/v1\/checkout\/?$/.test(url.pathname)
    && ['put', 'patch'].includes(override) && calc && !url.searchParams.has('_method')
    && /^[A-Za-z0-9]{10}$/.test(lowerHeaders['x-wp-nonce'] ?? '')
    && (format !== 'unparseable' || body.trim() === '')
    && ['missing', 'empty', 'expected'].includes(method);
  if (updateOnly) return { allowed: true, updateOnly: true, diagnostics };
  if (override !== 'none' || url.searchParams.has('_method') || method !== 'expected') {
    return { allowed: false, diagnostics, reason: 'checkout_method_unexpected' };
  }
  return testKeyObserved ? { allowed: true, updateOnly: false, diagnostics }
    : { allowed: false, diagnostics, reason: 'checkout_test_key_missing' };
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
  const state = { testKeyObserved: false, liveKeyObserved: false, declined: false, orderIds: [],
    paymentRequests: new WeakSet() };
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
      const headers = await request.allHeaders();
      if (new URL(url).hostname === 'api.stripe.com') {
        const authorization = headers.authorization ?? '';
        keyFlags(authorization, state);
        if (/^Basic\s+/i.test(authorization)) {
          keyFlags(Buffer.from(authorization.replace(/^Basic\s+/i, ''), 'base64').toString('utf8'), state);
        }
      }
      if (!allowedRequest(url) || state.liveKeyObserved) {
        if (state.liveKeyObserved) {
          result.unsafe_request_blocked = true;
          result.unsafe_reason ??= 'live_key_observed';
          if (new URL(url).hostname === 'api.stripe.com') result.stripe_endpoint = stripeEndpoint(url);
        }
        await route.abort('blockedbyclient');
        return;
      }
      if (new URL(url).origin === ORIGIN && request.method() === 'POST'
          && (/\/wc\/store\/v1\/checkout(?:[/?]|$)/.test(url) || /[?&]wc-ajax=checkout(?:&|$)/.test(url))) {
        const policy = checkoutRequestPolicy(url, body, headers, state.testKeyObserved);
        Object.assign(result, policy.diagnostics);
        if (policy.updateOnly) result.calc_totals_update_allowed = true;
        if (!policy.allowed) {
          result.unsafe_request_blocked = true;
          result.unsafe_reason ??= policy.reason;
          await route.abort('blockedbyclient');
          return;
        }
        if (!policy.updateOnly) state.paymentRequests.add(request);
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
            result.unsafe_reason = 'live_response_observed';
            result.stripe_endpoint = stripeEndpoint(url.href);
          }
          if (data?.error?.code === 'card_declined') state.declined = true;
        } else if (url.origin === ORIGIN && /\/wc\/store\/v1\/checkout(?:[/?]|$)/.test(url.href)
            && state.paymentRequests.has(response.request())) {
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
    await waitForCheckoutHydration(page);
    result.checkout_rendered = true;
    if (state.liveKeyObserved || result.unsafe_request_blocked) throw new Error('network');
    stage = 'shipping';
    await prepareNativeAddress(page, input, result);
    stage = 'address';
    await verifyContactEmail(page, input);
    if (state.liveKeyObserved || result.unsafe_request_blocked) throw new Error('network');
    stage = 'shipping';
    await verifyShipping(page, input, result);
    result.shipping_verified = true;
    await preparePayment(page);
    stage = 'test_key';
    await checkTestKey(page, state);
    result.test_publishable_key_verified = true;
    stage = 'decline';
    await fillCard(page, '4000000000000002');
    await verifyShipping(page, input, result);
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
    await verifyShipping(page, input, result);
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
