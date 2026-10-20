"""Readiness metadata publishing must preserve types and exclude private values."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'scripts/sanitize-readiness-report.py'
spec = importlib.util.spec_from_file_location('petish_readiness_report', SCRIPT)
validator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(validator)
UNAVAILABLE = {'status':'unavailable', 'category':'safe_metadata_unavailable'}
SECRET = 'PRIVATE_SENTINEL_CREDENTIAL_984251'


def tracking_fixture():
    return {'status':'success',
            'tracking_plugins':[{'slug':'woocommerce-shipment-tracking','installed':True,'active':True},
                                {'slug':'dsers','installed':False,'active':False}],
            'dsers_api_keys':{'table_present':True,'count':1,'permissions':['read_write'],'last_access_present':True},
            'webhooks':{'table_present':True,'total':3,'dsers_active_count':3,
                        'dsers_topics':['order.created','order.updated','order.deleted'],'callback_classifiers':['dsers']},
            'tracking_metadata':{'legacy_table_present':True,'hpos_table_present':False,
                                 'legacy':[{'key':'_wc_shipment_tracking_items','count':2}], 'hpos':[]},
            'tracking_settings':[{'flag':'hpos_enabled','enabled':False},
                                 {'flag':'customer_completed_order_email_enabled','enabled':True}]}


def staging_fixture():
    return {'status':'success', 'category':'complete', 'source_only':True, 'runtime_isolation_verified':False,
            'paths':{key:True for key in ('root_canonical','loader_canonical','private_directory_canonical',
                                         'private_directory_0700','private_config_canonical','private_config_0600')},
            'configuration':{'loader_requires_private':True, 'environment_staging_literal':True,
                             'wcpay_dev_mode_true_literal':True, 'disable_wp_cron_true_literal':True,
                             'defined_constant_names':['DB_NAME','DB_PASSWORD','WP_ENVIRONMENT_TYPE']},
            'guard':{'present':True,'canonical':True,'sha256':'a'*64,
                     'hooks_registered':['pre_wp_mail','pre_http_request'],
                     'direct_webhook_false_callback':True,'declared_function_names':['petish_staging_capture_mail']},
            'database':{'configuration_read':True,'name_differs_from_production':True,
                        'host_differs_from_production':False,'identity_differs_from_production':True},
            'woocommerce':{'active_plugins':['petish-passport','woocommerce'],
                           'active_plugins_outside_allowlist':[], 'enabled_setting':True,
                           'test_mode_setting':True,'checkout_mode':'classic','published_products':104,'active_webhooks':0},
            'variations':[{'variation_id':variant,'published':True,'parent_published':True,
                           'stock_quantity':None if index == 0 else 20,'manage_stock':None if index == 0 else True,
                           'stock_status':'instock'} for index,variant in enumerate((246,153,2137,858,1546))]}


class ReadinessReportTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.directory = Path(self.temp.name)
        self.source = self.directory/'private-source.json'

    def tearDown(self): self.temp.cleanup()

    def sanitize(self,kind,raw):
        self.source.write_text(json.dumps(raw))
        result=validator.sanitize(kind,self.source)
        self.assertNotIn(SECRET,json.dumps(result))
        return result

    def reject(self,kind,raw): self.assertEqual(self.sanitize(kind,raw),UNAVAILABLE)

    def test_valid_tracking_preserves_safe_aggregates_and_selected_names(self):
        raw=tracking_fixture();result=self.sanitize('tracking',raw)
        self.assertEqual(result,raw)
        self.assertEqual(result['dsers_api_keys']['count'],1)
        self.assertIs(type(result['tracking_metadata']['legacy'][0]['count']),int)

    def test_valid_staging_preserves_source_only_limits_and_nullable_stock(self):
        raw=staging_fixture();result=self.sanitize('staging',raw)
        self.assertEqual(result,raw)
        self.assertIs(result['source_only'],True)
        self.assertIs(result['runtime_isolation_verified'],False)
        self.assertIsNone(result['variations'][0]['stock_quantity'])

    def test_staging_partial_failure_retains_unknown_values_without_success_claim(self):
        raw=staging_fixture();raw.update(status='unavailable',category='database_separation')
        raw['database'].update(configuration_read=False,name_differs_from_production=None,
                               host_differs_from_production=None,identity_differs_from_production=None)
        raw['woocommerce'].update(enabled_setting=None,test_mode_setting=None,checkout_mode='unverified',
                                  published_products=None,active_webhooks=None)
        for row in raw['variations']:
            row.update(published=False,parent_published=False,stock_quantity=None,manage_stock=None,stock_status=None)
        result=self.sanitize('staging',raw)
        self.assertEqual(result,raw)
        self.assertEqual(result['status'],'unavailable')
        self.assertIs(result['source_only'],True);self.assertIs(result['runtime_isolation_verified'],False)
        self.assertIsNone(result['database']['identity_differs_from_production'])
        self.assertIsNone(result['woocommerce']['published_products'])

    def test_early_staging_failure_retains_only_fixed_failure_and_source_limits(self):
        raw={'status':'unavailable','category':'input','source_only':True,'runtime_isolation_verified':False}
        self.assertEqual(self.sanitize('staging',raw),raw)
        raw['category']=SECRET;self.reject('staging',raw)

    def test_finite_fractional_backorder_stock_preserved_and_nonfinite_refused(self):
        raw=staging_fixture();raw['variations'][0]['stock_quantity']=-2.5
        result=self.sanitize('staging',raw)
        self.assertEqual(result['variations'][0]['stock_quantity'],-2.5)
        for value in (float('nan'),float('inf'),float('-inf'),-10_000_001,10_000_001):
            raw['variations'][0]['stock_quantity']=value;self.reject('staging',raw)

    def test_unknown_keys_never_publish_secret_or_production_api_details(self):
        for kind,fixture,paths in [
            ('tracking',tracking_fixture,[(),('dsers_api_keys',),('webhooks',),('tracking_metadata',),('tracking_plugins',0)]),
            ('staging',staging_fixture,[(),('paths',),('configuration',),('guard',),('database',),('woocommerce',),('variations',0)])]:
            for path in paths:
                with self.subTest(kind=kind,path=path):
                    raw=fixture();target=raw
                    for key in path: target=target[key]
                    target['description']=SECRET+' https://api.production.example/private-token'
                    self.reject(kind,raw)

    def test_arbitrary_secret_values_and_unknown_enums_are_rejected(self):
        cases=[('tracking',tracking_fixture,('tracking_plugins',0,'slug')),
               ('tracking',tracking_fixture,('dsers_api_keys','permissions',0)),
               ('tracking',tracking_fixture,('webhooks','dsers_topics',0)),
               ('tracking',tracking_fixture,('webhooks','callback_classifiers',0)),
               ('tracking',tracking_fixture,('tracking_metadata','legacy',0,'key')),
               ('tracking',tracking_fixture,('tracking_settings',0,'flag')),
               ('staging',staging_fixture,('woocommerce','checkout_mode')),
               ('staging',staging_fixture,('guard','hooks_registered',0)),
               ('staging',staging_fixture,('guard','sha256')),
               ('staging',staging_fixture,('variations',0,'stock_status'))]
        for kind,fixture,path in cases:
            for bad in (SECRET,'https://api.production.example/private-token'):
                with self.subTest(kind=kind,path=path,bad_type='secret_or_url'):
                    raw=fixture();target=raw
                    for key in path[:-1]: target=target[key]
                    target[path[-1]]=bad
                    self.reject(kind,raw)

    def test_boolean_values_cannot_be_published_as_counts(self):
        cases=[('tracking',tracking_fixture,('dsers_api_keys','count')),
               ('tracking',tracking_fixture,('webhooks','total')),
               ('tracking',tracking_fixture,('webhooks','dsers_active_count')),
               ('tracking',tracking_fixture,('tracking_metadata','legacy',0,'count')),
               ('staging',staging_fixture,('woocommerce','published_products')),
               ('staging',staging_fixture,('woocommerce','active_webhooks')),
               ('staging',staging_fixture,('variations',0,'stock_quantity'))]
        for kind,fixture,path in cases:
            for bad in (True,False,-1,10_000_001,'1'):
                # Negative stock is a valid WooCommerce backorder quantity.
                if path[-1]=='stock_quantity' and bad == -1 and type(bad) is int: continue
                with self.subTest(kind=kind,path=path,bad=bad):
                    raw=fixture();target=raw
                    for key in path[:-1]: target=target[key]
                    target[path[-1]]=bad
                    self.reject(kind,raw)

    def test_numbers_and_strings_cannot_be_published_as_boolean_flags(self):
        for kind,fixture,path in [('tracking',tracking_fixture,('dsers_api_keys','table_present')),
                                  ('tracking',tracking_fixture,('tracking_plugins',0,'active')),
                                  ('staging',staging_fixture,('paths','root_canonical')),
                                  ('staging',staging_fixture,('woocommerce','test_mode_setting'))]:
            for bad in (0,1,'true','false'):
                raw=fixture();target=raw
                for key in path[:-1]: target=target[key]
                target[path[-1]]=bad
                self.reject(kind,raw)

    def test_unknown_plugin_names_collapse_to_other_without_raw_names_or_urls(self):
        raw=staging_fixture()
        raw['woocommerce']['active_plugins'] += ['private-vendor-extension','another-vendor-extension']
        raw['woocommerce']['active_plugins_outside_allowlist'] = ['private-vendor-extension']
        result=self.sanitize('staging',raw)
        self.assertEqual(result['status'],'success')
        self.assertEqual(result['woocommerce']['active_plugins'],['other','petish-passport','woocommerce'])
        self.assertEqual(result['woocommerce']['active_plugins_outside_allowlist'],['other'])
        encoded=json.dumps(result)
        self.assertNotIn('private-vendor',encoded);self.assertNotIn('api.production.example',encoded)
        raw['woocommerce']['active_plugins'].append('https://api.production.example/private-token')
        self.reject('staging',raw)

    def test_safe_unavailable_categories_preserved_but_unknown_errors_are_not(self):
        for category in ('database_connection','private_config','configuration_parser','tracking_settings'):
            self.assertEqual(self.sanitize('tracking',{'status':'unavailable','category':category}),
                             {'status':'unavailable','category':category})
        self.reject('tracking',{'status':'unavailable','category':SECRET})
        self.reject('staging',{'status':'unavailable','category':'database_connection','raw_error':SECRET})

    def test_staging_cannot_claim_runtime_isolation_from_source_audit(self):
        raw=staging_fixture();raw['runtime_isolation_verified']=True;self.reject('staging',raw)
        raw=staging_fixture();raw['source_only']=False;self.reject('staging',raw)

    def test_all_five_distinct_sample_variations_are_required(self):
        raw=staging_fixture();raw['variations'].pop();self.reject('staging',raw)
        raw=staging_fixture();raw['variations'][0]=copy.deepcopy(raw['variations'][1]);self.reject('staging',raw)
        raw=staging_fixture();raw['variations'][0]['variation_id']=999999;self.reject('staging',raw)

    def test_symlink_and_fifo_sources_are_refused_without_reading_or_blocking(self):
        target=self.directory/'secret-target';target.write_text(SECRET)
        for name,create in [('linked',lambda path:path.symlink_to(target)),('fifo',lambda path:os.mkfifo(path))]:
            source=self.directory/name;create(source);output=self.directory/(name+'-public.json')
            result=subprocess.run([sys.executable,str(SCRIPT),'tracking',str(source),str(output)],
                                  capture_output=True,text=True,timeout=3)
            self.assertEqual(result.returncode,0)
            self.assertEqual(json.loads(output.read_text()),UNAVAILABLE)
            self.assertNotIn(SECRET,result.stdout+result.stderr+output.read_text())

    def test_invalid_missing_oversized_and_nondict_sources_fail_closed(self):
        self.assertEqual(validator.sanitize('tracking',self.source),UNAVAILABLE)
        for data in ('not-json '+SECRET,'[]','null','"'+SECRET+'"',' '*65537):
            self.source.write_text(data)
            self.assertEqual(validator.sanitize('tracking',self.source),UNAVAILABLE)

    def test_cli_writes_private_fresh_output_and_refuses_existing_targets(self):
        self.source.write_text(json.dumps(tracking_fixture()));output=self.directory/'public.json'
        result=subprocess.run([sys.executable,str(SCRIPT),'tracking',str(self.source),str(output)],capture_output=True,text=True,timeout=3)
        self.assertEqual(result.returncode,0);self.assertEqual(output.stat().st_mode&0o777,0o600)
        before=output.read_bytes()
        result=subprocess.run([sys.executable,str(SCRIPT),'tracking',str(self.source),str(output)],capture_output=True,text=True,timeout=3)
        self.assertNotEqual(result.returncode,0);self.assertEqual(output.read_bytes(),before)


if __name__=='__main__': unittest.main()
