<?php
/** Actual request service with bounded database/order doubles; no mail or payments. */
define('ABSPATH', __DIR__ . '/fixture/'); define('DB_NAME','cancellation_fixture'); define('DAY_IN_SECONDS',86400);
$GLOBALS['actor']=11; $GLOBALS['manager']=false; $GLOBALS['options']=array(); $GLOBALS['posts']=array(); $GLOBALS['meta']=array();
$GLOBALS['events']=array(); $GLOBALS['orders']=array(); $GLOBALS['next_post']=100; $GLOBALS['registrations']=array();
class WP_Error { public function __construct(private $code,private $message=''){} public function get_error_code(){return $this->code;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function get_current_user_id(){return $GLOBALS['actor'];} function is_user_logged_in(){return $GLOBALS['actor']>0;}
function current_user_can($cap){return $cap==='manage_woocommerce'&&$GLOBALS['manager'];}
function wp_strip_all_tags($s){return strip_tags($s);} function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_SLASHES);}
function wp_slash($value){return is_array($value)?array_map('wp_slash',$value):(is_string($value)?addslashes($value):$value);}
function wp_unslash($value){return is_array($value)?array_map('wp_unslash',$value):(is_string($value)?stripslashes($value):$value);}
function wp_cache_delete(){return true;} function add_action($hook,$callback){$GLOBALS['registrations'][$hook][]=$callback;}
function register_post_type($name,$args){$GLOBALS['registered_type']=array($name,$args);}
function wp_mail(){throw new RuntimeException('No email permitted in core.');}
function do_action($hook,$id){
 global $wpdb;
 if($wpdb->held)throw new RuntimeException('Event hook must run outside order lock.');
 $record=Petish_Order_Cancellation_Requests::get($id);
 if(is_wp_error($record))throw new RuntimeException('Event hook before persistence.');
 $GLOBALS['events'][]=array($hook,$id,$record['revision'],count($record['events']));
}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function get_post_meta($id,$key,$single=false){$v=$GLOBALS['meta'][$id][$key]??array();return $single?($v[0]??''):$v;}
function update_post_meta($id,$key,$value){if($GLOBALS['throw_meta']??false)throw new RuntimeException('Private database failure');if(($GLOBALS['fail_meta']??false)||(($GLOBALS['fail_index']??false)&&$key==='_petish_cancel_state'))return false;$GLOBALS['meta'][$id][$key]=array(wp_unslash($value));return true;}
function wp_insert_post($row,$error=false){
 $id=++$GLOBALS['next_post'];$GLOBALS['posts'][$id]=(object)array('ID'=>$id,'post_type'=>$row['post_type'],'post_status'=>$row['post_status'],'post_author'=>$row['post_author']);
 foreach($row['meta_input']??array() as $key=>$value)$GLOBALS['meta'][$id][$key]=array($value);
 return $id;
}
function wp_delete_post($id,$force=false){unset($GLOBALS['posts'][$id],$GLOBALS['meta'][$id]);return true;}
function fixture_match_meta($id,$conditions){
 $checks=array();
 foreach($conditions as$test){
  if(!is_array($test))continue;
  if(isset($test['relation'])){$checks[]=fixture_match_meta($id,$test);continue;}
  $value=get_post_meta($id,$test['key'],true);$want=$test['value']??null;
  $checks[]=match($test['compare']??'='){'NOT EXISTS'=>!isset($GLOBALS['meta'][$id][$test['key']]),'!='=>$value!=$want,'>='=>$value>=$want,default=>(string)$value===(string)$want};
 }
 return ($conditions['relation']??'AND')==='OR'?in_array(true,$checks,true):!in_array(false,$checks,true);
}
function fixture_ids($args){
 $ids=array();
 foreach($GLOBALS['posts']as$id=>$p){
  if($p->post_type!==($args['post_type']??'')||$p->post_status!==($args['post_status']??''))continue;
  $ok=true;
  if(isset($args['meta_key']))$ok=(string)get_post_meta($id,$args['meta_key'],true)===(string)$args['meta_value'];
  $ok=$ok&&fixture_match_meta($id,$args['meta_query']??array());
  if($ok)$ids[]=$id;
 }
 rsort($ids);return $ids;
}
function get_posts($args){return array_slice(fixture_ids($args),0,$args['numberposts']??5);}
class WP_Query { public $posts,$found_posts,$max_num_pages; function __construct($args){$ids=fixture_ids($args);$this->found_posts=count($ids);$limit=$args['posts_per_page'];$this->max_num_pages=(int)ceil(count($ids)/$limit);$this->posts=array_slice($ids,(($args['paged']??1)-1)*$limit,$limit);} }
class FixtureItem { function __construct(private$id,public$quantity,private$name){}function get_id(){return$this->id;}function get_quantity(){return$this->quantity;}function get_name($context='view'){return$this->name;} }
class WC_Order {
 public $owner=11,$status='processing',$refund=array(31=>-1),$items;
 function __construct(public$id){$this->items=array(31=>new FixtureItem(31,4,'<b>Willow bridge</b>'),32=>new FixtureItem(32,2,'Foraging mat'));}
 function get_id(){return$this->id;}function get_type(){return'shop_order';}function get_customer_id($context='view'){return$this->owner;}
 function read_meta_data($force=false){}function get_status($context='view'){return$this->status;}function get_items($type='line_item'){return$this->items;}
 function get_qty_refunded_for_item($id){return$this->refund[$id]??0;}function get_order_number(){return'P-'.$this->id;}function get_currency($context='view'){return'AUD';}
 function save(){throw new RuntimeException('Core must never save native order.');}function update_status(){throw new RuntimeException('Core must never change native status.');}
}
function wc_get_order($id){return $GLOBALS['orders'][$id]??false;}
class FixtureDatabase {
 public $held=false,$busy=false;
 function prepare($sql,$key){return str_replace('%s',"'".$key."'",$sql);}
 function get_var($sql){if(str_starts_with($sql,'SELECT GET_LOCK')){if($this->busy)return'0';$this->held=true;return'1';}if(str_starts_with($sql,'SELECT RELEASE_LOCK')){$this->held=false;return'1';}throw new RuntimeException('Unexpected SQL');}
}
$wpdb=new FixtureDatabase();
require __DIR__.'/../wordpress/wp-content/plugins/petish-fulfilment/includes/class-petish-order-cancellation-requests.php';
$checks=array();function check($name,$value){global$checks;$checks[$name]=$value===true;if(!$value)throw new RuntimeException('Failed '.$name);}
function code($v,$code){return is_wp_error($v)&&$v->get_error_code()==='petish_cancel_'.$code;}
function latest($id){$r=Petish_Order_Cancellation_Requests::get($id);if(is_wp_error($r))throw new RuntimeException('Request unavailable.');return$r;}
$order=$GLOBALS['orders'][7]=new WC_Order(7);
check('include_registers_nothing',!$GLOBALS['registrations']);
check('default_off_no_records',code(Petish_Order_Cancellation_Requests::create(7,array(31=>1)),'disabled')&&!$GLOBALS['posts']);
Petish_Order_Cancellation_Requests::init();check('init_registers_only_private_type_hook',array_keys($GLOBALS['registrations'])===array('init'));
Petish_Order_Cancellation_Requests::register();$type=$GLOBALS['registered_type'];
check('private_type_nonpublic_nonrest',$type[0]==='petish_cancel_req'&&!$type[1]['public']&&!$type[1]['show_in_rest']&&!$type[1]['query_var']&&!$type[1]['show_ui']);
check('private_type_capability_locked',$type[1]['capabilities']['read_post']==='manage_woocommerce'&&$type[1]['capabilities']['create_posts']==='do_not_allow');
$GLOBALS['options'][Petish_Order_Cancellation_Requests::OPTION]='yes';
$GLOBALS['actor']=0;check('guest_denied',code(Petish_Order_Cancellation_Requests::create(7,array(31=>1)),'permission'));
$GLOBALS['actor']=12;check('other_owner_denied',code(Petish_Order_Cancellation_Requests::create(7,array(31=>1)),'permission'));
$GLOBALS['actor']=11;check('owner_native_order',Petish_Order_Cancellation_Requests::customer_order(7)===$order);
$available=Petish_Order_Cancellation_Requests::available_items($order);check('refunded_quantity_subtracted',$available[31]['available_quantity']===3&&$available[31]['refunded_quantity']===1);
check('native_name_cleaned',$available[31]['name']==='Willow bridge');
check('foreign_item_denied',code(Petish_Order_Cancellation_Requests::create(7,array(900=>1)),'items'));
check('refunded_units_cannot_be_selected',code(Petish_Order_Cancellation_Requests::create(7,array(31=>4)),'items'));
check('empty_selection_denied',code(Petish_Order_Cancellation_Requests::create(7,array()),'items'));
check('zero_quantity_denied',code(Petish_Order_Cancellation_Requests::create(7,array(31=>0)),'items'));
check('fractional_quantity_denied',code(Petish_Order_Cancellation_Requests::create(7,array(31=>1.2)),'items'));
check('string_quantity_not_coerced',code(Petish_Order_Cancellation_Requests::create(7,array(31=>'1')),'items'));
check('oversized_reason_denied',code(Petish_Order_Cancellation_Requests::create(7,array(31=>1),str_repeat('x',1001)),'reason'));
$order->status='refunded';check('native_final_order_new_case_denied',code(Petish_Order_Cancellation_Requests::create(7,array(31=>1)),'status'));
check('native_final_order_owner_read_allowed',Petish_Order_Cancellation_Requests::customer_order(7)===$order);$order->status='processing';
$wpdb->busy=true;check('busy_lock_no_case',code(Petish_Order_Cancellation_Requests::create(7,array(31=>1)),'lock')&&!$GLOBALS['posts']);$wpdb->busy=false;
$result=Petish_Order_Cancellation_Requests::create(7,array(32=>1,31=>2),'<em>Changed my mind</em>');$id=$result['id'];
check('case_created_private',!is_wp_error($result)&&$result['reused']===false&&get_post($id)->post_status==='private');
$record=latest($id);check('blob_source_identity',$record['customer_id']===11&&$record['order_id']===7&&$record['order_number']==='P-7'&&$record['currency']==='AUD');
check('selected_snapshot_is_canonical',array_keys($record['items'])===array(31,32)&&$record['items'][31]['quantity']===2&&$record['reason']==='Changed my mind');
check('reused_flag_not_persisted',!array_key_exists('reused',$record));
check('both_email_slots_pending_before_hook',count($record['events'])===1&&count($GLOBALS['events'])===1&&array_values($record['events'])[0]['deliveries']['customer']['status']==='pending'&&array_values($record['events'])[0]['deliveries']['staff']['status']==='pending');
$before_posts=count($GLOBALS['posts']);$duplicate=Petish_Order_Cancellation_Requests::create(7,array(31=>1),'Different reason');
check('one_active_case_replay_reuses',$duplicate['id']===$id&&$duplicate['reused']===true&&count($GLOBALS['posts'])===$before_posts&&count($GLOBALS['events'])===1);
$public=Petish_Order_Cancellation_Requests::public_snapshot($result);check('create_result_can_be_whitelisted',!is_wp_error($public));
check('public_snapshot_excludes_private_fields',!array_intersect(array_keys($public),array('customer_id','events','history','decision')));
check('customer_history_owned',count(Petish_Order_Cancellation_Requests::customer_requests(7))===1);
$GLOBALS['actor']=12;check('history_cross_owner_denied',code(Petish_Order_Cancellation_Requests::customer_requests(7),'permission'));
$GLOBALS['actor']=11;check('customer_staff_action_denied',code(Petish_Order_Cancellation_Requests::transition($id,'reviewing'),'permission'));
check('customer_queue_denied',code(Petish_Order_Cancellation_Requests::query(),'permission'));
$GLOBALS['actor']=99;$GLOBALS['manager']=true;
check('queue_open_count',Petish_Order_Cancellation_Requests::pending_count()===1&&Petish_Order_Cancellation_Requests::query()['records'][0]['id']===$id);
check('queue_invalid_filter_denied',code(Petish_Order_Cancellation_Requests::query(array('state'=>'publish')),'state'));
check('stale_revision_refused_inside_lock',code(Petish_Order_Cancellation_Requests::transition($id,'reviewing',array('expected_revision'=>999)),'revision')&&latest($id)['revision']===1);
$review=Petish_Order_Cancellation_Requests::transition($id,'reviewing',array('expected_revision'=>1,'private_note'=>'PRIVATE STAFF NOTE','public_message'=>'We are checking the supplier.'));
check('staff_progress_persisted',!is_wp_error($review)&&latest($id)['state']==='reviewing'&&latest($id)['revision']===2);
check('public_progress_message_visible',Petish_Order_Cancellation_Requests::public_snapshot(latest($id))['public_message']==='We are checking the supplier.');
check('public_staff_note_absent',!str_contains(wp_json_encode(Petish_Order_Cancellation_Requests::public_snapshot(latest($id))),'PRIVATE STAFF NOTE'));
$event_count_before=count($GLOBALS['events']);$same=Petish_Order_Cancellation_Requests::transition($id,'reviewing',array('expected_revision'=>2));
check('same_progress_no_email_or_revision',$same['unchanged']===true&&latest($id)['revision']===2&&count($GLOBALS['events'])===$event_count_before);
check('supplier_check_required',code(Petish_Order_Cancellation_Requests::record_decision($id,array(31=>1,32=>0),'Accepted one','Private ref',false,2),'decision'));
check('private_supplier_evidence_required',code(Petish_Order_Cancellation_Requests::record_decision($id,array(31=>1,32=>0),'Accepted one','',true,2),'decision'));
check('decision_quantity_bounded',code(Petish_Order_Cancellation_Requests::record_decision($id,array(31=>3,32=>0),'Accepted one','Private ref',true,2),'items'));
check('decision_all_lines_explicit',code(Petish_Order_Cancellation_Requests::record_decision($id,array(31=>1),'Accepted one','Private ref',true,2),'state'));
$decided=Petish_Order_Cancellation_Requests::record_decision($id,array(31=>1,32=>0),'One item can be cancelled.','PRIVATE SUPPLIER REFERENCE',true,2);
check('partial_decision_derived',$decided['state']==='partially_approved'&&$decided['revision']===3);
$snapshot=Petish_Order_Cancellation_Requests::public_snapshot($decided);
check('decision_public_quantity_and_message',$snapshot['accepted_items']===array(31=>1,32=>0)&&$snapshot['public_message']==='One item can be cancelled.');
check('decision_private_reference_absent',!str_contains(wp_json_encode($snapshot),'PRIVATE SUPPLIER REFERENCE'));
check('approved_case_stays_open_queue',Petish_Order_Cancellation_Requests::pending_count()===1);
check('close_supplier_refund_ack_required',code(Petish_Order_Cancellation_Requests::close($id,array('supplier_checked'=>true)),'confirmation'));
check('stale_decision_refused',code(Petish_Order_Cancellation_Requests::record_decision($id,array(31=>2,32=>1),'All','ref',true,2),'revision'));
$closed=Petish_Order_Cancellation_Requests::close($id,array('supplier_checked'=>true,'refund_handled'=>true,'expected_revision'=>3,'private_note'=>'Handled separately'));
check('closed_after_explicit_review',$closed['state']==='closed'&&$closed['revision']===4&&Petish_Order_Cancellation_Requests::pending_count()===0);
check('native_status_untouched',$order->status==='processing');
check('all_events_public_payload_only',!str_contains(wp_json_encode(array_column($closed['events'],'payload')),'PRIVATE SUPPLIER REFERENCE')&&!str_contains(wp_json_encode(array_column($closed['events'],'payload')),'PRIVATE STAFF NOTE'));
$immutable=Petish_Order_Cancellation_Requests::locked($id,static function($r){$key=array_key_first($r['events']);$r['events'][$key]['payload']['public_message']='Altered';$r['events'][$key]['payload_hash']=hash('sha256',wp_json_encode($r['events'][$key]['payload']));return array('record'=>$r,'result'=>true);});
check('existing_event_snapshot_immutable',code($immutable,'state'));
$mutated=Petish_Order_Cancellation_Requests::locked($id,static function($r){$r['customer_id']=12;return array('record'=>$r,'result'=>true);});check('request_owner_immutable',code($mutated,'state'));
$delivery=Petish_Order_Cancellation_Requests::locked($id,static function($r){$key=array_key_first($r['events']);$r['events'][$key]['deliveries']['staff']['status']='not_sent';return array('record'=>$r,'result'=>true);});
check('delivery_slot_updates_without_request_revision',$delivery===true&&latest($id)['revision']===4);
$nested=Petish_Order_Cancellation_Requests::locked($id,static function($r)use($id){$nested=Petish_Order_Cancellation_Requests::locked($id,static fn($v)=>array('record'=>$v,'result'=>true));return array('record'=>$r,'result'=>$nested);});check('reentrant_lock_denied',code($nested,'lock'));
$GLOBALS['actor']=11;$order->status='refunded';check('closed_history_survives_native_refund',count(Petish_Order_Cancellation_Requests::customer_requests(7))===1);$order->status='processing';
$GLOBALS['fail_meta']=true;$GLOBALS['orders'][8]=new WC_Order(8);$posts_before=count($GLOBALS['posts']);$failed=Petish_Order_Cancellation_Requests::create(8,array(31=>1));$GLOBALS['fail_meta']=false;
check('failed_initial_save_removes_only_owned_unsent_case',code($failed,'save')&&count($GLOBALS['posts'])===$posts_before&&latest($id)['state']==='closed');
$GLOBALS['orders'][9]=new WC_Order(9);$slash_reason='A literal \\ and "quotation"';
$slashed=Petish_Order_Cancellation_Requests::create(9,array(31=>1),$slash_reason);
check('metadata_unslashing_preserves_literal_text',!is_wp_error($slashed)&&latest($slashed['id'])['reason']===$slash_reason);
$GLOBALS['orders'][10]=new WC_Order(10);$GLOBALS['fail_index']=true;$posts_before=count($GLOBALS['posts']);
$failed=Petish_Order_Cancellation_Requests::create(10,array(31=>1));$GLOBALS['fail_index']=false;
check('missing_initial_queue_index_is_not_reported_saved',code($failed,'save')&&count($GLOBALS['posts'])===$posts_before);
$repair=Petish_Order_Cancellation_Requests::create(10,array(31=>1));$repair_id=$repair['id'];
$GLOBALS['actor']=99;$GLOBALS['manager']=true;$GLOBALS['fail_index']=true;
$failed=Petish_Order_Cancellation_Requests::transition($repair_id,'reviewing',array('expected_revision'=>1));$GLOBALS['fail_index']=false;
check('interrupted_index_write_reports_error_with_durable_source',code($failed,'save')&&latest($repair_id)['state']==='reviewing'&&get_post_meta($repair_id,Petish_Order_Cancellation_Requests::STATE_META,true)==='requested');
$event_count=count(latest($repair_id)['events']);
$repaired=Petish_Order_Cancellation_Requests::transition($repair_id,'reviewing',array('expected_revision'=>2));
check('repeat_action_repairs_index_without_new_event',$repaired['unchanged']===true&&get_post_meta($repair_id,Petish_Order_Cancellation_Requests::STATE_META,true)==='reviewing'&&count(latest($repair_id)['events'])===$event_count);
unset($GLOBALS['meta'][$repair_id][Petish_Order_Cancellation_Requests::STATE_META]);
$visible=array_column(Petish_Order_Cancellation_Requests::query()['records'],'id');
check('missing_derived_index_remains_visible_in_open_queue',in_array($repair_id,$visible,true));
$bad_slot=Petish_Order_Cancellation_Requests::locked($repair_id,static function($r){$key=array_key_first($r['events']);$r['events'][$key]['deliveries']['staff']['status']='submitted';return array('record'=>$r,'result'=>true);});
check('submission_requires_durable_claim_fields',code($bad_slot,'state'));
$GLOBALS['meta'][$id][Petish_Order_Cancellation_Requests::ORDER_META][]=999;
check('duplicate_order_index_rejected',code(Petish_Order_Cancellation_Requests::get($id),'state'));
array_pop($GLOBALS['meta'][$id][Petish_Order_Cancellation_Requests::ORDER_META]);
$GLOBALS['meta'][$id][Petish_Order_Cancellation_Requests::META][]=$closed;
check('duplicate_private_blob_rejected',code(Petish_Order_Cancellation_Requests::get($id),'state'));
array_pop($GLOBALS['meta'][$id][Petish_Order_Cancellation_Requests::META]);
$GLOBALS['actor']=11;$GLOBALS['manager']=false;
foreach(array(11,12)as$new_id){$GLOBALS['orders'][$new_id]=new WC_Order($new_id);$new=Petish_Order_Cancellation_Requests::create($new_id,array(31=>1));if(is_wp_error($new))throw new RuntimeException('Daily limit fixture');}
$GLOBALS['orders'][13]=new WC_Order(13);
check('five_new_cases_per_customer_per_day',code(Petish_Order_Cancellation_Requests::create(13,array(31=>1)),'limit'));
$order->owner=12;check('native_owner_change_hides_original_customer_history',code(Petish_Order_Cancellation_Requests::customer_requests(7),'permission'));
$order->owner=11;
$GLOBALS['actor']=13;$GLOBALS['orders'][14]=new WC_Order(14);$GLOBALS['orders'][14]->owner=13;
$GLOBALS['throw_meta']=true;$posts_before=count($GLOBALS['posts']);$thrown=Petish_Order_Cancellation_Requests::create(14,array(31=>1));$GLOBALS['throw_meta']=false;
check('metadata_exception_cleans_only_new_unsent_case',code($thrown,'save')&&count($GLOBALS['posts'])===$posts_before);
check('all_locks_released',!$wpdb->held);
echo json_encode(array('status'=>'success','checks'=>$checks),JSON_PRETTY_PRINT)."\n";
