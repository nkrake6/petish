<?php
/** Offline checks use the real cost-sheet parser/calculator and admin presentation. */
error_reporting(E_ALL);
set_error_handler(static function($severity,$message,$file,$line) { throw new ErrorException($message,0,$severity,$file,$line); });
define('ABSPATH', __DIR__ . '/');
function add_action(...$args) { $GLOBALS['hooks'][]=$args; }
function current_user_can($capability) { return $capability==='manage_woocommerce' && $GLOBALS['can_manage']; }
function wp_verify_nonce($nonce,$action) { return $nonce==='valid-nonce' && $action==='petish_delivery_cost_sheet'; }
function wp_unslash($value) { return $value; }
function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES); }
function esc_attr($value) { return esc_html($value); }
function esc_html__($value,...$args) { return esc_html($value); }
function esc_url($value) { return esc_attr($value); }
function admin_url($value='') { return 'https://petish.invalid/wp-admin/' . $value; }
function nocache_headers() { $GLOBALS['nocache_called']=true; }
function wp_nonce_field(...$args) { echo '<input name="_wpnonce" value="valid-nonce">'; }
function submit_button($text,...$args) { echo '<button>' . esc_html($text) . '</button>'; }
function wp_die(...$args) { throw new RuntimeException('denied'); }
function update_option(...$args) { throw new RuntimeException('unexpected_write'); }
function update_post_meta(...$args) { throw new RuntimeException('unexpected_write'); }
function wp_insert_post(...$args) { throw new RuntimeException('unexpected_write'); }
function wp_remote_request(...$args) { throw new RuntimeException('unexpected_network'); }
function wp_remote_get(...$args) { throw new RuntimeException('unexpected_network'); }
function wp_mail(...$args) { throw new RuntimeException('unexpected_mail'); }
function wc_create_order(...$args) { throw new RuntimeException('unexpected_order'); }
$root=dirname(__DIR__);
require $root . '/wordpress/wp-content/plugins/petish-fulfilment/includes/class-petish-delivery-cost-sheet.php';
require $root . '/wordpress/wp-content/plugins/petish-fulfilment/includes/class-petish-delivery-cost-sheet-admin.php';

$now=strtotime('2026-10-21T00:00:00Z');
$row=array_fill_keys(Petish_Delivery_Cost_Sheet::COLUMNS, '');
$row=array_replace($row,array(
    'product_id'=>'1','variation_id'=>'0','product_label'=>'Synthetic brush — Blue','expected_sku'=>'SKU-1','expected_parent_sku'=>'SKU-1',
    'supplier_product_url'=>'https://supplier.invalid/item/1','shipping_fingerprint'=>str_repeat('a',64),
    'country'=>'AU','state'=>'VIC','postcode'=>'3083','quantity'=>'2','service'=>'Tracked',
    'evidence_kind'=>'estimate','evidence_reference'=>'Synthetic source only',
    'observed_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now-3600),'expires_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now+3600),
    'supplier_currency'=>'USD','supplier_to_aud_rate'=>'1.5','fx_observed_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now-60),
    'supplier_unit_cost'=>'3','supplier_freight_total'=>'4','retail_unit_ex_tax_aud'=>'15',
    'customer_shipping_ex_tax_aud'=>'5','customer_tax_total_aud'=>'3.5','other_cost_aud'=>'1',
    'payment_fee_percent'=>'2','payment_fee_fixed_aud'=>'0.3','freight_buffer_percent'=>'10','target_margin_percent'=>'30',
));
$catalogue=array(1=>array('product_id'=>1,'variation_id'=>0,'product_label'=>$row['product_label'],'expected_sku'=>'SKU-1','expected_parent_sku'=>'SKU-1',
    'supplier_product_url'=>$row['supplier_product_url'],'shipping_fingerprint'=>$row['shipping_fingerprint'],
    'available'=>true,'maximum_quantity'=>10));
$countries=array('AU'=>array('states'=>array('VIC'=>'Victoria'),'state_required'=>true,'postcode_required'=>true),
    'US'=>array('states'=>array('CA'=>'California'),'state_required'=>true,'postcode_required'=>true));
