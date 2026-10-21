"""Temporary staging MU isolation proves ownership without WordPress or network access."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = '/workspace/.petish-dev/bin/php'
RUN = '39514823805-1'
PIN = 'f7615da9180aff6b8b85d81f5821ed1bd4933970adeee3462dd252a502681ee6'


class PetClubJetpackGuardTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='petish-club-guard-')
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name)
        self.tools = self.home / ('.petish-pet-club-deploy-' + RUN)
        self.tools.mkdir(mode=0o700)
        self.marker = self.tools / '.pet-club-owned'
        self.marker.write_text(RUN + '\n')
        self.marker.chmod(0o600)
        self.root = self.home / 'domains/staging.petish.biz/public_html'
        self.mu = self.root / 'wp-content/mu-plugins'
        self.mu.mkdir(parents=True)
        self.baseline_guard = self.mu / 'petish-staging-guard.php'
        self.baseline_guard.write_bytes(b'<?php // immutable staging guard fixture\n')
        self.guard = self.mu / 'zz-petish-pet-club-fixture.php'
        self.receipt = self.tools / 'pet-club-jetpack-guard.json'
        self.pending = self.tools / 'pet-club-jetpack-guard.php.pending'
        self.helper = self.tools / 'pet-club-jetpack-guard.php'
        # Use an immutable fixture pin in the copied helper only.
        source = (ROOT / 'scripts/pet-club-jetpack-guard.php').read_text()
        self.helper.write_text(source.replace(PIN, hashlib.sha256(self.baseline_guard.read_bytes()).hexdigest()))
        self.helper.chmod(0o600)
        self.environment = os.environ.copy()
        self.environment['HOME'] = str(self.home)

    def php(self, code, success=True):
        result = subprocess.run([PHP, '-d', 'display_errors=0', '-d', 'log_errors=0', '-r',
            'require $argv[1];' + code, str(self.helper), RUN], env=self.environment, capture_output=True)
        self.assertEqual(result.returncode == 0, success, result.stderr.decode())
        return result.stdout

    def install(self):
        self.php('petish_club_jetpack_guard_install($argv[2]);')

    def runtime(self, environment='staging', guard_active=True, root_override=None):
        code = '''$filters=[]; function wp_get_environment_type(){return $GLOBALS['environment'];}
function add_filter($key,$callback,$priority){$GLOBALS['filters'][$key]=is_string($callback)?$callback:'closure';}
function add_action($key,$callback,$priority){}
function __return_false(){return false;} function __return_true(){return true;}
define('ABSPATH',$argv[4].'/');define('PETISH_STAGING_GUARD_ACTIVE',$argv[3]==='yes');
$GLOBALS['environment']=$argv[2];require $argv[1].'/wp-content/mu-plugins/zz-petish-pet-club-fixture.php';
echo json_encode(['filters'=>$filters,'run'=>defined('PETISH_PET_CLUB_JETPACK_GUARD_RUN')?PETISH_PET_CLUB_JETPACK_GUARD_RUN:null]);'''
        result = subprocess.run([PHP, '-d', 'display_errors=0', '-r', code, str(self.root), environment,
                                 'yes' if guard_active else 'no', str(root_override or self.root)], env=self.environment, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr.decode())
        return json.loads(result.stdout)

    def test_install_runtime_remove_preserves_existing_guard(self):
        before = self.baseline_guard.read_bytes()
        self.install()
        self.assertEqual(self.guard.stat().st_mode & 0o7777, 0o600)
        self.assertEqual(self.receipt.stat().st_mode & 0o7777, 0o600)
        self.assertFalse(self.pending.exists())
        self.assertEqual(self.guard.stat().st_nlink, 1)
        self.assertEqual(self.runtime(), {'filters': {
            'jetpack_sync_listener_should_load': '__return_false',
            'jetpack_sync_sender_should_load': '__return_false',
            'pre_option_jetpack_sync_settings_disable': '__return_true', 'pre_update_option': 'closure',
            'pre_update_option_wcpay_account_data': 'closure',
            'pre_update_option_wcpay_multi_currency_cached_currencies': 'closure',
            'pre_update_option_the7_plugins_last_check': 'closure',
            'pre_update_site_option_the7_plugins_last_check': 'closure',
            'pre_http_request': 'closure', 'action_scheduler_allow_async_request_runner': '__return_false',
            'action_scheduler_queue_runner_concurrent_batches': 'closure'}, 'run': RUN})
        self.php('petish_club_jetpack_guard_remove($argv[2]);')
        self.assertFalse(self.guard.exists())
        self.assertFalse(self.receipt.exists())
        self.assertEqual(self.baseline_guard.read_bytes(), before)
        self.php('petish_club_jetpack_guard_remove($argv[2]);')

    def test_runtime_isolation_preserves_exact_caches_and_stops_async_and_http(self):
        self.install()
        code = r"""$hooks=[];
