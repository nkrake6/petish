"""Run the real standalone audit with a SELECT-only database fixture."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = Path('/workspace/.petish-dev/bin/php')
SOURCE = (ROOT/'scripts/inspect-supplier-delivery-remote.sh').read_text()
PAYLOAD = SOURCE.split("<<'PETISH_SUPPLIER_DELIVERY'\n",1)[1].rsplit('\nPETISH_SUPPLIER_DELIVERY',1)[0]
spec = importlib.util.spec_from_file_location('supplier_screen',ROOT/'scripts/sanitize-supplier-delivery-report.py')
screen = importlib.util.module_from_spec(spec); spec.loader.exec_module(screen)
PRIVATE = 'private-sentinel-never-publish'
DRIVER = r'''<?php
define('MYSQLI_REPORT_ERROR',1);define('MYSQLI_REPORT_STRICT',2);define('MYSQLI_OPT_CONNECT_TIMEOUT',3);
function mysqli_report($unused){} function mysqli_init(){return new AuditFixtureDatabase();}
class AuditFixtureRows {
 function __construct(private $rows){} function fetch_assoc(){return array_shift($this->rows);} function free(){}
}
class AuditFixtureDatabase {
 function options(...$args){} function real_connect(...$args){} function set_charset(...$args){} function close(){}
 function query($sql){
  if(!preg_match('/\ASELECT\s/',$sql)||preg_match('/\b(?:UPDATE|INSERT|DELETE|REPLACE|DROP|ALTER|CREATE)\b/i',$sql))throw new RuntimeException('private-sentinel-mutation');
  file_put_contents(getenv('FIXTURE_LOG'),json_encode($sql)."\n",FILE_APPEND);
  if(getenv('FIXTURE_FAIL')==='1')throw new RuntimeException('private-sentinel-never-publish');
  if(str_contains($sql,'GROUP BY p.post_type'))return new AuditFixtureRows([['post_type'=>'product','row_count'=>'2'],['post_type'=>'product_variation','row_count'=>'3']]);
  if(str_contains($sql,'GROUP BY m.meta_key')){
   $base=['rows_count'=>'1','products'=>'1','variations'=>'0','empty_count'=>'0','true_like'=>'0','false_like'=>'0','decimal_zero'=>'0','structured'=>'0'];
   return new AuditFixtureRows([
    ['name'=>'_dsers_product_id']+array_replace($base,['rows_count'=>'2','products'=>'2']),
    ['name'=>'_petish_supplier_shipping_cost']+array_replace($base,['false_like'=>'1','decimal_zero'=>'1']),
    ['name'=>'_supplier_secret_token']+$base,
    ['name'=>'_dsers_import_data']+array_replace($base,['structured'=>'1'])]);
  }
  if(str_contains($sql,'counts.order_count'))return new AuditFixtureRows([
   ['order_count'=>'700','observed'=>gmdate('Y-m-d')],['order_count'=>'0','observed'=>'2020-01-01']]);
  if(str_contains($sql,'`wp_options`')){
   $future=gmdate('Y-m-d\TH:i:s\Z',time()+3600);$past=gmdate('Y-m-d\TH:i:s\Z',time()-3600);
   $private=['source_url'=>'https://supplier.invalid/private-sentinel-never-publish','reference'=>'private-sentinel-never-publish','destination_scope'=>['postcodes'=>['private-sentinel-never-publish']]];
   $automatic=['schema_version'=>1,'rules'=>[
    ['per_unit_customer_shipping_ex_tax_aud'=>'0','free_shipping'=>true,'expires_utc'=>$future]+$private,
    ['per_unit_customer_shipping_ex_tax_aud'=>'0.00','free_shipping'=>true,'expires_utc'=>$past]+$private,
    ['per_unit_customer_shipping_ex_tax_aud'=>'4','free_shipping'=>false]+$private]];
   $quotes=['schema_version'=>1,'quotes'=>[
    ['customer_shipping_ex_tax_aud'=>'0','quote_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()-60),'expires_utc'=>$future]+$private,
    ['customer_shipping_ex_tax_aud'=>'0','quote_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()-7200),'expires_utc'=>$past]+$private]];
   return new AuditFixtureRows([
    ['option_name'=>'petish_shipping_mode','option_value'=>'automatic'],
    ['option_name'=>'petish_automatic_shipping_rates','option_value'=>serialize($automatic)],
    ['option_name'=>'petish_supplier_shipping_quotes','option_value'=>serialize($quotes)]]);
  }
  throw new RuntimeException('private-sentinel-unexpected-query');
 }
}
?>
'''


@unittest.skipUnless(PHP.is_file(),'Verified PHP runtime required')
class SupplierDeliveryAuditTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='petish-supplier-audit-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.site = self.root/'site'
        for name in screen.THEME_FILES:
            path=self.site/'wp-content/themes/dt-the7-child'/name
            path.parent.mkdir(parents=True,exist_ok=True); path.write_text('Public source fixture\n')
        self.script=self.root/'audit.php';self.script.write_text(DRIVER+PAYLOAD)
        self.log=self.root/'queries.jsonl'
        self.env={**os.environ,'PETISH_DB_PREFIX':'wp_','PETISH_SUPPLIER_AUDIT_SITE':str(self.site),
            'PETISH_DB_HOST':'localhost','PETISH_DB_NAME':'fixture','PETISH_DB_USER':'fixture',
            'PETISH_DB_PASSWORD':PRIVATE,'PETISH_DB_CLIENT_FLAGS':'0','FIXTURE_LOG':str(self.log)}

    def run_audit(self,**overrides):
        done=subprocess.run([str(PHP),'-n',str(self.script)],env={**self.env,**overrides},capture_output=True,text=True,timeout=10)
        self.assertEqual(done.stderr,'');self.assertNotIn(PRIVATE,done.stdout+done.stderr)
        self.assertNotIn(str(self.root),done.stdout)
        return done,json.loads(done.stdout)

    def test_actual_payload_publishes_only_bounded_product_aggregates(self):
        done,report=self.run_audit();self.assertEqual(done.returncode,0)
        self.assertEqual(report['published_counts'],{'products':2,'variations':3})
        self.assertEqual(report['excluded_metadata_field_count'],1)
        self.assertEqual({row['name'] for row in report['metadata_fields']},{'_dsers_product_id','_dsers_import_data','_petish_supplier_shipping_cost'})
        self.assertEqual(report['supplier_orders'],{'products_with_numeric_count':2,'products_with_fresh_observation':1,'hot_eligible_products':1})
        automatic=report['shipping_policy']['automatic']
        self.assertEqual((automatic['rows'],automatic['zero_rows'],automatic['unexpired_zero_rows'],automatic['explicit_free_unexpired_zero_rows']),(3,2,1,1))
        self.assertEqual(report['shipping_policy']['quotes']['current_zero_rows'],1)
        path=self.root/'raw.json';path.write_text(json.dumps(report));self.assertEqual(screen.screen(path),report)
        queries=[json.loads(line) for line in self.log.read_text().splitlines()]
        self.assertEqual(len(queries),4)
        self.assertTrue(all(query.startswith('SELECT ') for query in queries))
        self.assertFalse(any(word in '\n'.join(queries) for word in ('wp_users','shop_order','wp_comments','wc_orders')))
        self.assertIn("p.post_status='publish'",queries[1]);self.assertIn("parent.post_status='publish'",queries[1])

    def test_raw_database_errors_remain_private(self):
        done,report=self.run_audit(FIXTURE_FAIL='1');self.assertEqual(done.returncode,1)
        self.assertEqual(report,{'status':'unavailable','category':'published_products'})

    def test_screener_refuses_private_fields_names_counts_and_hashes(self):
        _,valid=self.run_audit();path=self.root/'raw.json'
        bad=[]
        value=copy.deepcopy(valid);value['raw_option']=PRIVATE;bad.append(value)
        value=copy.deepcopy(valid);value['metadata_fields'][0]['name']='_supplier_password';bad.append(value)
        value=copy.deepcopy(valid);value['metadata_fields'][0]['rows']=True;bad.append(value)
        value=copy.deepcopy(valid);value['metadata_fields'][0]['products']=99;bad.append(value)
        value=copy.deepcopy(valid);value['shipping_policy']['automatic']['explicit_free_unexpired_zero_rows']=3;bad.append(value)
        value=copy.deepcopy(valid);value['live_theme_hashes']['front-page.php']=PRIVATE;bad.append(value)
        for value in bad:
            path.write_text(json.dumps(value));self.assertEqual(screen.screen(path),{'status':'unavailable','category':'invalid_report'})
        linked=self.root/'link.json';linked.symlink_to(path)
        self.assertEqual(screen.screen(linked),{'status':'unavailable','category':'invalid_report'})


if __name__=='__main__':unittest.main()
