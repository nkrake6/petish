<?php
/** Behavioral staff/CSV/notification boundary checks; no WordPress writes or network. */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['allowed']=true;$GLOBALS['nonce_ok']=true;$GLOBALS['hooks']=array();$GLOBALS['calls']=array();$GLOBALS['options']=array();$GLOBALS['products']=array();
function current_user_can($cap){return $cap==='manage_woocommerce'&&$GLOBALS['allowed'];}
function wp_verify_nonce($value,$action){return $GLOBALS['nonce_ok']&&$value==='nonce:'.$action;}
function add_action($hook,$callback){$GLOBALS['hooks'][$hook]=$callback;}
function add_submenu_page(...$args){$GLOBALS['menu']=$args;}
function is_wp_error($value){return $value instanceof WP_Error;}
function wp_unslash($value){return $value;}
function wp_strip_all_tags($text){return strip_tags($text);}
function wc_get_formatted_variation(...$args){return 'Blue';}
function wc_get_product($id){return $GLOBALS['products'][$id]??null;}
function wc_get_order($id){return $id===7?new FixtureOrder():null;}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function esc_html($text){return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8');}
function esc_html__($text,$domain){return esc_html($text);}
function esc_attr($text){return esc_html($text);}
function esc_url($text){return esc_html($text);}
function admin_url($path){return 'https://staging.petish.invalid/wp-admin/'.$path;}
function nocache_headers(){$GLOBALS['nocache']=true;}
function wp_nonce_field($action){echo '<input type="hidden" name="_wpnonce" value="'.esc_attr('nonce:'.$action).'">';}
function submit_button($text,...$args){echo '<button>'.esc_html($text).'</button>';}
function wp_die($message,$title='',$args=array()){throw new RuntimeException('denied:'.($args['response']??0));}
class WP_Error{
 public function __construct(private $code,private $message=''){}
 public function get_error_code(){return $this->code;}
 public function get_error_message(){return $this->message;}
}
class WC_Product{
 public function __construct(public $id=11,public $sku='=SKU',public $name='<script>danger</script>Rabbit hide'){}
 public function is_type($type){return is_array($type)?in_array('simple',$type,true):$type==='simple';}
 public function needs_shipping(){return true;}public function get_status(...$args){return 'publish';}
 public function is_visible(){return true;}public function is_purchasable(){return true;}public function is_in_stock(){return true;}
 public function has_enough_stock($quantity){return $quantity<=10;}public function get_sku(...$args){return $this->sku;}
 public function get_id(){return $this->id;}public function get_name(){return $this->name;}
 public function get_meta(...$args){return 'https://supplier.example/item/11';}
}
class FixtureOrder{public function get_edit_order_url(){return admin_url('post.php?post=7&action=edit');}}
class Petish_Supplier_Quotes{public static function fingerprint($product){return str_repeat('a',64);}}
class Petish_Automatic_Shipping{
 public static function validate_book($book,$now=null){
  $rules=array();$errors=array();
  foreach($book['rules']as$i=>$rule){if($rule['evidence_reviewed']!==true||$rule['evidence_reference']==='')$errors[$i]='fixture_evidence';else$rules[$i]=$rule;}
  return array('schema_version'=>2,'rules'=>$rules,'errors'=>$errors,'revision'=>str_repeat('b',64));
 }
 public static function rate_package($package,$book,$now=null){
  $line=$package['contents'][0];if(!$line['data']->has_enough_stock($line['quantity']))return new WP_Error('fixture_stock');
  return array('cost'=>$book['rules'][0]['pricing']['type']==='exact_quantity'?$book['rules'][0]['pricing']['customer_shipping_ex_tax_aud']:'7.5');
 }
}
class Petish_Shipment_Ledger{
 public static function read($order){return $order===7?$GLOBALS['ledger']:new WP_Error('petish_shipment_order','unsafe private detail');}
 public static function coverage($order,$parcels){return 'partial';}
}
class Petish_Shipment_Notifications{
 public static function retry($order,$event){$GLOBALS['calls'][]=array('retry',$order,$event);return array('status'=>'submitted');}
 public static function resolve_uncertain($order,$event,$resolution){$GLOBALS['calls'][]=array('resolve',$order,$event,$resolution);return array('status'=>'resolved');}
 public static function reconcile($order){$GLOBALS['calls'][]=array('reconcile',$order);return array('status'=>'uncertain');}
}
require __DIR__.'/../wordpress/wp-content/plugins/petish-fulfilment/includes/class-petish-delivery-operations-admin.php';
$checks=array();
function check($name,$value){global$checks;$checks[$name]=$value===true;if(!$value)throw new RuntimeException($name);}
function rejected($callback){try{$callback();return false;}catch(InvalidArgumentException$ignored){return true;}}
function row(){return array_fill_keys(Petish_Delivery_Operations_Admin::COLUMNS,'')+array();}
function action($operation,$event){return array('order_id'=>'7','event_id'=>$event,'petish_operation'=>$operation,'_wpnonce'=>'nonce:'.Petish_Delivery_Operations_Admin::NONCE.':'.$operation.':7:'.$event);}
$class=Petish_Delivery_Operations_Admin::class;
check('include_registers_nothing',$GLOBALS['hooks']===array());
$class::init();check('admin_hooks_only',array_keys($GLOBALS['hooks'])===array('admin_menu','admin_post_'.$class::TEMPLATE_ACTION));
$class::menu();check('menu_requires_manager',$GLOBALS['menu'][3]==='manage_woocommerce');
$GLOBALS['allowed']=false;
check('unauthorized_nonce_rejected',!$class::authorized('nonce:'.$class::NONCE));
check('unauthorized_preview_rejected',rejected(fn()=>$class::preview_csv('unsafe')));
check('unauthorized_action_rejected',is_wp_error($class::notification_action(array())));
try{$class::render();check('unauthorized_render_denied',false);}catch(RuntimeException$e){check('unauthorized_render_denied',$e->getMessage()==='denied:403');}
$GLOBALS['allowed']=true;
check('wrong_nonce_rejected',!$class::authorized('wrong'));
$csv=$class::csv(array(array('product_label'=>'=SUM(1,2)','expected_sku'=>"\t+SKU")));
$parsed=$class::parse_csv($csv);
check('csv_formula_text_escaped',$parsed[0]['product_label']==="'=SUM(1,2)"&&str_starts_with($parsed[0]['expected_sku'],"'"));
check('csv_has_exact_headers',array_keys($parsed[0])===$class::COLUMNS);
check('csv_unknown_headings_rejected',rejected(fn()=>$class::parse_csv(str_replace('pricing_type','other_type',$csv))));
check('csv_duplicate_headings_rejected',rejected(fn()=>$class::parse_csv(str_replace('variation_id','product_id',$csv))));
check('csv_missing_headings_rejected',rejected(fn()=>$class::parse_csv("product_id\n11\n")));
check('csv_oversize_rejected',rejected(fn()=>$class::parse_csv(str_repeat('x',$class::MAX_BYTES+1))));
check('csv_invalid_utf8_rejected',rejected(fn()=>$class::parse_csv("\xff".$csv)));
check('csv_null_rejected',rejected(fn()=>$class::parse_csv($csv."\0")));
check('csv_oversized_field_rejected',rejected(fn()=>$class::parse_csv($class::csv(array(array('service'=>str_repeat('x',2001)))))));
check('csv_501_rows_rejected',rejected(fn()=>$class::csv(array_fill(0,501,array()))));
check('csv_500_rows_allowed',count($class::parse_csv($class::csv(array_fill(0,500,array('product_id'=>'11')))))===500);
check('csv_bom_accepted',count($class::parse_csv("\xef\xbb\xbf".$csv))===1);
$GLOBALS['products'][11]=new WC_Product();
$identity=$class::product_identity($GLOBALS['products'][11]);
$row=array_replace(row(),$identity,array('country'=>'AU','states'=>'VIC','postcodes'=>'3083','service'=>'Tracked','evidence_kind'=>'supplier_quote','evidence_reference'=>'private-ref','observed_utc'=>'2026-10-23T00:00:00Z','expires_utc'=>'2026-10-24T00:00:00Z','evidence_reviewed'=>'true','supplier_currency'=>'AUD','pricing_type'=>'exact_quantity','quantity'=>'2','customer_shipping_ex_tax_aud'=>'5','supplier_freight_total'=>'4'));
$preview=$class::preview_csv($class::csv(array($row)));
check('valid_bound_preview',$preview[0]['status']==='valid'&&$preview[0]['cost']==='5'&&$preview[0]['quantity']===2);
check('trusted_current_label_used',$preview[0]['identity']['product_label']==='dangerRabbit hide');
$wrong=$row;$wrong['shipping_fingerprint']=str_repeat('c',64);
check('stale_identity_rejected',$class::preview_csv($class::csv(array($wrong)))[0]['status']==='invalid');
$wrong=$row;$wrong['product_id']='12';
check('foreign_product_identity_rejected',$class::preview_csv($class::csv(array($wrong)))[0]['status']==='invalid');
$wrong=$row;$wrong['evidence_reviewed']='false';
check('unreviewed_evidence_rejected',$class::preview_csv($class::csv(array($wrong)))[0]['status']==='invalid');
$wrong=$row;$wrong['quantity']='11';
check('native_stock_failure_shown',$class::preview_csv($class::csv(array($wrong)))[0]['status']==='invalid');
$wrong=$row;$wrong['country_scope_verified']='yes';
check('ambiguous_confirmation_rejected',$class::preview_csv($class::csv(array($wrong)))[0]['status']==='invalid');
$wrong=$row;$wrong['first_customer_shipping_ex_tax_aud']='2';
check('exact_row_cannot_hide_other_pricing',$class::preview_csv($class::csv(array($wrong)))[0]['status']==='invalid');
$first=$row;$first['pricing_type']='first_additional';$first['quantity']='3';$first['min_quantity']='1';$first['max_quantity']='5';$first['customer_shipping_ex_tax_aud']='';$first['supplier_freight_total']='';$first['first_customer_shipping_ex_tax_aud']='5';$first['additional_customer_shipping_ex_tax_aud']='1.25';$first['supplier_first_freight']='4';$first['supplier_additional_freight']='1';
check('explicit_range_preview',$class::preview_csv($class::csv(array($first)))[0]['cost']==='7.5');
$first['quantity']='6';check('out_of_range_preview_rejected',$class::preview_csv($class::csv(array($first)))[0]['status']==='invalid');
$_SERVER['REQUEST_METHOD']='POST';$_GET=array();$_POST=array('_wpnonce'=>'wrong');$_FILES=array('petish_rate_csv'=>array('tmp_name'=>'/etc/passwd','size'=>100,'error'=>UPLOAD_ERR_OK));
ob_start();$class::render();$output=ob_get_clean();
check('upload_nonce_checked_first',str_contains($output,'Your form expired'));
$_POST['_wpnonce']='nonce:'.$class::NONCE;
ob_start();$class::render();$output=ob_get_clean();
check('non_uploaded_server_path_rejected',str_contains($output,'Choose an uploaded CSV'));
$event=str_repeat('d',64);$GLOBALS['ledger']=array('schema_version'=>1,'parcels'=>array(),'outbox'=>array($event=>array('status'=>'failed','type'=>'partial_dispatch','attempts'=>0)));
$post=action('retry',$event);
check('disabled_retry_never_dispatches',is_wp_error($class::notification_action($post))&&$GLOBALS['calls']===array());
$GLOBALS['options']=array('petish_shipment_ledger_enabled'=>'yes','petish_shipment_notifications_enabled'=>'yes');
$tampered=$post;$tampered['order_id']='8';check('nonce_bound_to_order',is_wp_error($class::notification_action($tampered)));
$tampered=$post;$tampered['event_id']=str_repeat('e',64);check('nonce_bound_to_event',is_wp_error($class::notification_action($tampered)));
$tampered=$post;$tampered['petish_operation']='confirmed_not_sent';check('nonce_bound_to_operation',is_wp_error($class::notification_action($tampered)));
$tampered=$post;$tampered['event_id']='../../secret';check('event_identifier_strict',is_wp_error($class::notification_action($tampered)));
foreach(array('pending','sending','submitted','uncertain','superseded')as$status){$GLOBALS['ledger']['outbox'][$event]['status']=$status;check('retry_rejects_'.$status,is_wp_error($class::notification_action($post)));}
check('forbidden_retries_do_not_dispatch',$GLOBALS['calls']===array());
$GLOBALS['ledger']['outbox'][$event]['status']='failed';check('failed_retry_dispatches',!is_wp_error($class::notification_action($post))&&count($GLOBALS['calls'])===1);
$GLOBALS['ledger']['outbox'][$event]['status']='uncertain';$resolve=action('confirmed_not_sent',$event);
check('uncertain_requires_confirmation',is_wp_error($class::notification_action($resolve))&&count($GLOBALS['calls'])===1);
$resolve['confirm_resolution']='yes';check('confirmed_unsent_resolution_explicit',!is_wp_error($class::notification_action($resolve))&&$GLOBALS['calls'][1][0]==='resolve'&&$GLOBALS['calls'][1][3]==='confirmed_not_sent');
$resolve=action('confirmed_submitted',$event);$resolve['confirm_resolution']='yes';check('confirmed_submitted_resolution_explicit',!is_wp_error($class::notification_action($resolve))&&$GLOBALS['calls'][2][3]==='confirmed_submitted');
$GLOBALS['ledger']['outbox'][$event]['status']='sending';$GLOBALS['ledger']['outbox'][$event]['lease_until']=time()+60;$reconcile=action('reconcile',$event);
check('active_sending_not_reconciled',is_wp_error($class::notification_action($reconcile)));
$GLOBALS['ledger']['outbox'][$event]['lease_until']=time()-1;check('expired_sending_reconcile_does_not_send',!is_wp_error($class::notification_action($reconcile))&&end($GLOBALS['calls'])[0]==='reconcile');
$GLOBALS['ledger']['outbox'][$event]['status']='uncertain';$_GET=array('tab'=>'notifications','order_id'=>'7');$_SERVER['REQUEST_METHOD']='GET';$_POST=array();
$GLOBALS['ledger']['parcels']=array(str_repeat('f',64)=>array('shipment'=>array('supplier_id'=>'supplier-7','shipment_id'=>'parcel-21','carrier'=>'Carrier & Co','tracking_number'=>'TRACK-731','tracking_url'=>'https://carrier.example/track/TRACK-731','dispatched_utc'=>'2026-10-23T00:00:00Z','revision'=>1,'items'=>array(73=>2,74=>1)),'payload_hash'=>str_repeat('a',64),'event_id'=>$event));
ob_start();$class::render();$output=ob_get_clean();
check('uncertain_ui_has_explicit_forms',str_contains($output,'Confirm already submitted')&&str_contains($output,'Confirm not sent')&&str_contains($output,'confirm_resolution'));
check('uncertain_ui_has_no_retry_form',!str_contains($output,'value="retry"'));
check('coverage_uses_current_order',str_contains($output,'Partially dispatched'));
check('native_ledger_shipment_fields_rendered',str_contains($output,'supplier-7 / parcel-21')&&str_contains($output,'Carrier &amp; Co')&&str_contains($output,'TRACK-731')&&str_contains($output,'2026-10-23T00:00:00Z'));
check('native_ledger_shipment_quantities_rendered',str_contains($output,'Item 73: 2<br>Item 74: 1<br>'));
check('no_active_setting_changes',$GLOBALS['options']===array('petish_shipment_ledger_enabled'=>'yes','petish_shipment_notifications_enabled'=>'yes'));
check('private_page_no_cache',!empty($GLOBALS['nocache']));
echo json_encode(array('status'=>'success','checks'=>$checks),JSON_PRETTY_PRINT)."\n";
