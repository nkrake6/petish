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

    def test_private_files_reject_links_modes_and_replacement(self):
        result = self.php("$base=" + repr(str(self.directory)) + ";\n" + r"""
$path=$base.'/state.json';petish_club_verify_write_json($path,['private'=>'fixture']);
$out=[(fileperms($path)&07777)===0600,petish_club_verify_read_json($path)===['private'=>'fixture']];
try{petish_club_verify_write_json($path,[]);$out[]=false;}catch(Throwable$e){$out[]=true;}
symlink($path,$base.'/linked');$out[]=!petish_club_verify_path($base.'/linked');
link($path,$base.'/hard');$out[]=!petish_club_verify_path($path);unlink($base.'/hard');
chmod($path,0644);clearstatcache();try{petish_club_verify_read_json($path);$out[]=false;}catch(Throwable$e){$out[]=true;}
$out[]=!petish_club_verify_path($base.'/../'.basename($base));echo json_encode($out);
""")
        self.assertEqual(result, [True] * 7)

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
