"""Exercise proof boundaries without access to any WordPress database or credentials."""
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php") or "/workspace/.petish-dev/bin/php"
VERIFY = ROOT / "scripts/verify-pet-club.php"
FIXTURE = ROOT / "scripts/pet-club-fixture.php"
BROWSER = ROOT / "tools/pet-club/browser.mjs"
PRIVATE_NAMES = ROOT / "scripts/pet-club-private-option-names.php"


class PetClubVerificationTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="petish-club-proof-")
        self.directory = Path(self.temporary.name)

    def tearDown(self):
        self.temporary.cleanup()

    def php(self, body):
        script = self.directory / "check.php"
        script.write_text("<?php define('PETISH_CLUB_VERIFY_LIBRARY',true); require "
                          + repr(str(VERIFY)) + "; $report=['checks'=>[]];\n" + body)
        result = subprocess.run([PHP, str(script)], capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 0, "Private proof unit fixture failed")
        self.assertFalse(result.stderr)
        return json.loads(result.stdout)

    def node(self, body):
        result = subprocess.run(["node", "--input-type=module", "-e",
                                 "import * as proof from " + json.dumps(BROWSER.as_uri()) + ";\n" + body],
                                capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 0, "Private browser unit fixture failed")
        self.assertFalse(result.stderr)
        return json.loads(result.stdout)

    def test_finite_native_checks_fail_closed(self):
        result = self.php(r"""
$out=[];foreach([['owner-email',true],['orders_unchanged','true'],['orders_unchanged',false]]as$args){
 try{petish_club_verify_mark(...$args);$out[]=false;}catch(Throwable$ignored){$out[]=true;}}
echo json_encode(['rejected'=>$out,'common'=>PETISH_CLUB_VERIFY_COMMON,'staging'=>PETISH_CLUB_VERIFY_STAGING,
 'production'=>PETISH_CLUB_VERIFY_PRODUCTION,'prepare'=>PETISH_CLUB_FIXTURE_PREPARE,'cleanup'=>PETISH_CLUB_FIXTURE_CLEANUP]);
""")
        self.assertEqual(result["rejected"], [True] * 3)
        self.assertEqual(result["production"], ["production_read_only_verified"])
        self.assertIn("request_nonce_and_method_verified", result["staging"])
        self.assertIn("existing_passports_unchanged", result["common"])
        self.assertIn("browser_input_written", result["prepare"])
        self.assertIn("fixture_cleanup_verified", result["cleanup"])

    def test_index_migration_proof_requires_owned_staging_pet_before_loading_migration(self):
        result = self.php(r'''
require ''' + repr(str(ROOT / 'scripts/pet-club-index-proof.php')) + r''';
$GLOBALS['environment']='production';$GLOBALS['foreign']=false;$GLOBALS['missing_marker']=false;$GLOBALS['pending_calls']=0;
function wp_get_environment_type(){return $GLOBALS['environment'];}
class Petish_Passport_Store{const TYPE='petish_pet';}
class Petish_Passport_Community{static function pending_index_count(){$GLOBALS['pending_calls']++;return 0;}}
function get_user_by($field,$value){
 foreach($GLOBALS['proof_state']['users']as$user)if(($field==='id'&&$user['id']===$value)||($field==='login'&&$user['login']===$value))
  return(object)['ID'=>$user['id'],'user_login'=>$user['login'],'user_email'=>$user['email'],'user_pass'=>'synthetic'];
 return false;
}
function wp_check_password(){return true;}
function get_user_meta(){return $GLOBALS['missing_marker']?'':$GLOBALS['proof_state']['ownership'];}
function get_post($id){return(object)['ID'=>$id,'post_author'=>$GLOBALS['foreign']?999:10,'post_type'=>'petish_pet','post_status'=>'private'];}
$state=['format'=>'petish-club-fixture-v1','run'=>'123-1','kind'=>'native','created'=>time(),'ownership'=>str_repeat('a',48),'pet_id'=>30,'users'=>[]];
foreach(['owner'=>10,'member'=>20]as$role=>$id){$login='petish-club-'.$role.'-'.str_repeat($role==='owner'?'b':'c',18);
 $state['users'][$role]=['id'=>$id,'login'=>$login,'email'=>$login.'@petish.invalid','password'=>str_repeat('d',48)];}
$GLOBALS['proof_state']=$state;$out=[];
foreach(['production','foreign','marker']as$case){
 $GLOBALS['environment']=$case==='production'?'production':'staging';$GLOBALS['foreign']=$case==='foreign';$GLOBALS['missing_marker']=$case==='marker';
 try{petish_club_verify_index_migration('/unused',$state);$out[]=false;}catch(Throwable$ignored){$out[]=true;}
}
$out[]=$GLOBALS['pending_calls']===0;
echo json_encode($out);
''')
        self.assertEqual(result, [True] * 4)

    def test_cli_cache_accepts_only_isolated_core_provenance(self):
        for foreign in (False, True):
            result = self.php(r'''
$base=__DIR__.'/core';mkdir($base);mkdir($base.'/wp-includes');mkdir($base.'/wp-content');
file_put_contents($base.'/wp-includes/class-wp-object-cache.php','<?php class WP_Object_Cache {}');
$api='<?php ';foreach(['init','get','get_multiple','set','set_multiple','add','add_multiple','replace','delete','delete_multiple',
 'incr','decr','flush','flush_runtime','flush_group','supports','close','switch_to_blog','add_global_groups','add_non_persistent_groups','reset']as$suffix)
 $api.='function wp_cache_'.$suffix.'(){return true;}';
file_put_contents($base.'/wp-includes/cache.php',$api);file_put_contents($base.'/foreign.php',$api);
require$base.'/wp-includes/class-wp-object-cache.php';require$base.''' + ("'/foreign.php';" if foreign else "'/wp-includes/cache.php';") + r'''
function wp_using_ext_object_cache(){return $GLOBALS['external']??null;}
function apply_filters(){return $GLOBALS['filter']??false;}
$GLOBALS['wp_object_cache']=new WP_Object_Cache();$out=[];
''' + (r'''
$out[]=!petish_club_verify_runtime_cache($base);
''' if foreign else r'''
$out[]=petish_club_verify_runtime_cache($base);$GLOBALS['external']=false;$out[]=petish_club_verify_runtime_cache($base);
foreach([true,0,'false']as$value){$GLOBALS['external']=$value;$out[]=!petish_club_verify_runtime_cache($base);}
$GLOBALS['external']=false;$GLOBALS['filter']=true;$out[]=!petish_club_verify_runtime_cache($base);$GLOBALS['filter']=false;
$GLOBALS['wp_object_cache']=new stdClass();$out[]=!petish_club_verify_runtime_cache($base);$GLOBALS['wp_object_cache']=new WP_Object_Cache();
file_put_contents($base.'/wp-content/advanced-cache.php','<?php /* inert fixture */');require$base.'/wp-content/advanced-cache.php';
$out[]=!petish_club_verify_runtime_cache($base);
''') + r'''
unlink($base.'/wp-content/advanced-cache.php');unlink($base.'/foreign.php');unlink($base.'/wp-includes/cache.php');unlink($base.'/wp-includes/class-wp-object-cache.php');
rmdir($base.'/wp-includes');rmdir($base.'/wp-content');rmdir($base);echo json_encode($out);
'''.replace("unlink($base.'/wp-content/advanced-cache.php');", "if(is_file($base.'/wp-content/advanced-cache.php'))unlink($base.'/wp-content/advanced-cache.php');"))
            self.assertEqual(result, [True] * (1 if foreign else 8))
        source = VERIFY.read_text()
        bootstrap = source[source.index('function petish_club_verify_bootstrap('):]
        self.assertLess(bootstrap.index('enable_loading_object_cache_dropin'), bootstrap.index("require$root.'/wp-load.php'"))
        self.assertIn('petish_club_verify_runtime_cache($root)', bootstrap)

    def test_sql_mutation_and_export_cannot_execute(self):
        result = self.php(r"""
$out=[];foreach(['SELECT ID FROM wp_posts','/* private */ SELECT 1;'," -- comment\n SHOW TABLES",
 'DESCRIBE wp_options','EXPLAIN SELECT ID FROM wp_posts']as$sql){
 $GLOBALS['petish_club_write_attempted']=false;
 $out[]=petish_club_verify_query_guard($sql)===$sql&&!$GLOBALS['petish_club_write_attempted'];}
foreach(['UPDATE wp_options SET option_value=1','DELETE FROM wp_posts','INSERT INTO wp_users VALUES (1)',
 'START TRANSACTION',"SELECT 'private' INTO OUTFILE '/tmp/leak'",'SELECT 1; DELETE FROM wp_posts']as$sql){
 $GLOBALS['petish_club_write_attempted']=false;
 $out[]=petish_club_verify_query_guard($sql)==='SELECT 0 WHERE 1=0'&&$GLOBALS['petish_club_write_attempted'];}
echo json_encode($out);
""")
        self.assertEqual(result, [True] * 11)

    def test_settings_diagnosis_proves_only_an_added_row_without_resetting(self):
        result = self.php(r"""
$before=[['option_name'=>'existing_setting','value_state'=>hash('sha256','unchanged')]];
$hash=hash('sha256',json_encode($before,JSON_THROW_ON_ERROR));
$added=['option_name'=>'action_scheduler_lock_async-request-runner','value_state'=>hash('sha256','temporary lock')];
$current=array_merge([$added],$before);$copy=$current;
$out=[petish_club_verify_single_added_option($hash,$current)===$added,$current===$copy,
 petish_club_verify_single_added_option($hash,$before)===null];
$changed=$current;$changed[1]['value_state']=hash('sha256','changed');
$out[]=petish_club_verify_single_added_option($hash,$changed)===null;
$out[]=petish_club_verify_single_added_option($hash,[])===null;
$out[]=petish_club_verify_option_category($added['option_name'])==='runtime_action_scheduler';
$out[]=petish_club_verify_option_category('petish_club_heart_123_456')==='petish';
$out[]=petish_club_verify_option_category('woocommerce_currency')==='woocommerce';
echo json_encode($out);
""")
        self.assertEqual(result, [True] * 8)

    def test_nonce_pair_proof_requires_exact_aggregate_baseline(self):
        result = self.php(r"""
$before=[['option_name'=>'existing_setting','value_state'=>hash('sha256','unchanged')]];
$hash=hash('sha256',json_encode($before,JSON_THROW_ON_ERROR));
$pair=[['option_name'=>'nonce_key','value_state'=>hash('sha256','never report this')],
 ['option_name'=>'nonce_salt','value_state'=>hash('sha256','never report this either')]];
$current=array_merge($before,$pair);$copy=$current;
$out=[petish_club_verify_new_nonce_options($hash,$current),$current===$copy,
 !petish_club_verify_new_nonce_options($hash,array_merge($before,[$pair[0]]))];
$current[0]['value_state']=hash('sha256','modified-setting');
$out[]=!petish_club_verify_new_nonce_options($hash,$current);
$delta=petish_club_verify_option_delta($before,$current);
$out[]=count($delta['added'])===2&&count($delta['modified'])===1&&$delta['removed']===[];
$out[]=!str_contains(json_encode($delta),'never report this');
echo json_encode($out);
""")
        self.assertEqual(result, [True] * 6)

    def test_only_bounded_structural_option_names_can_be_published(self):
        result = self.php(r"""
$out=[];foreach(['_wc_customer_update_lock','woocommerce_admin_lock','action_scheduler_lock_async-request-runner',
 'nonce_key']as$name)$out[]=petish_club_verify_safe_option_name($name);
foreach(['customer_12345','john@example.com','_wc_session_0123456789abcdef','petish_club_heart_12_34',
 'private_'.str_repeat('a',32),'private_deadbeef','/private/option','has.dot','a_b_'.str_repeat('x',101)]as$name)
 $out[]=!petish_club_verify_safe_option_name($name);
$out[]=petish_club_verify_option_prefix('_wc_session_12345')==='_wc_';
$out[]=petish_club_verify_option_prefix('john@example.com')===null;
echo json_encode($out);
""")
        self.assertEqual(result, [True] * 15)

    def test_structural_names_also_require_trusted_literal_source(self):
        root = self.directory / "wordpress"
        trusted = root / "wp-content/plugins/woocommerce"
        untrusted = root / "wp-content/uploads"
        theme = root / "wp-content/themes/custom"
        for folder in (trusted, untrusted, theme):
            folder.mkdir(parents=True)
        (trusted / "source.php").write_text("<?php $key = 'internal_runtime_lock'; // 'customer_john_smith'\n")
        (untrusted / "data.php").write_text("<?php $key = 'profile_sarah';\n")
        (theme / "custom.php").write_text("<?php $key = 'customer_john_smith';\n")
        result = self.php("$root=" + repr(str(root)) + ";\n" + r"""
$out=petish_club_verify_literal_option_names($root,['internal_runtime_lock','customer_john_smith','profile_sarah','nonce_key']);
sort($out);echo json_encode($out);
""")
        self.assertEqual(result, ["internal_runtime_lock", "nonce_key"])

    def test_private_transport_contains_names_only_and_rejects_other_changes(self):
        result = self.php("define('PETISH_CLUB_PRIVATE_NAMES_LIBRARY',true);require "
                          + repr(str(PRIVATE_NAMES)) + ";\n" + r"""
$before=array_fill_keys(['orders','accounts','products','passports','photos'],'same');
$before['settings_rows']=[['option_name'=>'existing_setting','value_state'=>hash('sha256','original')]];
$before['settings']=hash('sha256',json_encode($before['settings_rows'],JSON_THROW_ON_ERROR));$current=$before;
$current['settings_rows'][]=['option_name'=>'opaque_runtime_key_123','value_state'=>hash('sha256','never disclose')];
$payload=petish_club_private_option_payload('123-1',$before,$current);
$out=[array_keys($payload)===['format','run_id','option_names'],$payload['option_names']===['opaque_runtime_key_123'],
 !str_contains(json_encode($payload),'never disclose'),!str_contains(json_encode($payload),'value_state')];
$changed=$current;$changed['settings_rows'][0]['value_state']=hash('sha256','modified private cache');
$mixed=petish_club_private_option_payload('123-1',$before,$changed);
$out[]=$mixed['option_names']===['existing_setting','opaque_runtime_key_123']
 &&!str_contains(json_encode($mixed),'modified private cache')&&!str_contains(json_encode($mixed),'before_hash');
$modified=$before;$modified['settings_rows'][0]['value_state']=hash('sha256','modified private cache');
$out[]=petish_club_private_option_payload('123-1',$before,$modified)['option_names']===['existing_setting'];
foreach(['account','removed','too-many','wrong-run']as$mode){$changed=$current;$run='123-1';
 if($mode==='account')$changed['accounts']='changed';
 if($mode==='removed')array_shift($changed['settings_rows']);
 if($mode==='too-many')for($i=0;$i<32;$i++)$changed['settings_rows'][]=['option_name'=>'extra_'.$i,'value_state'=>hash('sha256','private')];
 if($mode==='wrong-run')$run='invalid';
 try{petish_club_private_option_payload($run,$before,$changed);$out[]=false;}catch(Throwable$e){$out[]=true;}}
echo json_encode($out);
""")
        self.assertEqual(result, [True] * 10)

    def test_diagnosis_blocks_bootstrap_writes_and_preserves_journal(self):
        source = VERIFY.read_text()
        self.assertLess(source.index("add_filter('query','petish_club_verify_query_guard',PHP_INT_MAX);"),
                        source.index("require$root.'/wp-load.php'"))
        fixture = FIXTURE.read_text()
        diagnostic = fixture[fixture.index("function petish_club_fixture_diagnose"):
                             fixture.index("function petish_club_fixture_main")]
        self.assertNotIn("unlink(", diagnostic)
        self.assertNotIn("update_option(", diagnostic)
        self.assertNotIn("delete_option(", diagnostic)
        self.assertIn("petish_club_verify_single_added_option", diagnostic)
        self.assertIn("$mode==='diagnose'", fixture)
        main = source[source.index("function petish_club_verify_main"):]
        self.assertIn("petish_club_verify_bootstrap($target,$target==='production')", main)
        self.assertNotIn("remove_filter('query'", main)
        self.assertNotIn("$GLOBALS['petish_club_write_attempted']=false", main)
        self.assertIn("petish_club_verify_read_only_scope($bootstrap_blocked)", main)
        self.assertLess(main.index("petish_club_verify_state_equal"), main.index("petish_club_verify_mark('production_read_only_verified'"))

    def test_blocked_bootstrap_attempts_are_preserved_without_hiding_contract_writes(self):
        result = self.php(r"""
$GLOBALS['guard_attached']=true;
function has_filter($hook,$callback){return $GLOBALS['guard_attached']?PHP_INT_MAX:false;}
petish_club_verify_query_guard('UPDATE wp_options SET option_value=1');
$bootstrap=$GLOBALS['petish_club_blocked_write_count'];
$out=[$bootstrap===1,$GLOBALS['petish_club_write_attempted']===true,
 petish_club_verify_query_guard('SELECT 1')==='SELECT 1',petish_club_verify_read_only_scope($bootstrap)];
$GLOBALS['guard_attached']=false;$out[]=!petish_club_verify_read_only_scope($bootstrap);
$GLOBALS['guard_attached']=true;petish_club_verify_query_guard('DELETE FROM wp_posts');
$out[]=!petish_club_verify_read_only_scope($bootstrap);
$out[]=$GLOBALS['petish_club_write_attempted']===true&&$GLOBALS['petish_club_blocked_write_count']===2;
echo json_encode($out);
""")
        self.assertEqual(result, [True] * 7)

    def test_async_dispatch_is_unhooked_before_lock_acquisition(self):
        result = self.php(r"""
$GLOBALS['async_hooked']=true;
class ActionScheduler_QueueRunner {
 static function instance(){static $runner;return $runner??=new self;}
 function unhook_dispatch_async_request(){$GLOBALS['async_hooked']=false;}
}
function has_action($hook,$callback){return $GLOBALS['async_hooked']?10:false;}
petish_club_verify_disable_async_dispatch();$first=$GLOBALS['async_hooked']===false;
$GLOBALS['async_hooked']=true;petish_club_verify_disable_async_dispatch();
echo json_encode([$first,$GLOBALS['async_hooked']===false]);
""")
        self.assertEqual(result, [True, True])

    def test_retirement_classifies_exact_four_modified_keys_without_normalizing(self):
        result = self.php(r"""
$before=array_fill_keys(['orders','accounts','products','passports','photos'],'same');$rows=[];
foreach(PETISH_CLUB_RUNTIME_CACHE_KEYS as$key)$rows[]=['option_name'=>$key,'value_state'=>hash('sha256','before-'.$key)];
$rows[]=['option_name'=>'unrelated_configuration','value_state'=>hash('sha256','untouched')];
$before['settings_rows']=$rows;$before['settings']=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
$current=$before;for($i=0;$i<4;$i++)$current['settings_rows'][$i]['value_state']=hash('sha256','after-'.$i);
$rehash=static function(&$state){$state['settings']=hash('sha256',json_encode($state['settings_rows'],JSON_THROW_ON_ERROR));};$rehash($current);
$copy=$before;$out=[petish_club_verify_retirement_delta($before,$current),$before===$copy];
foreach(['extra-modified','added','removed','protected','only-three','invalid-baseline']as$mode){$old=$before;$new=$current;
 if($mode==='extra-modified')$new['settings_rows'][4]['value_state']=hash('sha256','changed');
 if($mode==='added')$new['settings_rows'][]=['option_name'=>'new_setting','value_state'=>hash('sha256','new')];
 if($mode==='removed')array_pop($new['settings_rows']);
 if($mode==='protected')$new['accounts']='changed';
 if($mode==='only-three')$new['settings_rows'][0]=$old['settings_rows'][0];
 if($mode==='invalid-baseline')$old['settings']=str_repeat('a',64);
 $rehash($new);$out[]=!petish_club_verify_retirement_delta($old,$new);
}
$out[]=!in_array('settings_unchanged',PETISH_CLUB_FIXTURE_RETIRE,true)
 &&!in_array('fixture_cleanup_verified',PETISH_CLUB_FIXTURE_RETIRE,true);
echo json_encode($out);
""")
        self.assertEqual(result, [True] * 9)

    def test_theme_timestamp_proof_uses_executable_canonical_source(self):
        root = self.directory / 'wordpress'
        source = root / 'wp-content/themes/dt-the7/inc/mods/tgmpa/tgmpa.php'
        source.parent.mkdir(parents=True)
        cases = [
            ("<?php update_site_option('the7_plugins_last_check', time());", True),
            ("<?php // update_site_option('the7_plugins_last_check', time());\n", False),
            ('''<?php $str = "update_site_option('the7_plugins_last_check', time())";''', False),
            ("<?php update_option('the7_plugins_last_check', time());", False),
            ("<?php $object->update_site_option('the7_plugins_last_check', time());", False),
            ("<?php update_site_option('the7_plugins_last_check', $value);", False),
        ]
        for contents, expected in cases:
            source.write_text(contents)
            actual = self.php("echo json_encode(petish_club_verify_theme_timestamp_source(" + repr(str(root)) + "));\n")
            self.assertEqual(actual, expected)

    def test_retirement_preserves_original_evidence_and_does_not_claim_cleanup(self):
        source = FIXTURE.read_text()
        retired = source[source.index('function petish_club_fixture_retire'):
                         source.index('function petish_club_fixture_diagnose')]
        self.assertIn("petish_club_jetpack_guard_remove($run)", retired)
        self.assertIn("hash_equals($entry['sha256'],hash_file('sha256',$entry['path']))", retired)
        self.assertIn("hash_equals($diagnostic_hash,hash_file('sha256',$diagnostic))", retired)
        self.assertIn("($value['pet_id']??null)!==$state['pet_id']", retired)
        self.assertNotIn("unlink($entry", retired)
        self.assertNotIn("fixture_cleanup_verified", retired)
        self.assertNotIn("settings_unchanged", retired)
        self.assertIn("in_array($mode,['diagnose','retire'],true)", source)

    def test_private_files_reject_links_modes_and_replacement(self):
        result = self.php("$base=" + repr(str(self.directory)) + ";\n" + r"""
$path=$base.'/state.json';petish_club_verify_write_json($path,['private'=>'fixture']);
$out=[(fileperms($path)&07777)===0600,petish_club_verify_read_json($path)===['private'=>'fixture']];
try{petish_club_verify_write_json($path,['oversized'=>str_repeat('a',1048576)],true);$out[]=false;}catch(Throwable$e){$out[]=petish_club_verify_read_json($path)===['private'=>'fixture'];}
try{petish_club_verify_write_json($path,[]);$out[]=false;}catch(Throwable$e){$out[]=true;}
symlink($path,$base.'/linked');$out[]=!petish_club_verify_path($base.'/linked');
link($path,$base.'/hard');$out[]=!petish_club_verify_path($path);unlink($base.'/hard');
chmod($path,0644);clearstatcache();try{petish_club_verify_read_json($path);$out[]=false;}catch(Throwable$e){$out[]=true;}
$out[]=!petish_club_verify_path($base.'/../'.basename($base));echo json_encode($out);
""")
        self.assertEqual(result, [True] * 8)

    def test_cleanup_ownership_requires_planned_account_identity(self):
        result = self.php(r"""
$GLOBALS['account']=null;
function get_user_by($field,$value){return $GLOBALS['account'];}
function wp_check_password($value,$hash,$id){return $value===$hash;}
function get_user_meta($id,$key,$single){return $GLOBALS['marker']??'';}
$state=['format'=>'petish-club-fixture-v1','run'=>'123-1','kind'=>'browser','created'=>time(),
 'ownership'=>str_repeat('a',48),'users'=>[]];
foreach(['owner','member']as$role){$login='petish-club-'.$role.'-'.str_repeat('a',18);
 $state['users'][$role]=['id'=>0,'login'=>$login,'email'=>$login.'@petish.invalid','password'=>str_repeat('b',48)];}
$out=[petish_club_verify_fixture_owned($state,'123-1','browser')];
foreach(['wrong-run','wrong-kind','wrong-email','wrong-password','foreign-id','foreign-marker']as$case){
 $copy=$state;$run='123-1';$kind='browser';$u=$copy['users']['owner'];$GLOBALS['account']=null;
 if($case==='wrong-run')$run='124-1';elseif($case==='wrong-kind')$kind='native';
 else{$GLOBALS['account']=(object)['ID'=>42,'user_email'=>$u['email'],'user_pass'=>$u['password']];
 if($case==='wrong-email')$GLOBALS['account']->user_email='customer@example.invalid';
 if($case==='wrong-password')$GLOBALS['account']->user_pass='different';
 if($case==='foreign-id')$copy['users']['owner']['id']=43;
 if($case==='foreign-marker')$GLOBALS['marker']=str_repeat('c',48);}
 try{petish_club_verify_fixture_owned($copy,$run,$kind);$out[]=false;}catch(Throwable$e){$out[]=true;}}
echo json_encode($out);
""")
        self.assertEqual(result, [True] * 7)

    def test_sync_queue_ownership_is_exact_and_deserialization_is_inert(self):
        result = self.php(r"""
class WP_User { public $ID; public $data; }
class WP_Post { public $ID; public $post_author; public $post_type; public $post_status; }
class UnexpectedQueueClass { function __wakeup(){ $GLOBALS['awoken']=true; } }
$state=['created'=>time()-10,'users'=>[
 'owner'=>['id'=>51,'login'=>'petish-club-owner-'.str_repeat('a',18),'email'=>'owner@petish.invalid'],
 'member'=>['id'=>52,'login'=>'petish-club-member-'.str_repeat('a',18),'email'=>'member@petish.invalid']],
 'pet_id'=>61,'moments'=>['photo'=>62,'journal'=>63]];
$user=new WP_User;$user->ID=51;$user->data=(object)['ID'=>'51','user_login'=>$state['users']['owner']['login'],
 'user_email'=>$state['users']['owner']['email']];
$event=['jetpack_sync_register_user',[$user,[]],0,microtime(true),false];
$valid=fn($v)=>petish_club_verify_sync_owned(serialize($v),$state);
$out=[$valid($event)];
foreach(['jetpack_sync_save_user','jetpack_sync_add_user']as$hook){$copy=$event;$copy[0]=$hook;$out[]=$valid($copy);}
$delete=['jetpack_deleted_user',[52,null,false],52,microtime(true),false,
 ['external_user_id'=>52,'user_email'=>'member@petish.invalid','wpcom_user_id'=>null]];
$out[]=$valid($delete);
foreach(['foreign-subject','foreign-email','foreign-login','foreign-actor','foreign-actor-email',
 'reassignment','multisite','unknown-hook','object-in-flags','stale','importing','password']as$mode){
 $copy=unserialize(serialize($event));
 if($mode==='foreign-subject')$copy[1][0]->ID=999;
 if($mode==='foreign-email')$copy[1][0]->data->user_email='customer@example.invalid';
 if($mode==='foreign-login')$copy[1][0]->data->user_login='someone-else';
 if($mode==='foreign-actor')$copy[2]=999;
 if($mode==='foreign-actor-email'){$copy=$delete;$copy[5]['user_email']='customer@example.invalid';}
 if($mode==='reassignment'){$copy=$delete;$copy[1][1]=999;}
 if($mode==='multisite'){$copy=$delete;$copy[1][2]=true;}
 if($mode==='unknown-hook')$copy[0]='updated_option';
 if($mode==='object-in-flags')$copy[1][1]=[new UnexpectedQueueClass];
 if($mode==='stale')$copy[3]=$state['created']-20;
 if($mode==='importing')$copy[4]=true;
 if($mode==='password')$copy[1][0]->data->user_pass='must-not-exist';
 $out[]=!$valid($copy);
}
$post=new WP_Post;$post->ID=62;$post->post_author='51';$post->post_type='petish_pet_moment';$post->post_status='private';
$post_event=['jetpack_sync_save_post',[62,$post,false,[]],51,microtime(true),false];
$out[]=$valid($post_event);$post->post_author=999;$out[]=!$valid($post_event);
$out[]=$valid(['deleted_post',[61],51,microtime(true),false]);
$out[]=!$valid(['deleted_post',[999],51,microtime(true),false]);
$out[]=empty($GLOBALS['awoken']);
$recursive=[];$recursive['cycle']=&$recursive;$copy=$event;$copy[1][1]=$recursive;$out[]=!$valid($copy);
echo json_encode($out);
""")
        self.assertEqual(result, [True] * 22)

    def test_queue_removal_requires_exact_full_baseline_and_supports_retry(self):
        result = self.php(r"""
$base=[['option_name'=>'existing_setting','value_state'=>hash('sha256','original')]];
$before=['settings'=>hash('sha256',json_encode($base,JSON_THROW_ON_ERROR)),'settings_rows'=>$base];
$first=['option_name'=>'jpsq_sync-1792538716.123456-437686-1','value_state'=>hash('sha256','owned1')];
$second=['option_name'=>'jpsq_sync-1792538716.123456-437686-2','value_state'=>hash('sha256','owned2')];
$rows=[$base[0],$first,$second];$out=[];
$out[]=petish_club_verify_sync_removal_proof($before,$rows,[$first,$second]);
unset($before['settings_rows']);$out[]=petish_club_verify_sync_removal_proof($before,$rows,[$first,$second]);
$out[]=petish_club_verify_sync_removal_proof($before,[$base[0],$second],[$second]);
$out[]=!petish_club_verify_sync_removal_proof($before,$rows,[$first]);
$changed=$rows;$changed[0]['value_state']=hash('sha256','changed');
$out[]=!petish_club_verify_sync_removal_proof($before,$changed,[$first,$second]);
$changed=$first;$changed['value_state']=hash('sha256','raced');
$out[]=!petish_club_verify_sync_removal_proof($before,$rows,[$changed,$second]);
$out[]=!petish_club_verify_sync_removal_proof($before,$rows,[$first,$first,$second]);
$before['settings_rows']=[];$out[]=!petish_club_verify_sync_removal_proof($before,$rows,[$first,$second]);
echo json_encode($out);
""")
        self.assertEqual(result, [True] * 8)

    def test_sync_isolation_precedes_bootstrap_and_cleanup_is_conditional(self):
        source = VERIFY.read_text()
        self.assertLess(source.index("add_filter('jetpack_sync_listener_should_load'"),
                        source.index("require$root.'/wp-load.php'"))
        self.assertLess(source.index("add_filter('pre_option_jetpack_sync_settings_disable'"),
                        source.index("require$root.'/wp-load.php'"))
        self.assertIn("$sync::$listener===null&&$sync::$sender===null", source)
        self.assertIn("WHERE BINARY option_name=%s AND SHA2(option_value,256)=%s", source)
        self.assertNotIn("Settings::update_settings", source)
        self.assertNotIn("delete_all", source)
        cleanup = source[source.index("function petish_club_verify_fixture_cleanup"):
                         source.index("function petish_club_verify_staging")]
        self.assertLess(cleanup.index("petish_club_verify_sync_cleanup"), cleanup.index("unlink($path)"))
        self.assertIn("!$retain_journal&&!unlink($path)", cleanup)
        fixture = FIXTURE.read_text()
        self.assertIn("petish_club_verify_fixture_cleanup($root,$run,$kind,true)", fixture)
        self.assertLess(fixture.index("petish_club_jetpack_guard_remove($run)"),
                        fixture.index("if(!unlink($path))"))

    def test_native_and_fixture_fail_without_fixed_host_and_never_leak(self):
        for script, args in [(VERIFY, ["123-1", "production"]), (VERIFY, ["123-1", "staging"]),
                             (VERIFY, ["123-1", "foreign"]), (FIXTURE, ["prepare", "123-1"]),
                             (FIXTURE, ["cleanup", "123-1"])]:
            result = subprocess.run([PHP, "-d", "allow_url_fopen=0", str(script), *args],
                                    capture_output=True, timeout=20)
            self.assertEqual(result.returncode, 1)
            self.assertFalse(result.stderr)
            payload = json.loads(result.stdout)
            self.assertEqual(payload["status"], "failure")
            self.assertEqual(set(payload), {"status", "category", "checks"})
            self.assertNotIn("/workspace", result.stdout.decode())

    def test_browser_sanitizer_drops_credentials_and_unknown_values(self):
        result = self.node(r"""
const checks = Object.fromEntries(proof.CHECK_KEYS.map(key => [key, true]));
checks.privatePassword = 'must-not-escape';
const good = proof.publicReport({status:'success',category:'complete',checks,password:'must-not-escape'});
const bad = proof.publicReport({status:'success',category:'private-error',checks:{browser_started:'true'}});
console.log(JSON.stringify({good,bad}));
""")
        self.assertEqual(result["good"]["status"], "success")
        self.assertEqual(len(result["good"]["checks"]), 46)
        self.assertNotIn("must-not-escape", json.dumps(result))
        self.assertEqual(result["bad"]["status"], "failure")
        self.assertEqual(result["bad"]["category"], "input")
        self.assertFalse(result["bad"]["checks"]["browser_started"])

    def test_favourites_http_diagnostics_are_finite_and_private(self):
        result = self.node(r"""
const facts=proof.favouritesHttpFacts('guest',302,{'location':'/wp-login.php?redirect_to=PRIVATE','cache-control':'private, no-store','x-litespeed-cache':'miss'});
const report=proof.publicReport({category:'favourites',diagnostics:{...facts,owner:'PRIVATE',favourites_member_status_200:'true'}});
const cached=proof.favouritesHttpFacts('member',200,{'x-litespeed-cache':'hit'});
console.log(JSON.stringify({report,cached,keys:proof.DIAGNOSTIC_KEYS}));
""")
        self.assertEqual(len(result['keys']), 51)
        self.assertEqual(set(result['report']['diagnostics']), set(result['keys']))
        self.assertTrue(result['report']['diagnostics']['favourites_guest_redirect_to_staging_login'])
        self.assertTrue(result['report']['diagnostics']['favourites_guest_no_store_header'])
        self.assertFalse(result['report']['diagnostics']['favourites_member_status_200'])
        self.assertTrue(result['cached']['favourites_member_litespeed_hit'])
        self.assertNotIn('PRIVATE', json.dumps(result))

    def test_menu_diagnostics_bind_actual_stylesheet_without_publishing_bytes(self):
        result = self.node(r"""
const fs=await import('node:fs');let listener;const diagnostics={};
const pending=proof.observeMenuStylesheet({on(event,callback){if(event!=='response')throw Error('event');listener=callback;}},diagnostics);
const bytes=fs.readFileSync('./wordpress/wp-content/plugins/petish-passport/assets/passport.css');
const response=(url,body,status=200)=>({url:()=>url,status:()=>status,body:async()=>body});
listener(response('https://foreign.invalid/wp-content/plugins/petish-passport/assets/passport.css',Buffer.from('PRIVATE')));
const foreignIgnored=pending.length===0;
listener(response('https://staging.petish.biz/wp-content/plugins/petish-passport/assets/passport.css?ver=0.3.2',bytes));
await Promise.all(pending);const matched=diagnostics.menu_stylesheet_seen&&diagnostics.menu_stylesheet_bytes_verified;
listener(response('https://staging.petish.biz/wp-content/plugins/petish-passport/assets/passport.css?ver=0.3.2',Buffer.from('PRIVATE')));
await Promise.all(pending);const mismatch=!diagnostics.menu_stylesheet_bytes_verified;
const reports=proof.MENU_CATEGORIES.map(category=>proof.publicReport({category,diagnostics}));
console.log(JSON.stringify({foreignIgnored,matched,mismatch,reports,categories:proof.MENU_CATEGORIES}));
""")
        self.assertTrue(result['foreignIgnored'] and result['matched'] and result['mismatch'])
        self.assertEqual([report['category'] for report in result['reports']], result['categories'])
        self.assertNotIn('PRIVATE', json.dumps(result))
        spec = importlib.util.spec_from_file_location('petish_menu_sanitizer', ROOT / 'scripts/sanitize-pet-club-result.py')
        sanitizer = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(sanitizer)
        self.assertTrue(set(result['categories']) <= sanitizer.CATEGORIES)

    def test_menu_measurements_wait_for_transitions_but_reject_stable_wrong_styles(self):
        result = self.node(r"""
let attempts=0;
const values=[false,true,false,true,true,true];
const page={evaluate:async()=>true,waitForTimeout:async ms=>new Promise(resolve=>setTimeout(resolve,ms)),
 locator:()=>({evaluate:async()=>{const icons=values[Math.min(attempts++,values.length-1)];return{icons,height:true,palette:{cyan:icons}};}})};
const settled=await proof.settledAccountColourTrackFacts(page), transitioned=attempts;
page.locator=()=>({evaluate:async()=>({icons:false,height:true,palette:{cyan:false}})});
const wrong=await proof.settledAccountColourTrackFacts(page);
console.log(JSON.stringify([transitioned>=6,settled.icons,settled.height,!wrong.icons,wrong.height,!wrong.palette.cyan]));
""")
        self.assertEqual(result, [True] * 6)

    def test_browser_form_failures_report_only_finite_steps(self):
        result = self.node(r"""
console.log(JSON.stringify(proof.FORM_CATEGORIES.map(category=>proof.publicReport({
 status:'failure',category,privateValue:'must-not-escape',checks:{browser_started:true}
}))));
""")
        expected = ['form_identity', 'form_values', 'variety_default', 'variety_enhancement', 'variety_hidden',
                    'checkbox_spacing', 'variety_other', 'variety_species_reset',
                    'variety_selection', 'form_saved']
        self.assertEqual([entry['category'] for entry in result], expected)
        self.assertTrue(all(entry['status'] == 'failure'
                            and set(entry) == {'status', 'category', 'checks', 'diagnostics'} for entry in result))
        self.assertNotIn('must-not-escape', json.dumps(result))

    def test_browser_video_limit_failures_report_only_finite_steps(self):
        result = self.node(r"""
console.log(JSON.stringify(proof.VIDEO_LIMIT_CATEGORIES.map(category=>proof.publicReport({
 status:'failure',category,privateValue:'must-not-escape',checks:{video_client_limits_verified:true}
}))));
""")
        reasons = ('body_unavailable', 'multipart', 'request', 'window', 'action', 'identity', 'nonce', 'kind', 'caption', 'unknown')
        expected = ['video_nojs_context'] + [f'video_{limit}_{step}'
                    for limit in ('duration', 'size')
                    for step in ('form', 'form_invalid', 'submit', 'click', 'navigation', 'post_blocked',
                                 *(f'post_{reason}' for reason in reasons), 'response', 'notice_missing', 'notice_content', 'unchanged')]
        self.assertEqual([entry['category'] for entry in result], expected)
        self.assertTrue(all(entry['status'] == 'failure'
                            and set(entry) == {'status', 'category', 'checks', 'diagnostics'} for entry in result))
        self.assertNotIn('must-not-escape', json.dumps(result))
        spec = importlib.util.spec_from_file_location('petish_video_limit_sanitizer', ROOT / 'scripts/sanitize-pet-club-result.py')
        sanitizer = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(sanitizer)
        self.assertTrue(set(expected) <= sanitizer.CATEGORIES)

    def test_video_post_denial_diagnostics_never_contain_request_values(self):
        result = self.node(r"""
const url='https://staging.petish.biz/wp-admin/admin-post.php';
const state={input:{pet_id:123},operation:'petish_passport_create_moment',used:false};
const original={action:state.operation,pet_id:'123',moment_id:'0',kind:'video',caption:'Synthetic browser video.',petish_passport_nonce:'123456789a'};
const reason=(body,extra={})=>proof.momentPostDenial(url,'POST',{...state,...extra},body,'document','application/x-www-form-urlencoded');
const out=[reason(null),proof.momentPostDenial(url,'POST',state,'secret','document','multipart/form-data; boundary=')];
for(const [field,value] of [['action','PRIVATE'],['pet_id','999'],['petish_passport_nonce','SECRET'],['kind','photo'],['caption','PRIVATE CAPTION']]){
 out.push(reason(new URLSearchParams({...original,[field]:value}).toString()));
}
const body=new URLSearchParams(original).toString();out.push(reason(body,{used:true}),reason(body));
console.log(JSON.stringify(out));
""")
        self.assertEqual(result, ['body_unavailable', 'multipart', 'action', 'identity', 'nonce', 'kind', 'caption', 'window', 'unknown'])
        self.assertNotIn('PRIVATE', json.dumps(result))

    @unittest.skipUnless(shutil.which('chromium'), 'Chromium is required')
    def test_native_limit_submit_keeps_multipart_and_validity_with_site_scripts_disabled(self):
        result = self.node(r"""
const {createRequire}=await import('node:module');
const require=createRequire(new URL('./tools/staging-rehearsal/package.json',import.meta.url));
const {chromium}=require('playwright');
const origin='https://staging.petish.biz';
const state={input:{pet_id:123},operation:'petish_passport_create_moment',used:false};
const browser=await chromium.launch({headless:true,executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
try{
 const context=await browser.newContext({javaScriptEnabled:false,serviceWorkers:'block'});
 let posts=0,allowed=false,multipart=false;
 await context.route('**/*',async route=>{
  const request=route.request();
  if(request.method()==='POST'){
   posts++;const body=request.postData()??'';const type=request.headers()['content-type']??'';
   allowed=proof.allowedRequest(request.url(),'POST',state,body,request.resourceType(),type);
   multipart=type.startsWith('multipart/form-data;')&&body.includes('filename="synthetic-rejected.mp4"')&&body.includes('Synthetic browser video.');
   if(!allowed){await route.abort();return;}
   state.used=true;await route.fulfill({status:200,contentType:'text/html',body:'<ul class="woocommerce-error"><li>20 seconds</li></ul>'});return;
  }
  await route.fulfill({contentType:'text/html',body:`<form method="post" enctype="multipart/form-data" action="${origin}/wp-admin/admin-post.php">
   <input name="action" value="petish_passport_create_moment" type="hidden"><input name="pet_id" value="123" type="hidden">
   <input name="moment_id" value="0" type="hidden"><input name="kind" value="video" type="hidden">
   <input name="petish_passport_nonce" value="123456789a" type="hidden"><textarea name="caption">Synthetic browser video.</textarea>
   <input type="file" name="video" required><button type="submit">Save privately</button></form>
   <div style="position:fixed;inset:0;z-index:999;background:white"></div>`});
 });
 const page=await context.newPage();await page.goto(origin+'/fixture');
 let invalidRejected=false;try{await page.locator('form').evaluate(proof.requestNativeVideoLimit);}catch{invalidRejected=true;}
 const absent=posts===0;
 await page.locator('input[type=file]').setInputFiles({name:'synthetic-rejected.mp4',mimeType:'video/mp4',buffer:proof.videoFixture('synthetic-over-duration.mp4')});
 const navigated=page.waitForNavigation().then(response=>({response}),error=>({error}));
 const facts=await page.locator('form').evaluate(proof.requestNativeVideoLimit);
 const navigation=await navigated;if(navigation.error)throw navigation.error;
 console.log(JSON.stringify([invalidRejected,absent,posts===1,allowed,multipart,state.used,
  facts.native_form_valid,facts.submitter_visible,!facts.submitter_receives_pointer,
  (await page.locator('.woocommerce-error').textContent()).includes('20 seconds')]));
}finally{await browser.close();}
""")
        self.assertEqual(result, [True] * 10)

    def test_browser_video_fixtures_and_owned_request_boundaries(self):
        result = self.node(r"""
const origin='https://staging.petish.biz',input={pet_id:123,photo_moment_id:124,name:'Petish Club Test Rabbit'};
const state={input,videoId:126,token:'a'.repeat(32),operation:'petish_passport_create_moment',used:false};
const body=new URLSearchParams({action:state.operation,pet_id:'123',moment_id:'0',kind:'video',caption:'Synthetic browser video.',petish_passport_nonce:'123456789a'});
const url=origin+'/wp-admin/admin-post.php';const out=[];
out.push(proof.allowedRequest(url,'POST',state,body.toString()));
body.set('pet_id','456');out.push(!proof.allowedRequest(url,'POST',state,body.toString()));body.set('pet_id','123');
body.set('kind','photo');out.push(!proof.allowedRequest(url,'POST',state,body.toString()));body.set('kind','video');
body.set('moment_id','126');out.push(!proof.allowedRequest(url,'POST',state,body.toString()));
out.push(proof.allowedRequest(url+'?action=petish_pet_moment_video&moment_id=126','GET',state));
out.push(!proof.allowedRequest(url+'?action=petish_pet_moment_video&moment_id=456','GET',state));
out.push(!proof.allowedRequest(url+'?action=petish_pet_moment_video&moment_id=126','GET',{input}));
out.push(proof.allowedRequest(url+'?action=petish_club_video&token='+state.token+'&moment='+'b'.repeat(32),'GET',state));
out.push(!proof.allowedRequest(url+'?action=petish_club_video&token='+'c'.repeat(32)+'&moment='+'b'.repeat(32),'GET',state));
state.operation='petish_club_publish';const share=new URLSearchParams({action:state.operation,pet_id:'123',petish_club_nonce:'123456789a',confirm_share:'1'});
share.append('moment_ids[]','124');share.append('moment_ids[]','126');out.push(proof.allowedRequest(url,'POST',state,share.toString()));
share.append('moment_ids[]','127');out.push(!proof.allowedRequest(url,'POST',state,share.toString()));
out.push(proof.videoFixture('synthetic-valid.mp4').length===2179&&proof.videoFixture('synthetic-over-duration.mp4').length===54310);
try{proof.videoFixture('../synthetic-valid.mp4');out.push(false);}catch{out.push(true);}
console.log(JSON.stringify(out));
""")
        self.assertEqual(result, [True] * 13)

    def test_directory_index_probe_reads_exact_missing_public_keys(self):
        result = self.php(r"""
class Petish_Passport_Community{const TOKEN='_petish_club_token';const REVOKED='_petish_club_revoked';const BLOCKED='_petish_club_blocked';}
class ProbeDB{public $posts='wp_posts',$postmeta='wp_postmeta',$users='wp_users',$last_error='',$sql='',$args=[],$count='0';
 function prepare($sql,...$args){$this->sql=$sql;$this->args=$args;return $sql;}function get_var($sql){return $this->count;}}
$wpdb=new ProbeDB();$out=[petish_club_verify_pending_index_count()===0];
$out[]=str_starts_with($wpdb->sql,'SELECT COUNT(*)')&&str_contains($wpdb->sql,"p.post_status='private'")&&str_contains($wpdb->sql,'INNER JOIN `wp_users`');
$out[]=$wpdb->args===['petish_pet','_petish_club_token','_petish_club_revoked','_petish_club_blocked','_petish_club_find_name','_petish_club_find_species','_petish_club_find_variety'];
$wpdb->count='3';$out[]=petish_club_verify_pending_index_count()===3;
$wpdb->last_error='private sql failure';try{petish_club_verify_pending_index_count();$out[]=false;}catch(Throwable $e){$out[]=true;}
echo json_encode($out);
""")
        self.assertEqual(result, [True] * 5)

    def test_browser_request_guard_limits_mutations_to_owned_pet(self):
        result = self.node(r"""
const origin='https://staging.petish.biz';
const state={input:{pet_id:123,photo_moment_id:124,name:'Petish Club Test Rabbit'},operation:'petish_club_publish',used:false};
const fields=new URLSearchParams({action:'petish_club_publish',pet_id:'123',petish_club_nonce:'123456789a',confirm_share:'1','moment_ids[]':'124'});
const url=origin+'/wp-admin/admin-post.php';const ok=[];
ok.push(proof.allowedRequest(url,'POST',state,fields.toString()));
fields.set('pet_id','456');ok.push(!proof.allowedRequest(url,'POST',state,fields.toString()));fields.set('pet_id','123');
fields.set('moment_ids[]','456');ok.push(!proof.allowedRequest(url,'POST',state,fields.toString()));fields.set('moment_ids[]','124');
fields.set('share_age','1');ok.push(!proof.allowedRequest(url,'POST',state,fields.toString()));fields.delete('share_age');
state.used=true;ok.push(!proof.allowedRequest(url,'POST',state,fields.toString()));state.used=false;
for(const raw of ['https://petish.biz/','https://outside.invalid/',origin+'/checkout/',origin+'/?add-to-cart=3',origin+'/wp-admin/',origin+'/wp-admin/admin-post.php?action=petish_pet_photo&pet_id=456'])ok.push(!proof.allowedRequest(raw,'GET',state));
ok.push(proof.allowedRequest(origin+'/wp-admin/admin-post.php?action=petish_pet_photo&pet_id=123','GET',state));
ok.push(!proof.allowedRequest(url,'DELETE',state,fields.toString()));
state.operation='petish_passport_update';
const body='--bound\r\nContent-Disposition: form-data; name="action"\r\n\r\npetish_passport_update\r\n--bound\r\nContent-Disposition: form-data; name="pet_id"\r\n\r\n123\r\n--bound\r\nContent-Disposition: form-data; name="petish_passport_nonce"\r\n\r\n123456789a\r\n--bound\r\nContent-Disposition: form-data; name="name"\r\n\r\nPetish Club Test Rabbit\r\n--bound\r\nContent-Disposition: form-data; name="species"\r\n\r\nrabbit\r\n--bound\r\nContent-Disposition: form-data; name="variety"\r\n\r\nmini-lop\r\n--bound--\r\n';
ok.push(proof.allowedRequest(url,'POST',state,body,'document','multipart/form-data; boundary=bound'));
ok.push(!proof.allowedRequest(url,'POST',state,body.replace('mini-lop','syrian'),'document','multipart/form-data; boundary=bound'));
const edit=new URLSearchParams({action:'petish_passport_update',pet_id:'123',petish_passport_nonce:'123456789a',name:'Petish Club Test Rabbit',species:'rabbit',variety:'mini-lop',variety_other:'unexpected custom value'});
ok.push(!proof.allowedRequest(url,'POST',state,edit.toString()));
state.operation='petish_club_heart';state.token='a'.repeat(32);state.desiredHeart='1';
const heart=new URLSearchParams({action:'petish_club_heart',token:state.token,hearted:'1',petish_club_nonce:'123456789a'});
ok.push(proof.allowedRequest(url,'POST',state,heart.toString()));heart.set('token','b'.repeat(32));
ok.push(!proof.allowedRequest(url,'POST',state,heart.toString()));heart.set('token',state.token);heart.set('hearted','0');
ok.push(!proof.allowedRequest(url,'POST',state,heart.toString()));
console.log(JSON.stringify(ok));
""")
        self.assertEqual(result, [True] * 19)


if __name__ == "__main__":
    unittest.main()
