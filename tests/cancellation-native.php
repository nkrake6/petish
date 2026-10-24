<?php
/** Real WordPress/WooCommerce cancellation proof; disposable disconnected data only. */
if (PHP_SAPI !== 'cli') exit(2);
umask(0077);
ob_start();
require '/srv/petish-native/wp-load.php';
require_once ABSPATH.'wp-admin/includes/template.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/my-account/';

$mode = $argv[1] ?? '';
$owner = bin2hex(random_bytes(16));
$checks = array(); $failure = null;
$users = array(); $orders = array(); $products = array(); $pages = array(); $options = array();
$captured = array(); $mail_mode = 'accept'; $mail_claims = array(); $account_page = null;

function cancel_check($name, $condition) {
    global $checks;
    $checks[$name] = $condition === true;
    if (!$checks[$name]) throw new RuntimeException($name);
}
function cancel_ok($value, $name) { cancel_check($name, !is_wp_error($value)); return $value; }
function cancel_capture($callback) { ob_start(); try { $callback(); return ob_get_contents(); } finally { ob_end_clean(); } }
function cancel_option($key, $value) {
    global $options;
    if (!array_key_exists($key, $options)) $options[$key] = get_option($key, null);
    update_option($key, $value);
}
function cancel_user($suffix) {
    global $owner, $users;
    $id = wp_insert_user(array('user_login'=>'cancel-'.$owner.'-'.$suffix,'user_pass'=>bin2hex(random_bytes(24)),
        'user_email'=>$suffix.'-'.$owner.'@example.invalid','role'=>'customer'));
    cancel_ok($id,'owned_customer_created'); $users[] = (int)$id;
    update_user_meta($id,'_petish_native_owner',$owner); return (int)$id;
}
function cancel_product($suffix) {
    global $owner, $products;
    $p = new WC_Product_Simple(); $p->set_name($suffix === 'A' ? 'Willow <b>bridge</b> & hide' : 'Natural foraging mat');
    $p->set_status('publish'); $p->set_regular_price('25'); $p->set_price('25');
    $p->set_sku('CANCEL-'.$owner.'-'.$suffix); $p->set_manage_stock(true); $p->set_stock_quantity(50);
    $p->update_meta_data('_petish_native_owner',$owner); $p->save(); $products[] = (int)$p->get_id(); return $p;
}
function cancel_order($customer, $a, $b = null) {
    global $owner, $orders;
    $o = cancel_ok(wc_create_order(array('customer_id'=>$customer,'created_via'=>'petish-cancellation-native')),'owned_order_created');
    $orders[] = (int)$o->get_id(); $o->update_meta_data('_petish_native_owner',$owner);
    $o->set_billing_email('cancel-customer-'.$owner.'@example.invalid'); $o->set_currency('AUD');
    $o->set_payment_method('native-capture-only'); $o->add_product($a,3); if ($b) $o->add_product($b,2);
    $o->calculate_totals(false); $o->set_status('processing'); $o->save(); return $o;
}
function cancel_fresh($id) { return new WC_Order($id); }
function cancel_order_snapshot($id) {
    $o = cancel_fresh($id); $lines = array();
    foreach ($o->get_items('line_item') as $item) $lines[$item->get_id()] = array($item->get_name('edit'),$item->get_quantity(),
        $item->get_total(),$item->get_total_tax(),$o->get_qty_refunded_for_item($item->get_id()));
    return array('status'=>$o->get_status(),'total'=>$o->get_total(),'tax'=>$o->get_total_tax(),'refunded'=>$o->get_total_refunded(),
        'currency'=>$o->get_currency(),'method'=>$o->get_payment_method(),'lines'=>$lines);
}
function cancel_stock_snapshot() { global $products; $r=array(); foreach($products as$id)$r[$id]=wc_get_product($id)->get_stock_quantity('edit'); return $r; }
function cancel_page() {
    global $owner, $pages;
    $id = wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'Owned cancellation account',
        'post_content'=>'[woocommerce_my_account]','meta_input'=>array('_petish_native_owner'=>$owner)),true);
    cancel_ok($id,'owned_account_page_created'); $pages[] = (int)$id;
    cancel_option('woocommerce_myaccount_page_id',(int)$id); return (int)$id;
}
function cancel_customer_view($order, $order_id = null, $style = false) {
    global $wp, $wp_query, $post, $account_page;
    $old_query=$wp_query; $old_post=$post; $old_vars=$wp->query_vars;
    $id = $order_id ?? (string)$order->get_id();
    $wp_query = new WP_Query(); $wp_query->is_page=true; $wp_query->queried_object=get_post($account_page); $wp_query->queried_object_id=$account_page;
    $wp_query->query_vars=array('view-order'=>$id); $wp->query_vars=array('view-order'=>$id); $post=get_post($account_page);
    try { return cancel_capture(static function()use($order,$style){ if($style)Petish_Order_Cancellation_Customer::enqueue_style(); Petish_Order_Cancellation_Customer::render($order); }); }
    finally { $wp_query=$old_query; $post=$old_post; $wp->query_vars=$old_vars; }
}
function cancel_form($order, $selection, $reason='Changed plans') {
    $quantities=array(); $selected=array(); foreach($selection as$id=>$qty){$selected[]=(string)$id;$quantities[$id]=(string)$qty;}
    return array('order_id'=>(string)$order->get_id(),'_wpnonce'=>wp_create_nonce(Petish_Order_Cancellation_Customer::nonce_action($order->get_id())),
        'selected'=>$selected,'quantities'=>$quantities,'reason'=>$reason,'confirm_request'=>'yes');
}
function cancel_readonly($callback, &$writes) {
    $writes=0; $guard=static function($sql)use(&$writes){if(!preg_match('/\A\s*(?:SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i',$sql)){$writes++;throw new RuntimeException('unexpected_sql_write');}return$sql;};
    add_filter('query',$guard,PHP_INT_MAX); try{return$callback();}finally{remove_filter('query',$guard,PHP_INT_MAX);}
}
function cancel_case($id) { return cancel_ok(Petish_Order_Cancellation_Requests::get($id),'private_case_read'); }
function cancel_plain($html) { return strtolower(trim(preg_replace('/\s+/u',' ',html_entity_decode(wp_strip_all_tags($html),ENT_QUOTES,'UTF-8')))); }
function cancel_artifact($name,$html,$css = '') {
    global $mode;
    $directory='/checks/cancellation-artifacts'; if(!is_dir($directory))mkdir($directory,0700);
    if($css!=='') {
        $palette=file_get_contents('/checks/native-branding/theme-palette.css'); $at=strpos($palette,'/* Override generated The7 variables');
        if($at===false)throw new RuntimeException('palette_missing');
        $html='<!DOCTYPE html><html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Owned cancellation review</title><style>'
            .substr($palette,0,$at).$css.'body{margin:0;padding:24px;background:var(--color-bg);color:var(--color-text);font:17px/1.65 var(--font-body)}main{max-width:1040px;margin:auto}</style></head><body><main>'.$html.'</main></body></html>';
    }
    $logo='data:image/png;base64,'.base64_encode(file_get_contents('/checks/native-branding/combined-light-v2.png'));
    $html=str_replace(esc_url(home_url('/wp-content/uploads/native-branding/combined-light-v2.png')),$logo,$html);
    $csp='<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\'">';
    $html=str_replace('<head>','<head>'.$csp,$html);
    if(strlen($html)>4194304)throw new RuntimeException('artifact_too_large');
    file_put_contents($directory.'/'.$name.'-'.$mode.'.html',$html);
}
function cancel_staff_post($record, $operation, $extra = array()) {
    return $extra + array('case_id'=>(string)$record['id'],'revision'=>(string)$record['revision'],'operation'=>$operation,
        '_wpnonce'=>wp_create_nonce(Petish_Order_Cancellation_Admin::nonce_action($operation,$record['id'],$record['revision'])));
}

