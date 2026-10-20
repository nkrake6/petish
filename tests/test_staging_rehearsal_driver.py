"""Exercise the actual driver's provider validation and transport barriers."""

import json
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
DRIVER = ROOT / 'scripts/staging-rehearsal-remote.php'
PHP = shutil.which('php') or '/workspace/.petish-dev/bin/php'


@unittest.skipUnless(Path(PHP).is_file(), 'PHP is required for driver safety fixtures')
class StagingRehearsalDriverTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='petish-driver-test-')
        self.directory = Path(self.temporary.name)
        self.source = DRIVER.read_text()
        # Load only declarations from the actual driver; never bootstrap WP.
        start = self.source.index('function rehearsal_fail()')
        end = self.source.index('\ntry {\n    if ($argc')
        self.functions = self.directory / 'functions.php'
        self.functions.write_text('<?php\n' + self.source[start:end])

    def tearDown(self):
        self.temporary.cleanup()

    def execute(self, body):
        script = self.directory / 'fixture.php'
        script.write_text('<?php\nrequire ' + repr(str(self.functions)) + ';\n' + body)
        completed = subprocess.run([PHP, str(script)], capture_output=True, timeout=20)
        self.assertEqual(completed.returncode, 0, 'Driver safety fixture failed; diagnostics suppressed')
        self.assertFalse(completed.stderr, 'Driver fixture emitted unexpected diagnostics')
        return json.loads(completed.stdout)

    @staticmethod
    def charges():
        return r'''
$paid=['id'=>'ch_fixture','livemode'=>false,'paid'=>true,'captured'=>true,'currency'=>'aud',
 'amount'=>1460,'amount_refunded'=>0,'refunded'=>false,'refunds'=>['data'=>[]]];
$full=$paid;$full['amount_refunded']=1460;$full['refunded']=true;
$full['refunds']['data']=[
 ['id'=>'re_one','status'=>'succeeded','currency'=>'aud','charge'=>'ch_fixture','amount'=>600],
 ['id'=>'re_two','status'=>'succeeded','currency'=>'aud','charge'=>'ch_fixture','amount'=>860]];
'''

    def test_native_charge_and_full_refund_require_exact_order_and_amount(self):
        result = self.execute(self.charges() + r'''
$a=rehearsal_provider_charge($paid,'ch_fixture',1460);$b=rehearsal_provider_charge($full,'ch_fixture',1460);
echo json_encode([$a['order_reference_verified']===true,$a['livemode']===false,
 $a['currency']==='aud',$a['amount_minor']===1460,$a['refund_succeeded']===false,
 $b['refund_succeeded']===true,$b['amount_refunded_minor']===1460]);
''')
        self.assertTrue(all(result))

    def test_live_wrong_order_and_malformed_provider_records_refuse(self):
        result = self.execute(self.charges() + r'''
$bad=[];
foreach([['livemode',true],['id','ch_other'],['currency','usd'],['amount','1460'],['paid',1]]as[$k,$v]){$c=$paid;$c[$k]=$v;$bad[]=$c;}
foreach([['charge','ch_other'],['currency','usd'],['status','pending'],['amount','600'],['amount',599]]as[$k,$v]){$c=$full;$c['refunds']['data'][0][$k]=$v;$bad[]=$c;}
$c=$full;$c['refunds']['data'][1]['id']='re_one';$bad[]=$c;
$c=$full;$c['refunds']['data']=['unexpected'=>$c['refunds']['data'][0]];$bad[]=$c;
$c=$full;unset($c['refunds']);$bad[]=$c;$bad[]=['data'=>$paid];
$out=[];foreach($bad as$c){try{rehearsal_provider_charge($c,'ch_fixture',1460);$out[]=false;}catch(UnexpectedValueException$expected){$out[]=true;}}
echo json_encode($out);
''')
        self.assertEqual(len(result), 14)
        self.assertTrue(all(result))

    def test_actual_transport_closures_deny_outside_verified_https_provider_window(self):
        hosts = re.search(r'    \$provider_hosts=(\[[^\n]+\]);', self.source).group(1)
        start = self.source.index('    $provider_url=static function')
        end = self.source.index('    $block_mail=static function', start)
        actual_closures = self.source[start:end]
        result = self.execute(r'''
class WP_Error{function __construct(public $code,public $message){}}
$GLOBALS['filters']=[];function add_filter($name,$callback,...$rest){$GLOBALS['filters'][$name]=$callback;}
$provider_window=false;$provider_hosts=''' + hosts + ';\n' + actual_closures + r'''
$out=[];
foreach($provider_hosts as$host)$out[]=$block_http(false,['method'=>'GET'],'https://'.$host.'/test')instanceof WP_Error;
$provider_window=true;
foreach(['api.wordpress.com','jetpack.wordpress.com','payments.woocommerce.com','public-api.wordpress.com']as$host)$out[]=$block_http(false,['method'=>'POST'],'https://'.$host.'/test')===false;
$native=new WP_Error('native','blocked');$out[]=$block_http($native,['method'=>'GET'],'https://public-api.wordpress.com/test')===$native;
foreach(['http://public-api.wordpress.com/test','https://public-api.wordpress.com.evil.invalid/test',
 'https://evil.invalid/test','https://user:pass@public-api.wordpress.com/test',
 'https://public-api.wordpress.com:8443/test','https://public-api.wordpress.com/test#fragment']as$url)$out[]=$block_http(false,['method'=>'POST'],$url)instanceof WP_Error;
$out[]=$block_http(false,['method'=>'DELETE'],'https://public-api.wordpress.com/test')instanceof WP_Error;
$args=($GLOBALS['filters']['http_request_args'])(['redirection'=>9,'sslverify'=>false],'https://public-api.wordpress.com/test');
$out[]=$args['redirection']===0&&$args['sslverify']===true&&$args['reject_unsafe_urls']===true;
echo json_encode($out);
''')
        self.assertEqual(len(result), 17)
        self.assertTrue(all(result))

    def test_provider_gate_refuses_changed_account_or_native_live_mode(self):
        result = self.execute(r'''
define('WCPAY_DEV_MODE',true);define('PETISH_STAGING_GUARD_ACTIVE',true);
function wp_get_environment_type(){return 'staging';}function get_option(...$args){return [];}
$GLOBALS['dev']=true;$GLOBALS['test']=true;$GLOBALS['onboarding']=true;$GLOBALS['connected']=true;
$GLOBALS['account']=['id'=>'acct_fixture','is_live'=>false,'payments_enabled'=>true];
class WC_Payments{
 static function mode(){return new class{function is_dev(){return $GLOBALS['dev'];}function is_test(){return $GLOBALS['test'];}};}
 static function get_account_service(){return new class{function get_cached_account_data(){return $GLOBALS['account'];}};}
}
class WC_Payments_Onboarding_Service{static function is_test_mode_enabled(){return $GLOBALS['onboarding'];}}
$gateway=new class{function is_connected(){return $GLOBALS['connected'];}};
$fingerprint=rehearsal_account_fingerprint($GLOBALS['account']);
rehearsal_provider_assert($gateway,$fingerprint);$out=[strlen($fingerprint)===64];
foreach(['dev','test','onboarding','connected']as$key){$GLOBALS[$key]=false;try{rehearsal_provider_assert($gateway,$fingerprint);$out[]=false;}catch(RuntimeException$expected){$out[]=true;}$GLOBALS[$key]=true;}
$GLOBALS['account']['id']='acct_changed';try{rehearsal_provider_assert($gateway,$fingerprint);$out[]=false;}catch(RuntimeException$expected){$out[]=true;}
$GLOBALS['account']['id']='acct_fixture';$GLOBALS['account']['is_live']=true;try{rehearsal_provider_assert($gateway,$fingerprint);$out[]=false;}catch(RuntimeException$expected){$out[]=true;}
echo json_encode($out);
''')
        self.assertEqual(len(result), 7)
        self.assertTrue(all(result))


if __name__ == '__main__':
    unittest.main()