$currencies=array('AUD'=>'Australian dollar','USD'=>'US dollar');
$checks=array();
function check($name,$value) { $GLOBALS['checks'][$name]=(bool)$value; if(!$value) throw new RuntimeException($name); }
function preview($changes=array(),$context=null) {
    return Petish_Delivery_Cost_Sheet::preview(array(array_replace($GLOBALS['row'],$changes)),
        $context??$GLOBALS['catalogue'],$GLOBALS['countries'],$GLOBALS['currencies'],$GLOBALS['now'])[0];
}
function rejects($callback) { try{$callback();return false;}catch(InvalidArgumentException $error){return true;} }

$result=preview();
check('exact_landed_cost_and_contribution', $result['status']==='complete'
    && $result['totals']['supplier_goods_aud']==='9' && $result['totals']['supplier_freight_aud']==='6'
    && $result['totals']['freight_buffer_aud']==='0.6' && $result['totals']['modelled_cost_aud']==='17.67'
    && $result['totals']['contribution_aud']==='17.33' && $result['margin_percent']==='49.51'
    && $result['target_met']===true);
check('fee_on_customer_tax_inclusive_total', $result['totals']['customer_total_aud']==='38.5' && $result['totals']['payment_fees_aud']==='1.07');
$single=preview(array('quantity'=>'1'));
check('fixed_fee_once_per_scenario_not_unit', $single['totals']['payment_fees_aud']==='0.77' && $result['totals']['payment_fees_aud']==='1.07');
check('estimate_remains_estimate', $result['input']['evidence_kind']==='estimate' && !isset($result['approved']));
check('current_label_comes_from_catalogue_not_csv', preview(array('product_label'=>'A misleading old title'))['input']['product_label']==='Synthetic brush — Blue');
$half=preview(array('retail_unit_ex_tax_aud'=>'0.125','customer_shipping_ex_tax_aud'=>'0','customer_tax_total_aud'=>'0','payment_fee_fixed_aud'=>'0'));
check('half_cent_fee_preserved', $half['totals']['payment_fees_aud']==='0.005');
$micro=preview(array('supplier_unit_cost'=>'0.000001','supplier_to_aud_rate'=>'0.5','quantity'=>'1'));
check('fx_half_micro_rounds_up', $micro['totals']['supplier_goods_aud']==='0.000001');
$subtotal=preview(array('supplier_unit_cost'=>'0.01','supplier_to_aud_rate'=>'1.005','quantity'=>'2'));
check('fx_applied_to_quantity_subtotal_once', $subtotal['totals']['supplier_goods_aud']==='0.0201');
$unknown=preview(array('supplier_freight_total'=>''));
check('blank_freight_is_unknown_not_zero', $unknown['status']==='incomplete' && $unknown['totals']['supplier_freight_aud']===null
    && $unknown['totals']['contribution_aud']===null && !$unknown['supplier_zero_freight_entered']);
$zero=preview(array('supplier_freight_total'=>'0'));
check('explicit_supplier_zero_does_not_set_customer_free', $zero['supplier_zero_freight_entered'] && !$zero['customer_free_delivery_entered']
    && $zero['totals']['supplier_freight_aud']==='0' && $zero['totals']['contribution_aud']!==null);
$subsidy=preview(array('customer_shipping_ex_tax_aud'=>'0','promotion_note'=>'Merchant absorbs delivery; not supplier free'));
check('customer_free_is_separate_from_supplier_cost', $subsidy['customer_free_delivery_entered'] && !$subsidy['supplier_zero_freight_entered']
    && $subsidy['totals']['customer_shipping_minus_supplier_freight_aud']==='-6');
$promotion=preview(array('supplier_freight_total'=>'0','promotion_note'=>'First-order coupon only'));
check('promotion_conditions_preserved', $promotion['input']['promotion_note']==='First-order coupon only' && $promotion['input']['evidence_kind']==='estimate');
$fx_unknown=preview(array('supplier_to_aud_rate'=>'','fx_observed_utc'=>''));
check('missing_fx_blocks_contribution', $fx_unknown['status']==='incomplete' && $fx_unknown['totals']['contribution_aud']===null);
$aud=preview(array('supplier_currency'=>'AUD','supplier_to_aud_rate'=>'','fx_observed_utc'=>''));
check('aud_identity_conversion_needs_no_market_rate', $aud['status']==='complete' && $aud['totals']['supplier_goods_aud']==='6');
$negative=preview(array('retail_unit_ex_tax_aud'=>'1','customer_shipping_ex_tax_aud'=>'0'));
check('negative_contribution_and_target_failure', (float)$negative['totals']['contribution_aud']<0 && $negative['target_met']===false);
$zero_revenue=preview(array('retail_unit_ex_tax_aud'=>'0','customer_shipping_ex_tax_aud'=>'0','customer_tax_total_aud'=>'0'));
check('zero_revenue_has_no_division_or_margin', $zero_revenue['status']==='complete' && $zero_revenue['margin_percent']===null && $zero_revenue['target_met']===null);
$no_target=preview(array('target_margin_percent'=>''));
check('target_is_optional', $no_target['status']==='complete' && $no_target['target_met']===null && $no_target['margin_percent']==='49.51');

