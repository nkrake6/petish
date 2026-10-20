"""Boundaries of the private cost-sheet proof; native WordPress runs separately."""
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
VERIFIER = ROOT / "scripts/verify-delivery-cost-sheet.php"
CORE = ROOT / "wordpress/wp-content/plugins/petish-fulfilment/includes/class-petish-delivery-cost-sheet.php"
ADMIN = CORE.with_name("class-petish-delivery-cost-sheet-admin.php")
PHP = shutil.which("php") or "/workspace/.petish-dev/bin/php"

CHECKS = {
    "target_paths_verified", "target_environment_verified", "woocommerce_runtime_verified",
    "all_outbound_http_blocked", "mail_calls_blocked", "remote_file_access_disabled",
    "cron_and_async_runner_disabled", "cost_sheet_classes_loaded", "admin_hooks_registered",
    "anonymous_access_denied", "customer_access_denied", "manager_access_verified",
    "invalid_nonce_rejected", "valid_nonce_accepted", "catalogue_template_verified",
    "csv_formula_injection_prevented", "csv_limits_enforced", "exact_identity_and_quantity_verified",
    "unknown_cost_not_zero", "estimate_not_verified_rate", "known_calculation_verified",
    "admin_preview_rendered", "orders_unchanged", "users_and_authentication_unchanged",
    "product_prices_and_stock_unchanged", "shipping_policy_and_settings_unchanged",
}


