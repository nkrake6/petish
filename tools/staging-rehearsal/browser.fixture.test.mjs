/** Full browser flow with every response fulfilled locally; no network/payment. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { chromium } from 'playwright';
import { run, validateInput, publicReport } from './browser.mjs';

const fixtureExecutable = process.env.PETISH_FIXTURE_CHROMIUM;
const executable = fixtureExecutable || chromium.executablePath();
const available = fs.existsSync(executable);
const fixtureInput = () => validateInput({
  url: 'https://staging.petish.biz', login: 'fixture-qa', password: 'fixture-password',
  variation_id: 246, parent_id: 242, attribute_pa_color: 'blue', run_id: 'native-fixture',
  shipping_method_id: 'petish_rehearsal:native-fixture',
  safety: { environment_staging: true, woocommerce_test_mode: true, wcpay_dev_mode: true,
    account_is_live: false, guard_runtime_verified: true, suppliers_blocked: true,
    mail_capture_verified: true, webhooks_blocked: true },
});

function fixtureBrowser({ live = false, receiptId = 5001, omitSuccessOrderId = false } = {}) {
  const state = { items: [{ id: 999, quantity: 1 }], attempts: [], logins: 0, removed: 0, unexpected: [] };
  const key = live ? 'pk_live_fixture' : 'pk_test_fixture';
  const html = body => ({ status: 200, contentType: 'text/html', body: `<!doctype html><html><body>${body}</body></html>` });
  const cart = () => ({ items: state.items, shipping_rates: [{ package_id: 0,
    shipping_rates: [{ rate_id: 'petish_rehearsal:native-fixture', selected: true }] }] });
  async function fulfill(route) {
    const request = route.request(), url = new URL(request.url());
    if (url.hostname === 'js.stripe.com') {
      return route.fulfill(html(`<input name="number"><input name="expiry"><input name="cvc">
        <script>document.querySelector('[name=number]').addEventListener('input',event=>{
          parent.postMessage({fixtureNumber:event.target.value},'https://staging.petish.biz');
        });</script>`));
    }
    if (url.origin !== 'https://staging.petish.biz') {
      state.unexpected.push(url.hostname);
      return route.abort();
    }
    if (url.pathname === '/wp-login.php') {
      if (request.method() === 'POST') {
        state.logins++;
        return route.fulfill({ status: 200, contentType: 'text/html', headers: {
          'set-cookie': 'wordpress_logged_in_fixture=privatefixture; Path=/; Secure; HttpOnly; SameSite=Lax' },
          body: '<!doctype html><script>location.assign("/my-account/")</script>' });
      }
      return route.fulfill(html('<form action="/wp-login.php" method="POST"><input id="user_login" name="log"><input id="user_pass" name="pwd" type="password"><button id="wp-submit">Log in</button></form>'));
    }
    if (url.pathname === '/my-account/') return route.fulfill(html('<p>Fixture customer account</p>'));
    if (url.pathname === '/wp-json/wc/store/v1/cart') {
      return route.fulfill({ status: 200, contentType: 'application/json', headers: { Nonce: 'private-fixture-nonce' }, body: JSON.stringify(cart()) });
    }
    if (url.pathname === '/wp-json/wc/store/v1/cart/items' && request.method() === 'DELETE') {
      state.items = []; state.removed++;
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(cart()) });
    }
    if (url.pathname === '/cart/') {
      if (url.searchParams.get('add-to-cart') === '246') state.items = [{ id: 246, quantity: 1 }];
      return route.fulfill(html(state.items.length ? '<button class="wc-block-cart-item__remove-link" onclick="fetch(\'/wp-json/wc/store/v1/cart/items\',{method:\'DELETE\'}).then(()=>this.remove())">Remove item</button>' : '<p>Cart is empty</p>'));
    }
    if (url.pathname === '/checkout/') {
      return route.fulfill(html(`<main class="wc-block-checkout">
        <input id="email" type="email"><select id="shipping-country"><option value="AU">Australia</option></select>
        <input id="shipping-first_name"><input id="shipping-last_name"><input id="shipping-address_1">
        <input id="shipping-city"><select id="shipping-state"><option value="VIC">Victoria</option></select>
        <input id="shipping-postcode"><input id="shipping-phone">
        <input id="payment_method_woocommerce_payments" type="radio" value="woocommerce_payments" checked>
        <input type="checkbox" id="terms"><label for="terms">Agree to terms and conditions</label>
        <iframe style="width:600px;height:200px" src="https://js.stripe.com/v3/fixture?apiKey=${key}"></iframe>
        <button class="wc-block-components-checkout-place-order-button">Place order</button>
        <div id="result"></div></main><script>
        window.wcpayConfig={publishableKey:'${key}'};
        window.addEventListener('message',event=>{if(event.origin==='https://js.stripe.com')window.fixtureNumber=event.data.fixtureNumber;});
        document.querySelector('button').onclick=async()=>{
          const response=await fetch('/wp-json/wc/store/v1/checkout',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({payment_method:'woocommerce_payments',fixture_number:window.fixtureNumber})});
          const data=await response.json();
          if(data.declined)document.querySelector('#result').innerHTML='<div role="alert">Your card was declined.</div>';
          else location.assign(data.payment_result.redirect_url);
        };</script>`));
    }
    if (url.pathname === '/wp-json/wc/store/v1/checkout' && request.method() === 'POST') {
      const body = JSON.parse(request.postData());
      state.attempts.push(body.fixture_number);
      if (state.attempts.length === 1) return route.fulfill({ status: 200, contentType: 'application/json',
        body: JSON.stringify({ order_id: 5001, declined: true }) });
      return route.fulfill({ status: 200, contentType: 'application/json',
        body: JSON.stringify({ ...(omitSuccessOrderId ? {} : { order_id: receiptId }),
          payment_result: { redirect_url: `https://staging.petish.biz/checkout/order-received/${receiptId}/?key=wc_order_privatefixture` } }) });
    }
    if (url.pathname === `/checkout/order-received/${receiptId}/`) return route.fulfill(html('<h1>Order received</h1>'));
    if (url.pathname === '/favicon.ico') return route.fulfill({ status: 204, body: '' });
    state.unexpected.push(url.pathname);
    return route.abort();
  }
  return { state, chromium: { async launch(options) {
    const browser = await chromium.launch({ ...options, executablePath: executable });
    const newContext = browser.newContext.bind(browser);
    browser.newContext = async options => {
      const context = await newContext(options);
      const cookies = context.cookies.bind(context);
      context.cookies = async (...args) => { const values = await cookies(...args); state.cookie_names = values.map(value => value.name); return values; };
      const register = context.route.bind(context);
      context.route = async (pattern, handler) => register(pattern, route => handler({
        request: () => route.request(), abort: code => route.abort(code), continue: () => fulfill(route),
      }));
      return context;
    };
    return browser;
  } } };
}

test('full isolated native-shaped browser flow declines, retries and keeps receipt access private', { skip: !available, timeout: 30000 }, async () => {
  const fixture = fixtureBrowser();
  const result = await run(fixtureInput(), fixture.chromium);
  assert.equal(result.failure_stage, undefined, JSON.stringify({ stage: result.failure_stage, logins: fixture.state.logins, unexpected: fixture.state.unexpected, cookie_names: fixture.state.cookie_names }));
  assert.equal(result.customer_login_verified, true);
  assert.equal(result.shipping_verified, true);
  assert.equal(result.decline_verified, true);
  assert.equal(result.paid_receipt_verified, true);
  assert.equal(result.payment_attempts, 2);
  assert.equal(result.same_order_retried, true);
  assert.equal(result.order_id, 5001);
  assert.equal(result.order_key, 'wc_order_privatefixture');
  assert.deepEqual(fixture.state.attempts, ['4000000000000002', '4242424242424242']);
  assert.equal(fixture.state.logins, 1);
  assert.equal(fixture.state.removed, 1);
  assert.deepEqual(fixture.state.unexpected, []);
  assert.equal(JSON.stringify(publicReport(result)).includes('privatefixture'), false);
});

test('observing a live publishable key blocks the checkout before any submission', { skip: !available, timeout: 30000 }, async () => {
  const fixture = fixtureBrowser({ live: true });
  const result = await run(fixtureInput(), fixture.chromium);
  assert.equal(result.paid_receipt_verified, false);
  assert.equal(result.payment_attempts, 0);
  assert.equal(result.failure_stage, 'network');
  assert.equal(result.unsafe_request_blocked, true);
  assert.deepEqual(fixture.state.attempts, []);
});

test('receipt identity determines whether the declined order was retried', { skip: !available, timeout: 30000 }, async () => {
  const fixture = fixtureBrowser({ receiptId: 5002, omitSuccessOrderId: true });
  const result = await run(fixtureInput(), fixture.chromium);
  assert.equal(result.failure_stage, undefined);
  assert.equal(result.paid_receipt_verified, true);
  assert.equal(result.declined_order_id, 5001);
  assert.equal(result.order_id, 5002);
  assert.equal(result.same_order_retried, false);
});