try {
    cancel_check('disconnected_origin',defined('PETISH_FULFILMENT_NATIVE_OWNER') && wp_get_environment_type()==='local'
        && home_url()==='http://petish-fulfilment.invalid' && DB_NAME==='petish_fulfilment_native');
    cancel_check('exact_native_versions',get_bloginfo('version')==='7.1.3' && WC_VERSION==='11.2.1');
    cancel_check('requested_storage_mode',in_array($mode,array('no','yes'),true)
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()===($mode==='yes'));
    cancel_check('http_blocked',is_wp_error(wp_remote_get('https://example.com/')));
    cancel_check('smtp_blocked',wp_mail('nobody@example.invalid','Blocked','Synthetic')===false);
    cancel_check('cron_blocked',defined('DISABLE_WP_CRON') && DISABLE_WP_CRON===true);
    foreach(array('Petish_Cancellation_Policy','Petish_Order_Cancellation_Requests','Petish_Order_Cancellation_Emails','Petish_Order_Cancellation_Customer','Petish_Order_Cancellation_Admin')as$class)
        cancel_check('bootstrap_'.$class,class_exists($class));
    cancel_check('feature_flags_default_off',!Petish_Order_Cancellation_Requests::enabled() && !Petish_Order_Cancellation_Emails::enabled());
    $cpt=get_post_type_object(Petish_Order_Cancellation_Requests::POST_TYPE);
    cancel_check('case_post_type_private',$cpt && !$cpt->public && !$cpt->publicly_queryable && !$cpt->show_in_rest && !$cpt->show_ui && $cpt->exclude_from_search);
    require '/checks/native-branding/petish-email-branding.php';
    add_filter('petish_email_logo_url',static fn()=>home_url('/wp-content/uploads/native-branding/combined-light-v2.png'));
    add_filter('wc_get_template',static fn($located,$name)=>in_array($name,array('emails/email-header.php','emails/email-footer.php'),true)?'/checks/native-branding/'.basename($name):$located,PHP_INT_MAX,2);
    cancel_check('actual_petish_brand_shell',has_filter('woocommerce_email_styles','petish_email_branding_styles')!==false && is_file('/checks/native-branding/combined-light-v2.png'));
    define('PETISH_CANCELLATION_VERIFY_LIBRARY',true);
    require '/checks/native-verifiers/verify-cancellation-requests.php';
    $report=array('checks'=>array(),'observations'=>array());
    petish_cancel_contracts(rtrim(ABSPATH,'/')); cancel_check('hosted_verifier_native_contracts',!in_array(false,$report['checks'],true));
    petish_cancel_controls(); cancel_check('hosted_verifier_native_controls',!in_array(false,$report['checks'],true));
    petish_cancel_policy(); cancel_check('hosted_verifier_native_policy',!in_array(false,$report['checks'],true));
    // Old staging clones can lack the already-existing live delivery page. Only
    // that exact counterpart may be created; never create a separate FAQ page.
    $missing_count=(int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}`");
    $missing_run=random_int(100000000,999999999).'-1';
    petish_cancel_checkpoint($missing_run,array('owned_native_fixture'=>true));$missing_rejected=false;
    add_filter('query','petish_cancel_prepare_guard',PHP_INT_MAX);
    try{try{petish_cancel_prepare($missing_run);}catch(Throwable $ignored){$missing_rejected=true;}}
    finally{remove_filter('query','petish_cancel_prepare_guard',PHP_INT_MAX);}
    cancel_check('missing_delivery_creation_failure_rolls_back',$missing_rejected&&petish_cancel_faq_rows()===array()
        &&(int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}`")===$missing_count);
    add_filter('query','petish_cancel_prepare_guard',PHP_INT_MAX);
    try{
        petish_cancel_prepare(random_int(100000000,999999999).'-1');$created_rows=petish_cancel_faq_rows();
        cancel_check('missing_delivery_counterpart_created_only',count($created_rows)===1&&petish_cancel_faq_valid($created_rows[0])
            &&petish_cancel_faq_marker((int)$created_rows[0]['ID'])&&($report['observations']['delivery_counterpart_created']??false)
            &&(int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}`")===$missing_count+1);
        petish_cancel_prepare(random_int(100000000,999999999).'-1');
        cancel_check('created_delivery_counterpart_idempotent',petish_cancel_faq_rows()===$created_rows
            &&($report['observations']['faq_already_owned']??false)&&!$report['observations']['delivery_counterpart_created']);
        cancel_check('delivery_prepare_uses_index_and_verified_isolation',($report['checks']['faq_page_slug_indexed']??false)
            &&($report['checks']['faq_transaction_isolation_verified']??false));
    }finally{remove_filter('query','petish_cancel_prepare_guard',PHP_INT_MAX);}
    $created_id=(int)$created_rows[0]['ID'];update_post_meta($created_id,'_petish_native_owner',$owner);
    $pages[]=$created_id;wp_delete_post($created_id,true);array_pop($pages);
    $collision=wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_name'=>'delivery-shipping','post_title'=>'Delivery & shipping',
        'post_content'=>'Keep this unrelated page','meta_input'=>array('_petish_native_owner'=>$owner)),true);
    cancel_ok($collision,'owned_existing_delivery_page_created');
    $pages[]=(int)$collision;
    $collision_before=get_post($collision)->to_array(); $rejected=false;
    add_filter('query','petish_cancel_prepare_guard',PHP_INT_MAX);
    try { try { petish_cancel_prepare(random_int(100000000,999999999).'-1'); } catch(Throwable $ignored) { $rejected=true; } }
    finally { remove_filter('query','petish_cancel_prepare_guard',PHP_INT_MAX); }
    cancel_check('faq_prepare_collision_rejected_without_overwrite',$rejected && get_post($collision)->to_array()===$collision_before);
    wp_update_post(array('ID'=>$collision,'post_content'=>wp_slash(petish_cancel_faq_base())));
    $before_append=petish_cancel_faq_rows();
    $post_count=(int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}`");
    $rollback_run=random_int(100000000,999999999).'-1';
    petish_cancel_checkpoint($rollback_run,array('owned_native_fixture'=>true));
    $rollback_rejected=false;
    add_filter('query','petish_cancel_prepare_guard',PHP_INT_MAX);
    try {try{petish_cancel_prepare($rollback_run);}catch(Throwable $ignored){$rollback_rejected=true;}}
    finally{remove_filter('query','petish_cancel_prepare_guard',PHP_INT_MAX);}
    cancel_check('faq_append_failure_rolls_back_content_and_marker',$rollback_rejected && petish_cancel_faq_rows()===$before_append && petish_cancel_faq_markers((int)$collision)===array());
    $row_locked=false;$lock_probe=static function($sql)use($collision,&$row_locked,$wpdb){
        if(str_starts_with($sql,'UPDATE `'.$wpdb->posts.'` SET `post_content`')){
            $connection=mysqli_init();
            if(!mysqli_real_connect($connection,'localhost',DB_USER,DB_PASSWORD,DB_NAME,null,'/run/mysqld/mysqld.sock'))throw new RuntimeException('delivery_lock_probe_connection');
            try{
                mysqli_query($connection,'SET innodb_lock_wait_timeout=1');
                try{$changed=mysqli_query($connection,'UPDATE `'.$wpdb->posts.'` SET post_title=post_title WHERE ID='.(int)$collision);$code=mysqli_errno($connection);}
                catch(mysqli_sql_exception $error){$changed=false;$code=$error->getCode();}
                $row_locked=$changed===false&&(int)$code===1205;
            }finally{mysqli_close($connection);}
        }
        return$sql;
    };
    add_filter('query',$lock_probe,PHP_INT_MAX-1);
    add_filter('query','petish_cancel_prepare_guard',PHP_INT_MAX);
    try {
        petish_cancel_prepare(random_int(100000000,999999999).'-1');
        cancel_check('delivery_row_locked_before_its_update',$row_locked);
        $faq_rows=petish_cancel_faq_rows(); $faq_id=(int)$faq_rows[0]['ID'];
        cancel_check('faq_prepare_appends_to_existing_delivery_page',count($faq_rows)===1 && $faq_id===(int)$collision && petish_cancel_faq_valid($faq_rows[0]) && petish_cancel_faq_marker($faq_id) && $report['observations']['faq_appended']===true);
        cancel_check('faq_prepare_keeps_prior_delivery_text',str_starts_with($faq_rows[0]['post_content'],$before_append[0]['post_content']) && $faq_rows[0]['post_content']===$before_append[0]['post_content'].PETISH_CANCEL_FAQ_CONTENT);
        $fields_before=$before_append[0];$fields_after=$faq_rows[0];
        foreach(array('post_content','post_modified','post_modified_gmt')as$field){unset($fields_before[$field],$fields_after[$field]);}
        cancel_check('faq_prepare_preserves_page_identity_and_other_fields',$fields_before===$fields_after);
        cancel_check('faq_prepare_creates_no_new_pages',(int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}`")===$post_count);
        $faq_before=$faq_rows;
        petish_cancel_prepare(random_int(100000000,999999999).'-1');
        cancel_check('faq_prepare_idempotent',petish_cancel_faq_rows()===$faq_before && $report['observations']['faq_already_owned']===true);
        cancel_check('faq_prepare_scope_checks',($report['checks']['delivery_page_only_write_scope_verified']??false) && ($report['checks']['faq_checkpoint_saved']??false));
    } finally {remove_filter('query','petish_cancel_prepare_guard',PHP_INT_MAX);remove_filter('query',$lock_probe,PHP_INT_MAX-1);}
    clean_post_cache((int)$collision);
    if (($argv[2] ?? 'full') !== 'helpers') {
    $admin=get_users(array('role'=>'administrator','number'=>1))[0]; $customer=cancel_user('owner'); $stranger=cancel_user('stranger');
    $account_page=cancel_page(); $a=cancel_product('A'); $b=cancel_product('B'); $order=cancel_order($customer,$a,$b); $id=$order->get_id();
    $lines=array_values($order->get_items('line_item')); $line_a=(int)$lines[0]->get_id(); $line_b=(int)$lines[1]->get_id();
    $refund=wc_create_refund(array('order_id'=>$id,'amount'=>'25','reason'=>'Owned synthetic prior refund','refund_payment'=>false,'restock_items'=>false,
        'line_items'=>array($line_a=>array('qty'=>1,'refund_total'=>'25','refund_tax'=>array()))));
    cancel_ok($refund,'owned_prior_refund_created');
    $order=cancel_fresh($id); $baseline=cancel_order_snapshot($id); $stock=cancel_stock_snapshot();
    wp_set_current_user($customer);
    cancel_check('default_off_create_rejected',is_wp_error(Petish_Order_Cancellation_Requests::create($id,array($line_a=>1),'Changed plans')));
    cancel_check('default_off_customer_output_empty',cancel_customer_view($order)==='');
    cancel_option(Petish_Order_Cancellation_Requests::OPTION,'yes');
    $available=cancel_ok(Petish_Order_Cancellation_Requests::available_items($order),'native_available_quantities');
    cancel_check('native_refund_quantities_respected',$available[$line_a]['quantity']===3 && $available[$line_a]['refunded_quantity']===1 && $available[$line_a]['available_quantity']===2 && $available[$line_b]['available_quantity']===2);
    cancel_check('over_refunded_quantity_refused',is_wp_error(Petish_Order_Cancellation_Requests::create($id,array($line_a=>3),'Changed plans')));
    cancel_check('foreign_line_refused',is_wp_error(Petish_Order_Cancellation_Requests::create($id,array(999999=>1),'Changed plans')));
    $form=cancel_form($order,array($line_a=>2,$line_b=>1),'Changed <script>alert(1)</script> plans');
    $invalid=$form; $invalid['_wpnonce']='invalid';
    cancel_check('invalid_customer_nonce_refused',is_wp_error(Petish_Order_Cancellation_Customer::handle_request($invalid)));
    $invalid=$form; $invalid['confirm_request']='no';
    cancel_check('missing_customer_confirmation_refused',is_wp_error(Petish_Order_Cancellation_Customer::handle_request($invalid)));
    wp_set_current_user(0); cancel_check('anonymous_create_refused',is_wp_error(Petish_Order_Cancellation_Requests::create($id,array($line_a=>1),'Changed plans')));
    cancel_check('anonymous_view_empty',cancel_customer_view($order)==='');
    wp_set_current_user($stranger); cancel_check('other_customer_create_refused',is_wp_error(Petish_Order_Cancellation_Requests::create($id,array($line_a=>1),'Changed plans')));
    cancel_check('other_customer_history_refused',is_wp_error(Petish_Order_Cancellation_Requests::customer_requests($id)));
    cancel_check('other_customer_view_empty',cancel_customer_view($order)==='');
    wp_set_current_user($admin->ID); cancel_check('staff_not_owner_customer_view_empty',cancel_customer_view($order)==='');
    wp_set_current_user($customer); $before_form=cancel_customer_view($order,null,true);
    cancel_check('owner_form_contains_selection',str_contains($before_form,'Send cancellation request') && str_contains($before_form,'name="selected[]"') && str_contains($before_form,'max="2"'));
    cancel_check('owner_form_safe_names',!str_contains($before_form,'<b>bridge</b>') && str_contains($before_form,'Willow'));
    $styles=wp_styles(); cancel_check('native_customer_css_url',wp_style_is(Petish_Order_Cancellation_Customer::STYLE_HANDLE,'enqueued')
        && str_ends_with($styles->registered[Petish_Order_Cancellation_Customer::STYLE_HANDLE]->src,'/petish-fulfilment/assets/order-cancellation.css'));
    cancel_artifact('customer-form',$before_form,file_get_contents(WP_PLUGIN_DIR.'/petish-fulfilment/assets/order-cancellation.css'));
    cancel_check('wrong_order_endpoint_empty',cancel_customer_view($order,(string)($id+9999))==='');
    $case=cancel_ok(Petish_Order_Cancellation_Customer::handle_request($form),'native_request_created'); $case_id=$case['id'];
    cancel_check('new_case_not_reused',$case['reused']===false); unset($case['reused']);
    $saved=cancel_case($case_id); $event_id=array_key_first($saved['events']);
    cancel_check('request_persisted_before_notification',count($saved['events'])===1 && $saved['state']==='requested'
        && $saved['events'][$event_id]['deliveries']['customer']['status']==='pending' && $saved['events'][$event_id]['deliveries']['staff']['status']==='pending');
    cancel_check('private_request_post_persisted',get_post($case_id)->post_status==='private' && get_post_meta($case_id,Petish_Order_Cancellation_Requests::META,true)===$saved);
    cancel_check('customer_cannot_read_private_native_post',!current_user_can('read_post',$case_id));
    cancel_check('customer_staff_queue_refused',is_wp_error(Petish_Order_Cancellation_Requests::query()));
    $replay=cancel_ok(Petish_Order_Cancellation_Customer::handle_request($form),'duplicate_request_reused');
    cancel_check('duplicate_has_same_case_and_event',$replay['reused']===true && $replay['id']===$case_id && count(cancel_case($case_id)['events'])===1);
    cancel_check('request_order_untouched',cancel_order_snapshot($id)===$baseline && cancel_stock_snapshot()===$stock);
    cancel_check('customer_pending_status_visible',str_contains(cancel_customer_view($order),'Request received') && !str_contains(cancel_customer_view($order),'name="selected[]"'));
    wp_set_current_user($admin->ID);
    cancel_check('staff_case_query_native',Petish_Order_Cancellation_Requests::query(array('order_id'=>$id))['total']===1 && Petish_Order_Cancellation_Requests::pending_count()===1);
    $preview_before=cancel_case($case_id); $mail_calls=0;
    $no_mail=static function($result)use(&$mail_calls){$mail_calls++;return false;}; add_filter('pre_wp_mail',$no_mail,PHP_INT_MAX);
    try { $preview=cancel_readonly(static fn()=>Petish_Order_Cancellation_Emails::preview($case_id,$event_id,'customer'),$writes); }
    finally { remove_filter('pre_wp_mail',$no_mail,PHP_INT_MAX); }
    cancel_ok($preview,'native_customer_email_preview');
    cancel_check('preview_readonly_no_transport',$writes===0 && $mail_calls===0 && cancel_case($case_id)===$preview_before);
    $plain=cancel_plain($preview['html']);
    cancel_check('email_request_not_cancelled_claim',str_contains($plain,'received your cancellation request') && str_contains($plain,'not yet been confirmed cancelled'));
    cancel_check('email_policy_fourteen_days',str_contains($plain,'14 days') && str_contains($plain,'delivery'));
    cancel_check('email_policy_return_condition',str_contains($plain,'unused') && str_contains($plain,'complete') && str_contains($plain,'original packaging'));
    cancel_check('email_policy_fee_conditional',str_contains($plain,'where permitted') && str_contains($plain,'proposed deduction') && !str_contains($plain,'$10'));
    cancel_check('email_policy_rights_preserved',str_contains($plain,'consumer law') && str_contains($plain,'does not exclude your rights'));
    cancel_check('email_policy_bottom_after_order_details',strpos($preview['html'],'14 days')>strpos($preview['html'],'View your order and request'));
    cancel_check('email_real_animal_logo_and_css',str_contains($preview['html'],'combined-light-v2.png') && str_contains($preview['html'],'Petish shared email shell') && str_contains($preview['html'],'Need a hand?'));
    cancel_artifact('customer-ack-email',$preview['html']);
    $staff_preview=cancel_ok(Petish_Order_Cancellation_Emails::preview($case_id,$event_id,'staff'),'native_staff_email_preview');
    cancel_check('staff_email_correct_case_link',str_contains(html_entity_decode($staff_preview['html'],ENT_QUOTES),'page=petish-cancellation-requests&case_id='.$case_id));
    cancel_check('staff_email_manual_supplier_boundary',str_contains(cancel_plain($staff_preview['html']),'not automatically stopped dsers'));
    cancel_check('staff_email_no_customer_faq',!str_contains(cancel_plain($staff_preview['html']),'14 days'));
    cancel_artifact('staff-alert-email',$staff_preview['html']);
    $faq=do_shortcode('[petish_faqs]'); $faq_plain=cancel_plain($faq);
    cancel_check('shared_faq_shortcode_rendered',str_contains($faq,'change-of-mind') && !str_contains($faq,'[petish_faqs]') && str_contains($faq_plain,'14 days'));
    cancel_check('shared_faq_return_rules',str_contains($faq_plain,'original packaging') && str_contains($faq_plain,'return') && str_contains($faq_plain,'consumer law'));
    cancel_artifact('faqs',$faq,file_get_contents(WP_PLUGIN_DIR.'/petish-fulfilment/assets/cancellation-faq.css'));

    // Capture native wp_mail after the request and pending event already exist.
    $capture=static function($result,$mail)use(&$captured,&$mail_mode,&$mail_claims,$case_id){
        $record=Petish_Order_Cancellation_Requests::get($case_id); $sending=false;
        if(!is_wp_error($record))foreach($record['events']as$e)foreach($e['deliveries']as$d)if($d['status']==='sending' && $d['attempt_id'])$sending=true;
        global $wpdb;
        $lock='petish-cancel-'.substr(hash('sha256',DB_NAME.'|'.$record['order_id']),0,48);
        $outside=(string)$wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)',$lock))==='1';
        $mail_claims[]=$sending && $outside;
        $captured[]=$mail;
        if($mail_mode==='throw')throw new RuntimeException('SYNTHETIC_PRIVATE_SMTP_DETAIL');
        return$mail_mode==='accept';
    };
    add_filter('pre_wp_mail',$capture,PHP_INT_MAX,2);
    cancel_option(Petish_Order_Cancellation_Emails::OPTION,'yes');
    cancel_ok(Petish_Order_Cancellation_Emails::dispatch_pending($case_id),'pending_ack_dispatched_to_capture');
    $delivered=cancel_case($case_id);
    cancel_check('customer_and_staff_two_native_calls',count($captured)===2 && count(array_filter($mail_claims))===2);
    cancel_check('customer_and_staff_native_recipients',$captured[0]['to']===$order->get_billing_email('edit') && $captured[1]['to']==='orders@petish.biz');
    cancel_check('durable_ack_submitted',$delivered['events'][$event_id]['deliveries']['customer']['status']==='submitted' && $delivered['events'][$event_id]['deliveries']['staff']['status']==='submitted');
    cancel_ok(Petish_Order_Cancellation_Emails::dispatch_pending($case_id),'submitted_replay_safe');
    cancel_check('submitted_not_resent',count($captured)===2);
    wp_set_current_user($customer); cancel_ok(Petish_Order_Cancellation_Customer::handle_request($form),'customer_duplicate_after_mail_safe');
    cancel_check('customer_duplicate_no_new_mail',count($captured)===2 && count(cancel_case($case_id)['events'])===1);
    cancel_check('customer_email_preview_denied',is_wp_error(Petish_Order_Cancellation_Emails::preview($case_id,$event_id)));
    cancel_check('customer_staff_transition_denied',is_wp_error(Petish_Order_Cancellation_Requests::transition($case_id,'reviewing')));
    wp_set_current_user($admin->ID); $record=cancel_case($case_id);
    $bad=cancel_staff_post($record,'reviewing');$bad['_wpnonce']='invalid';
    cancel_check('invalid_staff_nonce_denied',is_wp_error(Petish_Order_Cancellation_Admin::action_request($bad)) && cancel_case($case_id)===$record);
    $mail_mode='false';
    $review=cancel_ok(Petish_Order_Cancellation_Admin::action_request(cancel_staff_post($record,'reviewing',array('private_note'=>'PRIVATE_SUPPLIER_REFERENCE','public_message'=>'We are reviewing your request.'))),'staff_review_started');
    $record=cancel_case($case_id); $progress_id=array_key_last($record['events']);
    cancel_check('false_transport_is_uncertain',$record['events'][$progress_id]['deliveries']['customer']['status']==='uncertain');
    $count=count($captured); cancel_ok(Petish_Order_Cancellation_Emails::dispatch_pending($case_id),'uncertain_dispatch_does_not_repeat');
    cancel_check('uncertain_not_resent',count($captured)===$count);
    cancel_check('uncertain_blind_retry_refused',is_wp_error(Petish_Order_Cancellation_Emails::queue_retry($case_id,$progress_id,'customer')));
    cancel_check('uncertain_resolution_requires_confirmation',is_wp_error(Petish_Order_Cancellation_Emails::resolve_uncertain($case_id,$progress_id,'customer','confirmed_not_sent',false)));
    cancel_ok(Petish_Order_Cancellation_Emails::resolve_uncertain($case_id,$progress_id,'customer','confirmed_not_sent',true),'uncertain_resolved_with_evidence');
    cancel_ok(Petish_Order_Cancellation_Emails::queue_retry($case_id,$progress_id,'customer'),'definitively_unsent_retry_queued');
    $mail_mode='accept'; cancel_ok(Petish_Order_Cancellation_Emails::dispatch_pending($case_id),'resolved_pending_retry_sent');
    cancel_check('retry_sent_only_confirmed_customer',count($captured)===$count+1);
    $record=cancel_case($case_id); $stale=$record['revision']-1;
    cancel_check('stale_staff_revision_refused',is_wp_error(Petish_Order_Cancellation_Requests::transition($case_id,'awaiting_supplier',array('expected_revision'=>$stale))));
    $mail_mode='throw'; cancel_ok(Petish_Order_Cancellation_Requests::transition($case_id,'awaiting_supplier',array('expected_revision'=>$record['revision'],'public_message'=>'We have asked the supplier.')),'supplier_review_recorded');
    $record=cancel_case($case_id); $supplier_id=array_key_last($record['events']);
    cancel_check('exception_is_uncertain_and_sanitized',$record['events'][$supplier_id]['deliveries']['customer']['status']==='uncertain' && !str_contains(wp_json_encode($record),'SYNTHETIC_PRIVATE_SMTP_DETAIL'));
    $mail_mode='accept';
    cancel_check('decision_requires_supplier_check',is_wp_error(Petish_Order_Cancellation_Requests::record_decision($case_id,array($line_a=>1,$line_b=>0),'One item can be cancelled.','PRIVATE_SUPPLIER_REFERENCE',false)));
    $record=cancel_case($case_id);
    cancel_ok(Petish_Order_Cancellation_Admin::action_request(cancel_staff_post($record,'record_decision',array('accepted_qty'=>array($line_a=>'1',$line_b=>'0'),
        'supplier_checked'=>'yes','private_reference'=>'PRIVATE_SUPPLIER_REFERENCE','public_message'=>'One willow bridge can be cancelled. We will confirm the refund amount separately.'))),'partial_decision_saved');
    $record=cancel_case($case_id); $decision_id=array_key_last($record['events']);
    cancel_check('partial_decision_derived',$record['state']==='partially_approved' && $record['decision']['accepted_items'][$line_a]===1 && $record['decision']['accepted_items'][$line_b]===0);
    $decision_preview=cancel_ok(Petish_Order_Cancellation_Emails::preview($case_id,$decision_id),'decision_email_preview');
    cancel_check('private_supplier_reference_not_in_customer_email',!str_contains($decision_preview['html'],'PRIVATE_SUPPLIER_REFERENCE'));
    cancel_check('approval_not_refund_confirmation',str_contains(cancel_plain($decision_preview['html']),'does not itself return a payment'));
    cancel_artifact('customer-decision-email',$decision_preview['html']);
    wp_set_current_user($customer); $status_html=cancel_customer_view(cancel_fresh($id));
    cancel_check('customer_partial_decision_visible',str_contains($status_html,'Part of your request approved') && str_contains($status_html,'Approved quantities'));
    cancel_check('customer_private_delivery_and_supplier_data_absent',!str_contains($status_html,'PRIVATE_SUPPLIER_REFERENCE') && !str_contains($status_html,'attempt_id') && !str_contains($status_html,'deliveries'));
    cancel_artifact('customer-status',$status_html,file_get_contents(WP_PLUGIN_DIR.'/petish-fulfilment/assets/order-cancellation.css'));
    wp_set_current_user($admin->ID);
    $old_get=$_GET;$_GET=array('case_id'=>(string)$case_id);$admin_html=cancel_capture(static fn()=>Petish_Order_Cancellation_Admin::render());$_GET=$old_get;
    cancel_check('staff_queue_case_rendered',str_contains($admin_html,'Cancellation') && str_contains($admin_html,'PRIVATE_SUPPLIER_REFERENCE'));
    cancel_artifact('staff-case',$admin_html,file_get_contents(ABSPATH.'wp-admin/css/common.css').file_get_contents(ABSPATH.'wp-admin/css/forms.css').file_get_contents(ABSPATH.'wp-admin/css/list-tables.css'));
    cancel_check('approved_case_still_open_for_refund',Petish_Order_Cancellation_Requests::pending_count()===1);
    cancel_check('case_cannot_close_without_refund_check',is_wp_error(Petish_Order_Cancellation_Requests::close($case_id,array('supplier_checked'=>true,'refund_handled'=>false))));
    $record=cancel_case($case_id);
    cancel_ok(Petish_Order_Cancellation_Admin::action_request(cancel_staff_post($record,'close',array('supplier_checked'=>'yes','refund_handled'=>'yes','private_note'=>'Synthetic review complete; no funds moved.'))),'case_closed_after_staff_confirmation');
    cancel_check('closed_case_exits_open_queue',cancel_case($case_id)['state']==='closed' && Petish_Order_Cancellation_Requests::pending_count()===0);
    cancel_check('all_case_work_keeps_order_and_stock_unchanged',cancel_order_snapshot($id)===$baseline && cancel_stock_snapshot()===$stock);
    remove_filter('pre_wp_mail',$capture,PHP_INT_MAX);

    // The request history remains visible after a separate native cancellation.
    $order=cancel_fresh($id); $order->set_status('cancelled'); $order->save(); wp_set_current_user($customer);
    cancel_check('terminal_order_owner_history_visible',str_contains(cancel_customer_view(cancel_fresh($id)),'Case closed'));
    cancel_check('terminal_order_new_request_refused',is_wp_error(Petish_Order_Cancellation_Requests::create($id,array($line_a=>1),'Another request')));
    cancel_check('terminal_order_form_absent',!str_contains(cancel_customer_view(cancel_fresh($id)),'name="selected[]"'));
    // Genuine database lock contention is exercised on a second private socket connection.
    $aux=cancel_order($customer,$a);$auxid=$aux->get_id();$auxline=(int)array_values($aux->get_items('line_item'))[0]->get_id();
    $conn=mysqli_init();cancel_check('second_database_connection',mysqli_real_connect($conn,'localhost',DB_USER,DB_PASSWORD,DB_NAME,null,'/run/mysqld/mysqld.sock'));
    $lock='petish-cancel-'.substr(hash('sha256',DB_NAME.'|'.$auxid),0,48);
    $row=mysqli_fetch_row(mysqli_query($conn,"SELECT GET_LOCK('".$lock."',0)"));cancel_check('second_connection_holds_order_lock',$row[0]==='1');
    try{cancel_check('concurrent_create_fails_closed',is_wp_error(Petish_Order_Cancellation_Requests::create($auxid,array($auxline=>1),'Lock proof')));}
    finally{mysqli_query($conn,"SELECT RELEASE_LOCK('".$lock."')");mysqli_close($conn);}
    cancel_option(Petish_Order_Cancellation_Emails::OPTION,'no');
    $lease_case=cancel_ok(Petish_Order_Cancellation_Requests::create($auxid,array($auxline=>1),'Owned abandoned-claim proof'),'abandoned_claim_case_created');
    $lease_event=array_key_first($lease_case['events']);
    cancel_ok(Petish_Order_Cancellation_Requests::locked($lease_case['id'],static function($record,$order,$now)use($lease_event){
        $slot=&$record['events'][$lease_event]['deliveries']['customer'];
        $slot['status']='sending';$slot['attempts']=1;$slot['attempt_id']=str_repeat('b',64);$slot['claimed_at']=$now;
        $slot['history'][]=array('status'=>'sending','attempt_id'=>$slot['attempt_id'],'at'=>$now,'outcome'=>'claimed');
        return array('record'=>$record,'result'=>true);
    }),'abandoned_claim_persisted');
    $mail_calls=0;add_filter('pre_wp_mail',$no_mail,PHP_INT_MAX);
    try{$lease=cancel_ok(Petish_Order_Cancellation_Emails::reconcile($lease_case['id'],time()+Petish_Order_Cancellation_Emails::LEASE_SECONDS+2),'expired_claim_reconciled_sender_off');}
    finally{remove_filter('pre_wp_mail',$no_mail,PHP_INT_MAX);}
    cancel_check('expired_claim_uncertain_without_mail',$lease['events'][$lease_event]['deliveries']['customer']['status']==='uncertain' && $mail_calls===0);
    wp_set_current_user($admin->ID);
    cancel_check('expired_claim_not_blindly_retried',is_wp_error(Petish_Order_Cancellation_Emails::queue_retry($lease_case['id'],$lease_event,'customer')));
    cancel_option(Petish_Order_Cancellation_Emails::OPTION,'yes');
    cancel_check('no_real_cancellation_or_refund_gateway_called',get_option(Petish_Order_Cancellation_Emails::OPTION)==='yes' && abs((float)$baseline['refunded']-25)<0.000001);
    }
} catch(Throwable $error) {
    $failure=isset($checks[$error->getMessage()])?$error->getMessage():'unexpected_native_failure';
    $failure_line=$error->getLine();
    $message=str_replace(array(DB_PASSWORD,DB_USER),array('[redacted]','[database-user]'),$error->getMessage());
    file_put_contents('/checks/cancellation-exception.json',wp_json_encode(array('class'=>get_class($error),'message'=>$message,'file'=>$error->getFile(),'line'=>$failure_line)));
} finally {
    try {
        if(isset($capture))remove_filter('pre_wp_mail',$capture,PHP_INT_MAX);
        if(isset($admin))wp_set_current_user($admin->ID);
        if(class_exists('Petish_Order_Cancellation_Requests') && $orders) {
            $case_ids=get_posts(array('post_type'=>Petish_Order_Cancellation_Requests::POST_TYPE,'post_status'=>'any','numberposts'=>-1,'fields'=>'ids',
                'meta_query'=>array(array('key'=>Petish_Order_Cancellation_Requests::ORDER_META,'value'=>$orders,'compare'=>'IN','type'=>'NUMERIC'))));
            foreach($case_ids as$case_id){
                $p=get_post($case_id);if(!$p || !in_array((int)$p->post_author,$users,true))throw new RuntimeException('case_cleanup_ownership');
                wp_delete_post($case_id,true);if(get_post($case_id))throw new RuntimeException('case_cleanup_failed');
            }
        }
        foreach(array_reverse($orders)as$id){$o=wc_get_order($id);if(!$o || $o->get_meta('_petish_native_owner',true)!==$owner)throw new RuntimeException('order_cleanup_ownership');$o->delete(true);if(wc_get_order($id))throw new RuntimeException('order_cleanup_failed');}
        foreach(array_reverse($products)as$id){$p=wc_get_product($id);if(!$p || $p->get_meta('_petish_native_owner',true)!==$owner)throw new RuntimeException('product_cleanup_ownership');$p->delete(true);}
        foreach($pages as$id){if(get_post_meta($id,'_petish_native_owner',true)!==$owner)throw new RuntimeException('page_cleanup_ownership');wp_delete_post($id,true);}
        if(!function_exists('wp_delete_user'))require_once ABSPATH.'wp-admin/includes/user.php';
        foreach($users as$id){if(get_user_meta($id,'_petish_native_owner',true)!==$owner)throw new RuntimeException('user_cleanup_ownership');wp_delete_user($id);}
        foreach($options as$key=>$value){if($value===null)delete_option($key);else update_option($key,$value);}
        $checks['owned_fixture_cleanup_complete']=true;
    } catch(Throwable $ignored){$checks['owned_fixture_cleanup_complete']=false;$failure=$failure??'fixture_cleanup_failed';}
}
ob_end_clean();
echo wp_json_encode(array('status'=>$failure===null?'success':'failure','storage'=>$mode==='yes'?'hpos':'classic','checks'=>$checks,'failure'=>$failure,'failure_line'=>$failure_line??null))."\n";
exit($failure===null?0:1);
