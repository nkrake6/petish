"""Only typed rehearsal metadata can cross the private/public report boundary."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
SCRIPT=ROOT/'scripts/sanitize-rehearsal-result.py'
spec=importlib.util.spec_from_file_location('petish_rehearsal_result',SCRIPT)
validator=importlib.util.module_from_spec(spec);spec.loader.exec_module(validator)
UNAVAILABLE={'status':'unavailable','category':'safe_result_unavailable'}
PRIVATE='PRIVATE_SENTINEL_TOKEN/DO-NOT-PUBLISH'


def fixture():
    return {'status':'success','category':'complete','run_id':'12345678-1',
            'checks':{'environment_staging':True,'woocommerce_test_mode':True,'wcpay_dev_mode':True,
                      'account_is_live':False,'guard_runtime_verified':True,'suppliers_blocked':True,
                      'mail_capture_verified':True,'webhooks_blocked':True,'database_separate':True,
                      'browser_input_written':False},
            'account':{'cached_account_present':True,'account_is_live':False,'native_connected':False,
                       'test_onboarding':True,'dev_mode':True,'test_mode':True,'gateway_test_mode':None},
            'native':{'mode_class':'WCPay\\Mode','mode_methods':['is_test','is_dev','is_test_mode'],
                      'account_service_class':'WC_Payments_Account',
                      'account_service_methods':['get_account_data','is_account_connected'],
                      'gateway_class':'WC_Payment_Gateway_WCPay','gateway_methods':['is_available'],
                      'helper_method_calls':['WC_Payments::get_gateway','Mode->is_test']},
            'structure':{'credentials':[{'path':'account.id','type':'string'},
                                        {'path':'publishable_key','type':'string'},
                                        {'path':'test_mode','type':'boolean'}],
                         'checkout_fixture':[{'path':'cart.items[0].variation_id','type':'integer'},
                                             {'path':'cart.items','type':'array'}]},
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

    def reject(self,raw):self.assertEqual(self.sanitize(raw),UNAVAILABLE)

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

    def test_unknown_top_level_credentials_order_keys_and_receipt_urls_reject_whole_report(self):
        for key,value in [('credentials',{'api_key':PRIVATE}),('order_key',PRIVATE),('order_id',1234),
                          ('receipt_url','https://provider.example/receipt/'+PRIVATE),('raw_error',PRIVATE),
                          ('customer_email','private@example.net'),('checkout_url','https://store.example/private/'+PRIVATE)]:
            with self.subTest(key=key):
                raw=fixture();raw[key]=value;self.reject(raw)

    def test_unknown_nested_keys_and_structure_values_never_publish(self):
        for path in [('checks',),('account',),('native',),('structure',),('structure','credentials',0),
                     ('structure','checkout_fixture',0)]:
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
        for group in ('credentials','checkout_fixture'):
            for bad in ('/home/account/private/key.pem','https://api.example/private','account.id\n'+PRIVATE,PRIVATE):
                raw=fixture();raw['structure'][group][0]['path']=bad;self.reject(raw)
            for bad in (PRIVATE,'secret','https://api.example/receipt',True,1,None):
                raw=fixture();raw['structure'][group][0]['type']=bad;self.reject(raw)
        raw=fixture();raw['structure']['credentials'][0]['value']=PRIVATE;self.reject(raw)

    def test_unknown_hosts_full_urls_userinfo_and_ports_are_rejected(self):
        for host in ('unknown-provider.example','https://api.stripe.com','api.stripe.com:443',
                     'api.stripe.com@private.example','localhost','127.0.0.1',PRIVATE,123,True):
            raw=fixture();raw['guard_allowed_hosts']=[host];self.reject(raw)

    def test_collections_input_and_identifier_sizes_are_bounded(self):
        cases=[]
        raw=fixture();raw['native']['mode_methods']=['is_test']*201;cases.append(raw)
        raw=fixture();raw['structure']['credentials']=[{'path':'key','type':'string'}]*201;cases.append(raw)
        raw=fixture();raw['guard_allowed_hosts']=['api.stripe.com']*31;cases.append(raw)
        raw=fixture();raw['native']['mode_class']='A'*151;cases.append(raw)
        raw=fixture();raw['structure']['credentials'][0]['path']='a'*151;cases.append(raw)
        for raw in cases:self.reject(raw)
        self.source.write_text(' '*65537);self.assertEqual(validator.sanitize(self.source),UNAVAILABLE)

    def test_missing_nondict_and_invalid_json_fail_closed(self):
        self.assertEqual(validator.sanitize(self.source),UNAVAILABLE)
        for content in ('null','[]','"'+PRIVATE+'"','not json '+PRIVATE):
            self.source.write_text(content);self.assertEqual(validator.sanitize(self.source),UNAVAILABLE)

    def test_symlink_and_fifo_source_are_refused_without_blocking_or_reading_secret(self):
        secret=self.directory/'secret-target';secret.write_text(PRIVATE)
        for name,create in [('link',lambda path:path.symlink_to(secret)),('fifo',lambda path:os.mkfifo(path))]:
            source=self.directory/name;create(source);output=self.directory/(name+'-result.json')
            result=subprocess.run([sys.executable,str(SCRIPT),str(source),str(output)],
                                  capture_output=True,text=True,timeout=3)
            self.assertEqual(result.returncode,0);self.assertEqual(json.loads(output.read_text()),UNAVAILABLE)
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


if __name__=='__main__':unittest.main()
