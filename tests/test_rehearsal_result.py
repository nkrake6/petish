"""Only typed rehearsal metadata can cross the private/public report boundary."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import shutil

ROOT=Path(__file__).resolve().parents[1]
SCRIPT=ROOT/'scripts/sanitize-rehearsal-result.py'
spec=importlib.util.spec_from_file_location('petish_rehearsal_result',SCRIPT)
validator=importlib.util.module_from_spec(spec);spec.loader.exec_module(validator)
UNAVAILABLE={'status':'unavailable','category':'safe_result_unavailable'}
VALIDATION_STAGES={'input','schema','status_category','run_id','checks','account',
                   'native','structure','provider_hosts','phase','browser'}
PRIVATE='PRIVATE_SENTINEL_TOKEN/DO-NOT-PUBLISH'


def fixture():
    return {'status':'success','category':'complete','run_id':'12345678-1',
            'checks':{'environment_staging':True,'woocommerce_test_mode':True,'wcpay_dev_mode':True,
                      'account_is_live':False,'guard_runtime_verified':True,'suppliers_blocked':True,
                      'mail_capture_verified':True,'webhooks_blocked':True,'database_separate':True,
                      'browser_input_written':False},
            'account':{'cached_account_present':True,'account_is_live':False,'native_connected':False,
                       'test_onboarding':True,'dev_mode':True,'test_mode':True,'gateway_test_mode':None,
                       'payments_enabled':None},
            'native':{'mode_class':'WCPay\\Mode','mode_methods':['is_test','is_dev','is_test_mode'],
                      'account_service_class':'WC_Payments_Account',
                      'account_service_methods':['get_account_data','is_account_connected'],
                      'gateway_class':'WC_Payment_Gateway_WCPay','gateway_methods':['is_available'],
                      'helper_method_calls':['WC_Payments::get_gateway','Mode->is_test','->get_charge'],
                      'api_client_class':'WC_Payments_API_Client','api_client_methods':['get_charge'],
                      'api_method_signatures':['get_charge']},
            'structure':{'credentials':[{'path':'account.id','type':'string'},
                                        {'path':'publishable_key','type':'string'},
                                        {'path':'test_mode','type':'boolean'}],
                         'checkout_fixture':[{'path':'cart.items[0].variation_id','type':'integer'},
                                             {'path':'cart.items','type':'array'},
                                             {'path':'item_count','type':'int'},
                                             {'path':'amount','type':'float'},
                                             {'path':'test_mode','type':'bool'}],
                         'captured_mail':[{'path':'items[0].subject','type':'string'}]},
            'guard_allowed_hosts':['public-api.wordpress.com','api.stripe.com','js.stripe.com']}


class RehearsalResultTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.directory=Path(self.temp.name)
        self.source=self.directory/'private-rehearsal.json'

    def tearDown(self):self.temp.cleanup()

    def sanitize(self,raw):
        self.source.write_text(json.dumps(raw))
        result=validator.sanitize(self.source)
        self.assertNotIn(PRIVATE,json.dumps(result))
        return result

    def assert_unavailable(self,result,stage=None):
        self.assertEqual({key:result[key] for key in ('status','category')},UNAVAILABLE)
        self.assertEqual(set(result),{'status','category','validation_stage'})
        self.assertIn(result['validation_stage'],VALIDATION_STAGES)
        self.assertNotIn(PRIVATE,json.dumps(result))
        if stage is not None:self.assertEqual(result['validation_stage'],stage)

    def reject(self,raw):self.assert_unavailable(self.sanitize(raw))

    def test_valid_preflight_preserves_only_metadata_and_native_identifiers(self):
        raw=fixture();result=self.sanitize(raw);self.assertEqual(result,raw)
        self.assertIs(result['checks']['account_is_live'],False)
        self.assertIsNone(result['account']['gateway_test_mode'])
        self.assertEqual(result['structure']['credentials'][0],{'path':'account.id','type':'string'})

    def test_minimal_finite_failure_is_preserved(self):
        for status,category in [('unavailable','preflight'),('failure','credentials'),('failure','cleanup')]:
            raw={'status':status,'category':category};self.assertEqual(self.sanitize(raw),raw)
        for raw in ({'status':'failure','category':PRIVATE}, {'status':PRIVATE,'category':'preflight'},
                    {'status':'success','category':'complete','run_id':'123-1\n'+PRIVATE}):self.reject(raw)

    def test_finite_validation_stage_identifies_rejected_section_without_private_data(self):
        cases=[('schema',lambda raw:raw.update(private_value=PRIVATE)),
               ('status_category',lambda raw:raw.update(category=PRIVATE)),
               ('run_id',lambda raw:raw.update(run_id=PRIVATE)),
               ('checks',lambda raw:raw['checks'].update(private_value=PRIVATE)),
               ('account',lambda raw:raw['account'].update(private_value=PRIVATE)),
               ('native',lambda raw:raw['native'].update(private_value=PRIVATE)),
               ('structure',lambda raw:raw['structure'].update(private_value=PRIVATE)),
               ('provider_hosts',lambda raw:raw.update(guard_allowed_hosts=[PRIVATE]))]
        for stage,mutate in cases:
            raw=fixture();mutate(raw)
            with self.subTest(stage=stage):self.assert_unavailable(self.sanitize(raw),stage)

    def test_nullable_run_id_and_native_mode_failure_preserve_typed_metadata(self):
        raw=fixture();raw.update(status='unavailable',category='native_test_mode_unverified',run_id=None)
        raw['account'].update(payments_enabled=None,gateway_test_mode=None)
        self.assertEqual(self.sanitize(raw),raw)

    def test_full_driver_phases_preserve_boolean_module_evidence(self):
        phase_checks={
            'preflight':{'environment_staging':True,'browser_input_written':True},
            'prepare':{'prepared':True,'shipping_simulated':True},
            'verify':{'paid_test_order_verified':True,'decline_stock_unchanged':True,
                      'paid_stock_reduced_once':True,'provider_test_payment_verified':True,
                      'native_order_emails_captured':True,'native_email_contents_verified':True},
            'refund':{'native_test_refund_verified':True,'provider_full_test_refund_verified':True,
                      'completed_email_captured':True,'refund_email_captured':True,
                      'stock_restored_to_baseline':True,'synthetic_tracking_note_only':True,
                      'supplier_action_performed':False,'customer_note_email_captured':True},
            'cleanup':{'shipping_fixture_removed':True,'qa_cart_cleared':True,
                       'order_refunded_before_cleanup':True,'recovery_required':False},
        }
        for phase,checks in phase_checks.items():
            with self.subTest(phase=phase):
                raw={'status':'success','category':'complete','phase':phase,'run_id':'12345-1','checks':checks}
                self.assertEqual(self.sanitize(raw),raw)
        recovery={'status':'unavailable','category':'cleanup','phase':'cleanup',
                  'checks':{'shipping_fixture_removed':False,'qa_cart_cleared':False,
                            'order_refunded_before_cleanup':False,'recovery_required':True}}
        self.assertEqual(self.sanitize(recovery),recovery)

    def test_provider_failure_categories_and_key_type_metadata_are_safe(self):
        for category in ('provider_identity','provider_order_reference','provider_request','native_provider_response'):
            raw={'status':'unavailable','category':category,'phase':'refund',
                 'structure':{'cached_account':[{'path':'id','type':'string'}],
                              'provider_charge':[{'path':'id','type':'string'},
                                                 {'path':'metadata.order_id','type':'string'},
                                                 {'path':'refunds.data[0].status','type':'string'}]}}
            self.assertEqual(self.sanitize(raw),raw)
            raw['structure']['provider_charge'][0]['value']=PRIVATE
            self.assert_unavailable(self.sanitize(raw),'structure')
        for path in ('pi_private123456789','metadata.ch_private123456789',
                     'account.acct_private123456789','refunds.re_private123456789',
                     'metadata.pk_test_private123456789','metadata.wc_order_private123456789'):
            raw={'status':'success','category':'complete','phase':'verify',
                 'structure':{'provider_charge':[{'path':path,'type':'string'}]}}
            self.assert_unavailable(self.sanitize(raw),'structure')

    def test_phase_enum_and_module_booleans_reject_custom_data_and_null(self):
        for phase in (PRIVATE,'payment_observed','browser',None,[],{}):
            raw=fixture();raw['phase']=phase
            self.assert_unavailable(self.sanitize(raw),'phase')
        for key in validator.MODULE_CHECKS:
            for value in (None,0,1,'true',PRIVATE,[],{}):
                with self.subTest(key=key,value_type=type(value).__name__):
                    raw={'status':'success','category':'complete','phase':'verify','checks':{key:value}}
                    self.assert_unavailable(self.sanitize(raw),'checks')
        for key in ('order_id','customer_id','baseline_stock','email_event_count','order_key','capture_file'):
            raw={'status':'success','category':'complete','phase':'verify','checks':{key:True}}
            self.assert_unavailable(self.sanitize(raw),'checks')

    def test_unknown_top_level_credentials_order_keys_and_receipt_urls_reject_whole_report(self):
        for key,value in [('credentials',{'api_key':PRIVATE}),('order_key',PRIVATE),('order_id',1234),
                          ('receipt_url','https://provider.example/receipt/'+PRIVATE),('raw_error',PRIVATE),
                          ('customer_email','private@example.net'),('checkout_url','https://store.example/private/'+PRIVATE)]:
            with self.subTest(key=key):
                raw=fixture();raw[key]=value;self.reject(raw)

    def test_unknown_nested_keys_and_structure_values_never_publish(self):
        for path in [('checks',),('account',),('native',),('structure',),('structure','credentials',0),
                     ('structure','checkout_fixture',0),('structure','captured_mail',0)]:
            raw=fixture();target=raw
            for key in path:target=target[key]
            target['private_value']=PRIVATE;self.reject(raw)

    def test_count_strings_and_numeric_flags_cannot_masquerade_as_boolean_checks(self):
        for group,key in [('checks','environment_staging'),('checks','account_is_live'),
                          ('account','native_connected'),('account','test_mode')]:
            for bad in (0,1,'true','false',PRIVATE,[],{}):
                with self.subTest(group=group,key=key,bad_type=type(bad).__name__):
                    raw=fixture();raw[group][key]=bad;self.reject(raw)

    def test_native_method_names_and_classes_reject_code_paths_and_private_values(self):
        for group in ('mode_methods','helper_method_calls','gateway_methods'):
            for bad in ('get_account_data()','/home/private/config.php',PRIVATE,
                        'https://provider.example/receipt/private','get_account\nsecret','eval($secret)'):
                raw=fixture();raw['native'][group]=[bad];self.reject(raw)
        for bad in (123,True,[],{},'/private/path',PRIVATE):
            raw=fixture();raw['native']['gateway_class']=bad;self.reject(raw)

    def test_structure_accepts_key_type_pairs_but_rejects_paths_urls_values_and_invalid_types(self):
        for group in ('credentials','checkout_fixture','captured_mail'):
            for bad in ('/home/account/private/key.pem','https://api.example/private','account.id\n'+PRIVATE,PRIVATE):
                raw=fixture();raw['structure'][group][0]['path']=bad;self.reject(raw)
            for bad in (PRIVATE,'secret','https://api.example/receipt',True,1,None):
                raw=fixture();raw['structure'][group][0]['type']=bad;self.reject(raw)
        raw=fixture();raw['structure']['credentials'][0]['value']=PRIVATE;self.reject(raw)

    def test_unknown_hosts_full_urls_userinfo_and_ports_are_rejected(self):
        for host in ('unknown-provider.example','https://api.stripe.com','api.stripe.com:443',
                     'api.stripe.com@private.example','localhost','127.0.0.1',PRIVATE,123,True):
            raw=fixture();raw['guard_allowed_hosts']=[host];self.reject(raw)

    def test_static_provider_subdomain_metadata_is_accepted_but_lookalikes_rejected(self):
        # This is report metadata validation, not a network allowlist or request.
        raw=fixture();raw['guard_allowed_hosts']=['unlisted-test.wcpayments.com']
        self.assertEqual(self.sanitize(raw),raw)
        for host in ('unlisted-test.wcpayments.com.attacker.example','unlisted-test.evilwcpayments.com',
                     'unlisted-test.wcpayments.com@attacker.example','wcpayments.com.attacker.example'):
            raw['guard_allowed_hosts']=[host];self.reject(raw)

    def test_collections_input_and_identifier_sizes_are_bounded(self):
        cases=[]
        raw=fixture();raw['native']['mode_methods']=['is_test']*201;cases.append(raw)
        raw=fixture();raw['structure']['credentials']=[{'path':'key','type':'string'}]*201;cases.append(raw)
        raw=fixture();raw['guard_allowed_hosts']=['api.stripe.com']*31;cases.append(raw)
        raw=fixture();raw['native']['mode_class']='A'*151;cases.append(raw)
        raw=fixture();raw['structure']['credentials'][0]['path']='a'*151;cases.append(raw)
        for raw in cases:self.reject(raw)
        self.source.write_text(' '*65537);self.assert_unavailable(validator.sanitize(self.source),'input')

    def test_missing_nondict_and_invalid_json_fail_closed(self):
        self.assert_unavailable(validator.sanitize(self.source),'input')
        for content in ('null','[]','"'+PRIVATE+'"','not json '+PRIVATE):
            self.source.write_text(content);self.assert_unavailable(validator.sanitize(self.source))

    def test_symlink_and_fifo_source_are_refused_without_blocking_or_reading_secret(self):
        secret=self.directory/'secret-target';secret.write_text(PRIVATE)
        for name,create in [('link',lambda path:path.symlink_to(secret)),('fifo',lambda path:os.mkfifo(path))]:
            source=self.directory/name;create(source);output=self.directory/(name+'-result.json')
            result=subprocess.run([sys.executable,str(SCRIPT),str(source),str(output)],
                                  capture_output=True,text=True,timeout=3)
            self.assertEqual(result.returncode,0);self.assert_unavailable(json.loads(output.read_text()),'input')
            self.assertNotIn(PRIVATE,result.stdout+result.stderr+output.read_text())

    def test_cli_creates_owner_only_output_and_never_replaces_existing_or_linked_targets(self):
        self.source.write_text(json.dumps(fixture()));output=self.directory/'result.json'
        result=subprocess.run([sys.executable,str(SCRIPT),str(self.source),str(output)],capture_output=True,text=True,timeout=3)
        self.assertEqual(result.returncode,0);self.assertEqual(output.stat().st_mode&0o777,0o600)
        before=output.read_bytes()
        for target in (output,self.directory/'output-link'):
            if target!=output:target.symlink_to(output)
            result=subprocess.run([sys.executable,str(SCRIPT),str(self.source),str(target)],capture_output=True,text=True,timeout=3)
            self.assertNotEqual(result.returncode,0);self.assertEqual(output.read_bytes(),before)

    def test_linked_source_and_deep_json_fail_closed_without_parser_exceptions(self):
        self.source.write_text(json.dumps(fixture()))
        linked=self.directory/'private-hardlink';os.link(self.source,linked)
        self.assert_unavailable(validator.sanitize(linked),'input')
        self.source.unlink();self.source.write_text('['*2000+'"'+PRIVATE+'"'+']'*2000)
        # Parsing may reject at the decoder or at the non-object schema guard.
        self.assert_unavailable(validator.sanitize(self.source))


# Browser reports are a separate flat publicReport schema, not driver envelopes.
class BrowserPublicReportTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.directory=Path(self.temp.name)
        self.source=self.directory/'browser-stdout.json'

    def tearDown(self):self.temp.cleanup()

    def sanitize(self,raw):
        self.source.write_text(json.dumps(raw))
        result=validator.sanitize_browser(self.source)
        self.assertNotIn(PRIVATE,json.dumps(result))
        return result

    def test_all_actual_public_browser_booleans_attempts_and_failure_enums(self):
        raw={key:True for key in validator.BROWSER_CHECKS}
        raw.update(unsafe_request_blocked=False,payment_attempts=2)
        self.assertEqual(self.sanitize(raw),raw)
        for stage in validator.BROWSER_STAGES:
            failed={'failure_stage':stage,'payment_attempts':0,'browser_started':False}
            self.assertEqual(self.sanitize(failed),failed)

    def test_private_browser_file_fields_cannot_be_published_as_stdout(self):
        for key,value in (('order_id',12345),('declined_order_id',12344),('created_order_ids',[12344,12345]),
                          ('order_key',PRIVATE),('password',PRIVATE),('receipt_url','https://example.invalid/'+PRIVATE),
                          ('customer_email','private@example.invalid'),('body',PRIVATE)):
            with self.subTest(key=key):
                result=self.sanitize({'payment_attempts':2,'paid_receipt_verified':True,key:value})
                self.assertEqual(result,{'status':'unavailable','category':'safe_result_unavailable','validation_stage':'browser'})

    def test_browser_types_counts_stages_and_missing_attempts_fail_closed(self):
        invalid=[{},[],None,{'payment_attempts':0,'failure_stage':PRIVATE}]
        for attempts in (True,False,-1,3,2.0,'2',PRIVATE,None):invalid.append({'payment_attempts':attempts})
        for key in validator.BROWSER_CHECKS:
            for value in (None,0,1,'true',PRIVATE):invalid.append({'payment_attempts':0,key:value})
        for raw in invalid:
            result=self.sanitize(raw)
            self.assertEqual(result,{'status':'unavailable','category':'safe_result_unavailable','validation_stage':'browser'})

    def test_finite_cart_diagnostics_preserve_progress_without_api_bodies(self):
        raw={'payment_attempts':0,'failure_stage':'cart','cart_page_loaded':True,
             'cart_api_available':False,'cart_empty_verified':False,
             'variation_add_request_completed':False,'expected_variation_in_cart':False,
             'cart_page_http_status':200,'cart_api_http_status':403,
             'cart_failure_step':'api','cart_api_error_code':'rest_cookie_invalid_nonce'}
        self.assertEqual(self.sanitize(raw),raw)
        for field in validator.BROWSER_HTTP_FIELDS:
            for status in (0,100,200,403,599):
                value={**raw,field:status};self.assertEqual(self.sanitize(value),value)
            for status in (True,False,99,600,-1,200.0,'200',PRIVATE,None):
                result=self.sanitize({**raw,field:status})
                self.assertEqual(result,{'status':'unavailable','category':'safe_result_unavailable','validation_stage':'browser'})
        for step in validator.BROWSER_CART_STEPS:
            value={**raw,'cart_failure_step':step};self.assertEqual(self.sanitize(value),value)
        for code in validator.BROWSER_CART_ERRORS:
            value={**raw,'cart_api_error_code':code};self.assertEqual(self.sanitize(value),value)
        for field in ('cart_failure_step','cart_api_error_code'):
            for value in (PRIVATE,'https://staging.petish.biz/?key='+PRIVATE,None,[],{}):
                self.assertEqual(self.sanitize({**raw,field:value}),
                    {'status':'unavailable','category':'safe_result_unavailable','validation_stage':'browser'})
        for field in ('cart_api_error_message','cart_api_body','cart_request_url','cart_nonce','cart_items'):
            self.assertEqual(self.sanitize({**raw,field:PRIVATE}),
                {'status':'unavailable','category':'safe_result_unavailable','validation_stage':'browser'})

    def test_shipping_diagnostics_currency_and_bounded_counts_are_public_metadata(self):
        raw={'payment_attempts':0,'failure_stage':'shipping','shipping_cart_api_available':True,
             'shipping_one_package_verified':True,'shipping_customer_destination_verified':True,
             'shipping_package_destination_verified':False,'shipping_expected_rate_present':False,
             'shipping_expected_rate_selected':False,'shipping_customer_update_verified':True,
             'checkout_currency_verified':True,'shipping_package_count':1,'shipping_rate_count':0,
             'shipping_api_http_status':200,'shipping_api_error_code':'none'}
        self.assertEqual(self.sanitize(raw),raw)
        for field,maximum in (('shipping_package_count',20),('shipping_rate_count',50)):
            for count in (0,1,maximum):
                value={**raw,field:count};self.assertEqual(self.sanitize(value),value)
            for count in (True,False,-1,maximum+1,1.0,'1',PRIVATE,None):
                self.assertEqual(self.sanitize({**raw,field:count}),
                    {'status':'unavailable','category':'safe_result_unavailable','validation_stage':'browser'})
        for code in validator.BROWSER_CART_ERRORS:
            value={**raw,'shipping_api_error_code':code};self.assertEqual(self.sanitize(value),value)
        for value in (PRIVATE,None,[],{},'https://staging.petish.biz/private'):
            self.assertEqual(self.sanitize({**raw,'shipping_api_error_code':value}),
                {'status':'unavailable','category':'safe_result_unavailable','validation_stage':'browser'})

    def test_checkout_guard_diagnostics_are_finite_classifications_only(self):
        raw={'payment_attempts':1,'failure_stage':'network','unsafe_request_blocked':True,
             'unsafe_reason':'checkout_method_unexpected','checkout_request_method':'empty',
             'checkout_body_format':'json','checkout_calc_totals':True,'stripe_endpoint':'payment_intents',
             'checkout_method_override':'put','calc_totals_update_allowed':True}
        self.assertEqual(self.sanitize(raw),raw)
        for field,allowed in validator.BROWSER_REQUEST_ENUMS.items():
            for value in allowed:
                report={**raw,field:value};self.assertEqual(self.sanitize(report),report)
            for value in (PRIVATE,'https://staging.petish.biz/checkout/?key='+PRIVATE,123,None,True,[],{}):
                self.assertEqual(self.sanitize({**raw,field:value}),
                    {'status':'unavailable','category':'safe_result_unavailable','validation_stage':'browser'})
        for field in ('checkout_body','checkout_payment_data','checkout_payment_method','checkout_url',
                      'checkout_api_key','unsafe_request_url','unsafe_request_payload','unsafe_message',
                      'stripe_request_url','stripe_error_message','stripe_body','stripe_client_secret'):
            self.assertEqual(self.sanitize({**raw,field:PRIVATE}),
                {'status':'unavailable','category':'safe_result_unavailable','validation_stage':'browser'})
        for field in ('shipping_address','shipping_destination','shipping_rate_id','shipping_api_body',
                      'shipping_api_error_message','shipping_html','checkout_currency_code'):
            self.assertEqual(self.sanitize({**raw,field:PRIVATE}),
                {'status':'unavailable','category':'safe_result_unavailable','validation_stage':'browser'})

    def test_browser_cli_and_fifo_guard_never_echo_private_source_or_exceptions(self):
        self.source.write_text(json.dumps({'payment_attempts':2,'paid_receipt_verified':True}))
        output=self.directory/'browser-public.json'
        result=subprocess.run([sys.executable,str(SCRIPT),'--browser',str(self.source),str(output)],
                              capture_output=True,text=True,timeout=3)
        self.assertEqual(result.returncode,0)
        self.assertEqual(json.loads(output.read_text()),{'payment_attempts':2,'paid_receipt_verified':True})
        self.assertEqual(output.stat().st_mode&0o777,0o600)
        fifo=self.directory/'private-fifo';os.mkfifo(fifo)
        self.assertEqual(validator.sanitize_browser(fifo),{'status':'unavailable','category':'safe_result_unavailable','validation_stage':'input'})
        secret_output=self.directory/('PRIVATE_SENTINEL_TOKEN')
        secret_output.write_text(PRIVATE)
        result=subprocess.run([sys.executable,str(SCRIPT),'--browser',str(self.source),str(secret_output)],
                              capture_output=True,text=True,timeout=3)
        self.assertEqual(result.returncode,1)
        self.assertNotIn('PRIVATE_SENTINEL_TOKEN',result.stdout+result.stderr)
        self.assertNotIn('Traceback',result.stdout+result.stderr)
        self.assertEqual(secret_output.read_text(),PRIVATE)

    @unittest.skipUnless(shutil.which('node'),'Node is required to exercise actual browser publicReport')
    def test_actual_js_public_report_matches_python_public_boundary(self):
        browser=ROOT/'tools/staging-rehearsal/browser.mjs'
        expected={'browser_started':True,'paid_receipt_verified':False,'payment_attempts':0,
                  'failure_stage':'cart','cart_page_loaded':True,'cart_api_available':False,
                  'cart_empty_verified':False,'variation_add_request_completed':False,
                  'expected_variation_in_cart':False,'cart_page_http_status':200,
                  'cart_api_http_status':403,'cart_failure_step':'api',
                  'cart_api_error_code':'rest_cookie_invalid_nonce',
                  'shipping_cart_api_available':True,'shipping_one_package_verified':True,
                  'shipping_customer_destination_verified':True,'shipping_package_destination_verified':False,
                  'shipping_expected_rate_present':False,'shipping_expected_rate_selected':False,
                  'shipping_customer_update_verified':True,'checkout_currency_verified':True,
                  'shipping_package_count':1,'shipping_rate_count':0,'shipping_api_http_status':200,
                  'shipping_api_error_code':'woocommerce_rest_invalid_address',
                  'unsafe_reason':'checkout_method_unexpected','checkout_request_method':'missing',
                  'checkout_body_format':'json','checkout_calc_totals':True,
                  'stripe_endpoint':'payment_intents','checkout_method_override':'put',
                  'calc_totals_update_allowed':True}
        code='import {publicReport} from '+json.dumps(browser.as_uri())+';'
        code+='process.stdout.write(JSON.stringify(publicReport('+json.dumps({
            **expected,
            'order_id':12345,'order_key':PRIVATE,'created_order_ids':[12345]})+')));'
        result=subprocess.run(['node','--input-type=module','-e',code],capture_output=True,text=True,timeout=5)
        self.assertEqual(result.returncode,0)
        self.assertNotIn(PRIVATE,result.stdout+result.stderr)
        raw=json.loads(result.stdout)
        self.assertEqual(raw,expected)
        self.assertEqual(self.sanitize(raw),raw)


if __name__=='__main__':unittest.main()
