<?php
/** Real WP/Woo proof. Runs only inside test-fulfilment-native.py's disposable sandbox. */
if (PHP_SAPI !== 'cli') exit(2);
ob_start();
require '/srv/petish-native/wp-load.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=petish-delivery-operations';

$checks = array();
$failure = null;
$failure_line = null;
$owned_products = array();
$owned_orders = array();
$owned_users = array();
$owned_zone = null;
$owner = bin2hex(random_bytes(16));
$options = array();
$mode = $argv[1] ?? '';

function native_check($name, $condition) {
    global $checks;
    $checks[$name] = $condition === true;
    if (!$checks[$name]) throw new RuntimeException($name);
}
function native_ok($value, $name) {
    native_check($name, !is_wp_error($value));
    return $value;
}
function native_option($key, $value) {
    global $options;
    if (!array_key_exists($key, $options)) $options[$key] = get_option($key, null);
    update_option($key, $value);
}
function native_product($suffix, $virtual = false) {
    global $owner, $owned_products;
    $product = new WC_Product_Simple();
    $product->set_name('Owned native fixture ' . $suffix);
    $product->set_status('publish');
    $product->set_sku('NATIVE-' . $owner . '-' . $suffix);
    $product->set_regular_price('25');
    $product->set_price('25');
    $product->set_virtual($virtual);
    $product->set_manage_stock(true);
    $product->set_stock_quantity(20);
    $product->set_stock_status('instock');
    $product->set_weight('0.5');
    $product->set_length('20');
    $product->set_width('15');
    $product->set_height('10');
    $product->update_meta_data('_petish_native_owner', $owner);
    $product->update_meta_data('_petish_supplier_product_url', 'https://www.aliexpress.com/item/' . ($suffix === 'A' ? '100500000001' : '100500000002') . '.html');
    $product->save();
    $owned_products[] = $product->get_id();
    return $product;
}
function native_package($products) {
    $contents = array();
    foreach ($products as $index => $pair) {
        [$product, $quantity] = $pair;
        $contents['native-' . $index] = array('key'=>'native-' . $index, 'product_id'=>$product->get_id(), 'variation_id'=>0,
            'quantity'=>$quantity, 'data'=>$product, 'line_total'=>25 * $quantity, 'line_subtotal'=>25 * $quantity, 'line_tax'=>0);
    }
    return array('contents'=>$contents, 'contents_cost'=>100, 'applied_coupons'=>array(), 'user'=>array('ID'=>0),
        'destination'=>array('country'=>'AU','state'=>'VIC','postcode'=>'3000','city'=>'Melbourne','address'=>'1 Synthetic Street','address_1'=>'1 Synthetic Street','address_2'=>''));
}
function native_identity($product) {
    return array('product_id'=>$product->get_id(),'variation_id'=>0,'expected_sku'=>$product->get_sku('edit'),
        'expected_parent_sku'=>$product->get_sku('edit'),'shipping_fingerprint'=>Petish_Supplier_Quotes::fingerprint($product),'country'=>'AU');
}
function native_v2($product, $pricing, $now) {
    return array_merge(native_identity($product), array('destination_scope'=>array('states'=>array('VIC'),'cities'=>array('Melbourne'),'postcodes'=>array('3000')),
        'supplier_product_url'=>$product->get_meta('_petish_supplier_product_url',true,'edit'), 'service'=>'Synthetic reviewed standard',
        'evidence_kind'=>$pricing['type'] === 'first_additional' ? 'rate_card' : 'supplier_quote',
        'evidence_reference'=>'Owned disconnected fixture', 'observed_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now-60),
        'expires_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now+3600),'evidence_reviewed'=>true,'supplier_currency'=>'AUD','pricing'=>$pricing));
}
function native_cost($package, $book, $cost, $name, $now) {
    $result = native_ok(Petish_Automatic_Shipping::rate_package($package,$book,$now), $name . '_available');
    native_check($name, $result['cost'] === $cost);
    return $result;
}
function native_order($a, $b) {
    global $owner, $owned_orders;
    $order = wc_create_order(array('created_via'=>'petish-disconnected-native'));
    native_ok($order, 'native_order_created');
    $owned_orders[] = $order->get_id();
    $order->update_meta_data('_petish_native_owner',$owner);
    $order->set_billing_email('native-' . $owner . '@example.invalid');
    $order->set_shipping_country('AU');
    $order->set_shipping_state('VIC');
    $order->set_shipping_postcode('3000');
    $order->set_shipping_city('Melbourne');
    $order->set_shipping_address_1('1 Synthetic Street');
    $order->add_product($a,3);
    $order->add_product($b,1);
    $order->calculate_totals(false);
    $order->save();
    return $order;
}
function native_shipment($id, $items, $now) {
    return array('supplier_id'=>'native-supplier','shipment_id'=>$id,'revision'=>1,'carrier'=>'Synthetic carrier',
        'tracking_number'=>'NATIVE-' . strtoupper($id),'tracking_url'=>'https://www.aftership.com/track/NATIVE-' . strtoupper($id),
        'dispatched_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now-60),'items'=>$items);
}
function native_state($id) {
    return native_ok(Petish_Shipment_Ledger::read($id),'ledger_read');
}
function native_meta_rows($id) {
    global $wpdb;
    $hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    $table = $hpos ? $wpdb->prefix.'wc_orders_meta' : $wpdb->postmeta;
    $column = $hpos ? 'order_id' : 'post_id';
    return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column}=%d AND meta_key=%s",$id,Petish_Shipment_Ledger::META));
}

try {
    native_check('disposable_origin', defined('PETISH_FULFILMENT_NATIVE_OWNER') && preg_match('/\A[a-f0-9]{32}\z/D',PETISH_FULFILMENT_NATIVE_OWNER) === 1
        && wp_get_environment_type() === 'local' && home_url() === 'http://petish-fulfilment.invalid' && DB_NAME === 'petish_fulfilment_native');
    native_check('exact_native_versions', get_bloginfo('version') === '7.1.3' && defined('WC_VERSION') && WC_VERSION === '11.2.1');
    native_check('native_storage_mode', in_array($mode,array('no','yes'),true)
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() === ($mode === 'yes'));
    native_check('native_http_blocked', is_wp_error(wp_remote_get('https://example.com/')));
    native_check('native_mail_blocked', wp_mail('nobody@example.invalid','Blocked fixture','No transport') === false);
    native_check('native_cron_blocked', defined('DISABLE_WP_CRON') && DISABLE_WP_CRON === true);
    native_check('native_bootstrap_loads_operations', class_exists('Petish_Shipment_Ledger') && class_exists('Petish_Shipment_Notifications')
        && class_exists('Petish_Delivery_Operations_Admin') && has_action('admin_menu',array('Petish_Delivery_Operations_Admin','menu')) !== false);
    native_check('native_shipping_registered', class_exists('Petish_Automatic_Shipping_Method') && class_exists('Petish_Delivery_Rate_Schedules'));
    native_check('ledger_default_disabled', !Petish_Shipment_Ledger::enabled() && !Petish_Shipment_Notifications::enabled());
    $admin = get_user_by('login','fixture-admin');
    native_check('disposable_admin', $admin instanceof WP_User && $admin->user_email === 'fixture-admin@example.invalid');
    wp_set_current_user($admin->ID);
    native_check('native_manager_capability', current_user_can('manage_woocommerce'));
    define('PETISH_DELIVERY_OPS_VERIFY_LIBRARY',true);
    require '/checks/native-verifiers/verify-delivery-operations.php';
    $report=array('checks'=>array(),'category'=>'verification');
    petish_delivery_ops_contracts('/srv/petish-native');
    petish_delivery_ops_admin('/srv/petish-native');
    native_check('hosted_verifier_native_admin_contracts',count($report['checks'])>=18 && !in_array(false,$report['checks'],true));
    native_option('woocommerce_currency','AUD');
    native_option('woocommerce_shipping_tax_class','');
    native_option('woocommerce_calc_taxes','no');
    native_option('petish_shipping_mode','automatic');
    $a = native_product('A');
    $b = native_product('B');
    $virtual = native_product('V',true);
    $scenario = petish_delivery_ops_catalogue();
    native_check('hosted_verifier_native_catalogue_found',is_array($scenario));
    petish_delivery_ops_scenarios($scenario);
    native_check('hosted_verifier_native_rate_csv_scenarios',!in_array(false,$report['checks'],true) && ($report['checks']['catalogue_csv_preview_verified']??false)===true);
    $now = time();
    $p2 = native_package(array(array($a,2)));
    $v1row = array_merge(native_identity($a),array('destination_scope'=>array('states'=>array('VIC'),'postcodes'=>array('3000')),
        'per_unit_customer_shipping_ex_tax_aud'=>'3.125','expires_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now+3600)));
    native_cost($p2,array('schema_version'=>1,'rules'=>array($v1row)),'6.25','v1_existing_per_unit_unchanged',$now);
    $exact = native_v2($a,array('type'=>'exact_quantity','quantity'=>2,'customer_shipping_ex_tax_aud'=>'5','supplier_freight_total'=>'4'),$now);
    $book = array('schema_version'=>2,'rules'=>array($exact));
    native_cost($p2,$book,'5','v2_exact_native_product',$now);
    native_check('v2_wrong_quantity_no_rate',is_wp_error(Petish_Automatic_Shipping::rate_package(native_package(array(array($a,1))),$book,$now)));
    native_cost(native_package(array(array($a,1),array($a,1))),$book,'5','v2_duplicate_lines_aggregate',$now);
    $card = native_v2($b,array('type'=>'first_additional','min_quantity'=>1,'max_quantity'=>5,'first_customer_shipping_ex_tax_aud'=>'4',
        'additional_customer_shipping_ex_tax_aud'=>'1.5','supplier_first_freight'=>'3','supplier_additional_freight'=>'1'),$now);
    $mixed = array('schema_version'=>2,'rules'=>array($exact,$card));
    native_cost(native_package(array(array($a,2),array($b,3))),$mixed,'12','v2_mixed_supplier_bounded_totals',$now);
    native_check('v2_beyond_bounds_no_rate',is_wp_error(Petish_Automatic_Shipping::rate_package(native_package(array(array($b,6))),array('schema_version'=>2,'rules'=>array($card)),$now)));
    $bad = $mixed; $bad['rules'][1]['evidence_reviewed'] = false;
    native_check('v2_invalid_other_rule_blocks_book',is_wp_error(Petish_Automatic_Shipping::rate_package($p2,$bad,$now)));
    native_check('v2_expired_no_rate',is_wp_error(Petish_Automatic_Shipping::rate_package($p2,$book,$now+3601)));
    $wrong_destination = $p2; $wrong_destination['destination']['city']='Sydney';
    native_check('v2_destination_scope_no_rate',is_wp_error(Petish_Automatic_Shipping::rate_package($wrong_destination,$book,$now)));
    $free = $book; $free['rules'][0]['pricing']['customer_shipping_ex_tax_aud']='0';
    native_check('v2_zero_requires_explicit_free',is_wp_error(Petish_Automatic_Shipping::rate_package($p2,$free,$now)));
    $free['rules'][0]['free_shipping']=true;
    native_cost($p2,$free,'0','v2_explicit_free_valid',$now);
    $price = Petish_Automatic_Shipping::rate_args($p2,$book,$now);
    native_check('native_aud_rate_context',!is_wp_error($price) && $price['cost']==='5' && $price['meta_data']['_petish_auto_currency']==='AUD');
    native_option(Petish_Automatic_Shipping::OPTION,$book);
    WC()->customer = new WC_Customer(0);
    WC()->customer->set_shipping_country('AU'); WC()->customer->set_shipping_state('VIC'); WC()->customer->set_shipping_postcode('3000'); WC()->customer->set_shipping_city('Melbourne');
    $method = new Petish_Automatic_Shipping_Method(0);
    $rates = $method->get_rates_for_package($p2);
    $rate = $rates ? reset($rates) : null;
    native_check('native_wc_shipping_rate', $rate instanceof WC_Shipping_Rate && $rate->get_method_id() === Petish_Automatic_Shipping::METHOD && (float)$rate->get_cost() === 5.0);
    $cached = $p2; $cached['rates']=array($rate->get_id()=>$rate);
    native_check('native_valid_cached_rate_kept',count(Petish_Automatic_Shipping::guard_cached_packages(array($cached),$now)[0]['rates'])===1);
    native_check('native_expired_cache_removed',count(Petish_Automatic_Shipping::guard_cached_packages(array($cached),$now+3601)[0]['rates'])===0);
    $a->set_weight('0.7'); $a->save();
    native_check('native_spec_change_blocks_rate',is_wp_error(Petish_Automatic_Shipping::rate_package($p2,$book,$now)));
    native_check('native_spec_change_removes_cache',count(Petish_Automatic_Shipping::guard_cached_packages(array($cached),$now)[0]['rates'])===0);
    $a->set_weight('0.5'); $a->save();
    native_cost($p2,$book,'5','native_original_spec_restored',$now);
    $source = $a->get_meta('_petish_supplier_product_url',true,'edit');
    $a->update_meta_data('_petish_supplier_product_url','https://www.aliexpress.com/item/100500000099.html'); $a->save();
    native_check('native_source_change_no_rate',is_wp_error(Petish_Automatic_Shipping::rate_package($p2,$book,$now)));
    $a->update_meta_data('_petish_supplier_product_url',$source); $a->save();
    $a->set_stock_quantity(1); $a->save();
    native_check('native_stock_no_rate',is_wp_error(Petish_Automatic_Shipping::rate_package($p2,$book,$now)));
    $a->set_stock_quantity(20); $a->save();

    $order = native_order($a,$b); $id = (int)$order->get_id();
    $lines = array_values($order->get_items('line_item')); $line_a=(int)$lines[0]->get_id(); $line_b=(int)$lines[1]->get_id();
    $shipment = native_shipment('parcel-one',array($line_a=>1),$now);
    native_check('disabled_record_refused',is_wp_error(Petish_Shipment_Ledger::record($id,$shipment,$now)) && native_meta_rows($id)===0);
    native_option(Petish_Shipment_Ledger::OPTION,'yes');
    native_option(Petish_Shipment_Notifications::OPTION,'yes');
    $first=native_ok(Petish_Shipment_Ledger::record($id,$shipment,$now),'first_parcel_recorded');
    native_check('native_partial_coverage',$first['dispatch_state']==='partial' && $first['changed']===true);
    $state=native_state($id); $fresh=new WC_Order($id); $fresh->read_meta_data(true);
    native_check('native_single_blob_persisted',native_meta_rows($id)===1 && $fresh->get_meta(Petish_Shipment_Ledger::META,true)===$state && count($state['outbox'])===1);
    $replay=native_ok(Petish_Shipment_Ledger::record($id,$shipment,$now),'same_revision_replay');
    native_check('native_replay_no_new_event',$replay['changed']===false && native_state($id)===$state);
    $conflict=$shipment; $conflict['tracking_number']='CONFLICT';
    native_check('same_revision_conflict_refused',is_wp_error(Petish_Shipment_Ledger::record($id,$conflict,$now)) && native_state($id)===$state);
    $bad=$shipment; $bad['shipment_id']='foreign'; $bad['items']=array(999999=>1);
    native_check('foreign_line_refused',is_wp_error(Petish_Shipment_Ledger::record($id,$bad,$now)));
    $bad['items']=array($line_a=>4);
    native_check('overallocation_refused',is_wp_error(Petish_Shipment_Ledger::record($id,$bad,$now)));
    $bad=$shipment; $bad['tracking_url']='https://127.0.0.1/private';
    native_check('private_tracking_url_refused',is_wp_error(Petish_Shipment_Ledger::record($id,$bad,$now)));
    $lock_name='petish-shipment-'.substr(hash('sha256',DB_NAME.'|'.$id),0,45);
    $connection=mysqli_init();
    native_check('secondary_db_connection',mysqli_real_connect($connection,null,DB_USER,DB_PASSWORD,DB_NAME,0,'/run/mysqld/mysqld.sock'));
    $escaped=mysqli_real_escape_string($connection,$lock_name);
    $acquired=mysqli_query($connection,"SELECT GET_LOCK('{$escaped}',0)");
    native_check('native_other_connection_lock',mysqli_fetch_row($acquired)[0]==='1');
    $contended=Petish_Shipment_Ledger::record($id,$shipment,$now);
    mysqli_query($connection,"SELECT RELEASE_LOCK('{$escaped}')"); mysqli_close($connection);
    native_check('native_lock_contention_no_mutation',is_wp_error($contended) && native_state($id)===$state);

    $recipient=$order->get_billing_email(); $captured=array(); $transport_mode='submitted'; $transport_count=0; $inside_claim=false; $reentrant_refused=false;
    add_filter('pre_wp_mail',static function($return,$mail)use(&$captured,$recipient){
        if(($mail['to']??null)===$recipient){$captured[]=$mail;return true;} return false;
    },PHP_INT_MAX,2);
    native_ok(Petish_Shipment_Notifications::configure_sender('native-capture',static function($transport_order,$event)use(&$transport_mode,&$transport_count,&$inside_claim,&$reentrant_refused,$recipient){
        $transport_count++;
        $persisted=Petish_Shipment_Ledger::read((int)$transport_order->get_id());
        $inside_claim=!is_wp_error($persisted) && $persisted['outbox'][$event['id']]['status']==='sending' && $persisted['outbox'][$event['id']]['attempts']>=1;
        $reentrant_refused=is_wp_error(Petish_Shipment_Notifications::send((int)$transport_order->get_id(),$event['id']));
        if($transport_mode==='throw') throw new RuntimeException('PRIVATE_SYNTHETIC_EXCEPTION_NEVER_STORE');
        if($transport_mode==='false') return false;
        if($transport_mode==='not_sent') return array('status'=>'not_sent');
        $preview=Petish_Shipment_Notifications::render_preview((int)$transport_order->get_id(),$event['id']);
        if(is_wp_error($preview)) return array('status'=>'not_sent');
        $accepted=wp_mail($recipient,$preview['subject'],$preview['html'],array('Content-Type: text/html; charset=UTF-8'));
        return array('status'=>$accepted ? 'submitted' : 'not_sent');
    }),'capture_adapter_configured');
    $before=count($captured);
    $preview=native_ok(Petish_Shipment_Notifications::render_preview($id,$first['event_id']),'native_preview_available');
    native_check('native_preview_no_transport',count($captured)===$before && str_contains($preview['html'],'Track this parcel') && str_contains($preview['html'],'<html'));
    $sent=native_ok(Petish_Shipment_Notifications::send($id,$first['event_id'],$now),'native_captured_send');
    native_check('native_claim_persisted_before_transport',$inside_claim && $reentrant_refused);
    native_check('native_mail_capture_submitted',$sent['status']==='submitted' && $sent['attempts']===1 && count($captured)===1 && str_contains($captured[0]['message'],'NATIVE-PARCEL-ONE'));
    native_check('native_submitted_never_resent',is_wp_error(Petish_Shipment_Notifications::send($id,$first['event_id'],$now)) && $transport_count===1);
    $second_shipment=native_shipment('parcel-two',array($line_a=>2,$line_b=>1),$now);
    $second=native_ok(Petish_Shipment_Ledger::record($id,$second_shipment,$now),'second_parcel_recorded');
    native_check('native_complete_coverage',$second['dispatch_state']==='dispatched');
    $transport_mode='not_sent';
    $failed=native_ok(Petish_Shipment_Notifications::send($id,$second['event_id'],$now),'definitive_not_sent');
    native_check('native_explicit_failure_retryable',$failed['status']==='failed' && count($captured)===1);
    $customer=wp_insert_user(array('user_login'=>'native-'.$owner,'user_pass'=>bin2hex(random_bytes(24)),'user_email'=>'customer-'.$owner.'@example.invalid','role'=>'customer'));
    native_ok($customer,'native_customer_created'); $owned_users[]=(int)$customer;
    wp_set_current_user($customer);
    native_check('native_customer_retry_forbidden',is_wp_error(Petish_Shipment_Notifications::retry($id,$second['event_id'],$now)));
    native_check('native_customer_preview_forbidden',is_wp_error(Petish_Shipment_Notifications::render_preview($id,$first['event_id'])));
    wp_set_current_user($admin->ID);
    native_ok(Petish_Shipment_Notifications::retry($id,$second['event_id'],$now),'native_manager_retry_queued');
    $transport_mode='submitted';
    $resent=native_ok(Petish_Shipment_Notifications::send($id,$second['event_id'],$now),'native_retry_captured_send');
    native_check('native_manager_retry_submitted',$resent['status']==='submitted' && $resent['attempts']===2 && count($captured)===2);
    native_check('native_full_dispatch_email_all_parcels',str_contains($captured[1]['message'],'NATIVE-PARCEL-ONE') && str_contains($captured[1]['message'],'NATIVE-PARCEL-TWO'));
    $before=native_state($id); $immutable=$before['outbox'][$first['event_id']]['payload'];
    $correction=$shipment; $correction['revision']=2; $correction['tracking_number']='CORRECTED-NATIVE'; $correction['tracking_url']='https://www.aftership.com/track/CORRECTED-NATIVE';
    $corrected=native_ok(Petish_Shipment_Ledger::record($id,$correction,$now),'tracking_correction_recorded');
    $state=native_state($id);
    native_check('native_correction_keeps_other_parcel',$state['parcels'][$second['parcel_key']]===$before['parcels'][$second['parcel_key']] && count($state['parcels'])===2
        && $state['outbox'][$first['event_id']]['payload']===$immutable && $state['outbox'][$corrected['event_id']]['type']==='tracking_correction');
    $revision_only=$correction; $revision_only['revision']=3; $before=$state;
    $higher=native_ok(Petish_Shipment_Ledger::record($id,$revision_only,$now),'revision_only_recorded');
    native_check('native_revision_only_no_event',$higher['event_id']===$corrected['event_id'] && count(native_state($id)['outbox'])===count($before['outbox']));
    native_check('native_stale_revision_refused',is_wp_error(Petish_Shipment_Ledger::record($id,$correction,$now)));
    $_GET=array('tab'=>'notifications','order_id'=>(string)$id);
    ob_start(); Petish_Delivery_Operations_Admin::render(); $admin_html=ob_get_clean(); $_GET=array();
    native_check('native_admin_parcel_shape_rendered',str_contains($admin_html,'native-supplier / parcel-one') && str_contains($admin_html,'CORRECTED-NATIVE')
        && str_contains($admin_html,'Synthetic carrier') && str_contains($admin_html,'Item '.$line_a.': 1') && !str_contains($admin_html,$recipient));
    $pre_action=native_state($id);
    $invalid=Petish_Delivery_Operations_Admin::notification_action(array('petish_operation'=>'retry','order_id'=>(string)$id,'event_id'=>$corrected['event_id'],'_wpnonce'=>'invalid'));
    native_check('native_invalid_admin_nonce_no_mutation',is_wp_error($invalid) && native_state($id)===$pre_action);
    $transport_mode='throw';
    $ambiguous=native_ok(Petish_Shipment_Notifications::send($id,$corrected['event_id'],$now),'native_ambiguous_exception');
    native_check('native_ambiguous_is_uncertain',$ambiguous['status']==='uncertain' && !str_contains(wp_json_encode(native_state($id)),'PRIVATE_SYNTHETIC_EXCEPTION'));
    native_check('native_uncertain_blind_retry_forbidden',is_wp_error(Petish_Shipment_Notifications::retry($id,$corrected['event_id'],$now)));
    native_ok(Petish_Shipment_Notifications::resolve_uncertain($id,$corrected['event_id'],'confirmed_not_sent',$now),'native_uncertain_resolution');
    native_ok(Petish_Shipment_Notifications::retry($id,$corrected['event_id'],$now),'native_resolved_retry_queued');
    $transport_mode='false';
    $false=native_ok(Petish_Shipment_Notifications::send($id,$corrected['event_id'],$now),'native_false_outcome');
    native_check('native_false_is_uncertain',$false['status']==='uncertain');
    native_ok(Petish_Shipment_Notifications::resolve_uncertain($id,$corrected['event_id'],'confirmed_submitted',$now),'native_confirm_submitted');
    native_check('native_resolved_submission_never_retried',is_wp_error(Petish_Shipment_Notifications::retry($id,$corrected['event_id'],$now)));

    // Simulate process death after a persisted claim; no adapter or mail is called.
    $lease_order=native_order($a,$b); $lease_id=(int)$lease_order->get_id(); $lease_lines=array_values($lease_order->get_items('line_item'));
    $lease_event=native_ok(Petish_Shipment_Ledger::record($lease_id,native_shipment('abandoned',array((int)$lease_lines[0]->get_id()=>1),$now),$now),'native_lease_fixture');
    native_ok(Petish_Shipment_Ledger::locked($lease_id,static function($state,$order,$time)use($lease_event){
        $event=&$state['outbox'][$lease_event['event_id']]; $event['status']='sending'; $event['attempts']=1; $event['sender_id']='native-capture';
        $event['attempt_id']=str_repeat('a',64);$event['claimed_at']=$time;$event['lease_until']=$time+300;$event['outcome_code']='claimed';$event['updated_at']=$time;
        $event['history'][]=array('attempt_id'=>$event['attempt_id'],'sender_id'=>$event['sender_id'],'status'=>'sending','outcome_code'=>'claimed','at'=>$time);
        return array('state'=>$state,'result'=>true);
    },$now),'native_abandoned_claim_persisted');
    $before_count=$transport_count; $before_state=native_state($lease_id);
    native_check('native_read_is_side_effect_free',native_state($lease_id)===$before_state && $before_state['outbox'][$lease_event['event_id']]['status']==='sending');
    $reconciled=native_ok(Petish_Shipment_Notifications::reconcile($lease_id,$now+301),'native_expired_claim_reconciled');
    native_check('native_expired_claim_uncertain_no_send',$reconciled['outbox'][$lease_event['event_id']]['status']==='uncertain' && $transport_count===$before_count);
    $mutation_order=native_order($a,$b);$mutation_id=(int)$mutation_order->get_id();$mutation_lines=array_values($mutation_order->get_items('line_item'));
    $mutation_shipment=native_shipment('requirements',array((int)$mutation_lines[0]->get_id()=>1),$now);
    $mutation_event=native_ok(Petish_Shipment_Ledger::record($mutation_id,$mutation_shipment,$now),'native_pinned_requirements_fixture');
    $before_mutation=native_state($mutation_id);$before_transport=$transport_count;
    $a->set_virtual(true);$a->save();
    native_check('native_physical_to_virtual_record_refused',is_wp_error(Petish_Shipment_Ledger::record($mutation_id,$mutation_shipment,$now)) && native_state($mutation_id)===$before_mutation);
    native_check('native_physical_to_virtual_send_refused',is_wp_error(Petish_Shipment_Notifications::send($mutation_id,$mutation_event['event_id'],$now)) && $transport_count===$before_transport);
    $a->set_virtual(false);$a->save();
    $mutation_lines[0]->set_quantity(4);$mutation_lines[0]->save();
    native_check('native_order_quantity_change_record_refused',is_wp_error(Petish_Shipment_Ledger::record($mutation_id,$mutation_shipment,$now)));
    native_check('native_order_quantity_change_send_refused',is_wp_error(Petish_Shipment_Notifications::send($mutation_id,$mutation_event['event_id'],$now)) && $transport_count===$before_transport && native_state($mutation_id)===$before_mutation);
    $mutation_lines[0]->set_quantity(3);$mutation_lines[0]->save();
    native_check('native_order_status_unchanged',(new WC_Order($id))->get_status()==='pending' && (new WC_Order($lease_id))->get_status()==='pending');
    native_check('native_only_expected_mail_captured',count($captured)===2);
} catch (Throwable $error) {
    $failure = isset($checks[$error->getMessage()]) ? $error->getMessage() : 'unexpected_native_failure';
    $failure_line = $error->getLine();
} finally {
    try {
        wp_set_current_user(isset($admin) && $admin instanceof WP_User ? $admin->ID : 0);
        foreach (array_reverse($owned_orders) as $id) {
            $order=wc_get_order($id);
            if ($order instanceof WC_Order && $order->get_meta('_petish_native_owner',true)===$owner) $order->delete(true);
            else throw new RuntimeException('cleanup ownership');
            if (wc_get_order($id)) throw new RuntimeException('cleanup order');
        }
        foreach (array_reverse($owned_products) as $id) {
            $product=wc_get_product($id);
            if ($product instanceof WC_Product && $product->get_meta('_petish_native_owner',true)===$owner) $product->delete(true);
            else throw new RuntimeException('cleanup ownership');
        }
        if (!function_exists('wp_delete_user')) require_once ABSPATH.'wp-admin/includes/user.php';
        foreach ($owned_users as $id) {
            $user=get_user_by('id',$id);
            if ($user instanceof WP_User && $user->user_login==='native-'.$owner) wp_delete_user($id);
            else throw new RuntimeException('cleanup ownership');
        }
        foreach ($options as $key=>$previous) {
            if ($previous===null) delete_option($key); else update_option($key,$previous);
        }
        $checks['native_owned_fixtures_removed']=true;
    } catch (Throwable $ignored) {
        $checks['native_owned_fixtures_removed']=false;
        $failure=$failure??'fixture_cleanup_failed';
    }
}
ob_end_clean();
echo wp_json_encode(array('status'=>$failure===null?'success':'failure','storage'=>$mode==='yes'?'hpos':'classic','checks'=>$checks,'failure'=>$failure,'failure_line'=>$failure_line))."\n";
exit($failure===null?0:1);