function callback_id($cb){return is_string($cb)?$cb:(is_array($cb)?spl_object_id($cb[0]).$cb[1]:spl_object_id($cb));}
function add_filter($name,$cb,$priority=10,$args=1){$GLOBALS['hooks'][$name][$priority][callback_id($cb)]=[$cb,$args];}
function add_action($name,$cb,$priority=10,$args=1){add_filter($name,$cb,$priority,$args);}
function remove_filter($name,$cb,$priority=10){unset($GLOBALS['hooks'][$name][$priority][callback_id($cb)]);}
function remove_action($name,$cb,$priority=10){remove_filter($name,$cb,$priority);}
function invoke_all($name){$groups=$GLOBALS['hooks']['all']??[];ksort($groups);foreach($groups as$items)foreach($items as[$cb,$count])$cb($name);}
function apply_filters($name,$value,...$args){invoke_all($name);$groups=$GLOBALS['hooks'][$name]??[];ksort($groups);
 foreach($groups as$items)foreach($items as[$cb,$count])$value=$cb(...array_slice([$value,...$args],0,$count));return $value;}
function do_action($name){invoke_all($name);$groups=$GLOBALS['hooks'][$name]??[];ksort($groups);
 foreach($groups as$items)foreach($items as[$cb,$count])$cb();}
function __return_false(){return false;}function __return_true(){return true;}
function wp_get_environment_type(){return 'staging';}
class WP_Error{private $code;function __construct($code,$message){$this->code=$code;}function get_error_code(){return $this->code;}}
class TestCache{public $disabled=false;public $calls=0;function disable_refresh(){$this->disabled=true;$this->calls++;}}
class WC_Payments{public static $cache;static function get_database_cache(){return self::$cache;}}
class ActionScheduler_QueueRunner{private static $runner;public $lock_writes=0;
 static function instance(){return self::$runner??=new self;}
 function hook(){add_action('shutdown',[$this,'maybe_dispatch_async_request']);}
 function maybe_dispatch_async_request(){$this->lock_writes++;}
 function unhook_dispatch_async_request(){remove_action('shutdown',[$this,'maybe_dispatch_async_request']);}}
define('ABSPATH',$argv[1].'/');define('PETISH_STAGING_GUARD_ACTIVE',true);
WC_Payments::$cache=new TestCache;
require $argv[1].'/wp-content/mu-plugins/zz-petish-pet-club-fixture.php';
$runner=ActionScheduler_QueueRunner::instance();$runner->hook();
do_action('plugins_loaded');do_action('wp_loaded');$runner->hook();do_action('shutdown');
$keys=['wcpay_account_data','wcpay_multi_currency_cached_currencies','the7_plugins_last_check'];
$old=['data'=>['currency'=>'AUD'],'fetched'=>123,'errored'=>false,'consecutive_errors'=>0];$new=['data'=>'changed'];
$checks=['other_cache_refresh_unchanged'=>!WC_Payments::$cache->disabled&&WC_Payments::$cache->calls===0,
 'async_lock_untouched'=>$runner->lock_writes===0,'exact_options_preserved'=>true];
// Even later callbacks cannot replace the owned cache values after the guard.
add_filter('pre_update_option',static fn($v)=>['changed'=>'late-global'],PHP_INT_MAX,3);
foreach($keys as$key){add_filter('pre_update_option_'.$key,static fn($v)=>['changed'=>'late-key'],PHP_INT_MAX,2);
 $value=apply_filters('pre_update_option_'.$key,$new,$old);
 $value=apply_filters('pre_update_option',$value,$key,$old);
 $checks['exact_options_preserved']=$checks['exact_options_preserved']&&$value===$old;}
$checks['site_timestamp_preserved']=apply_filters('pre_update_site_option_the7_plugins_last_check',999999,123456)===123456;
remove_filter('pre_update_option',array_values($hooks['pre_update_option'][PHP_INT_MAX])[0][0],PHP_INT_MAX);
$checks['unrelated_app_option_writable']=apply_filters('pre_update_option','new-app-value','petish_club_app_option','old-app-value')==='new-app-value';
add_filter('pre_http_request','__return_false',PHP_INT_MAX,3);
$http=apply_filters('pre_http_request',false,[],'https://example.invalid/');
$checks['outbound_http_blocked']=$http instanceof WP_Error&&$http->get_error_code()==='petish_club_verify_http_blocked';
add_filter('action_scheduler_allow_async_request_runner','__return_true',PHP_INT_MAX);
$checks['async_stays_disabled']=apply_filters('action_scheduler_allow_async_request_runner',true)===false
 &&apply_filters('action_scheduler_queue_runner_concurrent_batches',3)===0;
