<?php
/** Customer-view boundaries only: no WordPress writes, network, mail or persisted fixtures. */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['tracking_fixture']=array('enabled'=>'no','logged_in'=>true,'user'=>21,'admin'=>false,'account'=>true,'received'=>false,'endpoint'=>'view-order','query'=>'81','orders'=>array(),'ledger'=>array(),'coverage'=>'partial','hooks'=>array(),'styles'=>array(),'reads'=>0,'lookups'=>0);
function get_option($key,$default=false){return $key==='petish_shipment_customer_tracking_enabled'?$GLOBALS['tracking_fixture']['enabled']:$default;}
function is_admin(){return $GLOBALS['tracking_fixture']['admin'];}
function is_user_logged_in(){return $GLOBALS['tracking_fixture']['logged_in'];}
function get_current_user_id(){return $GLOBALS['tracking_fixture']['user'];}
function is_account_page(){return $GLOBALS['tracking_fixture']['account'];}
function is_order_received_page(){return $GLOBALS['tracking_fixture']['received'];}
function is_wc_endpoint_url($endpoint){return $GLOBALS['tracking_fixture']['endpoint']===$endpoint;}
function get_query_var($key){return $GLOBALS['tracking_fixture']['query'];}
function wc_get_order($id){$GLOBALS['tracking_fixture']['lookups']++;return $GLOBALS['tracking_fixture']['orders'][$id]??null;}
function add_action(...$args){$GLOBALS['tracking_fixture']['hooks'][]=$args;}
function wp_enqueue_style(...$args){$GLOBALS['tracking_fixture']['styles'][]=$args;}
function plugin_dir_url($path){return 'https://petish.example/wp-content/plugins/petish-fulfilment/';}
function wp_parse_url($url){return parse_url($url);}
function __($text,$domain){return $text;}
function esc_html($text){return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8');}
function esc_html__($text,$domain){return esc_html($text);}
function esc_attr($text){return esc_html($text);}
function esc_url($url,$protocols=null){return esc_attr($url);}
function wp_unique_id($prefix){static$id=0;return $prefix.++$id;}
function wp_timezone(){return new DateTimeZone('UTC');}
function wp_date($format,$timestamp,$timezone){return gmdate($format,$timestamp);}
function is_wp_error($value){return $value instanceof WP_Error;}
function update_option(...$args){throw new RuntimeException('Unexpected option write');}
function wp_mail(...$args){throw new RuntimeException('Unexpected mail send');}
function wp_remote_get(...$args){throw new RuntimeException('Unexpected network request');}
class WP_Error{public function __construct(public$code='private_error',public$message='PRIVATE-INTERNAL-DIAGNOSTIC'){} }
class WC_Order_Item_Product{
 public function __construct(private$id,private$name){}
 public function get_id(){return $this->id;}
 public function get_name($context='view'){if($context!=='edit')throw new RuntimeException('Use saved historical name');return $this->name;}
 public function get_product(){throw new RuntimeException('Customer display must not query live product');}
}
class WC_Order{
 public function __construct(private$id=81,private$owner=21,private$type='shop_order',public$items=array()){}
 public function get_id(){return $this->id;}
 public function get_customer_id($context='view'){if($context!=='edit')throw new RuntimeException('Use native owner');return $this->owner;}
 public function get_type(){return $this->type;}
 public function get_items($type){return $this->items;}
 public function save(){throw new RuntimeException('Unexpected order write');}
}
class Petish_Shipment_Ledger{
 public static function read($id){$GLOBALS['tracking_fixture']['reads']++;return $GLOBALS['tracking_fixture']['ledger'];}
 public static function coverage($order,$parcels,$required_items=null){$GLOBALS['tracking_fixture']['coverage_args']=array($order,$parcels,$required_items);return $GLOBALS['tracking_fixture']['coverage'];}
 public static function record(...$args){throw new RuntimeException('Unexpected shipment write');}
}
require __DIR__.'/../wordpress/wp-content/plugins/petish-fulfilment/includes/class-petish-shipment-customer-view.php';
$checks=array();$class=Petish_Shipment_Customer_View::class;
function check($name,$condition){global$checks;$checks[$name]=$condition===true;if(!$condition)throw new RuntimeException($name);}
function output($order=null){ob_start();Petish_Shipment_Customer_View::render($order??new WC_Order());return ob_get_clean();}
function shipment($number='TRACK-1'){return array('shipment'=>array('supplier_id'=>'PRIVATE-SUPPLIER','shipment_id'=>'PRIVATE-SHIPMENT','revision'=>1,'carrier'=>'Carrier & Co','tracking_number'=>$number,'tracking_url'=>'https://carrier.example/track/'.$number.'?lang=en&source=store','dispatched_utc'=>'2026-10-23T00:00:00Z','items'=>array(301=>2,302=>1)),'payload_hash'=>'PRIVATE-HASH','event_id'=>'PRIVATE-EVENT');}
function state(){return array('schema_version'=>1,'required_items'=>array(301=>3,302=>1),'parcels'=>array('PRIVATE-KEY'=>shipment()),'outbox'=>array('PRIVATE-OUTBOX'=>array('outcome_code'=>'PRIVATE-OUTCOME','customer_email'=>'PRIVATE-CUSTOMER-EMAIL','address'=>'PRIVATE-ADDRESS')));}
$fixture=&$GLOBALS['tracking_fixture'];
$native=new WC_Order(81,21,'shop_order',array(new WC_Order_Item_Product(301,'Rabbit <b>hide</b> & cave'),new WC_Order_Item_Product(302,'Wooden bridge')));
$fixture['orders'][81]=$native;$fixture['ledger']=state();
check('include_is_inert',$fixture['hooks']===array()&&$fixture['styles']===array()&&$fixture['reads']===0);
$class::init();check('only_native_render_and_asset_hooks',count($fixture['hooks'])===2&&$fixture['hooks'][0]===array('woocommerce_order_details_after_order_table',array($class,'render'),20)&&$fixture['hooks'][1]===array('wp_enqueue_scripts',array($class,'enqueue_style')));
check('default_off_no_lookup_or_output',!$class::enabled()&&output()===''&&$fixture['lookups']===0&&$fixture['reads']===0);
$class::enqueue_style();check('default_off_no_assets',$fixture['styles']===array());
foreach(array(true,1,'true','YES','on','')as$value){$fixture['enabled']=$value;check('literal_yes_required_'.json_encode($value),!$class::enabled());}
$fixture['enabled']='yes';check('literal_yes_enables',$class::enabled());
$fixture['logged_in']=false;check('guest_denied_before_lookup',output()===''&&$fixture['lookups']===0&&$fixture['reads']===0);
$fixture['logged_in']=true;$fixture['user']=0;check('zero_user_denied',output()==='');
$fixture['user']=22;check('foreign_owner_denied',output()===''&&$fixture['reads']===0);
$class::enqueue_style();check('foreign_owner_no_assets',$fixture['styles']===array());
$fixture['user']=21;$fixture['admin']=true;check('admin_request_denied',output()===''&&$fixture['reads']===0);$fixture['admin']=false;
$fixture['endpoint']='orders';check('unrelated_endpoint_denied',output()==='');$fixture['endpoint']='view-order';
$fixture['account']=false;check('non_account_scope_denied',output()==='');$fixture['account']=true;
foreach(array('081','81.0','8e1','-81',array(81),'81/','81x','0',str_repeat('9',40))as$value){$fixture['query']=$value;check('invalid_endpoint_id_'.json_encode($value),output()==='');}
$fixture['query']='82';check('unknown_native_order_denied',output()==='');$fixture['query']='81';
check('hook_order_must_match_endpoint',output(new WC_Order(82))==='');
check('native_order_object_required',output(81)==='');
$fixture['orders'][81]=new WC_Order(81,22);check('forged_owner_on_passed_object_denied',output(new WC_Order(81,21))==='');
$fixture['orders'][81]=new WC_Order(81,21,'shop_order_refund');check('non_standard_order_denied',output()==='');
$fixture['orders'][81]=$native;
$html=output(new WC_Order(81,999));
check('fresh_native_owner_is_authority',str_contains($html,'Shipment updates')&&$fixture['reads']===1);
check('partial_copy_only_for_partial_coverage',str_contains($html,'Part of your order has been dispatched. Some items are still awaiting a dispatch update.')&&!str_contains($html,'All physical items'));
check('carrier_tracking_date_are_visible',str_contains($html,'Carrier &amp; Co')&&str_contains($html,'TRACK-1')&&str_contains($html,'datetime="2026-10-23T00:00:00Z"'));
check('historical_line_name_escaped',str_contains($html,'Rabbit &lt;b&gt;hide&lt;/b&gt; &amp; cave')&&!str_contains($html,'<b>hide</b>'));
check('allocated_quantities_shown',str_contains($html,'Quantity: 2')&&str_contains($html,'Quantity: 1'));
check('native_https_link_escaped',str_contains($html,'href="https://carrier.example/track/TRACK-1?lang=en&amp;source=store"')&&str_contains($html,'rel="noopener noreferrer"')&&str_contains($html,'opens in a new tab'));
check('all_private_metadata_hidden',!str_contains($html,'PRIVATE-')&&!str_contains($html,'301')&&!str_contains($html,'302'));
check('coverage_receives_native_order_and_pinned_items',$fixture['coverage_args'][0]===$native&&$fixture['coverage_args'][1]===$fixture['ledger']['parcels']&&$fixture['coverage_args'][2]===array(301=>3,302=>1));
$fixture['coverage']='dispatched';$html=output();check('full_dispatch_copy_requires_valid_coverage',str_contains($html,'All physical items have been dispatched.')&&!str_contains($html,'Part of your order'));
check('dispatch_does_not_claim_delivered',!preg_match('/\bdelivered\b/i',$html));
$fixture['coverage']=new WP_Error('order_changed');$html=output();check('changed_coverage_generic_with_valid_parcel',str_contains($html,'We are checking the remaining shipment details.')&&str_contains($html,'TRACK-1')&&!str_contains($html,'All physical items')&&!str_contains($html,'PRIVATE-'));
$fixture['coverage']='partial';$fixture['ledger']['parcels']['PRIVATE-KEY']=shipment('CORRECTED-2');$html=output();check('latest_corrected_snapshot_only',str_contains($html,'CORRECTED-2')&&!str_contains($html,'TRACK-1'));
$fixture['ledger']['parcels']['PRIVATE-SECOND']=shipment('SECOND-3');$html=output();check('split_parcels_have_distinct_cards',substr_count($html,'<article class="petish-shipment-tracking__parcel"')===2&&str_contains($html,'Parcel 2'));
$fixture['orders'][81]=new WC_Order(81,21,'shop_order',array());$fixture['coverage']=new WP_Error('missing_item');$html=output();check('removed_line_uses_generic_name_without_id',str_contains($html,'Order item')&&!str_contains($html,'301')&&!str_contains($html,'All physical items'));
$fixture['orders'][81]=$native;$fixture['ledger']=new WP_Error();check('invalid_state_is_silent',output()==='');
$fixture['ledger']=state();$fixture['ledger']['parcels']=array();check('empty_ledger_is_silent',output()==='');
foreach(array('http://carrier.example/x','javascript:alert(1)','https://user:secret@carrier.example/x','https://127.0.0.1/x','https://carrier.local/x','https://carrier.example:8443/x','https://carrier.example/x#token','https://carrier.example/%250AInjected')as$url){$fixture['ledger']=state();$fixture['ledger']['parcels']['PRIVATE-KEY']['shipment']['tracking_url']=$url;check('unsafe_tracking_suppressed_'.hash('sha256',$url),output()==='');}
$fixture['ledger']=state();$fixture['ledger']['parcels']['PRIVATE-KEY']['shipment']['dispatched_utc']='2026-02-30T00:00:00Z';check('invalid_date_suppressed',output()==='');
$fixture['ledger']=state();$fixture['endpoint']='order-received';$fixture['account']=false;$fixture['received']=true;$html=output();check('signed_in_native_received_scope_supported',str_contains($html,'Shipment updates'));
$class::enqueue_style();check('owned_endpoint_enqueues_scoped_css',count($fixture['styles'])===1&&$fixture['styles'][0][0]===$class::STYLE_HANDLE&&str_ends_with($fixture['styles'][0][1],'/assets/shipment-tracking.css'));
check('no_data_changed_by_reads',$fixture['ledger']===state());
echo json_encode(array('status'=>'success','checks'=>$checks),JSON_PRETTY_PRINT)."\n";
