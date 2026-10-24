"""Public diagnostics retain finite DOM facts and permit only an owned guest Cart."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('storefront_runtime', ROOT / 'scripts/inspect-storefront-runtime.py')
RUNTIME = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RUNTIME)


def report():
    return {'status': 'success', 'category': 'complete', 'checks': dict.fromkeys(RUNTIME.PUBLIC_CHECKS, True),
            'routes': {name: {'http_ok': True, 'html_ok': True, 'targets': RUNTIME.empty_targets()} for name in RUNTIME.ROUTES}}


class Response:
    def __init__(self, url, body=b'<html><body><header id="masthead"></header></body></html>', status=200, encoding='identity'):
        self.url, self.body, self.status = url, body, status
        self.headers = {'Content-Encoding': encoding}
    def __enter__(self): return self
    def __exit__(self, *args): pass
    def geturl(self): return self.url
    def read(self, limit): return self.body[:limit]


class Opener:
    def __init__(self, response=None): self.calls, self.response = [], response
    def open(self, request, timeout):
        self.calls.append((request, timeout))
        return self.response(request.full_url) if self.response else Response(request.full_url)


class StorefrontRuntimeTests(unittest.TestCase):
    def test_private_text_urls_config_and_unknown_attributes_never_leave_dom_parser(self):
        sentinel = 'PRIVATE-CUSTOMER-ADDRESS-SECRET'
        html = f'''<html id="{sentinel}"><body data-customer="{sentinel}"><header id="masthead"><nav class="main-nav {sentinel}">
        <li class="petish-account-menu"><a href="https://private.invalid/{sentinel}">{sentinel}</a></li></nav></header>
        <iframe src="https://private.invalid/{sentinel}"></iframe><script>var config={{secret:"{sentinel}"}}</script>
        <div class="wc-block-cart__sidebar"><div class="ppcp-messages" data-config="{sentinel}"></div></div></body></html>'''
        facts = RUNTIME.inspect_html(html)
        self.assertNotIn(sentinel, json.dumps(facts))
        self.assertNotIn('private.invalid', json.dumps(facts))
        self.assertEqual(facts['account_menu']['count'], 1)
        self.assertEqual(facts['paypal_message']['count'], 1)
        chain = facts['account_menu']['chains'][0]
        self.assertEqual(chain[-1]['classes'], ['petish-account-menu'])
        self.assertIn('main-nav', chain[-2]['classes'])
        self.assertEqual(set(facts), set(RUNTIME.TARGETS))
        value = report(); value['routes']['cart']['targets'] = facts
        self.assertTrue(RUNTIME.screen_public(value)[1])

    def test_structure_output_is_bounded_with_deep_repeated_or_malformed_html(self):
        html = '<body>' + '<div>' * 40 + '<span class="ppcp-messages"></span>' * 130 + '</section>' + '</div>' * 40
        facts = RUNTIME.inspect_html(html)
        target = facts['paypal_message']
        self.assertEqual(target['count'], 99)
        self.assertEqual(len(target['chains']), 4)
        self.assertTrue(all(len(chain) == 8 for chain in target['chains']))
        for value in ['', b'', '<div>' * 258, b'x' * (RUNTIME.MAX_BYTES + 1), None]:
            with self.subTest(value_type=type(value).__name__):
                with self.assertRaises(ValueError): RUNTIME.inspect_html(value)
        self.assertEqual(RUNTIME.inspect_html('<header id="masthead"/><br><nav class="main-nav"><a/></nav>')['desktop_navigation']['count'], 1)

    def test_public_probe_makes_only_three_fixed_guest_gets_without_credentials(self):
        opener = Opener(); value = RUNTIME.inspect_public(opener)
        self.assertTrue(RUNTIME.screen_public(value)[1])
        self.assertEqual([item[0].full_url for item in opener.calls], ['https://petish.biz' + route for route in RUNTIME.ROUTES.values()])
        for request, timeout in opener.calls:
            self.assertEqual(request.get_method(), 'GET'); self.assertIsNone(request.data)
            self.assertNotIn('Cookie', dict(request.header_items())); self.assertNotIn('Authorization', dict(request.header_items()))
            self.assertEqual(timeout, 20)

    def test_redirect_error_compression_and_oversized_response_are_not_accepted_or_leaked(self):
        sentinel = 'PRIVATE-HTTP-SENTINEL'
        for make in [lambda url: Response('https://private.invalid/' + sentinel),
                     lambda url: Response(url, status=403), lambda url: Response(url, encoding='gzip'),
                     lambda url: Response(url, body=b'x' * (RUNTIME.MAX_BYTES + 1))]:
            value = RUNTIME.inspect_public(Opener(make))
            self.assertFalse(RUNTIME.screen_public(value)[1])
            self.assertNotIn(sentinel, json.dumps(value)); self.assertNotIn('private.invalid', json.dumps(value))

    def test_sanitizer_refuses_private_keys_wrong_types_and_excessive_structure(self):
        mutations = [lambda v: v.update(private='PRIVATE'), lambda v: v['checks'].update(private=True),
                     lambda v: v['checks'].__setitem__(next(iter(RUNTIME.PUBLIC_CHECKS)), 1),
                     lambda v: v['routes']['home'].__setitem__('http_ok', 1),
                     lambda v: v['routes']['home']['targets']['masthead'].__setitem__('count', True),
                     lambda v: v['routes']['home']['targets']['masthead'].__setitem__('count', 100),
                     lambda v: v['routes']['home']['targets']['masthead'].__setitem__('count', -1)]
        node = {'tag': 'div', 'id': None, 'classes': []}
        for altered in [{'tag': 'script'}, {'id': 'PRIVATE'}, {'classes': ['PRIVATE']},
                        {'classes': ['main-nav', 'main-nav']}, {'href': 'https://private.invalid/'}]:
            bad = {**node, **altered}
            mutations.append(lambda v, bad=bad: v['routes']['home']['targets'].__setitem__('masthead', {'count': 1, 'chains': [[bad]]}))
        mutations.append(lambda v: v['routes']['home']['targets'].__setitem__('masthead', {'count': 1, 'chains': [[node] * 9]}))
        for mutate in mutations:
            value = report(); mutate(value)
            with self.assertRaises(ValueError): RUNTIME.screen_public(value)
        value = report(); value['routes']['home']['html_ok'] = False
        safe, passed = RUNTIME.screen_public(value)
        self.assertFalse(passed); self.assertEqual(safe['status'], 'failure')

    def test_private_json_rejects_duplicate_fields_links_and_multiple_hardlinks(self):
        with self.assertRaises(ValueError): json.loads('{"status":"success","status":"failure"}', object_pairs_hook=RUNTIME.unique)
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); source = root / 'report.json'; source.write_text('{}'); source.chmod(0o600)
            self.assertEqual(RUNTIME.regular(source), b'{}')
            link = root / 'linked.json'; link.symlink_to(source)
            with self.assertRaises(ValueError): RUNTIME.regular(link)
            linked = root / 'hard.json'; os.link(source, linked)
            with self.assertRaises(ValueError): RUNTIME.regular(source)

    def test_live_browser_policy_limits_cart_mutations_and_reports_to_finite_facts(self):
        code = r'''import assert from 'node:assert/strict';
const m=await import('./tools/storefront-live-inspect.mjs');
const allow=(path,method='GET',type='fetch',body='',permit=null)=>m.allowedRequest('https://petish.biz'+path,method,type,body,permit);
for(const route of ['/', '/cart/', '/product/rainbow-habitat-hideout/'])assert(allow(route,'GET','document'));
assert(allow('/wp-admin/admin-ajax.php','POST','fetch','action=petish_account_dropdown'));
assert(allow('/?wc-ajax=petish_cart_count','POST','fetch',''));
for(const [route,body] of [['/wp-admin/admin-ajax.php?extra=1','action=petish_account_dropdown'],['/wp-admin/admin-ajax.php','action=petish_account_dropdown&private=1'],['/wp-admin/admin-ajax.php','action=other_action'],['/?wc-ajax=petish_cart_count&extra=1',''],['/?wc-ajax=petish_cart_count','private=1'],['/?wc-ajax=checkout','']])assert(!allow(route,'POST','fetch',body));
assert(!m.auditedSessionRead('https://other.invalid/wp-admin/admin-ajax.php','POST','action=petish_account_dropdown'));
assert(!m.auditedSessionRead('https://petish.biz/wp-admin/admin-ajax.php','GET','action=petish_account_dropdown'));
const nativeNonce=JSON.stringify({nonce:'SYNTHETIC12345'});
assert(allow('/?wc-ajax=ppc-sdk-v6-client-token','POST','fetch',nativeNonce));
for(const bad of [JSON.stringify({nonce:'SYNTHETIC12345',order_id:1}),JSON.stringify({nonce:'x'}),'{"nonce":"SYNTHETIC12345","nonce":"OTHER12345"}',JSON.stringify({action:'checkout'})])assert(!m.paypalSdkTokenRead('https://petish.biz/?wc-ajax=ppc-sdk-v6-client-token','POST',bad));
assert(!m.paypalSdkTokenRead('https://petish.biz/?wc-ajax=ppc-sdk-v6-client-token&extra=1','POST',nativeNonce));
assert(!m.paypalSdkTokenRead('https://other.invalid/?wc-ajax=ppc-sdk-v6-client-token','POST',nativeNonce));
for(const [url,method,body,kind] of [
 ['https://api.stripe.com/v1/elements/sessions?key=PRIVATE','GET','','stripe_session_get'],
 ['https://r.stripe.com/b','POST','PRIVATE','stripe_telemetry'],
 ['https://m.stripe.com/6','POST','PRIVATE','stripe_telemetry'],
 ['https://www.paypal.com/graphql','POST',JSON.stringify({query:'query MerchantConfiguration { public }',operationName:'PRIVATE'}),'paypal_graphql_query'],
 ['https://www.paypal.com/graphql','POST',JSON.stringify({query:'mutation CreateOrder { private }'}),'paypal_graphql_mutation'],
 ['https://www.paypal.com/graphql','POST',JSON.stringify({operationName:'PRIVATE'}),'paypal_graphql_other'],
 ['https://www.paypal.com/xoplatform/logger/api/logger','POST','PRIVATE','paypal_logger'],
 ['https://api.stripe.com/unknown-private-path','POST','PRIVATE','unknown_provider'],
]){assert.equal(m.providerRequestKind(url,method,body),kind);assert(!kind.includes('PRIVATE'));}

assert(allow('/wp-json/wc/store/v1/cart'));assert(allow('/wp-content/plugins/woocommerce/assets/test.js'));
for(const route of ['/checkout/','/wp-json/wc/store/v1/checkout','/wp-json/wc/v3/orders','/?add-to-cart=1','/cart/?remove_item=1'])assert(!allow(route));
for(const verb of ['POST','DELETE','PUT','PATCH'])assert(!allow('/wp-json/wc/store/v1/cart/items',verb));
const body=JSON.stringify({id:123,quantity:2});const permit={method:'POST',path:'/wp-json/wc/store/v1/cart/add-item',body,used:false};
assert(allow(permit.path,'POST','fetch',body,permit));permit.used=true;assert(!allow(permit.path,'POST','fetch',body,permit));
for(const bad of [{id:123,quantity:3},{id:0,quantity:2},{id:123,quantity:2,private:'sentinel'}]){const body=JSON.stringify(bad);assert(!allow('/wp-json/wc/store/v1/cart/add-item','POST','fetch',body,{method:'POST',path:'/wp-json/wc/store/v1/cart/add-item',body,used:false}));}
assert(allow('/wp-json/wc/store/v1/cart/items','DELETE','fetch','',{method:'DELETE',path:'/wp-json/wc/store/v1/cart/items',body:'',used:false}));
assert(!allow('/wp-json/wc/v3/orders','POST','fetch','',{method:'POST',path:'/wp-json/wc/v3/orders',body:'',used:false}));
const stripeBody=new URLSearchParams({key:'pk_test_SYNTHETIC_PUBLIC_WIDGET_KEY_123',type:'deferred_intent',locale:'en'}).toString();
assert(m.allowedRequest('https://api.stripe.com/v1/elements/sessions','POST','fetch',stripeBody));
for(const bad of [stripeBody+'&client_secret=private',stripeBody+'&deferred_intent[payment_intent]=private',stripeBody+'&deferred_intent%5BCLIENT_SECRET%5D=private',stripeBody+'&setup_intent=private',stripeBody+'&key=pk_test_DUPLICATEKEY123','key=sk_test_PRIVATEKEY123',JSON.stringify({key:'pk_test_SYNTHETIC_PUBLIC_WIDGET_KEY_123'})])assert(!m.publicStripeSession('https://api.stripe.com/v1/elements/sessions','POST',bad));
assert(!m.allowedRequest('https://www.paypal.com/graphql','POST','fetch',JSON.stringify({operationName:'unknown',query:'mutation CreateOrder { private }'})));
assert(m.allowedRequest('https://www.paypal.com/sdk/js','GET','script'));assert(!m.allowedRequest('https://www.paypal.com/v2/checkout/orders','GET','fetch'));
assert(!m.allowedRequest('https://api.stripe.com/v1/payment_intents','POST','fetch'));assert(!m.allowedRequest('https://petish.biz.attacker.invalid/cart/'));
const report=m.emptyReport();assert.deepEqual(Object.keys(report).sort(),['status','category','checks','observations','metrics'].sort());m.screenReport(report);
assert.throws(()=>m.screenReport({...report,private:'PRIVATE'}));
for(const value of [-1,Infinity,4097,'PRIVATE',true]){const v=structuredClone(report);v.metrics[m.PUBLIC_SCHEMA.metrics[0]]=value;assert.throws(()=>m.screenReport(v));}
const v=structuredClone(report);v.status='success';v.category='complete';assert.throws(()=>m.screenReport(v));
console.log('Fixed live-browser policy and finite report verified.');'''
        result = subprocess.run(['node', '--input-type=module', '-e', code], cwd=ROOT, text=True, capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('verified', result.stdout)

    def test_native_financing_measurement_uses_whole_message_and_sentence_font(self):
        code = r'''import assert from 'node:assert/strict';
import fs from 'node:fs';import os from 'node:os';import path from 'node:path';
import {createRequire} from 'node:module';
const require=createRequire(new URL('./tools/staging-rehearsal/package.json',import.meta.url));
const {chromium}=require('playwright');const m=await import('./tools/storefront-live-inspect.mjs');
const browser=await chromium.launch({headless:true,executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
const cropDir=fs.mkdtempSync(path.join(os.tmpdir(),'petish-public-payment-crop-'));fs.chmodSync(cropDir,0o700);
try{
 const page=await browser.newPage({viewport:{width:400,height:240}});await page.route('**/*',r=>r.abort());
 await page.setContent(`<style>body{margin:0;width:380px;font-size:12px}.afterpay-logo{display:inline-block;width:49.66px;height:15px;font-size:11.16px}.finance{font-size:18px}a{display:block;font-size:14px}</style><span class="afterpay-logo">Afterpay</span><p class="finance">Four interest-free payments of PRIVATE_AMOUNT.</p><a href="https://private.invalid/PRIVATE_TOKEN">See plans</a>`);
 const facts=await page.evaluate(m.afterpayDOMFacts,{providerFrame:true,afterpayOnly:true});
 assert.equal(facts.observations.afterpay_financing_text_measured,true);assert.equal(facts.observations.afterpay_see_plans_present,true);
 assert.equal(facts.observations.afterpay_see_plans_below,true);assert.equal(facts.metrics.afterpay_font_px,18);
 assert.equal(facts.metrics.afterpay_link_font_px,14);assert(facts.metrics.afterpay_message_width_px>300);
 assert(facts.metrics.afterpay_logo_width_px<60);assert(!JSON.stringify(facts).includes('PRIVATE'));
 const noLink=await page.evaluate(()=>{document.querySelector('a').textContent='Learn more';return true;});assert(noLink);
 const changed=await page.evaluate(m.afterpayDOMFacts,{providerFrame:true,afterpayOnly:true});
 assert.equal(changed.observations.afterpay_see_plans_present,false);assert.equal(changed.observations.afterpay_learn_more_present,true);
 await page.setContent('<span class="afterpay-logo" style="display:inline-block;width:49px;height:16px;font-size:11px">Afterpay</span>');
 const logoOnly=await page.evaluate(m.afterpayDOMFacts,{providerFrame:true,afterpayOnly:true});
 assert.equal(logoOnly.observations.afterpay_logo_present,true);assert.equal(logoOnly.observations.afterpay_financing_text_measured,false);assert.equal(logoOnly.metrics.afterpay_font_px,0);

 await page.setContent(`<style>body{margin:0}#masthead{height:60px}#payment-method-message{margin-top:60px;width:360px;min-height:120px}iframe{display:block;border:0;width:350px;height:100px}</style><header id="masthead">PRIVATE_HEADER_IDENTITY</header><main class="product"><section class="summary"><div id="payment-method-message"><iframe srcdoc="<p>Public payment information</p>"></iframe></div></section></main>`);
 const crop=await m.capturePublicPanel(page,cropDir,'product');assert.equal(crop.saved,true);assert(crop.width<=1600&&crop.height<=1600);
 const png=path.join(cropDir,'product-afterpay.private.png');assert.equal(fs.statSync(png).mode&0o7777,0o600);const before=fs.readFileSync(png);
 await page.evaluate(()=>{const input=document.createElement('input');input.value='PRIVATE_CUSTOMER';document.querySelector('#payment-method-message').prepend(input);});
 const blocked=await m.capturePublicPanel(page,cropDir,'product');assert.equal(blocked.saved,false);assert(fs.readFileSync(png).equals(before));

}finally{await browser.close();fs.rmSync(cropDir,{recursive:true,force:true});}
console.log('Native financing metrics remain scoped and private.');'''
        result = subprocess.run(['node', '--input-type=module', '-e', code], cwd=ROOT, text=True, capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('private', result.stdout)

    def test_compact_badge_geometry_reads_native_wrappers_without_replacing_them(self):
        code = r'''import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
const require=createRequire(new URL('./tools/staging-rehearsal/package.json',import.meta.url));
const {chromium}=require('playwright');const m=await import('./tools/storefront-live-inspect.mjs');
const browser=await chromium.launch({headless:true,executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
try{
 const page=await browser.newPage();await page.route('**/*',route=>route.abort());
 for(const width of [218,320,390,1280])for(const which of ['product','cart']){
  await page.setViewportSize({width,height:700});
  const host=`<div id="payment-method-message" class="petish-afterpay-brand-heading ${which==='cart'?'wc-block-components-bnpl-wrapper':''}" style="display:block"><div class="__PrivateStripeElement" style="display:block!important"><iframe class="controller" style="display:none;width:0;height:0"></iframe><iframe class="native-message" style="display:block;border:0;width:100%;height:92px" srcdoc="<style>body{margin:0}.finance{font-size:17px}.see{display:block;font-size:14px}</style><span class='finance'>Four interest-free payments PRIVATE_SENTINEL</span><a class='see'>See plans</a>"></iframe></div></div>`;
  const root=which==='product'?`<main class="product"><div class="summary">${host}</div></main>`:`<main class="wc-block-cart">${host}</main>`;
  await page.setContent(`<style>body{margin:0;padding:16px}.petish-afterpay-brand-heading{position:relative;width:100%;max-width:400px;box-sizing:border-box;border:1px solid green;min-height:64px;padding:12px 12px 12px 88px}.petish-afterpay-brand-heading::before{content:"";position:absolute;left:12px;top:50%;transform:translateY(-50%);display:block;width:64px;aspect-ratio:8/5;background:linear-gradient(green,green)}.petish-afterpay-brand-heading>.__PrivateStripeElement{min-width:0}</style>${root}`);
  await page.evaluate(()=>{window.nativeWrapper=document.querySelector('.__PrivateStripeElement');window.nativeFrame=document.querySelector('.native-message');window.nativeEvents=0;window.nativeWrapper.addEventListener('native-fixture-event',()=>window.nativeEvents++);});
  const facts=await page.evaluate(m.brandGeometryFacts,which);
  assert.equal(facts.present,true);assert.equal(facts.compact,true);assert.equal(facts.right,true);assert.equal(facts.below,false);
  assert.equal(facts.width,64);assert.equal(facts.height,40);assert(facts.messageLeft>=facts.brandRight);assert(facts.messageWidth>50);assert.equal(facts.messageHeight,92);
  assert(!JSON.stringify(facts).includes('PRIVATE'));
  assert(await page.evaluate(()=>{window.nativeWrapper.dispatchEvent(new Event('native-fixture-event'));return window.nativeEvents===1&&window.nativeFrame===document.querySelector('.native-message')&&window.nativeWrapper===window.nativeFrame.parentElement&&window.nativeFrame.style.height==='92px';}));
  const native=page.frames().find(frame=>frame!==page.mainFrame()&&frame.url()==='about:srcdoc');
  assert(native);assert.deepEqual(await native.evaluate(()=>[getComputedStyle(document.querySelector('.finance')).fontSize,getComputedStyle(document.querySelector('.see')).fontSize]),['17px','14px']);
  await page.evaluate(()=>window.nativeFrame.style.height='164px');
  const grown=await page.evaluate(m.brandGeometryFacts,which);assert.equal(grown.right,true);assert.equal(grown.messageHeight,164);
  await page.evaluate(()=>document.querySelector('.petish-afterpay-brand-heading').style.display='none');
  assert.equal((await page.evaluate(m.brandGeometryFacts,which)).present,false);
  await page.evaluate(()=>document.querySelector('.petish-afterpay-brand-heading').style.display='block');
  assert.equal((await page.evaluate(m.brandGeometryFacts,which)).right,true);
  await page.evaluate(()=>{const style=document.createElement('style');style.textContent='.petish-afterpay-brand-heading{padding:12px}.petish-afterpay-brand-heading::before{position:static;transform:none;margin-bottom:10px}';document.head.appendChild(style);});
  const below=await page.evaluate(m.brandGeometryFacts,which);assert.equal(below.right,false);assert.equal(below.below,true);
 }
 await page.setViewportSize({width:1280,height:800});
 await page.setContent(`<style>body{margin:0}.wc-block-cart__sidebar{width:320px}.wp-block-woocommerce-cart-order-summary-block{border-bottom:1px solid gray}.slot-wrapper{border-top:1px solid gray}.wc-block-components-bnpl-wrapper{height:64px;border:1px solid green;box-sizing:border-box}#native-total{height:32px}#express-payment-method-woocommerce_payments_express_checkout_googlePay,#express-payment-method-ppcp-gateway-paypal{height:48px}</style><div class="wc-block-cart"><aside class="wc-block-cart__sidebar"><section class="wp-block-woocommerce-cart-order-summary-block"><div id="native-total" class="wc-block-components-totals-wrapper"><div class="wc-block-components-totals-footer-item"></div></div><div class="wc-block-components-totals-wrapper slot-wrapper"><div class="wc-block-components-order-meta"><div class="wc-block-components-bnpl-wrapper">PRIVATE_FLOW_SENTINEL</div></div></div></section><div class="wc-block-cart__payment-options"><div class="wc-block-components-express-payment"><div id="express-payment-method-woocommerce_payments_express_checkout_googlePay"></div><div id="express-payment-method-ppcp-gateway-paypal"></div></div></div></aside></div>`);
 const native=await page.evaluate(m.cartNativeFlowFacts);
 for(const key of ['cart_bnpl_exact_native_slot_path','cart_slot_direct_summary_child','cart_meta_direct_slot_child','cart_slot_wrapper_class_present','cart_meta_only_bnpl_child','cart_slot_only_meta_child','cart_summary_direct_sidebar','cart_express_dom_after_summary','cart_bnpl_touches_summary_bottom_divider','cart_bnpl_touches_totals_top_divider','cart_visible_paypal_below_bnpl'])assert.equal(native.observations[key],true,key);
 assert.equal(native.observations.cart_visible_google_above_bnpl,false);assert(!JSON.stringify(native).includes('PRIVATE'));
 await page.evaluate(()=>{document.querySelector('.slot-wrapper').style.padding='16px 0';const s=document.createElement('style');s.textContent='.wc-block-cart__sidebar{display:flex;flex-direction:column}.wp-block-woocommerce-cart-order-summary-block{order:2}.wc-block-cart__payment-options,.wc-block-components-express-payment{display:contents}#express-payment-method-woocommerce_payments_express_checkout_googlePay{order:1}#express-payment-method-ppcp-gateway-paypal{order:3}';document.head.appendChild(s);});
 const spaced=await page.evaluate(m.cartNativeFlowFacts);assert.equal(spaced.observations.cart_visible_google_above_bnpl,true);assert.equal(spaced.observations.cart_visible_paypal_below_bnpl,true);
 assert.equal(spaced.observations.cart_express_in_payment_options,true);assert.equal(spaced.observations.cart_bnpl_touches_summary_bottom_divider,false);assert.equal(spaced.observations.cart_bnpl_touches_totals_top_divider,false);
 assert.equal(spaced.metrics.cart_bnpl_slot_top_inner_gap_px,16);assert.equal(spaced.metrics.cart_bnpl_slot_bottom_inner_gap_px,16);
 await page.evaluate(()=>document.querySelector('.wc-block-components-order-meta').appendChild(document.createElement('div')));
 assert.equal((await page.evaluate(m.cartNativeFlowFacts)).observations.cart_meta_only_bnpl_child,false);
}finally{await browser.close();}
console.log('Compact badge native-wrapper geometry verified at all four widths.');'''
        result = subprocess.run(['node', '--input-type=module', '-e', code], cwd=ROOT, text=True, capture_output=True, timeout=45)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('four widths', result.stdout)

    def test_native_report_requires_real_guard_truth_without_exposing_observations(self):
        checks = dict.fromkeys(RUNTIME.native_schema(), True)
        checks['native_probe_error_observed'] = False
        value = {'status': 'success', 'category': 'complete', 'checks': checks}
        self.assertTrue(RUNTIME.screen_native(copy.deepcopy(value))[1])
        for guard in ['orders_unchanged', 'settings_unchanged', 'readonly_sql_barrier_attached', 'cart_hook_observed']:
            altered = copy.deepcopy(value); altered['checks'][guard] = False
            self.assertFalse(RUNTIME.screen_native(altered)[1])
        for altered in [{**value, 'private': 'PRIVATE'}, {**value, 'checks': {**checks, 'private_url': True}},
                        {**value, 'checks': {**checks, 'orders_unchanged': 1}}]:
            with self.assertRaises(ValueError): RUNTIME.screen_native(altered)

    def test_browser_screen_rejects_private_data_and_requires_guest_cleanup(self):
        schema = RUNTIME.browser_schema()
        value = {'status': 'success', 'category': 'complete', 'checks': dict.fromkeys(schema['checks'], True),
                 'observations': dict.fromkeys(schema['observations'], False), 'metrics': dict.fromkeys(schema['metrics'], 0)}
        self.assertTrue(RUNTIME.screen_browser(copy.deepcopy(value))[1])
        for key in ['created_guest_cart_cleared', 'initial_cart_empty', 'network_barrier_enforced']:
            altered = copy.deepcopy(value); altered['checks'][key] = False
            self.assertFalse(RUNTIME.screen_browser(altered)[1])
        for bad in [-1, 4097, True, float('nan'), float('inf'), 'PRIVATE']:
            altered = copy.deepcopy(value); altered['metrics'][next(iter(schema['metrics']))] = bad
            with self.assertRaises(ValueError): RUNTIME.screen_browser(altered)
        altered = copy.deepcopy(value); altered['observations']['private_payload'] = 'PRIVATE'
        with self.assertRaises(ValueError): RUNTIME.screen_browser(altered)

    def test_native_and_full_workflow_branches_reject_unrelated_runs_before_transport(self):
        script = ROOT / 'scripts/hostinger-storefront-runtime.sh'
        for ref, repo in [('main', 'nkrake6/petish'), ('inspect-storefront-runtime', 'other/private')]:
            with tempfile.TemporaryDirectory() as temp:
                env = dict(os.environ, RUNNER_TEMP=temp, GITHUB_RUN_ID='123', GITHUB_RUN_ATTEMPT='1',
                           GITHUB_SHA='0' * 40, GITHUB_REPOSITORY=repo, GITHUB_REF_NAME=ref)
                result = subprocess.run(['bash', str(script)], cwd=ROOT, env=env, capture_output=True, timeout=15)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(list(Path(temp).iterdir()), [])


if __name__ == '__main__': unittest.main()