@unittest.skipUnless(Path(PHP).is_file(), "PHP is required")
class DeliveryCostSheetVerifierTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="petish-cost-sheet-verifier-")
        self.directory = Path(self.temporary.name)
        self.source = VERIFIER.read_text()
        self.functions = self.directory / "functions.php"
        self.functions.write_text("<?php\n" + self.source[
            self.source.index("const PETISH_COST_SHEET_VERIFY_CHECKS"):self.source.index("\ntry {\n")
        ])

    def tearDown(self):
        self.temporary.cleanup()

    def execute(self, body):
        fixture = self.directory / "fixture.php"
        fixture.write_text("<?php\n$report=['status'=>'failure','category'=>'verification','checks'=>[]];\n"
                           + "require " + repr(str(self.functions)) + ";\n" + body)
        result = subprocess.run([PHP, str(fixture)], capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 0, "Cost-sheet fixture failed; private diagnostics suppressed")
        self.assertFalse(result.stderr)
        return json.loads(result.stdout)

    def test_exact_economics_and_conservative_native_proof_fixtures(self):
        result = self.execute("define('ABSPATH','/private-fixture/');require " + repr(str(CORE)) + ";\n" + r"""
$results=petish_cost_sheet_verify_calculations(1792454400);
echo json_encode(['checks'=>$report['checks'],'complete'=>$results[0]['status']==='complete',
 'source'=>$results[0]['input']['evidence_kind'],'quantity'=>$results[0]['input']['quantity']]);
""")
        self.assertEqual(result["checks"], {
            "known_calculation_verified": True,
            "exact_identity_and_quantity_verified": True,
            "unknown_cost_not_zero": True,
            "csv_formula_injection_prevented": True,
            "csv_limits_enforced": True,
        })
        self.assertEqual((result["complete"], result["source"], result["quantity"]), (True, "estimate", "2"))

    def test_proof_contract_is_finite_and_cannot_accept_unknown_checks(self):
        result = self.execute(r"""
$bad=[];
foreach([['private-customer-value',true],['orders_unchanged','true'],['orders_unchanged',false]]as$pair){
 try{petish_cost_sheet_verify_mark(...$pair);$bad[]=false;}catch(RuntimeException$ignored){$bad[]=true;}
}
echo json_encode(['names'=>PETISH_COST_SHEET_VERIFY_CHECKS,'rejected'=>$bad]);
""")
        self.assertEqual(set(result["names"]), CHECKS)
        self.assertEqual(len(result["names"]), 26)
        self.assertEqual(result["rejected"], [True] * 3)
        marked = set(re.findall(r"petish_cost_sheet_verify_mark\('([^']+)'", self.source))
        self.assertEqual(marked, CHECKS)

    def test_catalogue_csv_roundtrip_retains_leaf_identity_and_blank_costs(self):
        result = self.execute("define('ABSPATH','/private-fixture/');require " + repr(str(CORE))
                              + ";require " + repr(str(ADMIN)) + ";\n" + r"""
class WC_Product{
 function __construct(private $id,private $type,private $parent=0){}
 function is_type($types){return in_array($this->type,(array)$types,true);}
 function needs_shipping(){return true;} function get_parent_id(){return $this->parent;}
 function get_status($context){return 'publish';} function is_visible(){return true;}
 function variation_is_visible(){return true;} function is_purchasable(){return true;}
 function is_in_stock(){return true;} function has_enough_stock($n){return $n<=3;}
 function get_sku($context){return '=SKU-'.$this->id;}
 function managing_stock(){return true;} function backorders_allowed(){return false;}
 function get_stock_managed_by_id(){return $this->id;} function get_stock_quantity($context){return 3;}
 function get_id(){return $this->id;} function get_name(){return '<b>Private product</b>';}
 function get_meta(...$args){return 'https://supplier.invalid/item/'.$this->id;}
}
class Petish_Supplier_Quotes{static function fingerprint($product){return str_repeat('a',64);}}
function is_wp_error($value){return false;}
function wc_get_product($id){return $GLOBALS['products'][(int)$id]??false;}
function wp_strip_all_tags($text){return strip_tags($text);}
function wc_get_formatted_variation(...$args){return 'Small &amp; blue';}
$GLOBALS['products']=[1=>new WC_Product(1,'simple'),2=>new WC_Product(2,'variable'),3=>new WC_Product(3,'variation',2)];
$rows=[];$identities=[];
foreach($GLOBALS['products']as$id=>$product){
 $identity=petish_cost_sheet_verify_private('identity',$product);
 if(!$identity)continue;
 $rows[]=array_intersect_key($identity,array_flip(Petish_Delivery_Cost_Sheet::COLUMNS));
 $identities[$id]=$identity;
}
$decoded=Petish_Delivery_Cost_Sheet::parse(Petish_Delivery_Cost_Sheet::csv($rows));
$catalogue=petish_cost_sheet_verify_private('catalogue',$decoded);
[$scenario,,$countries,$currencies]=petish_cost_sheet_verify_fixture(1792454400);
$out=[$catalogue===$identities,array_keys($catalogue)===[1,3],$catalogue[3]['product_id']===2,
 $catalogue[3]['variation_id']===3,$catalogue[3]['product_label']==='Private product — Small & blue'];
foreach($decoded as$i=>$row){
 foreach(Petish_Delivery_Cost_Sheet::COLUMNS as$column)
  $out[]=$row[$column]===Petish_Delivery_Cost_Sheet::csv_cell($rows[$i][$column]??'');
 $actual=array_replace($scenario,$row,array_diff_key($scenario,$rows[$i]),['quantity'=>'1']);
 $preview=Petish_Delivery_Cost_Sheet::preview([$actual],$catalogue,$countries,$currencies,1792454400)[0];
 $out[]=$preview['status']==='complete'&&$preview['input']['expected_sku']===$rows[$i]['expected_sku'];
 $out[]=$row['supplier_unit_cost']===''&&$row['supplier_freight_total']===''&&$row['country']==='';
}
echo json_encode($out);
""")
        self.assertTrue(all(result))

    def test_sql_mutations_and_file_exports_are_blocked_without_execution(self):
        result = self.execute(r"""
$out=[];
foreach(['SELECT * FROM wp_posts','/* private */ SELECT ID FROM wp_posts;',
 " -- private\n SHOW TABLES",'DESCRIBE wp_options','EXPLAIN SELECT ID FROM wp_posts']as$sql){
 $GLOBALS['petish_cost_sheet_write_attempted']=false;
 $out[]=petish_cost_sheet_verify_query_guard($sql)===$sql&&!$GLOBALS['petish_cost_sheet_write_attempted'];
}
foreach(["UPDATE wp_options SET option_value='private'","/* misleading */ DELETE FROM wp_posts",
 "INSERT INTO wp_users VALUES ('private')","REPLACE INTO wp_options VALUES ('private')",
 "START TRANSACTION","SELECT 'private' INTO OUTFILE '/private/file'","SELECT 1; DELETE FROM wp_posts"]as$sql){
 $GLOBALS['petish_cost_sheet_write_attempted']=false;
 $out[]=petish_cost_sheet_verify_query_guard($sql)==='SELECT 0 WHERE 1=0'&&$GLOBALS['petish_cost_sheet_write_attempted'];
}
echo json_encode($out);
""")
        self.assertEqual(result, [True] * 12)

    def test_product_stock_settings_and_authentication_are_private_read_only_digests(self):
        result = self.execute(r"""
define('ARRAY_A','ARRAY_A');$GLOBALS['mode']='same';$GLOBALS['queries']=[];
class Database{
 public $prefix='wp_';public $posts='wp_posts';public $postmeta='wp_postmeta';public $options='wp_options';
 function get_results($sql,$format){$GLOBALS['queries'][]=$sql;
  if(str_contains($sql,'SELECT ID,'))return [['ID'=>'1','post_type'=>'product','post_parent'=>'0','post_status'=>'publish']];
  if(str_contains($sql,'wp_options'))return [['option_id'=>'1','option_name'=>'petish_shipping_mode',
   'value_state'=>hash('sha256',$GLOBALS['mode']==='settings'?'changed-private-rates':'private-rates')]];
  return [['meta_id'=>'1','post_id'=>'1','meta_key'=>'_stock',
   'value_state'=>hash('sha256',$GLOBALS['mode']==='stock'?'changed-private-stock':'private-stock')]];
 }
}
$wpdb=new Database();
function petish_email_preview_order_counts(){return ['posts'=>1];}
function petish_email_preview_account_state(){return ['users'=>1,'auth_state'=>hash('sha256','private-auth')];}
$first=petish_cost_sheet_verify_state();$same=petish_cost_sheet_verify_state();
$GLOBALS['mode']='settings';$settings=petish_cost_sheet_verify_state();
$GLOBALS['mode']='stock';$stock=petish_cost_sheet_verify_state();
$out=[$first===$same,$first['settings']!==$settings['settings'],$first['products']!==$stock['products'],
 !str_contains(json_encode($first),'private-rates'),!str_contains(json_encode($first),'private-stock'),
 !str_contains(json_encode($first),'private-auth')];
foreach($GLOBALS['queries']as$sql)$out[]=str_starts_with($sql,'SELECT ');
$wpdb->options='wp_options;DROP TABLE wp_posts';
try{petish_cost_sheet_verify_state();$out[]=false;}catch(RuntimeException$ignored){$out[]=true;}
echo json_encode($out);
""")
        self.assertTrue(all(result))

    def test_transport_barriers_always_preempt_external_delivery(self):
        start = self.source.index("    $block_http=static function")
        end = self.source.index("    add_filter('pre_http_request'", start)
        result = self.execute(r"""
class WP_Error{function __construct(public $code,public $message){}}
""" + self.source[start:end] + r"""
$out=[];
foreach(['https://www.dsers.com/','https://public-api.wordpress.com/','http://127.0.0.1/']as$url){
 $blocked=$block_http(false,['method'=>'POST'],$url);
 $out[]=$blocked instanceof WP_Error&&$blocked->code==='petish_cost_sheet_verify_http_blocked';
}
$out[]=$block_mail(null,['to'=>'private@example.invalid'])===true;
$out[]=$false(true)===false;$out[]=$zero(99)===0;
echo json_encode($out);
""")
        self.assertEqual(result, [True] * 6)

    def test_unsaved_principals_do_not_touch_a_role_or_account(self):
        result = self.execute(r"""
class WP_User{
 public $roles=[];public $caps=[];public $allcaps=[];
 function __construct(public $ID){if($ID!==0)throw new RuntimeException();}
}
$out=[];
foreach([[null,[]],['customer',['read'=>true]],['shop_manager',['manage_woocommerce'=>true]]]as$context){
 petish_cost_sheet_verify_principal(...$context);$u=$GLOBALS['current_user'];
 $out[]=$u->ID===0&&$u->caps===$context[1]&&$u->allcaps===$context[1];
}
echo json_encode($out);
""")
        self.assertEqual(result, [True] * 3)
        self.assertNotRegex(self.source, r"\b(?:wp_insert_user|wp_update_user|update_option|update_post_meta|wp_insert_post|wc_create_order)\s*\(")

    def test_canonical_paths_reject_links_and_dot_segments(self):
        regular = self.directory / "regular.php"
        regular.write_text("private")
        symlink = self.directory / "link.php"
        symlink.symlink_to(regular)
        hard = self.directory / "hard.php"
        os.link(regular, hard)
        result = self.execute("$paths=" + repr([
            str(self.directory), str(regular), str(symlink),
            str(self.directory) + "/../" + self.directory.name,
        ]).replace("'", '"') + ";\necho json_encode([petish_cost_sheet_verify_path($paths[0],true),"
            "petish_cost_sheet_verify_path($paths[1]),petish_cost_sheet_verify_path($paths[2]),"
            "petish_cost_sheet_verify_path($paths[3],true)]);")
        self.assertEqual(result, [True, False, False, False])

    def test_only_fixed_targets_produce_finite_failure_reports(self):
        env = dict(os.environ, HOME=str(self.directory))
        for mode in ("production", "staging"):
            result = subprocess.run([PHP, "-d", "allow_url_fopen=0", str(VERIFIER), "123-1", mode],
                                    env=env, capture_output=True, timeout=20)
            self.assertEqual(result.returncode, 1)
            self.assertFalse(result.stderr)
            self.assertEqual(json.loads(result.stdout), {"status": "failure", "category": "paths", "checks": {}})
        for run, mode in (("123-1", "../private"), ("123-1", "https://unrelated.invalid"),
                          ("invalid-private-run", "staging"), ("0-1", "production")):
            result = subprocess.run([PHP, "-d", "allow_url_fopen=0", str(VERIFIER), run, mode],
                                    env=env, capture_output=True, timeout=20)
            self.assertEqual(result.returncode, 1)
            self.assertFalse(result.stderr)
            self.assertEqual(json.loads(result.stdout), {"status": "failure", "category": "input", "checks": {}})
            self.assertNotIn(b"private", result.stdout)


if __name__ == "__main__":
    unittest.main()
