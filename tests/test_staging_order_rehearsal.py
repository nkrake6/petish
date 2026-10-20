"""Scope and payment-safety checks; no WordPress server or gateway is contacted."""

import json
from pathlib import Path
import subprocess
import tempfile
import time
import unittest


ROOT = Path(__file__).resolve().parents[1]
PHP = Path('/workspace/.petish-dev/bin/php')
MODULE = ROOT / 'scripts/staging-rehearsal-order.php'
TEMPLATE = ROOT / 'scripts/staging-rehearsal-fixture.php'


class StagingOrderRehearsalTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.root.chmod(0o700)

    def tearDown(self):
        self.temporary.cleanup()

    def execute(self, source):
        script = self.root / 'test.php'
        script.write_text('<?php\n' + source)
        result = subprocess.run([str(PHP), str(script)], capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 0, 'PHP safety fixture failed; private diagnostics suppressed')
        return json.loads(result.stdout)

    def mail_record(self, email, order_id=9001):
        body = '<p>Order #' + str(order_id) + '</p><p>Small pet carrier</p><p>Total: <span>$14.60</span></p>'
        if email == 'customer_note':
            body += '<p>STAGING REHEARSAL 12345-1: synthetic tracking demonstration only; no parcel or supplier order. https://tracking.invalid/rehearsal/12345-1</p>'
        return {'headers': '\r\n'.join(['X-Petish-Rehearsal: 12345-1', 'X-Petish-Rehearsal-Order: ' + str(order_id), 'X-Petish-Rehearsal-Email: ' + email]),
                'to': 'admin@petish.invalid' if email == 'new_order' else 'qa@petish.invalid',
                'subject': 'Order #' + str(order_id), 'message': body}

    def fixture(self):
        config_path = self.root / 'fixture.json'
        config = {'run_id': '12345-1', 'customer_id': 7, 'variation_id': 246, 'quantity': 1,
                  'country': 'AU', 'postcode': '3083', 'currency': 'AUD', 'shipping_ex_tax': 6,
                  'started': int(time.time()) - 30, 'expires': int(time.time()) + 3600}
        for name in ('email', 'stock'):
            path = self.root / (name + '-events.jsonl')
            path.write_text('')
            path.chmod(0o600)
            config[name + '_events'] = str(path)
        config_path.write_text(json.dumps(config))
        config_path.chmod(0o600)
        fixture = self.root / 'mu.php'
        fixture.write_text(TEMPLATE.read_text().replace("'__PETISH_REHEARSAL_CONFIG_PATH__'", repr(str(config_path))))
        return fixture

    def stubs(self, fixture):
        return r'''
define('ABSPATH',__DIR__.'/');define('PETISH_STAGING_GUARD_ACTIVE',true);
function wp_get_environment_type(){return 'staging';}
function add_filter(...$args){}function add_action(...$args){}
$GLOBALS['uid']=7;$GLOBALS['currency']='AUD';
function get_current_user_id(){return $GLOBALS['uid'];}
function get_woocommerce_currency(){return $GLOBALS['currency'];}
class WC_Payments {public static function mode(){return new class{function is_dev(){return true;}function is_test(){return true;}};}}
class WC_Tax {static function get_shipping_tax_rates(){return [];}static function calc_shipping_tax($a,$b){return [];}}
class WC_Shipping_Rate {public $cost;public $id;public function __construct($id,$name,$cost,...$rest){$this->id=$id;$this->cost=$cost;}function add_meta_data(...$a){}}
require ''' + repr(str(fixture)) + ';\n'

    def test_library_include_has_no_automatic_wordpress_or_payment_calls(self):
        result = self.execute('require ' + repr(str(MODULE)) + ';echo json_encode(function_exists("petish_rehearsal_refund"));')
        self.assertTrue(result)

    def test_shipping_override_is_exact_customer_product_quantity_destination_and_currency(self):
        fixture = self.fixture()
        result = self.execute(self.stubs(fixture) + r'''
$p=['contents'=>[['product_id'=>242,'variation_id'=>246,'quantity'=>1]],'destination'=>['country'=>'AU','postcode'=>'3083']];
$original=['existing'=>'unchanged'];$out=[];
$rate=petish_rehearsal_fixture_rates($original,$p);$out[]=array_keys($rate)===['petish_rehearsal_shipping:12345-1']&&reset($rate)->cost===6;
$GLOBALS['uid']=8;$out[]=petish_rehearsal_fixture_rates($original,$p)===$original;$GLOBALS['uid']=7;
foreach([['quantity',2],['variation_id',153],['product_id',999]]as[$key,$value]){$q=$p;$q['contents'][0][$key]=$value;$out[]=petish_rehearsal_fixture_rates($original,$q)===$original;}
$q=$p;$q['destination']['postcode']='3000';$out[]=petish_rehearsal_fixture_rates($original,$q)===$original;
$q=$p;$q['destination']['country']='US';$out[]=petish_rehearsal_fixture_rates($original,$q)===$original;
$GLOBALS['currency']='USD';$out[]=petish_rehearsal_fixture_rates($original,$p)===$original;
echo json_encode($out);''')
        self.assertTrue(all(result))

    def test_missing_private_fixture_never_overrides_existing_rates(self):
        fixture = self.fixture()
        (self.root / 'fixture.json').unlink()
        result = self.execute(self.stubs(fixture) + r'''
$p=['contents'=>[['product_id'=>242,'variation_id'=>246,'quantity'=>1]],'destination'=>['country'=>'AU','postcode'=>'3083']];
echo json_encode(petish_rehearsal_fixture_rates(['existing'=>1],$p)===['existing'=>1]);''')
        self.assertTrue(result)

    def test_provider_proof_rejects_live_mismatched_amount_and_partial_refunds(self):
        result = self.execute('require ' + repr(str(MODULE)) + r''';
$order=new class {function get_total(){return 14.6;}};
$base=['livemode'=>false,'paid'=>true,'captured'=>true,'amount_minor'=>1460,'amount_refunded_minor'=>0,'refund_succeeded'=>false,'order_reference_verified'=>true,'currency'=>'aud'];
$out=[];foreach([['livemode',true],['amount_minor',1],['amount_refunded_minor',1],['order_reference_verified',false],['currency','usd']]as[$k,$v]){
 $proof=$base;$proof[$k]=$v;$ctx=['provider_probe'=>static function()use($proof){return $proof;}];
 try{petish_order_provider($ctx,$order,false);$out[]=false;}catch(RuntimeException$e){$out[]=true;}
}
$ctx=['provider_probe'=>static function()use($base){return $base;}];
try{petish_order_provider($ctx,$order,true);$out[]=false;}catch(RuntimeException$e){$out[]=true;}
$out[]=is_array(petish_order_provider($ctx,$order,false));echo json_encode($out);''')
        self.assertTrue(all(result))

    def test_stock_events_are_scoped_and_keep_decline_and_success_snapshots(self):
        fixture = self.fixture()
        result = self.execute(self.stubs(fixture) + r'''
$GLOBALS['stock']=24;
function wc_get_product($id){return new class{function get_stock_quantity(){return $GLOBALS['stock'];}};}
class WC_Order {
 function get_customer_id(){return 7;}function get_date_created(){return new DateTimeImmutable();}
 function get_shipping_country(){return 'AU';}function get_shipping_postcode(){return '3083';}function get_shipping_total(){return 6;}function get_coupon_codes(){return [];}
 function get_items($type){if($type==='fee')return [];return [new class($type){function __construct($t){}function get_variation_id(){return 246;}function get_product_id(){return 242;}function get_quantity(){return 1;}function get_method_id(){return 'petish_rehearsal_shipping';}function get_name(){return 'STAGING SIMULATION [12345-1] — no actual carrier';}}];}
 function get_meta($k,$single){return '12345-1';}function get_id(){return 9000;}
 function get_data_store(){return new class{function get_stock_reduced($o){return $GLOBALS['stock']===23;}};}
}
$order=new WC_Order();petish_rehearsal_fixture_snapshot($order,'failed');$GLOBALS['stock']=23;petish_rehearsal_fixture_snapshot($order,'payment_complete');
$cfg=petish_rehearsal_fixture_config();$rows=array_map(static fn($s)=>json_decode($s,true),file($cfg['stock_events'],FILE_IGNORE_NEW_LINES));
echo json_encode(array_column($rows,'stock')===[24,23]&&array_column($rows,'event')===['failed','payment_complete']);''')
        self.assertTrue(result)

    def test_native_email_markers_and_event_ids_are_scoped(self):
        fixture = self.fixture()
        result = self.execute(self.stubs(fixture) + r'''
class WC_Order {
 function get_customer_id(){return 7;}function get_date_created(){return new DateTimeImmutable();}
 function get_shipping_country(){return 'AU';}function get_shipping_postcode(){return '3083';}function get_shipping_total(){return 6;}function get_coupon_codes(){return [];}
 function get_items($type){if($type==='fee')return [];return [new class{function get_variation_id(){return 246;}function get_product_id(){return 242;}function get_quantity(){return 1;}function get_method_id(){return 'petish_rehearsal_shipping';}function get_name(){return 'STAGING SIMULATION [12345-1] — no actual carrier';}}];}
 function get_meta($k,$single){return '12345-1';}function get_id(){return 9000;}
}
$headers=petish_rehearsal_fixture_email('Content-Type: text/html','customer_processing_order',new WC_Order());
echo json_encode(str_contains($headers,'X-Petish-Rehearsal: 12345-1')&&str_contains($headers,'X-Petish-Rehearsal-Order: 9000')
&&petish_rehearsal_fixture_email('unchanged','unexpected_email',new WC_Order())==='unchanged');''')
        self.assertTrue(result)

    def test_private_journal_rejects_link_or_shared_permissions(self):
        path = self.root / 'journal.json'
        path.write_text('{}')
        path.chmod(0o644)
        source = 'require ' + repr(str(MODULE)) + ';try{petish_order_private_file(' + repr(str(path)) + ');echo json_encode(false);}catch(RuntimeException$e){echo json_encode(true);}'
        self.assertTrue(self.execute(source))
        path.chmod(0o600)
        saved = path.with_name('saved.json')
        path.rename(saved)
        path.symlink_to(saved)
        self.assertTrue(self.execute(source))

    def test_already_started_full_refund_is_verified_without_second_gateway_mutation(self):
        public = self.root / 'public_html'
        public.mkdir()
        capture = self.root / 'captured.jsonl'
        email_types = ['new_order', 'customer_processing_order', 'customer_note', 'customer_completed_order', 'customer_refunded_order']
        capture.write_text('\n'.join(json.dumps(self.mail_record(email)) for email in email_types) + '\n')
        capture.chmod(0o600)
        captured_stat = capture.stat()
        event_file = self.root / 'email-events.jsonl'
        event_file.write_text('\n'.join(json.dumps({'run_id': '12345-1', 'order_id': 9001, 'email_id': email})
                             for email in email_types) + '\n')
        event_file.chmod(0o600)
        journal = {'run_id': '12345-1', 'customer_id': 7, 'phase': 'refund_started', 'order_id': 9001,
                   'max_prior_order': 9000, 'refund_started': True, 'baseline_stock': 24,
                   'unit_price': '8', 'prices_include_tax': True, 'product_label': 'small pet carrier', 'admin_recipients': ['admin@petish.invalid'],
                   'capture_file': str(capture), 'capture_device': captured_stat.st_dev,
                   'capture_inode': captured_stat.st_ino, 'capture_offset': 0}
        journal_file = self.root / 'order-journal.json'
        journal_file.write_text(json.dumps(journal))
        journal_file.chmod(0o600)
        context = {'run_id': '12345-1', 'customer_id': 7, 'customer_email': 'qa@petish.invalid',
                   'root': str(public), 'work': str(self.root)}
        body = r'''
define('ABSPATH',__PUBLIC__);define('PETISH_STAGING_GUARD_ACTIVE',true);
function wp_get_environment_type(){return 'staging';}function home_url(){return 'https://staging.petish.biz';}
function get_woocommerce_currency(){return 'AUD';}
function wc_price($amount,$options){return '<span>$14.60</span>';}
function get_user_by(...$args){return (object)['user_email'=>'qa@petish.invalid','roles'=>['customer']];}
class WC_Payments {static function mode(){return new class{function is_dev(){return true;}function is_test(){return true;}};}}
function petish_rehearsal_fixture_config(){return [];}
function petish_rehearsal_fixture_order($order,$config){return true;}
function wc_get_order($id){return new class {
 function get_payment_method(){return 'woocommerce_payments';}function get_meta(...$args){return 'test';}
 function get_currency(){return 'AUD';}function get_billing_email(){return 'qa@petish.invalid';}
 function get_total(){return 14.6;}function get_total_refunded(){return 14.6;}function has_status($status){return $status==='refunded';}
 function get_items($kind){return [new class{function get_total(){return 7.27;}function get_total_tax(){return 0.73;}}];}
};}
function wc_get_product($id){return new class {function get_parent_id(){return 242;}function managing_stock(){return true;}function get_stock_quantity(){return 24;}function get_price(){return 8;}};}
$GLOBALS['refund_calls']=0;function wc_create_refund($args){$GLOBALS['refund_calls']++;throw new RuntimeException();}
require __MODULE__;
$context=json_decode(__CONTEXT__,true);
$context['provider_probe']=static function($order){return ['livemode'=>false,'paid'=>true,'captured'=>true,
 'amount_minor'=>1460,'amount_refunded_minor'=>1460,'refund_succeeded'=>true,'order_reference_verified'=>true,'currency'=>'aud'];};
$result=petish_rehearsal_refund($context);
echo json_encode($result['native_test_refund_verified']&&$result['native_email_contents_verified']&&$result['customer_note_email_captured']&&$GLOBALS['refund_calls']===0);
'''.replace('__PUBLIC__', repr(str(public) + '/')).replace('__MODULE__', repr(str(MODULE))).replace('__CONTEXT__', repr(json.dumps(context)))
        self.assertTrue(self.execute(body))

    def test_stable_operation_lock_serializes_overlapping_retries(self):
        counter = self.root / 'counter'
        counter.write_text('0')
        source = self.root / 'lock.php'
        source.write_text('<?php require ' + repr(str(MODULE)) + ';$context=["work"=>' + repr(str(self.root)) + '];'
                          'petish_order_exclusive($context,static function(){ $p=' + repr(str(counter)) + ';'
                          '$value=(int)file_get_contents($p);usleep(75000);file_put_contents($p,(string)($value+1));});')
        processes = [subprocess.Popen([str(PHP), str(source)], stdout=subprocess.PIPE, stderr=subprocess.PIPE) for _ in range(2)]
        for process in processes:
            process.communicate(timeout=10)
            self.assertEqual(process.returncode, 0)
        self.assertEqual(counter.read_text(), '2')

    def test_saved_order_tagging_and_order_destination_scope(self):
        fixture = self.fixture()
        body = self.stubs(fixture) + r'''
class WC_Order {
 public $date=null;public $country='AU';public $postcode='3083';public $shipping=6;public $coupon=[];public $fee=false;public $saved=0;public $tag='';
 function get_customer_id(){return 7;}function get_date_created(){return $this->date;}
 function get_shipping_country(){return $this->country;}function get_shipping_postcode(){return $this->postcode;}function get_shipping_total(){return $this->shipping;}function get_coupon_codes(){return $this->coupon;}
 function get_items($type){if($type==='fee')return $this->fee?[new stdClass()]:[];return [new class{function get_variation_id(){return 246;}function get_product_id(){return 242;}function get_quantity(){return 1;}function get_method_id(){return 'petish_rehearsal_shipping';}function get_name(){return 'STAGING SIMULATION [12345-1] — no actual carrier';}}];}
 function get_meta(...$args){return $this->tag;}function update_meta_data($key,$value){$this->tag=$value;}function save(){$this->saved++;}
}
$order=new WC_Order();petish_rehearsal_fixture_tag($order);$out=[$order->saved===0];
$order->date=new DateTimeImmutable();petish_rehearsal_fixture_tag($order);$out[]=$order->saved===1&&$order->tag==='12345-1';
$config=petish_rehearsal_fixture_config();$out[]=petish_rehearsal_fixture_order($order,$config);
foreach([['country','US'],['postcode','3000'],['shipping',7],['coupon',['test']],['fee',true]]as[$key,$value]){
 $bad=clone$order;$bad->$key=$value;$out[]=!petish_rehearsal_fixture_order($bad,$config);
}
echo json_encode($out);'''
        self.assertTrue(all(self.execute(body)))
        self.assertIn("add_action('woocommerce_checkout_order_created'", TEMPLATE.read_text())

    def test_duplicate_captures_cannot_substitute_for_missing_native_email_type(self):
        event_file = self.root / 'email-events.jsonl'
        event_file.write_text('\n'.join(json.dumps({'run_id': '12345-1', 'order_id': 9001, 'email_id': email})
                            for email in ['new_order', 'customer_processing_order']) + '\n')
        event_file.chmod(0o600)
        capture = self.root / 'captured.jsonl'
        captures = [self.mail_record('new_order'), self.mail_record('customer_processing_order', 90010)]
        capture.write_text('\n'.join(json.dumps(x) for x in captures) + '\n')
        capture.chmod(0o600)
        metadata = capture.stat()
        context = {'run_id': '12345-1', 'work': str(self.root), 'customer_email': 'qa@petish.invalid'}
        journal = {'capture_file': str(capture), 'capture_device': metadata.st_dev,
                   'capture_inode': metadata.st_ino, 'capture_offset': 0,
                   'product_label': 'small pet carrier', 'admin_recipients': ['admin@petish.invalid']}
        body = 'require ' + repr(str(MODULE)) + ';$context=json_decode(' + repr(json.dumps(context)) + ',true);'
        body += '$journal=json_decode(' + repr(json.dumps(journal)) + ',true);'
        body += "function wc_price($a,$b){return '<span>$14.60</span>';}function wc_get_order($id){return new class{function get_currency(){return 'AUD';}function get_total(){return 14.6;}};}"
        body += 'try{petish_order_emails($context,$journal,9001,["new_order","customer_processing_order"]);echo json_encode(false);}'
        body += 'catch(RuntimeException$e){echo json_encode(true);}'
        self.assertTrue(self.execute(body))

    def test_private_native_email_contents_and_synthetic_note_are_verified(self):
        records = [self.mail_record('new_order'), self.mail_record('customer_processing_order'), self.mail_record('customer_note')]
        context = {'run_id': '12345-1', 'customer_email': 'qa@petish.invalid'}
        journal = {'product_label': 'small pet carrier', 'admin_recipients': ['admin@petish.invalid']}
        body = 'require ' + repr(str(MODULE)) + ';$context=json_decode(' + repr(json.dumps(context)) + ',true);'
        body += '$journal=json_decode(' + repr(json.dumps(journal)) + ',true);$records=json_decode(' + repr(json.dumps(records)) + ',true);'
        body += r'''
function wc_price($a,$b){return '<span>$14.60</span>';}function wc_get_order($id){return new class{function get_currency(){return 'AUD';}function get_total(){return 14.6;}};}
$types=['new_order','customer_processing_order','customer_note'];$out=[];
foreach($types as$i=>$type){petish_order_email_contents($context,$journal,9001,$type,$records[$i]);$out[]=true;}
foreach([['to','wrong@petish.invalid'],['subject',''],['message',''],['message','Order #90010 Small pet carrier $14.60'],['message','Order #9001 Wrong product $14.60'],['message','Order #9001 Small pet carrier $99.60']]as[$key,$value]){
 $record=$records[1];$record[$key]=$value;try{petish_order_email_contents($context,$journal,9001,'customer_processing_order',$record);$out[]=false;}catch(RuntimeException$e){$out[]=true;}
}
$record=$records[2];$record['message']=str_replace('no parcel or supplier order','parcel ordered',$record['message']);
try{petish_order_email_contents($context,$journal,9001,'customer_note',$record);$out[]=false;}catch(RuntimeException$e){$out[]=true;}
echo json_encode($out);'''
        self.assertTrue(all(self.execute(body)))


if __name__ == '__main__':
    unittest.main()
