/* Offline native-host fixture. No SDK, checkout request, order or payment. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('../tools/staging-rehearsal/node_modules/playwright');
const root = path.resolve(__dirname, '../wordpress/wp-content/themes/dt-the7-child');
const wooRoot = [process.env.PETISH_WOOCOMMERCE_ROOT,
  path.resolve(__dirname, '../wordpress/wp-content/plugins/woocommerce'),
  '/tmp/petish-fyp-repro/WordPress-7.1.3/wp-content/plugins/woocommerce']
  .find(value => value && fs.existsSync(path.join(value, 'assets/client/blocks/cart.css')));
if (!wooRoot) throw new Error('Set PETISH_WOOCOMMERCE_ROOT to native WooCommerce source for offline Cart CSS verification.');
const css = [
  `${wooRoot}/assets/client/blocks/cart.css`,
  path.join(root,'assets/css/petish.css'),
  `${root}/assets/css/cart-payment-layout.css`,
].map(file => fs.readFileSync(file, 'utf8')).join('\n');
const script = fs.readFileSync(`${root}/assets/js/cart-payment-layout.js`, 'utf8');
const preferred = 'express-payment-method-woocommerce_payments_express_checkout_googlePay';
const duplicate = 'express-payment-method-ppcp-googlepay';
const stripeSrc = 'https://js.stripe.com/v3/elements-inner-express-checkout-fixture.html';
const paypalSrc = 'https://www.paypal.com/credit-presentment/fixture';
const paypalMethod = (id, label) => `<li id="express-payment-method-${id}"><div class="native-paypal-frame"><button class="fixture-paypal-button">${label}</button></div></li>`;
const wallet = `<li id="${preferred}"><div class="StripeElement"><iframe title="Native Stripe Express Checkout host fixture" src="${stripeSrc}"></iframe></div></li>`;
const paypalGoogle = `<li id="${duplicate}"><div id="ppc-button-ppcp-googlepay" class="ppcp-button-apm ppcp-button-googlepay"><button class="gpay-button fixture-paypal-button">Google Pay via PayPal</button></div></li>`;
const methods = `${paypalMethod('ppcp-gateway-paypal','Pay with PayPal')}${paypalMethod('ppcp-gateway-paylater','Pay in 4')}${wallet}`; // Native server registry selected WooPayments.
const html = `<html><head><style>${css}
body{margin:0;padding:16px;box-sizing:border-box}main{max-width:900px;margin:auto}
.wc-block-cart__sidebar{width:100%;max-width:580px!important;margin:0!important}
.wc-block-components-express-payment__event-buttons{padding:0;margin:0;list-style:none}
.wc-block-components-express-payment__event-buttons>li{min-height:48px;position:relative}
.wc-block-components-express-payment__content{padding:0;margin:0}
.wc-block-components-express-payment--cart{position:relative}
.fixture-paypal-button{width:100%;min-height:48px;border:1px solid #777;box-sizing:border-box;font:16px/1.3 sans-serif;padding:8px}
.StripeElement,.native-paypal-frame{width:100%}iframe{display:block;width:100%;height:48px;border:0}.ppcp-messages iframe{height:54px}
.wc-block-components-express-payment-continue-rule{display:flex;justify-content:center;margin:16px 0}
</style></head><body id="the7-body" class="petish-site petish-cart-payment-layout"><main><div class="wc-block-cart"><div class="wc-block-cart__sidebar"><div class="wp-block-woocommerce-cart-order-summary-block">Cart totals $79.95</div><div class="wc-block-cart__payment-options"><div class="wc-block-components-express-payment wc-block-components-express-payment--cart"><div class="wc-block-components-express-payment__content"><ul class="wc-block-components-express-payment__event-buttons">${methods}</ul></div></div><div class="wc-block-components-express-payment-continue-rule wc-block-components-express-payment-continue-rule--cart">Or</div></div><div class="wc-block-cart__submit"><button>Proceed to checkout</button></div><div id="ppcp-cart-paylater-messages" class="ppcp-messages" data-block-name="woocommerce-paypal-payments/cart-paylater-messages" data-pp-placement="cart"><iframe title="Native PayPal message host fixture" src="${paypalSrc}"></iframe></div></div></div><div class="wc-block-checkout"><div id="unknown-payment-method"><iframe title="Unknown unrelated provider fixture" srcdoc="Unrelated"></iframe></div></div></main><script>
window.fixture = {eligible:true, initialized:true, callbacks:[], clicks:0};
window.wp = {data:{select:function(name){if(name!=='wc/store/payment') return null; return {getAvailableExpressPaymentMethods:function(){return fixture.eligible?{woocommerce_payments_express_checkout_googlePay:{name:'woocommerce_payments_express_checkout_googlePay'}}:{}},expressPaymentMethodsInitialized:function(){return fixture.initialized}}},subscribe:function(callback){fixture.callbacks.push(callback);return function(){fixture.callbacks=fixture.callbacks.filter(function(item){return item!==callback})}}}};
fixture.emit=function(){fixture.callbacks.forEach(function(callback){callback()})};
document.querySelectorAll('.fixture-paypal-button').forEach(function(button){button.addEventListener('click',function(){fixture.clicks++})});
fixture.originals=Array.from(document.querySelectorAll('iframe,.fixture-paypal-button'));
</script></body></html>`;

(async () => {
  const browser = await chromium.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
  const results = [];
  try {
    const context = await browser.newContext({ serviceWorkers: 'block' });
    const requests = [];
    await context.route('**/*', async route => {
      const url = route.request().url();
      requests.push(url);
      assert.ok([stripeSrc, paypalSrc].includes(url), `only fixture iframe addresses are intercepted: ${url}`);
      await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><style>body{margin:0;font:16px sans-serif}button{width:100%;height:48px}</style><button type="button">Offline native iframe fixture</button>' });
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    for (const width of [320, 390, 768, 1280]) {
      await page.setViewportSize({ width, height: 1100 });
      await page.setContent(html);
      await page.addScriptTag({ content: script });
      await page.waitForFunction(() => document.querySelector('.wc-block-cart__sidebar').classList.contains('petish-cart-has-paypal-message'));
      const layout = await page.evaluate(({ preferred, duplicate }) => {
        const rect = selector => { const r = document.querySelector(selector).getBoundingClientRect(); return { x:r.x, y:r.y, right:r.right, bottom:r.bottom, width:r.width, height:r.height }; };
        return { width:innerWidth, documentWidth:document.documentElement.scrollWidth, wallet:rect(`[id="${preferred}"]`), paypal:rect('[id="express-payment-method-ppcp-gateway-paypal"]'), paylater:rect('[id="express-payment-method-ppcp-gateway-paylater"]'), message:rect('#ppcp-cart-paylater-messages'), rule:rect('.wc-block-components-express-payment-continue-rule--cart'), checkout:rect('.wc-block-cart__submit'), duplicateCount:document.querySelectorAll(`[id="${duplicate}"]`).length, unknownDisplay:getComputedStyle(document.querySelector('#unknown-payment-method')).display, unknownFrame:getComputedStyle(document.querySelector('#unknown-payment-method iframe')).display };
      }, { preferred, duplicate });
      assert.equal(layout.documentWidth, width, `mobile layout fits ${width}`);
      assert.equal(layout.duplicateCount, 0, 'native server provider selection offers one Google Pay host');
      assert.ok(layout.wallet.bottom < layout.paypal.y, 'separate wallet panel above PayPal');
      assert.equal(layout.paypal.bottom, layout.paylater.y, 'PayPal and Pay in 4 form one group');
      assert.equal(layout.paylater.bottom, layout.message.y, 'native instalment message joins the group directly below Pay in 4');
      assert.ok(layout.message.bottom <= layout.rule.y, 'native Or follows message');
      assert.ok(layout.rule.bottom <= layout.checkout.y, 'native checkout follows Or');
      for (const key of ['wallet', 'paypal', 'paylater', 'message']) {
        assert.ok(layout[key].width <= 400 && layout[key].right <= width, `${key} fits and is at most400px`);
        assert.equal(layout[key].width, layout.wallet.width, 'consistent panel widths');
      }
      assert.notEqual(layout.unknownDisplay, 'none');
      assert.notEqual(layout.unknownFrame, 'none');
      await page.locator('[id="express-payment-method-ppcp-gateway-paypal"] button').click();
      assert.equal(await page.evaluate(() => fixture.clicks), 1, 'native button handler retained');
      assert.ok(await page.evaluate(() => fixture.originals.every(node => node.isConnected)), 'all native button/iframe nodes remain mounted');
      results.push(layout);
    }

    // SDK v6 puts its message iframe behind a custom-element shadow boundary.
    // Only resizing that closed shadow content may reveal the message host.
    const v6html = html.replace(/<iframe title="Native PayPal message host fixture"[^>]*><\/iframe>/, '<paypal-message></paypal-message>');
    for (const width of [320, 390, 768, 1280]) {
      await page.setViewportSize({width,height:1100});
      await page.setContent(v6html);
      await page.evaluate(() => {
        fixture.messageHost=document.querySelector('.ppcp-messages paypal-message');
        const shadow=fixture.messageHost.attachShadow({mode:'closed'});
        shadow.innerHTML='<style>:host{display:block}.native-content{height:0}</style><div class="native-content"></div>';
        fixture.messageContent=shadow.querySelector('.native-content');
        fixture.messageContainer=document.querySelector('.ppcp-messages');
        fixture.messageMutations=0;
        new MutationObserver(records=>{fixture.messageMutations+=records.length}).observe(fixture.messageContainer,{subtree:true,childList:true,attributes:true});
      });
      await page.addScriptTag({content:script});
      await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
      assert.equal(await page.locator('.wc-block-cart__sidebar').evaluate(node=>node.classList.contains('petish-cart-has-paypal-message')),false,'empty v6 native host gets no message group');
      await page.evaluate(()=>{fixture.messageContent.style.height='54px'});
      await page.waitForFunction(()=>document.querySelector('.wc-block-cart__sidebar').classList.contains('petish-cart-has-paypal-message'));
      const nativeMessage = await page.evaluate(() => {
        const container=document.querySelector('.ppcp-messages');
        const paylater=document.getElementById('express-payment-method-ppcp-gateway-paylater');
        return {containerIdentity:container===fixture.messageContainer,hostIdentity:container.firstElementChild===fixture.messageHost,closedBoundary:fixture.messageHost.shadowRoot===null,lightIframeAbsent:container.querySelector('iframe')===null,lightMutations:fixture.messageMutations,paylaterBottom:paylater.getBoundingClientRect().bottom,messageTop:container.getBoundingClientRect().y,messageWidth:container.getBoundingClientRect().width,documentWidth:document.documentElement.scrollWidth};
      });
      assert.equal(nativeMessage.containerIdentity,true);
      assert.equal(nativeMessage.hostIdentity,true);
      assert.equal(nativeMessage.closedBoundary,true);
      assert.equal(nativeMessage.lightIframeAbsent,true);
      assert.equal(nativeMessage.lightMutations,0,'shadow height changed without a light-DOM mutation');
      assert.equal(nativeMessage.paylaterBottom,nativeMessage.messageTop,'v6 message joins Pay in 4 group');
      assert.ok(nativeMessage.messageWidth<=400);
      assert.equal(nativeMessage.documentWidth,width);
      await page.evaluate(()=>{fixture.messageContent.style.height='0px'});
      await page.waitForFunction(()=>!document.querySelector('.wc-block-cart__sidebar').classList.contains('petish-cart-has-paypal-message'));
      await page.evaluate(()=>{fixture.messageContent.style.height='96px'});
      await page.waitForFunction(()=>document.querySelector('.wc-block-cart__sidebar').classList.contains('petish-cart-has-paypal-message'));
      assert.equal(await page.evaluate(()=>fixture.messageContainer.firstElementChild===fixture.messageHost),true,'native v6 message host remains mounted across resize changes');
    }
    await page.locator('[id="express-payment-method-ppcp-gateway-paypal"] button').click();
    assert.equal(await page.evaluate(()=>fixture.clicks),1,'native PayPal handler remains attached with v6 message');

    await page.addScriptTag({content:fs.readFileSync(path.resolve(__dirname,'../tools/homepage/payment-layout-public-proof.js'),'utf8')});
    const safeProof = await page.evaluate(() => {
      window.wcpayStripeSiteMessaging={privateConfiguration:'private-proof-sentinel'};
      document.querySelector('.wc-block-cart').appendChild(Object.assign(document.createElement('p'),{textContent:'private-proof-sentinel customer@example.invalid'}));
      return petishPaymentLayoutPublicProof();
    });
    const finite = value => typeof value==='boolean' || (typeof value==='number' && Number.isFinite(value) && value>=0 && value<=8192)
      || (typeof value==='string' && ['product','cart','other','light','dark','unspecified'].includes(value))
      || (value && typeof value==='object' && Object.values(value).every(finite));
    assert(finite(safeProof)); assert.equal(safeProof.page,'cart'); assert.equal(safeProof.cart.visible_google_pay_count,1);
    assert(!/private-proof-sentinel|customer@example\.invalid|<iframe|stripe\.com|paypal\.com/.test(JSON.stringify(safeProof)));
    assert(JSON.stringify(safeProof).length<12000);
    // Native browser unavailability removes its own host. Presentation never
    // manufactures a second provider or suppresses PayPal/Pay in 4/checkout.
    await page.evaluate(id => { fixture.detachedPreferred=document.getElementById(id); fixture.detachedPreferred.remove(); fixture.eligible=false; fixture.emit(); }, preferred);
    await page.waitForTimeout(50);
    assert.equal(await page.locator(`[id="${duplicate}"]`).count(), 0);
    assert.equal(await page.locator('[id="express-payment-method-ppcp-gateway-paypal"] button').count(), 1);
    await page.locator('[id="express-payment-method-ppcp-gateway-paylater"] button').click();
    assert.equal(await page.evaluate(() => fixture.clicks), 2);
    await page.evaluate(() => { fixture.focusedButton=document.activeElement; document.querySelector('.wc-block-components-express-payment__event-buttons').appendChild(fixture.detachedPreferred); fixture.eligible=true; fixture.emit(); });
    await page.waitForTimeout(50);
    assert.ok(await page.locator(`[id="${preferred}"]`).isVisible());
    assert.equal(await page.locator(`[id="${duplicate}"]`).count(), 0);
    assert.ok(await page.evaluate(() => document.activeElement===fixture.focusedButton), 'native payment focus preserved across wallet remount');
    assert.ok(await page.evaluate(() => fixture.originals.every(node => node.isConnected)), 'native button/iframe identity preserved across cart remount');
    // A request with WooPayments unconfigured retains the native PayPal provider.
    const paypalOnlyMethods = `${paypalMethod('ppcp-gateway-paypal','Pay with PayPal')}${paypalMethod('ppcp-gateway-paylater','Pay in 4')}${paypalGoogle}`;
    await page.setContent(html.replace(methods, paypalOnlyMethods));
    await page.addScriptTag({content:script});
    await page.waitForFunction(() => document.querySelector('.wc-block-cart__sidebar').classList.contains('petish-cart-has-paypal-message'));
    assert.equal(await page.locator(`[id="${preferred}"]`).count(),0);
    assert.ok(await page.locator(`[id="${duplicate}"]`).isVisible());
    await page.locator(`[id="${duplicate}"] button`).click();
    assert.equal(await page.evaluate(() => fixture.clicks),1,'native unconfigured-WooPayments PayPal Google Pay handler preserved');
    assert.deepEqual(errors, []);
    const proof = { status:'passed', fixture:'offline native Woo/PayPal/WooPayments host shape, mocked native eligibility store; no live provider validation', widths:results.map(r=>r.width), checks:['one native WooPayments Google Pay provider','native server selection fixture shape','preferred above PayPal','separate wallet and joined PayPal/Payin4/message panels','SDK v6 native host behind closed shadow boundary','shadow resize without light-DOM mutation refreshes message group','empty native message host leaves no panel','400px maximum and mobile fit','native handlers and node identity','native ineligibility leaves PayPal/Payin4/checkout available','native remount preserves one provider','WooPayments unconfigured keeps native PayPal Google Pay','unrelated iframe and checkout untouched'], interceptedFixtureFrames:requests.length, outboundNetworkRequests:0, results };
    console.log(JSON.stringify({ status:proof.status, widths:proof.widths, checks:proof.checks, outboundNetworkRequests:0 }));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode=1; });
