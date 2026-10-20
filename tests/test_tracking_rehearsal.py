"""Exercise private tracking scope, captured-mail filtering and mutation boundaries."""

import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / 'scripts/tracking-rehearsal-remote.php'
PHP = shutil.which('php') or '/workspace/.petish-dev/bin/php'


@unittest.skipUnless(Path(PHP).is_file(), 'PHP is required for tracking isolation fixtures')
class TrackingRehearsalTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='petish-tracking-test-')
        self.directory = Path(self.temporary.name)
        self.directory.chmod(0o700)

    def tearDown(self):
        self.temporary.cleanup()

    def execute(self, body):
        script = self.directory / 'fixture.php'
        script.write_text('<?php\nrequire ' + repr(str(HELPER)) + ';\n' + body)
        completed = subprocess.run([PHP, str(script)], capture_output=True, timeout=20)
        self.assertEqual(completed.returncode, 0, 'Tracking safety fixture failed; diagnostics suppressed')
        self.assertFalse(completed.stderr, 'Tracking fixture emitted unexpected diagnostics')
        return json.loads(completed.stdout)

    def test_production_real_recipients_and_noncustomer_contexts_refuse(self):
        result = self.execute(r'''
define('PETISH_STAGING_GUARD_ACTIVE',true);define('WCPAY_DEV_MODE',true);define('ABSPATH',__DIR__);
$GLOBALS['environment']='staging';$GLOBALS['host']='staging.petish.biz';$GLOBALS['currency']='AUD';
$GLOBALS['email']='qa@petish.invalid';$GLOBALS['roles']=['customer'];
function wp_get_environment_type(){return $GLOBALS['environment'];}
function home_url(){return 'https://'.$GLOBALS['host'];}
function get_woocommerce_currency(){return $GLOBALS['currency'];}
function get_user_by($kind,$id){return (object)['user_email'=>$GLOBALS['email'],'roles'=>$GLOBALS['roles']];}
class Mode{function is_dev(){return true;}function is_test(){return true;}}
class WC_Payments{static function mode(){return new Mode();}}
$context=['run_id'=>'123-1','root'=>__DIR__,'work'=>__DIR__,'customer_id'=>1,
 'customer_email'=>'qa@petish.invalid','with_captured_mail'=>static fn($callback)=>$callback()];
$out=[petish_tracking_context($context)===$context];
foreach([['environment','production'],['host','petish.biz'],['currency','USD'],['roles',['administrator']],
 ['email','real@example.com']]as[$name,$value]){
 $prior=$GLOBALS[$name];$GLOBALS[$name]=$value;
 try{petish_tracking_context($context);$out[]=false;}catch(RuntimeException $expected){$out[]=true;}
 $GLOBALS[$name]=$prior;
}
$bad=$context;$bad['customer_email']='real@example.com';
try{petish_tracking_context($bad);$out[]=false;}catch(RuntimeException $expected){$out[]=true;}
echo json_encode($out);
''')
        self.assertTrue(all(result))
        self.assertEqual(len(result), 7)

    @staticmethod
    def scoped_stub():
        return r'''
$GLOBALS['fixture_order']=['created_via'=>'petish-tracking-rehearsal','tag'=>'123-1','customer'=>1,
 'email'=>'qa@petish.invalid','payment'=>'','transaction'=>'','product'=>0,'variation'=>0];
class Item{function get_product_id(){return $GLOBALS['fixture_order']['product'];}function get_variation_id(){return $GLOBALS['fixture_order']['variation'];}}
class Order{
 function get_id(){return 9;}
 function get_created_via(){return $GLOBALS['fixture_order']['created_via'];}
 function get_meta($key,$single){return $GLOBALS['fixture_order']['tag'];}
 function get_customer_id(){return $GLOBALS['fixture_order']['customer'];}
 function get_billing_email(){return $GLOBALS['fixture_order']['email'];}
 function get_payment_method(){return $GLOBALS['fixture_order']['payment'];}
 function get_transaction_id(){return $GLOBALS['fixture_order']['transaction'];}
 function get_items(){return [new Item()];}
 function delete($force){$GLOBALS['deleted']=true;$GLOBALS['deletes']=($GLOBALS['deletes']??0)+1;}
}
function wc_get_order($id){return ($GLOBALS['deleted']??false)?false:new Order();}
$context=['run_id'=>'123-1','customer_id'=>1,'customer_email'=>'qa@petish.invalid'];
'''

    def test_orders_outside_tagged_unpaid_productless_fixture_cannot_be_mutated(self):
        result = self.execute(self.scoped_stub() + r'''
$out=[petish_tracking_scoped_order($context,1) instanceof Order];
foreach([['created_via','checkout'],['tag','122-1'],['customer',2],['email','other@petish.invalid'],
 ['payment','woocommerce_payments'],['transaction','ch_other'],['product',246],['variation',246]]as[$name,$value]){
 $prior=$GLOBALS['fixture_order'][$name];$GLOBALS['fixture_order'][$name]=$value;
 try{petish_tracking_request($context,1,'POST',[]);$out[]=false;}catch(RuntimeException $expected){$out[]=true;}
 $GLOBALS['fixture_order'][$name]=$prior;
}
foreach([0,-1,'1',null]as$id){try{petish_tracking_scoped_order($context,$id);$out[]=false;}catch(RuntimeException $expected){$out[]=true;}}
foreach(['','../1','abc','A123']as$id){try{petish_tracking_request($context,1,'DELETE',[],$id);$out[]=false;}catch(RuntimeException $expected){$out[]=true;}}
echo json_encode($out);
''')
        self.assertTrue(all(result))
        self.assertEqual(len(result), 17)

    def test_cleanup_recovers_inserted_order_after_throw_and_does_not_delete_twice(self):
        result = self.execute(self.scoped_stub() + r'''
class_alias('Order','WC_Order');$id=null;$order=new WC_Order();$checks=[];
try{throw new RuntimeException('simulated save hook failure after insertion');}
catch(RuntimeException $expected){}
finally{petish_tracking_cleanup_order($context,$id,$order,$checks);}
$out=[$id===null,$order===null,($checks['tracking_synthetic_order_removed']??false)===true,($GLOBALS['deletes']??0)===1];
petish_tracking_cleanup_order($context,$id,$order,$checks);$out[]=($GLOBALS['deletes']??0)===1;
$GLOBALS['deleted']=false;$GLOBALS['fixture_order']['tag']='other-run';$id=9;$order=new WC_Order();
try{petish_tracking_cleanup_order($context,$id,$order,$checks);$out[]=false;}catch(RuntimeException $expected){$out[]=true;}
$out[]=($GLOBALS['deletes']??0)===1;
echo json_encode($out);
''')
        self.assertTrue(all(result))

    def test_captured_mail_only_uses_matching_run_order_type_and_exact_recipient(self):
        result = self.execute(r'''
$file=__DIR__.'/captured.jsonl';file_put_contents($file,'');chmod($file,0600);
$context=['capture_file'=>$file,'run_id'=>'123-1','customer_email'=>'qa@petish.invalid'];
$snapshot=petish_tracking_capture_snapshot($file);
$base=['headers'=>"X-Petish-Tracking-Rehearsal: 123-1\r\nX-Petish-Tracking-Order: 9\r\nX-Petish-Tracking-Email: customer_partial_shipped_order\r\n",
 'to'=>'qa@petish.invalid','message'=>'synthetic message','subject'=>'synthetic subject'];
$foreign=$base;$foreign['headers']=str_replace('123-1','122-1',$base['headers']);$foreign['message']='private unrelated data';
$otherorder=$base;$otherorder['headers']=str_replace('Order: 9','Order: 10',$base['headers']);
$othertype=$base;$othertype['headers']=str_replace('customer_partial_shipped_order','customer_completed_order',$base['headers']);
foreach([$foreign,$otherorder,$othertype,$base]as$record)file_put_contents($file,json_encode($record)."\n",FILE_APPEND);
$captured=petish_tracking_captured($context,$snapshot,9,'customer_partial_shipped_order');
$out=[count($captured)===1,$captured[0]['message']==='synthetic message'];
$bad=$base;$bad['to']='real@example.com';file_put_contents($file,json_encode($bad)."\n",FILE_APPEND);
try{petish_tracking_captured($context,$snapshot,9,'customer_partial_shipped_order');$out[]=false;}catch(RuntimeException $expected){$out[]=true;}
echo json_encode($out);
''')
        self.assertTrue(all(result))

    def test_capture_rejects_symlinks_public_permissions_and_replaced_inode(self):
        result = self.execute(r'''
$file=__DIR__.'/captured.jsonl';file_put_contents($file,'');chmod($file,0600);
$snapshot=petish_tracking_capture_snapshot($file);$context=['capture_file'=>$file,'run_id'=>'123-1'];$out=[];
chmod($file,0644);clearstatcache(true,$file);
try{petish_tracking_capture_snapshot($file);$out[]=false;}catch(RuntimeException $expected){$out[]=true;}
chmod($file,0600);rename($file,$file.'.old');file_put_contents($file,'');chmod($file,0600);
try{petish_tracking_captured($context,$snapshot,9,'customer_completed_order');$out[]=false;}catch(RuntimeException $expected){$out[]=true;}
unlink($file);symlink($file.'.old',$file);
try{petish_tracking_capture_snapshot($file);$out[]=false;}catch(RuntimeException $expected){$out[]=true;}
echo json_encode($out);
''')
        self.assertTrue(all(result))

    def test_email_evidence_requires_full_logo_brand_product_and_each_link(self):
        result = self.execute(r'''
$html='combined-light-v2.png #6532A1 Petish tracking rehearsal item PETISH-STAGING-PARCEL-ONE https://tracking.petish.invalid/parcel/PETISH-STAGING-PARCEL-ONE';
$numbers=['PETISH-STAGING-PARCEL-ONE'];$out=[petish_tracking_email_matches(['message'=>$html],$numbers)];
foreach(['combined-light-v2.png','#6532A1','Petish tracking rehearsal item','https://tracking.petish.invalid/parcel/PETISH-STAGING-PARCEL-ONE']as$remove)
 $out[]=petish_tracking_email_matches(['message'=>str_replace($remove,'',$html)],$numbers)===false;
$out[]=petish_tracking_email_matches(['message'=>$html],['PETISH-STAGING-PARCEL-ONE','PETISH-STAGING-PARCEL-TWO'])===false;
echo json_encode($out);
''')
        self.assertTrue(all(result))


if __name__ == '__main__':
    unittest.main()