echo json_encode($checks);
"""
        result = subprocess.run([PHP, '-d', 'display_errors=0', '-r', code, str(self.root)],
                                env=self.environment, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr.decode())
        checks = json.loads(result.stdout)
        self.assertEqual(len(checks), 7)
        self.assertTrue(all(checks.values()), checks)

    def test_runtime_is_inert_without_staging_and_matching_receipt(self):
        self.install()
        for environment, active in [('production', True), ('staging', False)]:
            self.assertEqual(self.runtime(environment, active), {'filters': [], 'run': None})
        self.assertEqual(self.runtime(root_override=self.home / 'domains/petish.biz/public_html'), {'filters': [], 'run': None})
        value = json.loads(self.receipt.read_text())
        value['token'] = 'a' * 48
        self.receipt.write_text(json.dumps(value))
        self.assertEqual(self.runtime(), {'filters': [], 'run': None})
        self.php('petish_club_jetpack_guard_remove($argv[2]);', False)
        self.assertTrue(self.guard.exists())
        self.assertTrue(self.receipt.exists())

    def test_disabled_link_reports_finite_category_without_writing(self):
        result = subprocess.run([PHP, '-d', 'disable_functions=link', '-d', 'display_errors=0', '-r',
            'require $argv[1];$report=[];try{petish_club_jetpack_guard_install($argv[2]);}'
            'catch(Throwable $error){echo json_encode($report);exit(1);}',
            str(self.helper), RUN], env=self.environment, capture_output=True)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(json.loads(result.stdout), {'category': 'guard_link_unavailable'})
        self.assertEqual(result.stderr, b'')
        self.assertFalse(self.receipt.exists())
        self.assertFalse(self.pending.exists())
        self.assertFalse(self.guard.exists())

    def test_shell_publication_works_with_disabled_php_link_and_preserves_foreign_targets(self):
        wrapper = (ROOT / 'scripts/hostinger-pet-club.sh').read_text()
        function = wrapper[wrapper.index('fixture_phase() {'):wrapper.index('\nverify_staging_source() {')]
        binaries = self.home / 'bin'
        binaries.mkdir()
        (binaries / 'php').write_text('#!/bin/sh\nexec ' + PHP + ' -d disable_functions=link "$@"\n')
        (binaries / 'wp').write_text('#!/bin/sh\nprintf "fixture-production-db\\n"\n')
        (binaries / 'ln').write_text("""#!/bin/sh
case "$TEST_GUARD_SCENARIO" in
  foreign_file) printf 'foreign-file-sentinel' > "$4";;
  foreign_directory) mkdir "$4";;
  foreign_symlink) ln_path="$4"; /usr/bin/ln -s "$HOME/foreign-source" "$ln_path";;
  same_bytes_copy) cp "$3" "$4"; exit 0;;
  failure) printf 'private-ln-error-sentinel' >&2; exit 1;;
esac
exec /usr/bin/ln "$@"
""")
        for path in binaries.iterdir():
            path.chmod(0o700)
        (self.tools / 'pet-club-fixture.php').write_text("""<?php
