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
