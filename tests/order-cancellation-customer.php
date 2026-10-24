<?php
/** Exercise the customer boundary without mail, SQL, refunds or supplier requests. */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['cancel_customer'] = array('enabled'=>false,'logged_in'=>true,'user'=>21,'admin'=>false,'account'=>true,
    'endpoint'=>'view-order','query'=>'81','orders'=>array(),'requests'=>array(),'hooks'=>array(),'filters'=>array(),
    'styles'=>array(),'create_calls'=>array(),'owner_reads'=>0,'notices'=>array(),'method'=>'POST');
class WP_Error {
    public function __construct(private $code, private $message='PRIVATE-ERROR') {}
    public function get_error_code() { return $this->code; }
}
class CustomerRedirect extends RuntimeException {}
class CustomerDie extends RuntimeException {}
function is_wp_error($value) { return $value instanceof WP_Error; }
function is_admin() { return $GLOBALS['cancel_customer']['admin']; }
function is_user_logged_in() { return $GLOBALS['cancel_customer']['logged_in']; }
function get_current_user_id() { return $GLOBALS['cancel_customer']['user']; }
function is_account_page() { return $GLOBALS['cancel_customer']['account']; }
function is_wc_endpoint_url($endpoint) { return $GLOBALS['cancel_customer']['endpoint']===$endpoint; }
function get_query_var($key) { return $GLOBALS['cancel_customer']['query']; }
function add_action(...$args) { $GLOBALS['cancel_customer']['hooks'][]=$args; }
function add_filter(...$args) { $GLOBALS['cancel_customer']['filters'][]=$args; }
function wp_enqueue_style(...$args) { $GLOBALS['cancel_customer']['styles'][]=$args; }
function plugin_dir_url($file) { return 'https://petish.example/wp-content/plugins/petish-fulfilment/'; }
function __($text,$domain) { return $text; }
function esc_html($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function esc_html__($text,$domain) { return esc_html($text); }
function esc_attr($value) { return esc_html($value); }
function esc_attr__($text,$domain) { return esc_attr($text); }
function esc_url($value) { return esc_attr($value); }
function admin_url($path) { return 'https://petish.example/wp-admin/'.$path; }
function home_url($path) { return 'https://petish.example'.$path; }
function wc_get_account_endpoint_url($endpoint) { return 'https://petish.example/my-account/'.$endpoint.'/'; }
function wp_verify_nonce($nonce,$action) { return $nonce==='nonce:'.$action; }
function wp_nonce_field($action,$name='_wpnonce',$referer=true,$display=true) {
    $html='<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr('nonce:'.$action).'">';
    if ($display) echo $html;
    return $html;
}
function get_option($key,$default=false) { return $default; }
function wp_date($format,$time,$zone) { return gmdate($format,$time); }
function wp_timezone() { return new DateTimeZone('UTC'); }
function nocache_headers() { $GLOBALS['cancel_customer']['nocache']=true; }
function wp_unslash($value) { return $value; }
function wc_add_notice($message,$type) {
    if (!WC()->session) throw new RuntimeException('Admin-post must initialize the native notice session');
    $GLOBALS['cancel_customer']['notices'][]=array($type,$message);
}
function wp_safe_redirect($url,$status=302) { $GLOBALS['cancel_customer']['redirect']=array($url,$status); throw new CustomerRedirect(); }
function wp_die($message,$title='',$args=array()) { $GLOBALS['cancel_customer']['die']=$args; throw new CustomerDie(); }
function wp_mail(...$args) { throw new RuntimeException('No mail from the customer boundary'); }
function wp_remote_get(...$args) { throw new RuntimeException('No supplier call from the customer boundary'); }
function update_option(...$args) { throw new RuntimeException('No settings write from the customer boundary'); }

class WC_Order {
    public function __construct(private $id=81,private $owner=21,private $status='processing',private $type='shop_order') {}
    public function get_id() { return $this->id; }
    public function get_customer_id($context='view') { return $this->owner; }
    public function get_status() { return $this->status; }
    public function get_type() { return $this->type; }
    public function get_order_number() { return 'P-'.$this->id; }
    public function get_view_order_url() { return 'https://petish.example/my-account/view-order/'.$this->id.'/'; }
    public function update_status(...$args) { throw new RuntimeException('No native order status mutation'); }
    public function save() { throw new RuntimeException('No native order save'); }
}
class CustomerWoo {
    public $session = null;
    public $session_initializations = 0;
    public function initialize_session() { $this->session = new stdClass(); $this->session_initializations++; }
    public function initialize_cart() { throw new RuntimeException('Do not initialize the cart'); }
}
function WC() { static $woo; return $woo ??= new CustomerWoo(); }

class Petish_Cancellation_Policy {
    public static function request_text() { return 'CANONICAL REVIEW COPY: cancellation is subject to fulfilment and supplier review.'; }
    public static function fee_text() { return 'CANONICAL FEE COPY: staff will disclose any permitted voluntary fee; Australian Consumer Law rights remain.'; }
}

class Petish_Order_Cancellation_Requests {
    public static function enabled() { return $GLOBALS['cancel_customer']['enabled']; }
    public static function customer_order($id) {
        $GLOBALS['cancel_customer']['owner_reads']++;
        $order=$GLOBALS['cancel_customer']['orders'][$id]??null;
        return $order instanceof WC_Order && $order->get_customer_id()===get_current_user_id()
            ? $order : new WP_Error('petish_cancel_permission');
    }
    public static function can_request($order) { return in_array($order->get_status(),array('pending','on-hold','processing','completed','failed'),true); }
    public static function available_items($order) {
        return array(301=>array('name'=>'Rabbit <b>hide</b> & cave','quantity'=>3,'refunded_quantity'=>1,'available_quantity'=>2),
            302=>array('name'=>'Wooden bridge','quantity'=>1,'refunded_quantity'=>0,'available_quantity'=>1));
    }
    public static function customer_requests($id) { return $GLOBALS['cancel_customer']['requests']; }
    public static function public_snapshot($record) {
        return array_intersect_key($record,array_flip(array('id','order_id','order_number','currency','items','reason','state','created_at','updated_at','revision','accepted_items','public_message')));
    }
    public static function create($id,$items,$reason='') {
        $GLOBALS['cancel_customer']['create_calls'][]=array($id,$items,$reason);
        return $GLOBALS['cancel_customer']['create_result']??array('id'=>901,'order_id'=>$id,'state'=>'requested','reused'=>false);
    }
}

require __DIR__.'/../wordpress/wp-content/plugins/petish-fulfilment/includes/class-petish-order-cancellation-customer.php';
$checks=array();
function check($name,$condition) { global $checks; $checks[$name]=$condition===true; if (!$condition) throw new RuntimeException($name); }
function output($order=null) { ob_start(); Petish_Order_Cancellation_Customer::render($order??new WC_Order()); return ob_get_clean(); }
function request_post() {
    return array('order_id'=>'81','_wpnonce'=>'nonce:'.Petish_Order_Cancellation_Customer::nonce_action(81),
        'selected'=>array('301','302'),'quantities'=>array(301=>'2',302=>'1'),'reason'=>'Changed my mind','confirm_request'=>'yes');
}
function is_error($value,$suffix) { return is_wp_error($value) && str_ends_with($value->get_error_code(),$suffix); }
function public_case($state='requested') {
    return array('id'=>901,'order_id'=>81,'order_number'=>'P-81','currency'=>'AUD',
        'items'=>array(301=>array('name'=>'Rabbit <b>hide</b> & cave','quantity'=>2),302=>array('name'=>'Wooden bridge','quantity'=>1)),
        'reason'=>'Please cancel <script>private</script>', 'state'=>$state,'created_at'=>1792790400,'updated_at'=>1792790400,'revision'=>1,
        'accepted_items'=>array(301=>1,302=>0),'public_message'=>'We can approve <b>one</b> item.',
        'private_reference'=>'PRIVATE-SUPPLIER-EVIDENCE','events'=>array('PRIVATE-DELIVERY-OUTCOME'),
        'customer_id'=>21,'private_note'=>'PRIVATE-STAFF-NOTE');
}
$f=&$GLOBALS['cancel_customer'];
$f['orders'][81]=new WC_Order();
$class=Petish_Order_Cancellation_Customer::class;
$actions=array('view'=>array('url'=>'native-view','name'=>'View'),'cancel'=>array('url'=>'native-cancel','name'=>'Cancel'));
check('include_inert',$f['hooks']===array() && $f['filters']===array());
$class::init();
check('native_hooks_only',count($f['hooks'])===3 && count($f['filters'])===1
    && $f['hooks'][0]===array('woocommerce_order_details_after_order_table',array($class,'render'),30)
    && $f['hooks'][2]===array('admin_post_'.$class::POST_ACTION,array($class,'submit'))
    && $f['filters'][0]===array('woocommerce_my_account_my_orders_actions',array($class,'order_actions'),10,2));
check('default_off_render_silent',output()==='' && $f['owner_reads']===0);
check('default_off_actions_preserved',$class::order_actions($actions,new WC_Order())===$actions);
$class::enqueue_style(); check('default_off_no_style',$f['styles']===array());
check('default_off_submit_denied',is_error($class::handle_request(request_post()),'_disabled') && $f['create_calls']===array());
$f['enabled']=true;
$f['logged_in']=false;
check('guest_silent',output()==='' && $f['owner_reads']===0);
check('guest_cannot_submit',is_error($class::handle_request(request_post()),'_permission') && $f['create_calls']===array());
$f['logged_in']=true;$f['user']=22;
check('other_customer_silent',output()==='');
check('other_customer_cannot_submit',is_error($class::handle_request(request_post()),'_permission'));
check('other_customer_native_actions_preserved',$class::order_actions($actions,new WC_Order())===$actions);
$f['user']=21;$f['admin']=true;check('admin_render_silent',output()==='');$f['admin']=false;
$f['account']=false;check('non_account_silent',output()==='');$f['account']=true;
$f['endpoint']='orders';check('unrelated_endpoint_silent',output()==='');$f['endpoint']='view-order';
foreach (array('081','81.0','8e1','-81','81/','81x','0',array(81),str_repeat('9',40)) as $value) {
    $f['query']=$value;check('bad_endpoint_'.hash('sha256',json_encode($value)),output()==='');
}
$f['query']='81';
check('wrong_hook_order_silent',output(new WC_Order(82))==='');
check('non_native_hook_order_silent',output(81)==='');
$f['orders'][81]=new WC_Order(81,22);
check('forged_passed_owner_cannot_override_native',output(new WC_Order(81,21))==='');
$f['orders'][81]=new WC_Order();
$html=output();
check('owner_form_visible',str_contains($html,'Send cancellation request') && str_contains($html,'id="cancel-request"'));
check('request_not_automatic_cancel',str_contains($html,'I understand this is a request and cancellation is subject to review.'));
check('canonical_policy_used',str_contains($html,'CANONICAL REVIEW COPY') && str_contains($html,'CANONICAL FEE COPY'));
check('fee_not_flat_or_blanket',!str_contains($html,'$10') && !str_contains($html,'non-refundable'));
check('faq_link_canonical',str_contains($html,'https://petish.example/delivery-shipping/#change-of-mind'));
check('form_post_own_action_and_nonce',str_contains($html,'method="post"') && str_contains($html,'https://petish.example/wp-admin/admin-post.php')
    && str_contains($html,$class::POST_ACTION) && str_contains($html,'nonce:'.$class::nonce_action(81)));
check('saved_names_escaped',str_contains($html,'Rabbit &lt;b&gt;hide&lt;/b&gt; &amp; cave') && !str_contains($html,'Rabbit <b>'));
check('remaining_quantity_bounds',str_contains($html,'name="quantities[301]" min="1" max="2"') && str_contains($html,'Available to request: 2'));
check('items_not_preselected',!str_contains($html,'checked'));
check('optional_reason_bounded',str_contains($html,'maxlength="1000"') && str_contains($html,'Reason (optional)'));
check('no_financial_action_in_customer_form',!str_contains($html,'Refund manually') && !str_contains($html,'Refund via'));
$class::enqueue_style();
check('scoped_style_correct_url',count($f['styles'])===1 && $f['styles'][0][0]===$class::STYLE_HANDLE
    && $f['styles'][0][1]==='https://petish.example/wp-content/plugins/petish-fulfilment/assets/order-cancellation.css');
$changed=$class::order_actions($actions,new WC_Order());
check('owned_order_gets_request_action',!isset($changed['cancel']) && $changed['view']===$actions['view']
    && $changed['petish-cancellation']['name']==='Request cancellation'
    && $changed['petish-cancellation']['url']==='https://petish.example/my-account/view-order/81/#cancel-request');
$f['requests']=array(public_case());
$html=output();
check('existing_request_shown',str_contains($html,'Request received') && str_contains($html,'An open request already exists'));
check('open_request_form_not_repeated',!str_contains($html,'Send cancellation request'));
check('request_reason_and_public_message_escaped',str_contains($html,'&lt;script&gt;private&lt;/script&gt;') && str_contains($html,'&lt;b&gt;one&lt;/b&gt;') && !str_contains($html,'<script>'));
check('private_case_data_never_rendered',!str_contains($html,'PRIVATE-') && !str_contains($html,'901') && !str_contains($html,'customer_id'));
$changed=$class::order_actions($actions,new WC_Order());
check('existing_request_action_label',$changed['petish-cancellation']['name']==='View cancellation request');
$f['requests']=array(public_case('partially_approved'));$html=output();
check('partial_decision_visible',str_contains($html,'Part of your request approved') && str_contains($html,'Approved quantities') && str_contains($html,'Quantity: 1'));
check('approval_does_not_claim_refund_sent',str_contains($html,'Any approved refund is handled separately') && !str_contains($html,'has been refunded'));
$f['requests']=array(public_case('closed'));$html=output();
check('closed_case_retained_and_new_request_available',str_contains($html,'Case closed') && str_contains($html,'Send cancellation request'));
$f['orders'][81]=new WC_Order(81,21,'refunded');$html=output();
check('native_final_order_keeps_case_history',str_contains($html,'Case closed') && !str_contains($html,'Send cancellation request'));
$f['requests']=array();check('native_final_without_cases_silent',output()==='');
check('native_final_without_cases_keeps_native_actions',$class::order_actions($actions,new WC_Order())===$actions);
$f['orders'][81]=new WC_Order();
$post=request_post();$result=$class::handle_request($post);
check('selected_line_quantities_delegate',!is_wp_error($result) && end($f['create_calls'])===array(81,array(301=>2,302=>1),'Changed my mind'));
$count=count($f['create_calls']);
$post=request_post();$post['_wpnonce']='invalid';check('bad_nonce_denied',is_error($class::handle_request($post),'_nonce'));
$post=request_post();unset($post['_wpnonce']);check('missing_nonce_denied',is_error($class::handle_request($post),'_nonce'));
$post=request_post();$post['order_id']='82';check('cross_order_nonce_denied',is_error($class::handle_request($post),'_nonce'));
$post=request_post();$post['confirm_request']='no';check('confirmation_required',is_error($class::handle_request($post),'_confirmation'));
foreach (array(null,array(),array('301','301'),array('0301'),array('301x'),array('foreign'=>301),array_fill(0,101,'301')) as $selected) {
    $post=request_post();$post['selected']=$selected;check('bad_selection_'.hash('sha256',json_encode($selected)),is_error($class::handle_request($post),'_items'));
}
foreach (array('0','1.5','1e2','-1','01',array(1),'10001',str_repeat('9',30)) as $quantity) {
    $post=request_post();$post['quantities'][301]=$quantity;check('bad_quantity_'.hash('sha256',json_encode($quantity)),is_error($class::handle_request($post),'_items'));
}
foreach (array(array('reason'),str_repeat('a',1001),"bad\0reason","\xFF") as $reason) {
    $post=request_post();$post['reason']=$reason;check('bad_reason_'.hash('sha256',serialize($reason)),is_error($class::handle_request($post),'_reason'));
}
check('rejected_requests_never_reach_core',count($f['create_calls'])===$count);
$post=request_post();$post['selected']=array('301');$post['quantities']=array(301=>'999');
$f['create_result']=new WP_Error('petish_cancel_items');
check('native_core_bounds_authoritative',is_error($class::handle_request($post),'_items'));
unset($f['create_result']);
$_SERVER['REQUEST_METHOD']='POST';$_POST=request_post();$_POST['redirect']='https://attacker.example/';
try { $class::submit(); } catch (CustomerRedirect $expected) {}
check('submit_uses_303_prg_to_native_order',$f['redirect']===array('https://petish.example/my-account/view-order/81/#cancel-request',303));
check('submit_sets_nocache_and_safe_ack',$f['nocache']===true && end($f['notices'])===array('success','Your cancellation request has been recorded. You can follow its progress on this page.'));
check('admin_post_initializes_only_native_notice_session',WC()->session instanceof stdClass && WC()->session_initializations===1);
$f['create_result']=array('id'=>901,'order_id'=>81,'state'=>'requested','reused'=>true);
$_POST=request_post();$_POST['selected']=array('302');
try { $class::submit(); } catch (CustomerRedirect $expected) {}
check('reused_case_notice_never_claims_new_selection_saved',end($f['notices'])===array('notice','An open cancellation request already exists for this order. Its details are unchanged. Contact orders@petish.biz with your order number if you need to change it.'));
unset($f['create_result']);
$_POST=request_post();$_POST['_wpnonce']='invalid';
try { $class::submit(); } catch (CustomerRedirect $expected) {}
check('bad_post_errors_are_generic',end($f['notices'])[0]==='error' && !str_contains(end($f['notices'])[1],'PRIVATE-'));
check('existing_notice_session_preserved',WC()->session_initializations===1);
$f['user']=22;$_POST=request_post();
try { $class::submit(); } catch (CustomerRedirect $expected) {}
check('foreign_request_redirect_does_not_reveal_order',$f['redirect']===array('https://petish.example/my-account/orders/',303));
$f['user']=21;$_SERVER['REQUEST_METHOD']='GET';$count=count($f['create_calls']);
try { $class::submit(); } catch (CustomerDie $expected) {}
check('get_submit_rejected_without_core_write',$f['die']===array('response'=>405) && count($f['create_calls'])===$count);
echo json_encode(array('status'=>'success','checks'=>$checks),JSON_PRETTY_PRINT)."\n";
