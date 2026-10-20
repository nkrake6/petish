"""Exercise proof boundaries without access to any WordPress database or credentials."""
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
foreach(['account','modified','removed','too-many','wrong-run']as$mode){$changed=$current;$run='123-1';
 if($mode==='account')$changed['accounts']='changed';
 if($mode==='modified')$changed['settings_rows'][0]['value_state']=hash('sha256','changed');
 if($mode==='removed')array_shift($changed['settings_rows']);
 if($mode==='too-many')for($i=0;$i<32;$i++)$changed['settings_rows'][]=['option_name'=>'extra_'.$i,'value_state'=>hash('sha256','private')];
 if($mode==='wrong-run')$run='invalid';
 try{petish_club_private_option_payload($run,$before,$changed);$out[]=false;}catch(Throwable$e){$out[]=true;}}
echo json_encode($out);
""")
        self.assertEqual(result, [True] * 9)

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
        self.assertEqual(len(result["good"]["checks"]), 31)
        self.assertNotIn("must-not-escape", json.dumps(result))
        self.assertEqual(result["bad"]["status"], "failure")
        self.assertEqual(result["bad"]["category"], "input")
        self.assertFalse(result["bad"]["checks"]["browser_started"])

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
const body='--bound\r\nContent-Disposition: form-data; name="action"\r\n\r\npetish_passport_update\r\n--bound\r\nContent-Disposition: form-data; name="pet_id"\r\n\r\n123\r\n--bound\r\nContent-Disposition: form-data; name="petish_passport_nonce"\r\n\r\n123456789a\r\n--bound\r\nContent-Disposition: form-data; name="name"\r\n\r\nPetish Club Test Rabbit\r\n--bound\r\nContent-Disposition: form-data; name="species"\r\n\r\nrabbit\r\n--bound--\r\n';
ok.push(proof.allowedRequest(url,'POST',state,body,'document','multipart/form-data; boundary=bound'));
state.operation='petish_club_heart';state.token='a'.repeat(32);state.desiredHeart='1';
const heart=new URLSearchParams({action:'petish_club_heart',token:state.token,hearted:'1',petish_club_nonce:'123456789a'});
ok.push(proof.allowedRequest(url,'POST',state,heart.toString()));heart.set('token','b'.repeat(32));
ok.push(!proof.allowedRequest(url,'POST',state,heart.toString()));heart.set('token',state.token);heart.set('hearted','0');
ok.push(!proof.allowedRequest(url,'POST',state,heart.toString()));
console.log(JSON.stringify(ok));
""")
        self.assertEqual(result, [True] * 17)


if __name__ == "__main__":
    unittest.main()
