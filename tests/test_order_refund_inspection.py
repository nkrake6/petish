"""Private order diagnostics: actual PHP projection plus optional native MariaDB proof."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'scripts/inspect-order-refund.php'
PHP = shutil.which('php') or '/workspace/.petish-dev/bin/php'
DATE = '2026-10-25 01:02:03'

HARNESS = r'''<?php
define('PETISH_ORDER_REFUND_INSPECTION_LIBRARY',true);
require $argv[1];
$fixture=json_decode(file_get_contents($argv[2]),true,32,JSON_THROW_ON_ERROR);
final class SyntheticRefundDB {
 public $calls=[]; public $f;
 function __construct($f){$this->f=$f;}
 function rows($sql,$params=[]){
  if(!str_starts_with($sql,'SELECT ')||str_contains($sql,';'))throw new RuntimeException('write');
  $this->calls[]=$sql;
  if(str_contains($sql,'information_schema.TABLES'))return array_map(fn($name)=>['TABLE_NAME'=>'wp_fixture_'.$name,'ENGINE'=>'InnoDB'],$this->f['tables']);
  if(str_contains($sql,'option_name,option_value'))return array_map(fn($k,$v)=>['option_name'=>$k,'option_value'=>$v],array_keys($this->f['options']),array_values($this->f['options']));
  $id=(string)($params[0]??0);
  if(str_contains($sql,'first_name AS name')||str_contains($sql,"meta_key='_billing_first_name'"))return isset($this->f['names'][$id])?[['name'=>$this->f['names'][$id]]]:[];
  if(str_starts_with($sql,'SELECT id,type FROM'))return isset($this->f['hpos'][$id])?[array_intersect_key($this->f['hpos'][$id],array_flip(['id','type']))]:[];
  if(str_starts_with($sql,'SELECT ID,post_type FROM'))return isset($this->f['posts'][$id])?[array_intersect_key($this->f['posts'][$id],array_flip(['ID','post_type']))]:[];
  if(str_starts_with($sql,'SELECT id,type,status'))return isset($this->f['hpos'][$id])?[$this->f['hpos'][$id]]:[];
  if(str_starts_with($sql,'SELECT ID,post_type,post_status'))return isset($this->f['posts'][$id])?[$this->f['posts'][$id]]:[];
  if(str_starts_with($sql,'SELECT shipping_total_amount'))return isset($this->f['operational'][$id])?[$this->f['operational'][$id]]:[];
  if(str_starts_with($sql,'SELECT meta_key,meta_value')){
   $key=str_contains($sql,'wc_orders_meta')?'hpos_meta':'legacy_meta';
   return $this->f[$key][$id]??[];
  }
  if(str_starts_with($sql,'SELECT id FROM'))return array_values(array_map(fn($v)=>['id'=>$v['id']],array_filter($this->f['hpos'],fn($v)=>(string)$v['parent_order_id']===$id&&$v['type']==='shop_order_refund')));
  if(str_starts_with($sql,'SELECT ID AS id'))return array_values(array_map(fn($v)=>['id'=>$v['ID']],array_filter($this->f['posts'],fn($v)=>(string)$v['post_parent']===$id&&$v['post_type']==='shop_order_refund')));
  if(str_starts_with($sql,'SELECT i.order_item_id'))return $this->f['allocations'][$id]??[];
  if(str_starts_with($sql,'SELECT comment_content'))return array_map(fn($v)=>['comment_content'=>$v],array_slice($this->f['notes'],0,101));
  throw new RuntimeException('unknown_query');
 }
}
$db=new SyntheticRefundDB($fixture);
try{
 $selector=$fixture['selector']??['schema'=>1,'order_id'=>7001,'expected_billing_first_name'=>' Demo Person '];
 $out=(new Petish_Order_Refund_Collector($db,'wp_fixture_'))->collect($selector);
 petish_order_refund_validate_report($out);
 echo json_encode(['report'=>$out,'calls'=>$db->calls],JSON_THROW_ON_ERROR);
}catch(Throwable $e){echo json_encode(['error'=>$e instanceof Petish_Order_Refund_Error?$e->getMessage():'test_exception','calls'=>$db->calls],JSON_THROW_ON_ERROR);}
'''


def metadata(values):
    return [{'meta_key': k, 'meta_value': v} for k, v in values.items()]


def allocation(item_id, kind, total, tax='0'):
    names = ('cost', 'total_tax') if kind == 'shipping' else ('_line_total', '_line_tax')
    return [{'order_item_id': str(item_id), 'order_item_type': kind, 'meta_key': key, 'meta_value': value}
            for key, value in zip(names, (total, tax))]


def fixture(hpos=True):
    parent_meta = {'_wcpay_transaction_fee': '3.48', '_wcpay_net': '116.52', '_charge_id': 'ch_PRIVATE',
                   '_intent_id': 'pi_PRIVATE', '_wcpay_payment_transaction_id': 'txn_PRIVATE', '_intention_status': 'succeeded',
                   '_wcpay_intent_currency': 'aud', '_wcpay_refund_status': 'successful', '_wcpay_mode': 'prod',
                   '_wcpay_payment_method_details': json.dumps({'type': 'card', 'billing_details': {'email': 'PRIVATE@example.invalid'}, 'card': {'last4': '1234'}})}
    refund_meta = {'_refund_amount': '100', '_refunded_payment': '1', '_wcpay_refund_id': 're_PRIVATE', '_wcpay_refund_transaction_id': 'txn_REFUND_PRIVATE'}
    f = {'tables': ['options', 'posts', 'postmeta', 'comments', 'woocommerce_order_items', 'woocommerce_order_itemmeta'],
         'options': {'home': 'https://petish.biz', 'siteurl': 'https://petish.biz/', 'woocommerce_custom_orders_table_enabled': 'yes' if hpos else 'no', 'woocommerce_currency': 'AUD'},
         'names': {'7001': 'dEmO pErSoN'}, 'hpos': {}, 'posts': {}, 'operational': {}, 'hpos_meta': {}, 'legacy_meta': {},
         'allocations': {'7001': allocation(1, 'line_item', '100') + allocation(2, 'shipping', '20'), '3000': allocation(3, 'line_item', '-100')},
         'notes': ['A payment of <b>120.00</b> was <strong>successfully charged</strong> using WooPayments (pi_PRIVATE).',
                   'A refund of 100.00 was successfully processed using WooPayments (re_PRIVATE). Reason: PRIVATE NAME.']}
    if hpos:
        f['tables'] += ['wc_orders', 'wc_orders_meta', 'wc_order_operational_data', 'wc_order_addresses']
        for order_id, kind, amount, parent in ((7001, 'shop_order', '120', 0), (3000, 'shop_order_refund', '-100', 7001)):
            f['hpos'][str(order_id)] = {'id': str(order_id), 'type': kind, 'status': 'wc-cancelled' if parent == 0 else 'wc-completed',
                                      'currency': 'AUD', 'total_amount': amount, 'tax_amount': '0', 'payment_method': 'woocommerce_payments',
                                      'date_created_gmt': DATE, 'date_updated_gmt': DATE, 'parent_order_id': str(parent)}
            f['operational'][str(order_id)] = {'shipping_total_amount': '20' if parent == 0 else '0', 'shipping_tax_amount': '0', 'discount_total_amount': '0'}
        f['hpos_meta'] = {'7001': metadata(parent_meta), '3000': metadata(refund_meta)}
    else:
        for order_id, kind, parent in ((7001, 'shop_order', 0), (3000, 'shop_order_refund', 7001)):
            f['posts'][str(order_id)] = {'ID': str(order_id), 'post_type': kind, 'post_status': 'wc-cancelled' if parent == 0 else 'wc-completed',
                                       'post_parent': str(parent), 'post_date_gmt': DATE, 'post_modified_gmt': DATE}
        f['legacy_meta'] = {'7001': metadata(dict(parent_meta, _order_total='120', _order_currency='AUD', _order_shipping='20', _order_shipping_tax='0', _payment_method='woocommerce_payments')),
                            '3000': metadata(dict(refund_meta, _order_total='-100', _order_currency='AUD'))}
    return f


class OrderRefundInspectionTests(unittest.TestCase):
    def collect(self, data):
        with tempfile.TemporaryDirectory(prefix='petish-refund-unit-') as raw:
            work = Path(raw)
            (work / 'fixture.json').write_text(json.dumps(data))
            (work / 'fixture.php').write_text(HARNESS)
            result = subprocess.run([PHP, '-d', 'allow_url_fopen=0', str(work / 'fixture.php'), str(SOURCE), str(work / 'fixture.json')],
                                    text=True, capture_output=True, timeout=15, env={'PATH': os.defpath})
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.stderr, '')
            return json.loads(result.stdout)

    def test_shipping_shortfall_is_computed_from_actual_allocations_in_both_stores(self):
        for hpos in (True, False):
            with self.subTest(hpos=hpos):
                result = self.collect(fixture(hpos))
                report = result['report']
                self.assertEqual(report['remaining_recorded_amount'], '20.00')
                self.assertEqual(report['order_shipping'], '20.00')
                self.assertEqual(report['refunded_shipping_total'], '0.00')
                self.assertTrue(report['remaining_matches_unrefunded_shipping'])
                self.assertTrue(report['refund_allocation_complete'])
                self.assertEqual(report['transaction_fee_cached'], '3.48')
                self.assertFalse(report['settlement_verified'])

    def test_full_refund_and_retained_merchant_fee_are_separate(self):
        f = fixture()
        f['hpos']['3000']['total_amount'] = '-120'
        f['hpos_meta']['3000'][0]['meta_value'] = '120'
        f['allocations']['3000'] += allocation(4, 'shipping', '-20')
        report = self.collect(f)['report']
        self.assertEqual(report['remaining_recorded_amount'], '0.00')
        self.assertEqual(report['transaction_fee_cached'], '3.48')
        self.assertEqual(report['merchant_net_cached'], '116.52')
        self.assertEqual(report['refunded_shipping_total'], '20.00')

    def test_unallocated_manual_refund_does_not_prove_shipping_omission(self):
        f = fixture(); f['allocations']['3000'] = []; f['hpos_meta']['3000'][1]['meta_value'] = '0'
        report = self.collect(f)['report']
        self.assertEqual(report['unallocated_refund_amount'], '100.00')
        self.assertFalse(report['refund_allocation_complete'])
        self.assertIsNone(report['remaining_matches_unrefunded_shipping'])
        self.assertFalse(report['refunds'][0]['refunded_payment_flag'])

    def test_shipping_tax_is_included_in_exact_comparison(self):
        f = fixture(); f['operational']['7001'].update(shipping_total_amount='18.18', shipping_tax_amount='1.82')
        f['allocations']['7001'] = allocation(1, 'line_item', '100') + allocation(2, 'shipping', '18.18', '1.82')
        report = self.collect(f)['report']
        self.assertTrue(report['remaining_matches_unrefunded_shipping'])
        self.assertEqual(report['order_shipping_tax'], '1.82')

    def test_name_guard_precedes_financial_queries_and_never_returns_name(self):
        f = fixture(); f['names']['7001'] = 'Different customer'
        result = self.collect(f)
        self.assertEqual(result['report'], {'format': 'petish-order-refund-inspection-v1', 'status': 'identity_mismatch', 'order_id': 7001})
        self.assertFalse(any('total_amount' in call or 'SELECT meta_key' in call or 'comment_content' in call for call in result['calls']))

    def test_origin_guard_precedes_selected_order_and_financial_queries(self):
        for key in ('home', 'siteurl'):
            for wrong in ('https://staging.petish.biz', 'https://petish.biz.example.invalid', 'https://foreign.invalid/petish.biz'):
                f = fixture(); f['options'][key] = wrong
                result = self.collect(f)
                self.assertEqual(result['error'], 'origin_mismatch')
                self.assertEqual(len(result['calls']), 2)

    def test_missing_order_returns_no_financial_metadata(self):
        f = fixture(); f['hpos'] = {}
        self.assertEqual(self.collect(f)['report'], {'format': 'petish-order-refund-inspection-v1', 'status': 'not_found', 'order_id': 7001})

    def test_private_identity_notes_ids_and_card_data_cannot_escape(self):
        result = self.collect(fixture())
        text = json.dumps(result['report'])
        for private in ('Demo Person', 'dEmO pErSoN', 'PRIVATE', '1234', 'example.invalid', 'pi_PRIVATE', 'ch_PRIVATE', 're_PRIVATE'):
            self.assertNotIn(private, text)
        self.assertEqual(result['report']['payment_method_type'], 'card')
        self.assertTrue(result['report']['has_charge_id'])
        self.assertTrue(result['report']['refunds'][0]['provider_refund_id_present'])

    def test_stored_notes_are_classified_without_revealing_text(self):
        f = fixture()
        f['notes'] += ['Refund of 120 failed due to insufficient funds in your WooPayments balance PRIVATE.',
                       'A refund of 120 is pending using WooPayments.',
                       'Payment authorization was successfully cancelled (pi_PRIVATE).',
                       'WooPayments refunded its 3.48 application fee on this transaction.']
        report = self.collect(f)['report']
        for key in ('note_payment_succeeded_count', 'note_refund_succeeded_count', 'note_refund_pending_count', 'note_refund_failed_count',
                    'note_insufficient_balance_count', 'note_auth_cancelled_count', 'note_application_fee_refunded_count'):
            self.assertEqual(report[key], 1, key)

    def test_customer_reason_is_not_misclassified_as_provider_refund_failure(self):
        f = fixture()
        f['notes'] = ['A refund of100 was successfully processed using WooPayments. Reason: Cancelled item failed and customer had insufficient funds.']
        report = self.collect(f)['report']
        self.assertEqual(report['note_refund_succeeded_count'], 1)
        self.assertEqual(report['note_refund_failed_count'], 0)
        self.assertEqual(report['note_insufficient_balance_count'], 0)

    def test_hpos_flag_prevents_silent_fallback_and_metadata_drift(self):
        f = fixture(); f['tables'].remove('wc_order_operational_data')
        self.assertEqual(self.collect(f)['error'], 'storage_layout')
        f = fixture(); f['hpos_meta']['7001'] += metadata({'_order_total': '119'})
        self.assertEqual(self.collect(f)['error'], 'storage_drift')

    def test_duplicate_critical_metadata_and_refund_currency_drift_fail_closed(self):
        f = fixture(); f['hpos_meta']['7001'] += [f['hpos_meta']['7001'][0]]
        self.assertEqual(self.collect(f)['error'], 'metadata')
        f = fixture(); f['hpos']['3000']['currency'] = 'USD'
        self.assertEqual(self.collect(f)['error'], 'storage_drift')

    def test_real_dual_store_drift_is_rejected_not_used_as_fallback(self):
        f = fixture(); legacy = fixture(False)
        f['options']['woocommerce_custom_orders_table_data_sync_enabled'] = 'yes'
        f['posts'] = legacy['posts']; f['legacy_meta'] = legacy['legacy_meta']
        self.assertTrue(self.collect(f)['report']['dual_store_checked'])
        f['legacy_meta']['7001'][len(f['legacy_meta']['7001']) - 5]['meta_value'] = '119'
        self.assertIn('error', self.collect(f))

    def test_inactive_unsynchronized_shadow_is_not_treated_as_authority(self):
        f = fixture(); legacy = fixture(False)
        f['posts'] = legacy['posts']; f['legacy_meta'] = legacy['legacy_meta']
        f['legacy_meta']['7001'] = metadata({'_order_total': '1', '_order_currency': 'USD'})
        report = self.collect(f)['report']
        self.assertEqual(report['order_total'], '120.00')
        self.assertFalse(report['dual_store_checked'])

    def test_cross_currency_hints_do_not_create_a_fee_deduction_conclusion(self):
        f = fixture(); f['hpos_meta']['7001'] += metadata({'_wcpay_multi_currency_order_default_currency': 'USD'})
        report = self.collect(f)['report']
        self.assertEqual(report['multi_currency_default_currency'], 'USD')
        self.assertFalse(report['original_capture_amount_verified'])
        self.assertFalse(report['settlement_verified'])
        self.assertNotIn('refundable_after_fee', report)

    def test_selector_types_are_strict(self):
        for order_id in (True, 0, -1, '7001', [7001]):
            f = fixture(); f['selector'] = {'schema': 1, 'order_id': order_id}
            self.assertEqual(self.collect(f)['error'], 'input')

    def test_cli_rejects_unsafe_files_and_duplicate_keys_without_stdout_details(self):
        with tempfile.TemporaryDirectory(prefix='petish-refund-cli-') as raw:
            work = Path(raw); work.chmod(0o700)
            request = work / 'request.json'; output = work / 'report.json'
            request.write_text('{"schema":1,"order_id":7001,"order_id":2}'); request.chmod(0o600)
            result = subprocess.run([PHP, str(SOURCE), str(request), str(output)], capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0); self.assertEqual(result.stdout, ''); self.assertEqual(result.stderr, 'input\n'); self.assertFalse(output.exists())
            request.chmod(0o644)
            result = subprocess.run([PHP, str(SOURCE), str(request), str(output)], capture_output=True, text=True)
            self.assertEqual(result.stdout, ''); self.assertEqual(result.stderr, 'paths\n')


@unittest.skipUnless(os.environ.get('PETISH_REFUND_NATIVE_MYSQL') == 'yes', 'Requires explicitly enabled disconnected native Docker proof.')
class NativeOrderRefundInspectionTests(unittest.TestCase):
    def test_actual_woocommerce_legacy_and_hpos_rows_and_readonly_transaction(self):
        spec = importlib.util.spec_from_file_location('refund_native', ROOT / 'scripts/test-fulfilment-native.py')
        native = importlib.util.module_from_spec(spec); spec.loader.exec_module(native)
        work, state = native.prepare(Path('/tmp/petish-fyp-repro/WordPress-7.1.3'))
        proof = r'''<?php
require '/srv/petish-native/wp-load.php';
if(!defined('PETISH_FULFILMENT_NATIVE_OWNER')||wp_get_environment_type()!=='local')exit(2);
$o=wc_create_order();$o->set_billing_first_name('Synthetic');$o->set_currency('AUD');$o->set_payment_method('woocommerce_payments');
$i=new WC_Order_Item_Product();$i->set_name('Synthetic item');$i->set_quantity(1);$i->set_subtotal('100');$i->set_total('100');$o->add_item($i);
$s=new WC_Order_Item_Shipping();$s->set_method_title('Synthetic delivery');$s->set_total('20');$o->add_item($s);$o->calculate_totals(false);
$o->update_meta_data('_wcpay_transaction_fee','3.48');$o->update_meta_data('_wcpay_net','116.52');$o->save();
$r=wc_create_refund(['order_id'=>$o->get_id(),'amount'=>'100','refund_payment'=>false,'restock_items'=>false,'line_items'=>[$i->get_id()=>['qty'=>1,'refund_total'=>'100','refund_tax'=>[]]]]);
if(is_wp_error($r))exit(3);
foreach(['HOST'=>DB_HOST,'USER'=>DB_USER,'PASSWORD'=>DB_PASSWORD,'NAME'=>DB_NAME,'PREFIX'=>$wpdb->prefix,'CLIENT_FLAGS'=>'0']as$k=>$v)putenv('PETISH_DB_'.$k.'='.$v);
$wpdb->update($wpdb->options,['option_value'=>'https://petish.biz'],['option_name'=>'home']);
$wpdb->update($wpdb->options,['option_value'=>'https://petish.biz'],['option_name'=>'siteurl']);
define('PETISH_ORDER_REFUND_INSPECTION_LIBRARY',true);require '/checks/inspect-order-refund.php';
$report=petish_order_refund_collect(['schema'=>1,'order_id'=>(int)$o->get_id(),'expected_billing_first_name'=>' synthetic ']);
if($report['remaining_recorded_amount']!=='20.00'||!$report['remaining_matches_unrefunded_shipping']||$report['order_shipping']!=='20.00')exit(4);
$db=new Petish_Order_Refund_DB();$db->begin();$p=new ReflectionProperty($db,'connection');$p->setAccessible(true);$c=$p->getValue($db);$denied=false;
try{$c->query("INSERT INTO wp_options(option_name,option_value,autoload) VALUES('petish_forbidden_probe','1','no')");}catch(mysqli_sql_exception $e){$denied=true;}
$db->close();if(!$denied||(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_options WHERE option_name='petish_forbidden_probe'")!==0)exit(5);
$r->delete(true);$o->delete(true);
echo json_encode(['status'=>'success','storage'=>$report['storage'],'read_only_transaction_enforced'=>true]);
'''
        try:
            native.private_write(work / 'inspect-order-refund.php', SOURCE.read_bytes())
            native.private_write(work / 'refund-native.php', proof)
            for mode, expected in (('no', 'legacy'), ('yes', 'hpos')):
                native.wp(state, work, ['option', 'update', 'woocommerce_custom_orders_table_enabled', mode])
                output = native.run(['exec', state['application'], 'php', '-d', 'sendmail_path=/bin/false', '/checks/refund-native.php'], work, 'refund_native', timeout=120)
                self.assertEqual(json.loads(output), {'status': 'success', 'storage': expected, 'read_only_transaction_enforced': True})
        finally:
            native.cleanup(work, state)


if __name__ == '__main__':
    unittest.main()