foreach(array(
    'expired'=>array('expires_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now)),
    'future_supplier_observation'=>array('observed_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now+1)),
    'overlong_validity'=>array('expires_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now+604800)),
    'invalid_calendar_date'=>array('observed_utc'=>'2026-02-30T00:00:00Z'),
    'stale_fx'=>array('fx_observed_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now-604801)),
    'future_fx'=>array('fx_observed_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now+1)),
    'zero_foreign_fx'=>array('supplier_to_aud_rate'=>'0'),
    'incorrect_aud_fx'=>array('supplier_currency'=>'AUD','supplier_to_aud_rate'=>'2'),
    'unsupported_currency'=>array('supplier_currency'=>'ZZZ'),
    'negative_amount'=>array('supplier_freight_total'=>'-1'),
    'amount_with_thousands'=>array('supplier_freight_total'=>'1,000'),
    'scientific_amount'=>array('supplier_freight_total'=>'1e3'),
    'excess_precision'=>array('supplier_freight_total'=>'0.0000001'),
    'fee_above_100_percent'=>array('payment_fee_percent'=>'100.000001'),
    'changed_supplier'=>array('supplier_product_url'=>'https://another.invalid/item/1'),
    'changed_fingerprint'=>array('shipping_fingerprint'=>str_repeat('b',64)),
    'wrong_sku'=>array('expected_sku'=>'SKU-2'),
    'wrong_variation'=>array('variation_id'=>'2'),
    'zero_quantity'=>array('quantity'=>'0'),
    'fractional_quantity'=>array('quantity'=>'1.5'),
    'quantity_over_stock'=>array('quantity'=>'11'),
    'unsupported_country'=>array('country'=>'GB'),
    'missing_state'=>array('state'=>''),
    'wrong_state'=>array('state'=>'NSW'),
    'missing_postcode'=>array('postcode'=>''),
    'wildcard_postcode'=>array('postcode'=>'30*'),
    'missing_service'=>array('service'=>''),
    'missing_evidence'=>array('evidence_reference'=>''),
    'invented_evidence_kind'=>array('evidence_kind'=>'verified'),
    'calculation_overflow'=>array('supplier_unit_cost'=>'99999999','supplier_to_aud_rate'=>'10000'),
) as$name=>$changes) check('reject_'.$name, preview($changes)['status']==='invalid');
$unavailable=$catalogue;$unavailable[1]['available']=false;
check('reject_unavailable_catalogue_product', preview(array(),$unavailable)['status']==='invalid');

$csv=Petish_Delivery_Cost_Sheet::csv(array($row));
check('csv_round_trip_and_bom', Petish_Delivery_Cost_Sheet::parse("\xEF\xBB\xBF".$csv)===array($row));
$formula_row=$row;$formula_row['expected_sku']='=HYPERLINK("https://invalid")';$formula_row['evidence_reference']=" \t+SUM(1,2)";
$formula_csv=Petish_Delivery_Cost_Sheet::csv(array($formula_row));
$formula_parsed=Petish_Delivery_Cost_Sheet::parse($formula_csv);
check('csv_formula_text_is_neutralized', str_starts_with($formula_parsed[0]['expected_sku'],"'=") && str_starts_with($formula_parsed[0]['evidence_reference'],"'"));
$formula_context=$catalogue;$formula_context[1]['expected_sku']=$formula_row['expected_sku'];
check('escaped_csv_identity_still_pins_actual_sku', Petish_Delivery_Cost_Sheet::preview($formula_parsed,$formula_context,$countries,$currencies,$now)[0]['status']==='complete');
check('template_has_no_fabricated_costs', ($template=Petish_Delivery_Cost_Sheet::parse(Petish_Delivery_Cost_Sheet::csv(array($catalogue[1]))))[0]['supplier_unit_cost']===''
    && $template[0]['supplier_freight_total']==='' && $template[0]['country']==='' && $template[0]['evidence_kind']==='');
check('reject_csv_wrong_header', rejects(static fn()=>Petish_Delivery_Cost_Sheet::parse(str_replace('product_id,','wrong_id,',$csv))));
check('reject_duplicate_csv_header', rejects(static fn()=>Petish_Delivery_Cost_Sheet::parse(str_replace('variation_id,','product_id,',$csv))));
check('reject_oversized_csv', rejects(static fn()=>Petish_Delivery_Cost_Sheet::parse(str_repeat('x',1048577))));
check('reject_non_utf8_csv', rejects(static fn()=>Petish_Delivery_Cost_Sheet::parse($csv."\xff")));
check('reject_nul_csv', rejects(static fn()=>Petish_Delivery_Cost_Sheet::parse($csv."\0")));
$too_many=implode("\n",array_merge(array(strtok($csv,"\n")),array_fill(0,501,trim(substr($csv,strpos($csv,"\n")+1)))));
check('reject_over_500_rows', rejects(static fn()=>Petish_Delivery_Cost_Sheet::parse($too_many)));
check('reject_non_list_preview', rejects(static fn()=>Petish_Delivery_Cost_Sheet::preview(array('row'=>$row),$catalogue,$countries,$currencies,$now)));

$GLOBALS['can_manage']=false;
check('admin_capability_required', !Petish_Delivery_Cost_Sheet_Admin::authorized() && !Petish_Delivery_Cost_Sheet_Admin::authorized('valid-nonce'));
try{Petish_Delivery_Cost_Sheet_Admin::render();check('admin_render_denied',false);}catch(RuntimeException$error){check('admin_render_denied',$error->getMessage()==='denied');}
$GLOBALS['can_manage']=true;
check('admin_nonce_required_for_action', !Petish_Delivery_Cost_Sheet_Admin::authorized('') && !Petish_Delivery_Cost_Sheet_Admin::authorized('wrong')
    && Petish_Delivery_Cost_Sheet_Admin::authorized('valid-nonce'));
Petish_Delivery_Cost_Sheet_Admin::init();
check('only_admin_routes_registered', count($GLOBALS['hooks'])===2
    && $GLOBALS['hooks'][0][0]==='admin_menu' && $GLOBALS['hooks'][1][0]==='admin_post_petish_delivery_cost_template');
$_SERVER['REQUEST_METHOD']='GET';
ob_start();Petish_Delivery_Cost_Sheet_Admin::render();$html=ob_get_clean();
check('admin_explains_ephemeral_scope_and_trial', str_contains($html,'Each row is one independent') && str_contains($html,'not net profit')
    && str_contains($html,'no costs are saved') && str_contains($html,'AU/VIC/3083') && str_contains($html,'Live delivery settings are unchanged')
    && $GLOBALS['nocache_called']===true);
$render=new ReflectionMethod(Petish_Delivery_Cost_Sheet_Admin::class,'results');
$xss=$result;$xss['input']['promotion_note']='<script>alert(1)</script>';
ob_start();$render->invoke(null,array($xss,$unknown,$negative));$html=ob_get_clean();
check('results_escape_source_text_and_keep_unknown_distinct', !str_contains($html,'<script>') && str_contains($html,'&lt;script&gt;')
    && str_contains($html,'Estimate only') && str_contains($html,'Preview only') && str_contains($html,'Unknown')
    && str_contains($html,'Negative contribution'));
$_SERVER['REQUEST_METHOD']='POST';$_POST=array('_wpnonce'=>'wrong');$_FILES=array();
ob_start();Petish_Delivery_Cost_Sheet_Admin::render();$html=ob_get_clean();
check('bad_nonce_blocks_upload_before_read', str_contains($html,'Your form expired'));
$_POST=array('_wpnonce'=>'valid-nonce');$_FILES=array('petish_cost_csv'=>array('error'=>UPLOAD_ERR_OK,'tmp_name'=>__FILE__,'size'=>1));
ob_start();Petish_Delivery_Cost_Sheet_Admin::render();$html=ob_get_clean();
check('non_upload_local_path_is_refused', str_contains($html,'Choose a CSV file'));
echo json_encode(array('status'=>'success','checks'=>$checks),JSON_PRETTY_PRINT)."\n";