require __DIR__.'/pet-club-jetpack-guard.php';
$report=['status'=>'failure','category'=>'guard_verified','checks'=>(object)[]];
try{petish_club_jetpack_guard_verify($argv[2]);$report['status']='success';$report['category']='complete';}
catch(Throwable $ignored){}
echo json_encode($report);exit($report['status']==='success'?0:1);
""")
        environment = self.environment.copy()
        environment['PATH'] = str(binaries) + ':' + environment['PATH']
        for scenario in ('success', 'failure', 'foreign_file', 'foreign_directory', 'foreign_symlink', 'same_bytes_copy'):
            with self.subTest(scenario=scenario):
                work = self.home / ('work-' + scenario)
                work.mkdir()
                environment['TEST_GUARD_SCENARIO'] = scenario
                script = 'work=$1; target_run=$2; petish_ssh=(bash -c)\n'
                script += 'screen_phase(){ return 0; }\n' + function + '\nfixture_phase prepare\n'
                result = subprocess.run(['bash', '-c', script, 'test', str(work), RUN],
                                        env=environment, capture_output=True)
                self.assertEqual(result.stdout, b'')
                self.assertEqual(result.stderr, b'')
                response = json.loads((work / 'fixture.private.json').read_text())
                self.assertNotIn('sentinel', json.dumps(response))
                if scenario == 'success':
                    self.assertEqual(result.returncode, 0)
                    self.assertEqual(response, {'status': 'success', 'category': 'complete', 'checks': {}})
                    self.assertFalse(self.pending.exists())
                    self.assertEqual(self.guard.stat().st_nlink, 1)
                    self.php('petish_club_jetpack_guard_remove($argv[2]);')
                else:
                    self.assertNotEqual(result.returncode, 0)
                    self.assertEqual(response, {'status': 'failure', 'category':
                        'guard_verified' if scenario == 'same_bytes_copy' else 'guard_publish', 'checks': {}})
                    self.assertTrue(self.pending.exists())
                    self.assertTrue(self.receipt.exists())
                    if scenario.startswith('foreign_'):
                        self.php('petish_club_jetpack_guard_remove($argv[2]);', False)
                        if scenario == 'foreign_file':
                            self.assertEqual(self.guard.read_text(), 'foreign-file-sentinel')
                        if self.guard.is_dir():
                            self.assertEqual(list(self.guard.iterdir()), [])
                            self.guard.rmdir()
                        else:
                            self.guard.unlink()
                    self.php('petish_club_jetpack_guard_remove($argv[2]);')

    def test_existing_and_changed_files_are_preserved(self):
        self.guard.write_text('<?php // foreign file')
        self.guard.chmod(0o600)
        before = self.guard.read_bytes()
        self.php('petish_club_jetpack_guard_install($argv[2]);', False)
        self.php('petish_club_jetpack_guard_remove($argv[2]);', False)
        self.assertEqual(self.guard.read_bytes(), before)
        self.assertFalse(self.receipt.exists())
        self.guard.unlink()
        self.install()
        self.guard.write_text('<?php // concurrent operator edit')
        self.php('petish_club_jetpack_guard_remove($argv[2]);', False)
        self.assertEqual(self.guard.read_text(), '<?php // concurrent operator edit')
        self.assertTrue(self.receipt.exists())

    def test_expired_owned_guard_is_inert_and_recoverable(self):
        self.php('''[$root,$path,$receipt,$pending]=petish_club_jetpack_guard_paths($argv[2]);
$token=str_repeat('a',48);$expires=time()-1;
$code=petish_club_jetpack_guard_code($root,$path,$receipt,$argv[2],$token,$expires);
$value=['format'=>'petish-club-jetpack-guard-v1','run_id'=>$argv[2],'token'=>$token,'expires'=>$expires,'sha256'=>hash('sha256',$code)];
petish_club_jetpack_guard_write($receipt,json_encode($value));petish_club_jetpack_guard_write($path,$code);''')
        self.assertEqual(self.runtime(), {'filters': [], 'run': None})
        self.php('petish_club_jetpack_guard_remove($argv[2]);')
        self.assertFalse(self.guard.exists())
        self.assertFalse(self.receipt.exists())

    def test_interrupted_atomic_publication_is_recoverable(self):
        self.install()
        os.link(self.guard, self.pending)
        self.assertEqual(self.guard.stat().st_nlink, 2)
        self.php('petish_club_jetpack_guard_remove($argv[2]);')
        self.assertFalse(self.guard.exists())
        self.assertFalse(self.pending.exists())
        self.assertFalse(self.receipt.exists())

    def test_receipt_only_and_pending_only_interruption_states_recover(self):
        self.install()
        self.guard.unlink()
        self.php('petish_club_jetpack_guard_remove($argv[2]);')
        self.assertFalse(self.receipt.exists())
        self.install()
        self.guard.rename(self.pending)
        self.php('petish_club_jetpack_guard_remove($argv[2]);')
        self.assertFalse(self.pending.exists())
        self.assertFalse(self.receipt.exists())

    def test_malformed_and_linked_receipts_are_inert_and_preserved(self):
        self.install()
        before = self.receipt.read_bytes()
        self.receipt.write_text('{malformed')
        self.assertEqual(self.runtime(), {'filters': [], 'run': None})
        self.php('petish_club_jetpack_guard_remove($argv[2]);', False)
        self.receipt.write_bytes(before)
        other = self.tools / 'other-receipt.json'
        self.receipt.rename(other)
        self.receipt.symlink_to(other)
        self.assertEqual(self.runtime(), {'filters': [], 'run': None})
        self.php('petish_club_jetpack_guard_remove($argv[2]);', False)
        self.assertEqual(other.read_bytes(), before)
        self.assertTrue(self.guard.exists())

    def test_symlinks_hardlinks_wrong_run_and_pinned_guard_drift_refuse(self):
        self.install()
        outside = self.home / 'outside'
        os.link(self.guard, outside)
        self.php('petish_club_jetpack_guard_remove($argv[2]);', False)
        self.assertTrue(outside.exists())
        outside.unlink()
        self.php('petish_club_jetpack_guard_remove("99999-1");', False)
        self.marker.write_text('99999-1\n')
        self.php('petish_club_jetpack_guard_remove($argv[2]);', False)
        self.marker.write_text(RUN + '\n')
        self.baseline_guard.write_text('<?php // changed staging guard')
        self.php('petish_club_jetpack_guard_remove($argv[2]);', False)
        self.assertTrue(self.guard.exists())


if __name__ == '__main__':
    unittest.main()
