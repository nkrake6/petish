<?php
/** Offline boundaries for the actual cancellation staff handlers and response. */
define('ABSPATH', __DIR__ . '/fixture/');
define('PETISH_STAGING_GUARD_ACTIVE', true);
$GLOBALS['manager'] = true; $GLOBALS['gets'] = 0; $GLOBALS['calls'] = array(); $GLOBALS['hooks'] = array();
$GLOBALS['options'] = array(); $GLOBALS['option_writes'] = array();
class WP_Error { public function __construct(private $code, private $message = '') {} public function get_error_code() { return $this->code; } }
function is_wp_error($value) { return $value instanceof WP_Error; }
function current_user_can($cap) { return $cap === 'manage_woocommerce' && $GLOBALS['manager']; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid:' . $action; }
function wp_strip_all_tags($text) { return strip_tags($text); }
function add_action($hook, $callback, ...$args) { $GLOBALS['hooks'][$hook] = $callback; }
function add_submenu_page(...$args) { $GLOBALS['menu'] = $args; }
function admin_url($path) { return 'https://admin.example.test/' . $path; }
function add_query_arg($key, $value = null, $url = null) { if (is_array($key)) return $value . '?' . http_build_query($key); return $url . (str_contains($url, '?') ? '&' : '?') . rawurlencode($key) . '=' . rawurlencode($value); }
function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function esc_html__($value, $domain) { return esc_html($value); }
function __($value, $domain) { return $value; }
function nocache_headers() { $GLOBALS['nocache'] = true; }
function wp_nonce_field($action) { echo '<input name="_wpnonce" value="' . esc_attr('valid:' . $action) . '">'; }
function submit_button($text, $type = '', $name = '', $wrap = true) { echo '<button>' . esc_html($text) . '</button>'; }
function wp_unslash($value) { return $value; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['option_writes'][]=array($key,$value,$autoload); $GLOBALS['options'][$key]=$value; return true; }
function wp_safe_redirect($url, $status) { echo json_encode(array('url'=>$url,'status'=>$status,'flags'=>$GLOBALS['options'],'messages'=>count($GLOBALS['calls']))); }
function wp_die($value, $title = '', $args = array()) { throw new RuntimeException('Denied'); }
function wp_mail() { throw new RuntimeException('No real mail permitted'); }
function wc_get_order($id) { return $id === 7 ? new WC_Order(7) : false; }
class WC_Order { function __construct(private $id) {} function get_id() { return $this->id; } function get_edit_order_url() { return 'https://admin.example.test/order/7'; } }
$event = str_repeat('a', 64);
$GLOBALS['record'] = array('id'=>9,'order_id'=>7,'order_number'=>'P-7<&>','customer_id'=>31,'currency'=>'AUD','revision'=>3,'state'=>'requested',
 'items'=>array(11=>array('name'=>'Willow <b>bridge</b>','quantity'=>2),12=>array('name'=>'Foraging mat','quantity'=>1)),
 'reason'=>'Customer <script>reason</script>', 'decision'=>null,'created_at'=>100,'updated_at'=>200,
 'history'=>array(array('at'=>100,'actor_id'=>31,'action'=>'created','state'=>'requested','private_note'=>'Private <staff> note')),
 'events'=>array($event=>array('kind'=>'received','created_at'=>100,'deliveries'=>array(
  'customer'=>array('status'=>'pending','attempts'=>0),'staff'=>array('status'=>'uncertain','attempts'=>1)))));
class Petish_Order_Cancellation_Requests {
 const OPTION = 'petish_cancellation_requests_enabled';
 static function enabled() { return false; }
 static function pending_count() { return 1; }
 static function get($id) { $GLOBALS['gets']++; return $id === 9 ? $GLOBALS['record'] : new WP_Error('missing'); }
 static function query($filters) { $GLOBALS['filters'] = $filters; return array('records'=>array($GLOBALS['record']),'total'=>1,'page'=>1,'pages'=>1); }
 static function transition($id,$state,$data) { $GLOBALS['calls'][]=array('transition',$id,$state,$data); return array('saved'=>true); }
 static function record_decision(...$args) { $GLOBALS['calls'][]=array_merge(array('decision'),$args); return array('saved'=>true); }
 static function close(...$args) { $GLOBALS['calls'][]=array_merge(array('close'),$args); return array('saved'=>true); }
}
class Petish_Order_Cancellation_Emails {
 const OPTION = 'petish_cancellation_emails_enabled';
 static function enabled() { return false; }
 static function policy_text() { return 'Fees are reviewed and disclosed where permitted. ACL rights remain.'; }
 static function dispatch_pending(...$args) { $GLOBALS['calls'][]=array_merge(array('send_pending'),$args); return array(); }
 static function reconcile(...$args) { $GLOBALS['calls'][]=array_merge(array('reconcile'),$args); return array(); }
 static function queue_retry(...$args) { $GLOBALS['calls'][]=array_merge(array('retry'),$args); return array(); }
 static function resolve_uncertain(...$args) { $GLOBALS['calls'][]=array_merge(array('resolve'),$args); return array(); }
 static function preview($id,$event,$audience) { $GLOBALS['previews'][]=array($id,$event,$audience); return array('subject'=>'Request <preview>','html'=>'<!doctype html><html><head><title>Mail</title></head><body><p>Customer preview &amp; reference</p></body></html>'); }
}
require __DIR__ . '/../wordpress/wp-content/plugins/petish-fulfilment/includes/class-petish-order-cancellation-admin.php';
function post($operation, $extra = array()) {
 $event = $extra['event_id'] ?? ''; $audience = $extra['audience'] ?? '';
 return $extra + array('operation'=>$operation,'case_id'=>'9','revision'=>'3','_wpnonce'=>'valid:' . Petish_Order_Cancellation_Admin::nonce_action($operation,9,3,$event,$audience));
}
if (($argv[1] ?? '') === 'preview') {
 $_SERVER['REQUEST_METHOD']='POST'; $_POST=post('preview',array('event_id'=>$event,'audience'=>'customer'));
 Petish_Order_Cancellation_Admin::preview_email(); exit;
}
if (($argv[1] ?? '') === 'settings_redirect') {
 $_SERVER['REQUEST_METHOD']='POST'; $_POST=array('_wpnonce'=>'valid:' . Petish_Order_Cancellation_Admin::NONCE . ':settings','accept_requests'=>'yes','_wp_http_referer'=>'https://evil.example.test/');
 Petish_Order_Cancellation_Admin::save_settings(); exit;
}
$checks=array();
function check($name,$value) { global $checks; $checks[$name]=$value===true; if (!$value) throw new RuntimeException('Failed: ' . $name); }
function error($value,$suffix) { return is_wp_error($value) && $value->get_error_code()==='petish_cancellation_admin_' . $suffix; }
function capture($callback) { ob_start(); try { $callback(); return ob_get_contents(); } finally { ob_end_clean(); } }
check('include_registers_no_hooks', $GLOBALS['hooks']===array());
Petish_Order_Cancellation_Admin::init();
check('private_hooks', isset($GLOBALS['hooks']['admin_menu'],$GLOBALS['hooks']['admin_post_petish_cancellation_staff_action'],$GLOBALS['hooks']['admin_post_petish_cancellation_email_preview'],$GLOBALS['hooks']['admin_post_petish_cancellation_staff_settings'],$GLOBALS['hooks']['woocommerce_admin_order_data_after_order_details']));
check('no_anonymous_post_handler', !isset($GLOBALS['hooks']['admin_post_nopriv_petish_cancellation_staff_action']));
Petish_Order_Cancellation_Admin::menu();
check('manager_menu', $GLOBALS['menu'][3]==='manage_woocommerce');
$GLOBALS['manager']=false; $gets=$GLOBALS['gets'];
check('customer_action_denied', error(Petish_Order_Cancellation_Admin::action_request(post('reviewing')),'permission'));
check('customer_preview_denied', error(Petish_Order_Cancellation_Admin::preview_request(post('preview',array('event_id'=>$event,'audience'=>'customer'))),'permission'));
try { Petish_Order_Cancellation_Admin::render(); check('customer_page_denied',false); } catch (RuntimeException $e) { check('customer_page_denied',$e->getMessage()==='Denied'); }
check('denial_before_case_read',$GLOBALS['gets']===$gets && $GLOBALS['calls']===array());
$GLOBALS['manager']=true;
$bad=post('reviewing');$bad['_wpnonce']='invalid';$gets=$GLOBALS['gets'];
check('invalid_nonce_no_read',error(Petish_Order_Cancellation_Admin::action_request($bad),'request') && $GLOBALS['gets']===$gets);
$bad=post('reviewing');unset($bad['_wpnonce']);
check('missing_nonce_no_read',error(Petish_Order_Cancellation_Admin::action_request($bad),'request') && $GLOBALS['gets']===$gets);
$bad=post('reviewing');$bad['revision']='2';$bad['_wpnonce']='valid:' . Petish_Order_Cancellation_Admin::nonce_action('reviewing',9,2);
check('stale_revision_no_mutation',error(Petish_Order_Cancellation_Admin::action_request($bad),'stale') && !$GLOBALS['calls']);
$bad=post('reviewing');$bad['case_id']='9e0';
check('scientific_id_denied',error(Petish_Order_Cancellation_Admin::action_request($bad),'request'));
$bad=post('reviewing');$bad['revision']=array('3');
check('array_revision_denied',error(Petish_Order_Cancellation_Admin::action_request($bad),'request'));
$bad=post('refund');
check('financial_operation_absent',error(Petish_Order_Cancellation_Admin::action_request($bad),'request'));
$valid=post('reviewing',array('private_note'=>'<b>private</b>','public_message'=>'<i>Public progress</i>'));
check('review_action',!is_wp_error(Petish_Order_Cancellation_Admin::action_request($valid)));
check('expected_revision_forwarded',$GLOBALS['calls'][0]===array('transition',9,'reviewing',array('private_note'=>'private','public_message'=>'Public progress','expected_revision'=>3)));
$bad=post('awaiting_supplier',array('private_note'=>str_repeat('x',1001)));
check('oversize_note_denied',error(Petish_Order_Cancellation_Admin::action_request($bad),'input'));
check('supplier_progress_action',!is_wp_error(Petish_Order_Cancellation_Admin::action_request(post('awaiting_supplier'))));
$decision=post('record_decision',array('accepted_qty'=>array(11=>'1',12=>'0'),'public_message'=>'Partial cancellation agreed','private_reference'=>'Supplier private reference','supplier_checked'=>'yes'));
check('partial_decision_action',!is_wp_error(Petish_Order_Cancellation_Admin::action_request($decision)));
$call=end($GLOBALS['calls']);
check('decision_quantity_and_revision_binding',$call===array('decision',9,array(11=>1,12=>0),'Partial cancellation agreed','Supplier private reference',true,3));
$bad=$decision;unset($bad['supplier_checked']);check('supplier_confirmation_required',error(Petish_Order_Cancellation_Admin::action_request($bad),'confirmation'));
$bad=$decision;$bad['private_reference']='';check('supplier_reference_required',error(Petish_Order_Cancellation_Admin::action_request($bad),'confirmation'));
$bad=$decision;$bad['accepted_qty'][11]='3';check('excess_quantity_denied',error(Petish_Order_Cancellation_Admin::action_request($bad),'quantity'));
$bad=$decision;$bad['accepted_qty'][11]='-1';check('negative_quantity_denied',error(Petish_Order_Cancellation_Admin::action_request($bad),'quantity'));
$bad=$decision;$bad['accepted_qty'][11]='1.0';check('fractional_quantity_denied',error(Petish_Order_Cancellation_Admin::action_request($bad),'quantity'));
$bad=$decision;$bad['accepted_qty']=array(11=>'1',77=>'0');check('foreign_line_denied',error(Petish_Order_Cancellation_Admin::action_request($bad),'quantity'));
$bad=$decision;$bad['accepted_qty']=array(11=>'1');check('missing_line_denied',error(Petish_Order_Cancellation_Admin::action_request($bad),'confirmation'));
$close=post('close',array('supplier_checked'=>'yes','refund_handled'=>'yes','private_note'=>'Provider independently checked'));
check('explicit_close',!is_wp_error(Petish_Order_Cancellation_Admin::action_request($close)));
check('close_ack_revision_forwarded',end($GLOBALS['calls'])===array('close',9,array('supplier_checked'=>true,'refund_handled'=>true,'private_note'=>'Provider independently checked','expected_revision'=>3)));
$bad=$close;unset($bad['refund_handled']);check('no_implicit_refund_completion',error(Petish_Order_Cancellation_Admin::action_request($bad),'confirmation'));
check('send_pending_handler',!is_wp_error(Petish_Order_Cancellation_Admin::action_request(post('send_pending'))));
check('reconcile_handler',!is_wp_error(Petish_Order_Cancellation_Admin::action_request(post('reconcile'))));
$mail=post('queue_retry',array('event_id'=>$event,'audience'=>'customer'));
check('retry_handler',!is_wp_error(Petish_Order_Cancellation_Admin::action_request($mail)));
check('retry_exact_target',end($GLOBALS['calls'])===array('retry',9,$event,'customer'));
$mail=post('confirmed_not_sent',array('event_id'=>$event,'audience'=>'staff'));
check('uncertain_evidence_required',error(Petish_Order_Cancellation_Admin::action_request($mail),'confirmation'));
$mail['confirm_resolution']='yes';check('uncertain_explicit_resolution',!is_wp_error(Petish_Order_Cancellation_Admin::action_request($mail)));
check('resolution_exact_target',end($GLOBALS['calls'])===array('resolve',9,$event,'staff','confirmed_not_sent',true));
$bad=post('queue_retry',array('event_id'=>str_repeat('b',64),'audience'=>'customer'));check('foreign_event_denied',error(Petish_Order_Cancellation_Admin::action_request($bad),'event'));
$bad=post('queue_retry',array('event_id'=>$event,'audience'=>'external'));check('foreign_recipient_denied',error(Petish_Order_Cancellation_Admin::action_request($bad),'event'));
$preview=post('preview',array('event_id'=>$event,'audience'=>'customer'));
$before=count($GLOBALS['calls']);$result=Petish_Order_Cancellation_Admin::preview_request($preview);
check('staff_preview_readonly',!is_wp_error($result) && count($GLOBALS['calls'])===$before);
check('preview_data_contract',$result['kind']==='received' && $result['created_at']===100 && $result['audience']==='customer' && str_contains($result['html'],'Customer preview'));
$bad=$preview;$bad['audience']='staff';check('preview_nonce_audience_bound',error(Petish_Order_Cancellation_Admin::preview_request($bad),'request'));
$bad=$preview;$bad['event_id']=strtoupper($event);check('uppercase_event_rejected',error(Petish_Order_Cancellation_Admin::preview_request($bad),'request'));
$_GET=array();$before=count($GLOBALS['calls']);$queue=capture(static fn()=>Petish_Order_Cancellation_Admin::render());
check('queue_escaped',str_contains($queue,'P-7&lt;&amp;&gt;') && str_contains($queue,'Willow &lt;b&gt;bridge&lt;/b&gt;') && !str_contains($queue,'<b>bridge</b>'));
check('queue_readonly',count($GLOBALS['calls'])===$before && $GLOBALS['filters']===array('state'=>'open','page'=>1,'per_page'=>20));
check('default_off_settings_visible',str_contains($queue,'Accept customer cancellation requests') && str_contains($queue,'Send customer acknowledgements and staff alerts') && !str_contains($queue,'value="yes" checked'));
check('staging_capture_disclosure',str_contains($queue,'Messages remain subject to the staging mail capture.') && str_contains($queue,'does not send an existing pending message'));
$_GET=array('state'=>array('invalid'),'paged'=>'9999999999','order_id'=>'7');$queue=capture(static fn()=>Petish_Order_Cancellation_Admin::render());
check('query_filters_bounded',$GLOBALS['filters']===array('state'=>'open','page'=>10000,'per_page'=>20,'order_id'=>7));
$_GET=array('case_id'=>'9');$body=capture(static fn()=>Petish_Order_Cancellation_Admin::render());
check('native_gateway_controls_link',str_contains($body,'https://admin.example.test/order/7') && str_contains($body,'original payment gateway'));
check('fulfilment_not_assumed',str_contains($body,'Absence of tracking does not prove') && str_contains($body,'does not place a hold in DSers'));
check('fee_and_delivery_checks',str_contains($body,'actual delivery date') && str_contains($body,'exact proposed refund amount') && str_contains($body,'Statutory remedies'));
check('customer_reason_escaped',str_contains($body,'&lt;script&gt;reason&lt;/script&gt;') && !str_contains($body,'<script>reason</script>'));
check('private_staff_note_escaped',str_contains($body,'Private &lt;staff&gt; note'));
check('uncertain_resolution_checkbox_copy',str_contains($body,'sending process stopped and provider evidence'));
check('preview_forms_post',str_contains($body,'petish_cancellation_email_preview') && str_contains($body,'target="_blank" rel="noopener noreferrer"'));
check('no_order_or_money_automatic_action',!str_contains($body,'action="refund"') && str_contains($body,'nor issues money') && count($GLOBALS['calls'])===$before);
$notice=capture(static fn()=>Petish_Order_Cancellation_Admin::order_notice(new WC_Order(7)));
check('private_order_hold_warning',str_contains($notice,'does not automatically stop DSers') && str_contains($notice,'case_id=9'));
$GLOBALS['manager']=false;check('customer_never_sees_order_warning',capture(static fn()=>Petish_Order_Cancellation_Admin::order_notice(new WC_Order(7)))==='');
$_SERVER['REQUEST_METHOD']='POST';$settings=array('_wpnonce'=>'valid:' . Petish_Order_Cancellation_Admin::NONCE . ':settings','accept_requests'=>'yes','send_emails'=>'yes');
check('settings_customer_denied',error(Petish_Order_Cancellation_Admin::settings_request($settings),'permission') && !$GLOBALS['option_writes']);
$GLOBALS['manager']=true;$_SERVER['REQUEST_METHOD']='GET';
check('settings_get_denied',error(Petish_Order_Cancellation_Admin::settings_request($settings),'settings') && !$GLOBALS['option_writes']);
$_SERVER['REQUEST_METHOD']='POST';$bad=$settings;$bad['_wpnonce']='invalid';
check('settings_invalid_nonce_denied',error(Petish_Order_Cancellation_Admin::settings_request($bad),'settings') && !$GLOBALS['option_writes']);
$bad=$settings;unset($bad['_wpnonce']);
check('settings_missing_nonce_denied',error(Petish_Order_Cancellation_Admin::settings_request($bad),'settings') && !$GLOBALS['option_writes']);
foreach (array('on','no',true,1,array('yes'),null) as $index=>$invalid) {
 $bad=$settings;$bad['send_emails']=$invalid;
 check('settings_strict_flag_' . $index,error(Petish_Order_Cancellation_Admin::settings_request($bad),'settings') && !$GLOBALS['option_writes']);
}
$before=count($GLOBALS['calls']);$settings['petish_shipping_mode']='unsafe';
$saved=Petish_Order_Cancellation_Admin::settings_request($settings);
check('settings_two_allowlisted_flags',!is_wp_error($saved) && $GLOBALS['option_writes']===array(array('petish_cancellation_requests_enabled','yes',false),array('petish_cancellation_emails_enabled','yes',false)));
check('settings_never_dispatch_or_change_case',count($GLOBALS['calls'])===$before && $GLOBALS['record']['revision']===3);
$GLOBALS['option_writes']=array();
$saved=Petish_Order_Cancellation_Admin::settings_request(array('_wpnonce'=>$settings['_wpnonce']));
check('unchecked_flags_disable_only_the_two_options',$saved===array('accept_requests'=>'no','send_emails'=>'no') && $GLOBALS['options']===array('petish_cancellation_requests_enabled'=>'no','petish_cancellation_emails_enabled'=>'no'));
check('settings_no_unrelated_option_writes',array_column($GLOBALS['option_writes'],0)===array('petish_cancellation_requests_enabled','petish_cancellation_emails_enabled'));
echo json_encode(array('status'=>'success','checks'=>$checks),JSON_PRETTY_PRINT) . "\n";
