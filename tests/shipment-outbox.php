<?php
/** Offline behavioural proof for persistence, interruption and parcel boundaries. */
define('ABSPATH',__DIR__.'/fixture/');define('DB_NAME','fixture_shipment');
$GLOBALS['options']=array();$GLOBALS['records']=array();$GLOBALS['manager']=true;$GLOBALS['writes']=0;$GLOBALS['sent']=0;
class WP_Error{function __construct(private $code,private $message=''){}function get_error_code(){return$this->code;}}
function is_wp_error($v){return$v instanceof WP_Error;}
function get_option($k,$default=false){return$GLOBALS['options'][$k]??$default;}
function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_SLASHES);}
function wp_parse_url($v){return parse_url($v);}
function current_user_can($cap){return$cap==='manage_woocommerce'&&$GLOBALS['manager'];}
function esc_html($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}function esc_url($v){return esc_html($v);}function esc_html__($v,$domain){return esc_html($v);}
function wp_mail(){throw new RuntimeException('No real mail permitted.');}
function add_action(){throw new RuntimeException('No hooks permitted on include.');}
function add_filter(){throw new RuntimeException('No hooks permitted on include.');}
class WC_Product{function __construct(private $physical=true){}function needs_shipping(){return$this->physical;}}
class FixtureLine{function __construct(private$id,public$quantity=2,public$physical=true){}function get_id(){return$this->id;}function get_quantity(){return$this->quantity;}function get_product(){return new WC_Product($this->physical);}}
class FixtureMeta{function __construct(private$v){}function get_data(){return$this->v;}}
class WC_Order{
 private$meta=array();public$id;
 function __construct($id){$this->id=$id;$this->read_meta_data(true);}
 function get_id(){return$this->id;}
 function get_order_number(){return$GLOBALS['records'][$this->id]['order_number']??(string)$this->id;}
 function read_meta_data($force=false){$this->meta=$GLOBALS['records'][$this->id]['meta']??array();}
 function get_meta($key,$single=true){return$this->meta[$key]??'';}
 function get_meta_data(){return array_map(static fn($key,$value)=>new FixtureMeta(array('key'=>$key,'value'=>$value)),array_keys($this->meta),array_values($this->meta));}
 function update_meta_data($key,$value){$this->meta[$key]=$value;}
 function save_meta_data(){
  $GLOBALS['writes']++;if($GLOBALS['fail_before']??false)throw new RuntimeException('fixture interrupted before save');
  if(isset($GLOBALS['reentrant'])){$callback=$GLOBALS['reentrant'];unset($GLOBALS['reentrant']);$callback();}
  $GLOBALS['records'][$this->id]['meta']=$this->meta;
  if($GLOBALS['fail_after']??false)throw new RuntimeException('fixture interrupted after save');
 }
 function get_items($type='line_item'){return$GLOBALS['records'][$this->id]['items'];}
}
function wc_get_order($id){return isset($GLOBALS['records'][$id])?new WC_Order($id):false;}
class FixtureDatabase{
 public$held=false;public$busy=false;
 function prepare($sql,$name){return str_replace('%s',"'".$name."'",$sql);}
 function get_var($sql){if(str_starts_with($sql,'SELECT GET_LOCK(')){if($this->busy)return'0';$this->held=true;return'1';}if(str_starts_with($sql,'SELECT RELEASE_LOCK(')){$this->held=false;return'1';}throw new RuntimeException('Unexpected SQL');}
}
$wpdb=new FixtureDatabase();
function petish_email_branding_styles($css){return$css.'/* Petish shared email shell */ body { background-color:#F6F3FC; }';}
function WC(){return new class{function mailer(){return new class{function wrap_message($subject,$body){return'<html><head><title>Petish</title></head><body><header>Petish</header><h1>'.esc_html($subject).'</h1>'.$body.'</body></html>';}};}};}
require __DIR__.'/../wordpress/wp-content/plugins/petish-fulfilment/includes/class-petish-shipment-ledger.php';
require __DIR__.'/../wordpress/wp-content/plugins/petish-fulfilment/includes/class-petish-shipment-notifications.php';
$checks=array();function check($name,$value){global$checks;$checks[$name]=$value===true;if(!$value)throw new RuntimeException('Failed: '.$name);}
function error_code($v,$code){return is_wp_error($v)&&$v->get_error_code()==='petish_shipment_'.$code;}
function fixture($id){$GLOBALS['records'][$id]=array('items'=>array(11=>new FixtureLine(11,2),12=>new FixtureLine(12,1),13=>new FixtureLine(13,1,false)),'meta'=>array());}
$now=strtotime('2026-10-23T00:00:00Z');
function shipment($id='one',$revision=1,$items=array(11=>1)) {return array('supplier_id'=>'supplier-1','shipment_id'=>$id,'revision'=>$revision,'carrier'=>'Example carrier','tracking_number'=>'PARCEL-'.$id,'tracking_url'=>'https://tracking.example.test/parcel/'.$id,'dispatched_utc'=>'2026-10-22T23:00:00Z','items'=>$items);}
fixture(7);$initial=shipment();
check('default_off',error_code(Petish_Shipment_Ledger::record(7,$initial,$now),'disabled')&&$GLOBALS['writes']===0);
$GLOBALS['options'][Petish_Shipment_Ledger::OPTION]='yes';
$first=Petish_Shipment_Ledger::record(7,$initial,$now);check('first_partial',!is_wp_error($first)&&$first['dispatch_state']==='partial');
$state=Petish_Shipment_Ledger::read(7);check('event_persisted_with_parcel',count($state['parcels'])===1&&count($state['outbox'])===1&&$state['outbox'][$first['event_id']]['status']==='pending');
$writes=$GLOBALS['writes'];$copy=Petish_Shipment_Ledger::record(7,array_reverse($initial,true),$now);check('canonical_exact_replay',!is_wp_error($copy)&&!$copy['changed']&&$GLOBALS['writes']===$writes);
$bad=$initial;$bad['tracking_number']='DIFFERENT';check('same_revision_conflict',error_code(Petish_Shipment_Ledger::record(7,$bad,$now),'revision_conflict'));
$bad=$initial;$bad['revision']=2;$bad['tracking_url']='https://127.0.0.1/';check('unsafe_link_rejected',error_code(Petish_Shipment_Ledger::record(7,$bad,$now),'input'));
$bad=shipment('bad',1,array(99=>1));check('foreign_line_rejected',error_code(Petish_Shipment_Ledger::record(7,$bad,$now),'overallocated'));
$bad=shipment('bad',1,array(11=>2));check('overallocated_rejected',error_code(Petish_Shipment_Ledger::record(7,$bad,$now),'overallocated'));
$correction=shipment('one',2);$correction['tracking_number']='CORRECTED';$corrected=Petish_Shipment_Ledger::record(7,$correction,$now+1);
$state=Petish_Shipment_Ledger::read(7);check('correction_supersedes_unsent',!is_wp_error($corrected)&&count($state['parcels'])===1&&$state['outbox'][$first['event_id']]['status']==='superseded'&&$state['outbox'][$corrected['event_id']]['type']==='tracking_correction');
$last=Petish_Shipment_Ledger::record(7,shipment('two',1,array(11=>1,12=>1)),$now+2);check('real_quantities_full',!is_wp_error($last)&&$last['dispatch_state']==='dispatched');
$state=Petish_Shipment_Ledger::read(7);check('other_parcel_preserved',count($state['parcels'])===2&&$state['parcels'][$corrected['parcel_key']]['shipment']['tracking_number']==='CORRECTED');
check('notification_default_off',error_code(Petish_Shipment_Notifications::send(7,$last['event_id'],$now+3),'disabled'));
$GLOBALS['options'][Petish_Shipment_Notifications::OPTION]='yes';
check('no_unconfigured_transport',error_code(Petish_Shipment_Notifications::send(7,$last['event_id'],$now+3),'sender_unconfigured'));
$GLOBALS['outcome']=array('status'=>'submitted');
$transport=static function($order,$event){
 global$wpdb;$GLOBALS['sent']++;check('transport_outside_lock',!$wpdb->held);
 $state=Petish_Shipment_Ledger::read($order->get_id());check('claim_durable_before_transport',$state['outbox'][$event['id']]['status']==='sending'&&$state['outbox'][$event['id']]['sender_id']==='capture-only');
 if(isset($GLOBALS['during_transport'])){$callback=$GLOBALS['during_transport'];unset($GLOBALS['during_transport']);$callback($order,$event);}
 if($GLOBALS['throw_transport']??false)throw new RuntimeException('Private SMTP detail must not persist');
 return$GLOBALS['outcome'];
};
check('sender_configured',Petish_Shipment_Notifications::configure_sender('capture-only',$transport)===true);
check('second_sender_rejected',error_code(Petish_Shipment_Notifications::configure_sender('different',static fn()=>true),'sender_conflict'));
$result=Petish_Shipment_Notifications::send(7,$last['event_id'],$now+3);check('submitted_not_delivered',!is_wp_error($result)&&$result['status']==='submitted'&&$result['attempts']===1);
$sent=$GLOBALS['sent'];check('submitted_not_replayed',error_code(Petish_Shipment_Notifications::send(7,$last['event_id'],$now+4),'status')&&$GLOBALS['sent']===$sent);
check('superseded_not_sent',error_code(Petish_Shipment_Notifications::send(7,$first['event_id'],$now+4),'status'));
$preview=Petish_Shipment_Notifications::render_preview(7,$last['event_id']);check('native_preview_without_mail',!is_wp_error($preview)&&str_contains($preview['html'],'Petish')&&str_contains($preview['html'],'PARCEL-two')&&str_contains($preview['html'],'CORRECTED')&&$GLOBALS['sent']===$sent);
$GLOBALS['outcome']=array('status'=>'not_sent');$failed=Petish_Shipment_Notifications::send(7,$corrected['event_id'],$now+5);check('authoritative_not_sent_failed',!is_wp_error($failed)&&$failed['status']==='failed');
$GLOBALS['manager']=false;$writes=$GLOBALS['writes'];check('customer_retry_denied',error_code(Petish_Shipment_Notifications::retry(7,$corrected['event_id'],$now+6),'permission')&&$GLOBALS['writes']===$writes);
check('customer_preview_denied',error_code(Petish_Shipment_Notifications::render_preview(7,$last['event_id']),'permission'));
$GLOBALS['manager']=true;check('manager_retry_pending',Petish_Shipment_Notifications::retry(7,$corrected['event_id'],$now+6)['status']==='pending');
$GLOBALS['outcome']=false;$uncertain=Petish_Shipment_Notifications::send(7,$corrected['event_id'],$now+7);check('generic_false_uncertain',!is_wp_error($uncertain)&&$uncertain['status']==='uncertain');
$sent=$GLOBALS['sent'];check('uncertain_not_retried',error_code(Petish_Shipment_Notifications::retry(7,$corrected['event_id'],$now+8),'status')&&error_code(Petish_Shipment_Notifications::send(7,$corrected['event_id'],$now+8),'status')&&$GLOBALS['sent']===$sent);
check('explicit_uncertain_resolution',Petish_Shipment_Notifications::resolve_uncertain(7,$corrected['event_id'],'confirmed_not_sent',$now+9)['status']==='failed');
check('resolved_needs_explicit_retry',error_code(Petish_Shipment_Notifications::send(7,$corrected['event_id'],$now+10),'status'));
Petish_Shipment_Notifications::retry(7,$corrected['event_id'],$now+10);
$GLOBALS['during_transport']=static function($order,$event)use($now){
 $before=$GLOBALS['sent'];check('concurrent_send_denied',error_code(Petish_Shipment_Notifications::send($order->get_id(),$event['id'],$now+12),'status')&&$GLOBALS['sent']===$before);
 $state=Petish_Shipment_Notifications::reconcile($order->get_id(),$now+400);check('expired_claim_uncertain',$state['outbox'][$event['id']]['status']==='uncertain');
};
$GLOBALS['throw_transport']=true;$uncertain=Petish_Shipment_Notifications::send(7,$corrected['event_id'],$now+11);unset($GLOBALS['throw_transport']);
check('exception_sanitized',!is_wp_error($uncertain)&&$uncertain['status']==='uncertain'&&!str_contains(wp_json_encode(Petish_Shipment_Ledger::read(7)),'Private SMTP'));
fixture(8);$GLOBALS['fail_before']=true;$failed=Petish_Shipment_Ledger::record(8,shipment(),$now);unset($GLOBALS['fail_before']);check('before_save_failure_no_ledger',error_code($failed,'save')&&count(Petish_Shipment_Ledger::read(8)['outbox'])===0);
$GLOBALS['fail_after']=true;$failed=Petish_Shipment_Ledger::record(8,shipment(),$now);unset($GLOBALS['fail_after']);check('after_save_failure_durable',error_code($failed,'save')&&count(Petish_Shipment_Ledger::read(8)['outbox'])===1);
check('interrupted_save_replay_idempotent',Petish_Shipment_Ledger::record(8,shipment(),$now)['changed']===false);
$event8=array_key_first(Petish_Shipment_Ledger::read(8)['outbox']);$sent=$GLOBALS['sent'];$GLOBALS['fail_before']=true;
$failed=Petish_Shipment_Notifications::send(8,$event8,$now+1);unset($GLOBALS['fail_before']);
check('claim_save_failure_never_sends',error_code($failed,'save')&&$GLOBALS['sent']===$sent&&Petish_Shipment_Ledger::read(8)['outbox'][$event8]['status']==='pending');
$GLOBALS['fail_after']=true;$failed=Petish_Shipment_Notifications::send(8,$event8,$now+2);unset($GLOBALS['fail_after']);
check('interrupted_claim_retained_without_send',error_code($failed,'save')&&$GLOBALS['sent']===$sent&&Petish_Shipment_Ledger::read(8)['outbox'][$event8]['status']==='sending');
$reconciled=Petish_Shipment_Notifications::reconcile(8,$now+400);
check('interrupted_claim_needs_review',$reconciled['outbox'][$event8]['status']==='uncertain'&&$GLOBALS['sent']===$sent);
fixture(9);$GLOBALS['reentrant']=static function()use($now){check('reentrant_write_denied',error_code(Petish_Shipment_Ledger::record(9,shipment('nested'),$now),'lock'));};
check('outer_record_survives_reentry',!is_wp_error(Petish_Shipment_Ledger::record(9,shipment(),$now)));
$wpdb->busy=true;check('busy_lock_fails_closed',error_code(Petish_Shipment_Ledger::record(9,shipment('three'),$now),'lock'));$wpdb->busy=false;
check('locks_released',!$wpdb->held);
fixture(10);$one=Petish_Shipment_Ledger::record(10,shipment(),$now);$two=Petish_Shipment_Ledger::record(10,shipment('two',1,array(11=>1,12=>1)),$now);
$fixed=shipment('one',2);$fixed['tracking_number']='LATEST';$fixed=Petish_Shipment_Ledger::record(10,$fixed,$now+1);$state=Petish_Shipment_Ledger::read(10);
check('other_parcel_full_snapshot_superseded',$state['outbox'][$two['event_id']]['status']==='superseded');
$preview=Petish_Shipment_Notifications::render_preview(10,$fixed['event_id']);
check('full_correction_uses_consistent_snapshot',!is_wp_error($preview)&&str_contains($preview['html'],'LATEST')&&str_contains($preview['html'],'PARCEL-two')&&str_contains($preview['html'],'All physical items'));
fixture(11);$partial=Petish_Shipment_Ledger::record(11,shipment('one',1,array(11=>2)),$now);$state=Petish_Shipment_Ledger::read(11);
check('physical_requirements_pinned',$state['required_items']===array(11=>2,12=>1)&&$partial['dispatch_state']==='partial');
$GLOBALS['records'][11]['items'][12]->physical=false;$writes=$GLOBALS['writes'];$sent=$GLOBALS['sent'];
check('catalogue_virtual_toggle_cannot_imply_dispatch',error_code(Petish_Shipment_Ledger::record(11,shipment('one',2,array(11=>2)),$now+1),'order_changed')&&$GLOBALS['writes']===$writes);
check('changed_physical_requirements_block_send',error_code(Petish_Shipment_Notifications::send(11,$partial['event_id'],$now+1),'order_changed')&&$GLOBALS['writes']===$writes&&$GLOBALS['sent']===$sent);
fixture(12);$GLOBALS['records'][12]['order_number']='REF <img src=x onerror=alert(1)> & "12"';
$escaped=shipment('escaped',1,array(11=>2));$escaped['carrier']='Carrier & "Friends"';$escaped['tracking_url']='https://tracking.example.test/parcel?one=1&two=2';
$escaped=Petish_Shipment_Ledger::record(12,$escaped,$now);$writes=$GLOBALS['writes'];$sent=$GLOBALS['sent'];
$GLOBALS['options'][Petish_Shipment_Ledger::OPTION]='no';$GLOBALS['options'][Petish_Shipment_Notifications::OPTION]='no';
$preview=Petish_Shipment_Notifications::render_preview(12,$escaped['event_id']);
check('preview_flags_off_read_only',!is_wp_error($preview)&&$GLOBALS['writes']===$writes&&$GLOBALS['sent']===$sent);
check('preview_reuses_petish_brand_css',str_contains($preview['html'],'<style type="text/css">/* Petish shared email shell */')&&str_contains($preview['html'],'#F6F3FC'));
check('preview_reference_and_carrier_escaped',!str_contains($preview['html'],'<img src=x')&&str_contains($preview['html'],'REF &lt;img')&&str_contains($preview['html'],'Carrier &amp; &quot;Friends&quot;'));
check('preview_link_and_quantities_escaped',str_contains($preview['html'],'?one=1&amp;two=2')&&str_contains($preview['html'],'2 items at dispatch'));
check('preview_customer_privacy',!str_contains($preview['html'],'supplier-1')&&!str_contains($preview['html'],$escaped['event_id'])&&!str_contains($preview['html'],$escaped['parcel_key'])&&!str_contains($preview['html'],'pending')&&!str_contains($preview['html'],'lease_until'));
$historic=Petish_Shipment_Notifications::render_preview(10,$two['event_id']);$current=Petish_Shipment_Notifications::render_preview(10,$fixed['event_id']);
check('preview_preserves_historical_tracking_snapshot',str_contains($historic['html'],'PARCEL-one')&&!str_contains($historic['html'],'LATEST')&&str_contains($current['html'],'LATEST')&&str_contains($current['html'],'PARCEL-two'));
check('preview_correction_explains_update',str_contains($current['html'],'Updated tracking')&&str_contains($current['html'],'We have updated the tracking details')&&$current['subject']==='Your Petish tracking details have changed');
check('preview_partial_not_full_claim',str_contains($preview['html'],'Part of your order is on its way')&&!str_contains($preview['html'],'all physical items in your order have been dispatched'));
echo json_encode(array('status'=>'success','checks'=>$checks),JSON_PRETTY_PRINT)."\n";
