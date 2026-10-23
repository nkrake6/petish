<?php
/** Staging-only proof. No orders, users, rates, options or notifications are saved. */
if (PHP_SAPI !== 'cli') exit(1);
define('PETISH_CLUB_VERIFY_LIBRARY', true);
require_once __DIR__ . '/verify-pet-club.php';

const PETISH_DELIVERY_OPS_CHECKS = [
 'source_manifest_verified','source_unchanged','delivery_classes_loaded','reviewed_class_paths_verified',
 'admin_hooks_registered','anonymous_access_denied','customer_access_denied','manager_access_verified',
 'invalid_nonce_rejected','valid_nonce_accepted','notification_request_nonce_verified',
 'feature_flags_disabled','notification_sender_unconfigured','disabled_mutations_rejected',
 'private_preview_access_denied','notification_api_available','admin_preview_rendered',
 'csv_formula_injection_prevented','csv_limits_enforced','schema_boundaries_verified',
 'active_v1_policy_preserved','shipping_options_unchanged','sql_write_attempts_absent','native_memory_cache_verified',
 'exact_quantity_total_verified','quantity_outside_evidence_rejected','first_additional_total_verified',
 'invalid_evidence_rejected','stale_mapping_rejected','ambiguous_schedule_rejected',
 'v1_per_unit_behavior_preserved','currency_context_verified','cache_tags_invalidate',
 'cached_rate_guard_verified','catalogue_csv_preview_verified',
];
function petish_delivery_ops_mark($key, $value) {
 global $report;
 if (!in_array($key, PETISH_DELIVERY_OPS_CHECKS, true) || !is_bool($value)) petish_club_verify_fail();
 $report['checks'][$key] = $value;
 if (!$value) petish_club_verify_fail();
}
function petish_delivery_ops_source($root, $manifest) {
 $plugin = $root . '/wp-content/plugins/petish-fulfilment';
 if (!petish_club_verify_path($plugin, true) || !is_array($manifest) || count($manifest) < 6 || count($manifest) > 128) petish_club_verify_fail();
 foreach (['petish-fulfilment.php','includes/class-petish-automatic-shipping.php','includes/class-petish-delivery-rate-schedules.php',
  'includes/class-petish-shipment-ledger.php','includes/class-petish-shipment-notifications.php','includes/class-petish-delivery-operations-admin.php'] as $name)
  if (!isset($manifest[$name])) petish_club_verify_fail();
 foreach ($manifest as $name=>$digest) {
  if (!is_string($name) || !preg_match('~\A(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:php|js|css|md|json|svg|png|jpg|jpeg|webp|txt)\z~D', $name)
   || array_intersect(explode('/', $name), ['.','..']) || !is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/D', $digest)
   || !petish_club_verify_path($plugin . '/' . $name) || filesize($plugin . '/' . $name) > 1048576
   || !hash_equals($digest, hash_file('sha256', $plugin . '/' . $name))) petish_club_verify_fail();
 }
 // Include unlisted files in the private protection digest; their names/content are never reported.
 $files = []; $total = 0;
 foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin, FilesystemIterator::SKIP_DOTS)) as $file) {
  $path = $file->getPathname();
  if (!petish_club_verify_path($path) || count($files) >= 256 || ($size = filesize($path)) > 1048576 || ($total += $size) > 12582912) petish_club_verify_fail();
  $files[substr($path, strlen($plugin) + 1)] = hash_file('sha256', $path);
 }
 ksort($files); return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
}
function petish_delivery_ops_principal($role, $caps) {
 $user = new WP_User(0); $user->roles = $role === null ? [] : [$role];
 $user->caps = $caps; $user->allcaps = $caps; $GLOBALS['current_user'] = $user;
}
function petish_delivery_ops_denied($callback) {
 $handler = static fn()=>static function(){ throw new RuntimeException('petish_delivery_operations_denied'); };
 add_filter('wp_die_handler', $handler, PHP_INT_MAX);
 try { $callback(); return false; }
 catch (RuntimeException $error) { return $error->getMessage() === 'petish_delivery_operations_denied'; }
 finally { remove_filter('wp_die_handler', $handler, PHP_INT_MAX); }
}
function petish_delivery_ops_error($value, $code = null) {
 return is_wp_error($value) && ($code === null || $value->get_error_code() === $code);
}
function petish_delivery_ops_contracts($root) {
 global $report;
 $report['category'] = 'contracts';
 $classes = [
  'Petish_Automatic_Shipping'=>'class-petish-automatic-shipping.php',
  'Petish_Delivery_Rate_Schedules'=>'class-petish-delivery-rate-schedules.php',
  'Petish_Shipment_Ledger'=>'class-petish-shipment-ledger.php',
  'Petish_Shipment_Notifications'=>'class-petish-shipment-notifications.php',
  'Petish_Delivery_Operations_Admin'=>'class-petish-delivery-operations-admin.php',
 ];
 petish_delivery_ops_mark('delivery_classes_loaded', array_reduce(array_keys($classes), static fn($ok,$name)=>$ok && class_exists($name, false), true));
 foreach ($classes as $name=>$file)
  if ((new ReflectionClass($name))->getFileName() !== $root . '/wp-content/plugins/petish-fulfilment/includes/' . $file) petish_club_verify_fail();
 petish_delivery_ops_mark('reviewed_class_paths_verified', true);
 petish_delivery_ops_mark('admin_hooks_registered', has_action('admin_menu', ['Petish_Delivery_Operations_Admin','menu']) !== false
  && has_action('admin_post_' . Petish_Delivery_Operations_Admin::TEMPLATE_ACTION, ['Petish_Delivery_Operations_Admin','download']) !== false);
 petish_delivery_ops_mark('notification_api_available', is_callable(['Petish_Shipment_Ledger','record']) && is_callable(['Petish_Shipment_Ledger','read'])
  && is_callable(['Petish_Shipment_Notifications','send']) && is_callable(['Petish_Shipment_Notifications','retry'])
  && is_callable(['Petish_Shipment_Notifications','resolve_uncertain']) && is_callable(['Petish_Shipment_Notifications','render_preview']));
 petish_delivery_ops_mark('feature_flags_disabled', get_option('petish_shipment_ledger_enabled','no') === 'no'
  && get_option('petish_shipment_notifications_enabled','no') === 'no' && !Petish_Shipment_Ledger::enabled() && !Petish_Shipment_Notifications::enabled());
 petish_delivery_ops_mark('notification_sender_unconfigured', Petish_Shipment_Notifications::sender_ready() === false);
 $empty = Petish_Automatic_Shipping::validate_book(['schema_version'=>2,'rules'=>[]]);
 petish_delivery_ops_mark('schema_boundaries_verified', is_array($empty) && ($empty['schema_version'] ?? null) === 2
  && $empty['rules'] === [] && $empty['errors'] === []
  && petish_delivery_ops_error(Petish_Automatic_Shipping::validate_book(['schema_version'=>3,'rules'=>[]]))
  && petish_delivery_ops_error(Petish_Automatic_Shipping::validate_book(['schema_version'=>2,'rules'=>[],'save'=>true]))
  && petish_delivery_ops_error(Petish_Delivery_Rate_Schedules::validate_rule(['evidence_reviewed'=>false])));
 $policy = get_option(Petish_Automatic_Shipping::OPTION, ['schema_version'=>1,'rules'=>[]]);
 petish_delivery_ops_mark('active_v1_policy_preserved', is_array($policy) && ($policy['schema_version'] ?? null) === 1);
}
function petish_delivery_ops_admin($root) {
 global $report;
 $report['category'] = 'admin';
 require_once $root . '/wp-admin/includes/template.php';
 $old_user = $GLOBALS['current_user'] ?? null; $old_get = $_GET; $old_post = $_POST; $old_files = $_FILES;
 $old_method = $_SERVER['REQUEST_METHOD'];
 try {
  $_GET = []; $_POST = []; $_FILES = []; $_SERVER['REQUEST_METHOD'] = 'GET';
  petish_delivery_ops_principal(null, []);
  petish_delivery_ops_mark('anonymous_access_denied', !Petish_Delivery_Operations_Admin::authorized()
   && petish_delivery_ops_denied(static fn()=>Petish_Delivery_Operations_Admin::render()));
  petish_delivery_ops_mark('private_preview_access_denied', petish_delivery_ops_error(Petish_Shipment_Notifications::render_preview(0, str_repeat('a',64)), 'petish_shipment_permission'));
  petish_delivery_ops_principal('customer', ['read'=>true]);
  petish_delivery_ops_mark('customer_access_denied', !Petish_Delivery_Operations_Admin::authorized()
   && petish_delivery_ops_denied(static fn()=>Petish_Delivery_Operations_Admin::render()));
  petish_delivery_ops_principal('shop_manager', ['read'=>true,'manage_woocommerce'=>true]);
  petish_delivery_ops_mark('manager_access_verified', Petish_Delivery_Operations_Admin::authorized() === true);
  petish_delivery_ops_mark('invalid_nonce_rejected', !Petish_Delivery_Operations_Admin::authorized('invalid-process-only-nonce'));
  petish_delivery_ops_mark('valid_nonce_accepted', (bool)Petish_Delivery_Operations_Admin::authorized(wp_create_nonce(Petish_Delivery_Operations_Admin::NONCE)));
  $action = ['order_id'=>'1','event_id'=>str_repeat('a',64),'petish_operation'=>'retry','_wpnonce'=>'invalid-process-only-nonce'];
  petish_delivery_ops_mark('notification_request_nonce_verified', petish_delivery_ops_error(Petish_Delivery_Operations_Admin::notification_action($action), 'petish_operations_request'));
  $shipment = ['supplier_id'=>'process-proof','shipment_id'=>'process-proof','revision'=>1,'carrier'=>'Process proof',
   'tracking_number'=>'NOT-A-REAL-SHIPMENT','tracking_url'=>'https://example.com/track/process-proof',
   'dispatched_utc'=>gmdate('Y-m-d\TH:i:s\Z', time()-60),'items'=>[1=>1]];
  petish_delivery_ops_mark('disabled_mutations_rejected', petish_delivery_ops_error(Petish_Shipment_Notifications::send(0,str_repeat('a',64)), 'petish_shipment_disabled')
   && petish_delivery_ops_error(Petish_Shipment_Notifications::retry(0,str_repeat('a',64)), 'petish_shipment_disabled')
   && petish_delivery_ops_error(Petish_Shipment_Ledger::record(0,$shipment), 'petish_shipment_disabled'));
  $rates = petish_club_verify_capture(static fn()=>Petish_Delivery_Operations_Admin::render());
  $_GET = ['tab'=>'notifications'];
  $notifications = petish_club_verify_capture(static fn()=>Petish_Delivery_Operations_Admin::render());
  petish_delivery_ops_mark('admin_preview_rendered', is_string($rates) && strlen($rates) < 1048576 && str_contains($rates,'Preview delivery rates')
   && str_contains($rates,'petish_rate_csv') && str_contains($rates,Petish_Delivery_Operations_Admin::TEMPLATE_ACTION)
   && is_string($notifications) && strlen($notifications) < 1048576 && str_contains($notifications,'Shipment notifications'));
  petish_delivery_ops_mark('csv_formula_injection_prevented', Petish_Delivery_Operations_Admin::csv_cell('=1+1') === "'=1+1"
   && Petish_Delivery_Operations_Admin::csv_cell("\t@SUM(1)") === "'\t@SUM(1)");
  $limits = 0;
  try { Petish_Delivery_Operations_Admin::parse_csv(str_repeat('x',1048577)); } catch (InvalidArgumentException $ignored) { $limits++; }
  try { Petish_Delivery_Operations_Admin::csv(array_fill(0,501,[])); } catch (InvalidArgumentException $ignored) { $limits++; }
  petish_delivery_ops_mark('csv_limits_enforced', $limits === 2 && Petish_Delivery_Operations_Admin::MAX_ROWS === 500 && Petish_Delivery_Operations_Admin::MAX_BYTES === 1048576);
 } finally {
  $GLOBALS['current_user'] = $old_user; $_GET = $old_get; $_POST = $old_post; $_FILES = $old_files; $_SERVER['REQUEST_METHOD'] = $old_method;
 }
}
function petish_delivery_ops_catalogue() {
 $query = new WP_Query(['post_type'=>['product','product_variation'],'post_status'=>'publish','posts_per_page'=>500,
  'paged'=>1,'orderby'=>'ID','order'=>'ASC','fields'=>'ids','no_found_rows'=>true,'update_post_term_cache'=>false]);
 foreach ($query->posts as $id) {
  $product = wc_get_product($id); $identity = Petish_Delivery_Operations_Admin::product_identity($product);
  if (!$identity || !$product->has_enough_stock(3)) continue;
  $source = wp_parse_url($identity['supplier_product_url']);
  if (!filter_var($identity['supplier_product_url'], FILTER_VALIDATE_URL) || !is_array($source)
   || ($source['scheme'] ?? '') !== 'https' || empty($source['host']) || isset($source['user']) || isset($source['pass']) || isset($source['fragment'])) continue;
  $package = ['contents'=>[['data'=>$product,'product_id'=>$identity['product_id'],'variation_id'=>$identity['variation_id'],'quantity'=>2]],
   'destination'=>['country'=>'AU','state'=>'VIC','city'=>'Bundoora','postcode'=>'3083']];
  if (!is_wp_error(Petish_Automatic_Shipping::snapshot_lines($package))) return [$product,$identity,$package];
 }
 return null;
}
function petish_delivery_ops_scenarios($fixture) {
 global $report;
 [$product,$identity,$package] = $fixture; $now = time();
 $rule = array_intersect_key($identity, array_flip(['product_id','variation_id','expected_sku','expected_parent_sku','supplier_product_url','shipping_fingerprint']));
 $rule += ['country'=>'AU','destination_scope'=>['states'=>['VIC'],'cities'=>[],'postcodes'=>['3083']],
  'country_scope_verified'=>false,'service'=>'Synthetic process-only proof','evidence_kind'=>'supplier_quote',
  'evidence_reference'=>'Synthetic process-only fixture; not a supplier quote','observed_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now-60),
  'expires_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now+3600),'evidence_reviewed'=>true,'supplier_currency'=>'AUD','free_shipping'=>false,
  'pricing'=>['type'=>'exact_quantity','quantity'=>2,'customer_shipping_ex_tax_aud'=>'5','supplier_freight_total'=>'4']];
 $book = ['schema_version'=>2,'rules'=>[$rule]];
 $rate = Petish_Automatic_Shipping::rate_package($package,$book,$now);
 petish_delivery_ops_mark('exact_quantity_total_verified', !is_wp_error($rate) && $rate['cost'] === '5');
 $one = $package; $one['contents'][0]['quantity'] = 1;
 petish_delivery_ops_mark('quantity_outside_evidence_rejected', petish_delivery_ops_error(Petish_Automatic_Shipping::rate_package($one,$book,$now)));
 $progressive = $rule; $progressive['evidence_kind'] = 'rate_card';
 $progressive['pricing'] = ['type'=>'first_additional','min_quantity'=>1,'max_quantity'=>2,
  'first_customer_shipping_ex_tax_aud'=>'5','additional_customer_shipping_ex_tax_aud'=>'2','supplier_first_freight'=>'4','supplier_additional_freight'=>'1'];
 $two_rate = Petish_Automatic_Shipping::rate_package($package,['schema_version'=>2,'rules'=>[$progressive]],$now);
 $one_rate = Petish_Automatic_Shipping::rate_package($one,['schema_version'=>2,'rules'=>[$progressive]],$now);
 $three = $package; $three['contents'][0]['quantity'] = 3;
 petish_delivery_ops_mark('first_additional_total_verified', !is_wp_error($two_rate) && $two_rate['cost'] === '7'
  && !is_wp_error($one_rate) && $one_rate['cost'] === '5'
  && petish_delivery_ops_error(Petish_Automatic_Shipping::rate_package($three,['schema_version'=>2,'rules'=>[$progressive]],$now)));
 $invalid = [];
 $bad = $rule; $bad['evidence_kind'] = 'estimate'; $invalid[] = $bad;
 $bad = $rule; $bad['expires_utc'] = gmdate('Y-m-d\TH:i:s\Z',$now-1); $invalid[] = $bad;
 $bad = $rule; $bad['pricing']['supplier_freight_total'] = ''; $invalid[] = $bad;
 $bad = $rule; $bad['pricing']['customer_shipping_ex_tax_aud'] = '0'; $invalid[] = $bad;
 $bad = $rule; $bad['evidence_reviewed'] = false; $invalid[] = $bad;
 $all_rejected = true;
 foreach ($invalid as $bad) {
  $checked = Petish_Automatic_Shipping::validate_book(['schema_version'=>2,'rules'=>[$bad]],$now);
  $all_rejected = $all_rejected && is_array($checked) && !empty($checked['errors'])
   && petish_delivery_ops_error(Petish_Automatic_Shipping::rate_package($package,['schema_version'=>2,'rules'=>[$rule,$bad]],$now));
 }
 petish_delivery_ops_mark('invalid_evidence_rejected', $all_rejected);
 $bad = $rule; $bad['expected_sku'] .= '-process-stale';
 $bad_source = $rule; $bad_source['supplier_product_url'] = 'https://example.com/process-only-stale-source';
 petish_delivery_ops_mark('stale_mapping_rejected', petish_delivery_ops_error(Petish_Delivery_Rate_Schedules::validate_rule($bad,$now))
  && petish_delivery_ops_error(Petish_Delivery_Rate_Schedules::validate_rule($bad_source,$now)));
 petish_delivery_ops_mark('ambiguous_schedule_rejected', petish_delivery_ops_error(Petish_Automatic_Shipping::rate_package($package,['schema_version'=>2,'rules'=>[$rule,$rule]],$now)));
 $v1 = array_intersect_key($rule,array_flip(['product_id','variation_id','expected_sku','expected_parent_sku','shipping_fingerprint','country','free_shipping','expires_utc']));
 $v1 += ['destination_scope'=>['states'=>['VIC'],'postcodes'=>['3083']],'per_unit_customer_shipping_ex_tax_aud'=>'5'];
 $old_rate = Petish_Automatic_Shipping::rate_package($package,['schema_version'=>1,'rules'=>[$v1]],$now);
 petish_delivery_ops_mark('v1_per_unit_behavior_preserved', !is_wp_error($old_rate) && $old_rate['cost'] === '10');
 $aud = Petish_Automatic_Shipping::rate_args($package,$book,$now,['code'=>'AUD','rate'=>1,'decimals'=>2]);
 $usd = Petish_Automatic_Shipping::rate_args($package,$book,$now,['code'=>'USD','rate'=>0.5,'decimals'=>2]);
 petish_delivery_ops_mark('currency_context_verified', !is_wp_error($aud) && abs((float)$aud['cost']-5)<0.000001 && $aud['price_decimals'] === 6
  && !is_wp_error($usd) && abs((float)$usd['cost']-2.5)<0.000001
  && $usd['meta_data']['_petish_auto_currency'] === 'USD'
  && petish_delivery_ops_error(Petish_Automatic_Shipping::rate_args($package,$book,$now,['code'=>'USD','rate'=>0,'decimals'=>2])));
 $option = static fn()=>$book; $mode = static fn()=>'automatic';
 add_filter('pre_option_' . Petish_Automatic_Shipping::OPTION,$option,PHP_INT_MAX);
 add_filter('pre_option_petish_shipping_mode',$mode,PHP_INT_MAX);
 try {
  $fresh = Petish_Automatic_Shipping::cache_packages([$package],$now);
  $changed = Petish_Automatic_Shipping::cache_packages([$one],$now);
  $expired = Petish_Automatic_Shipping::cache_packages([$package],$now+7200);
  petish_delivery_ops_mark('cache_tags_invalidate', preg_match('/^[a-f0-9]{64}$/D',$fresh[0]['petish_automatic_shipping_cache']) === 1
   && $fresh[0]['petish_automatic_shipping_cache'] !== $changed[0]['petish_automatic_shipping_cache']
   && $fresh[0]['petish_automatic_shipping_cache'] !== $expired[0]['petish_automatic_shipping_cache']);
  $args = Petish_Automatic_Shipping::rate_args($package,null,$now);
  if (is_wp_error($args)) petish_club_verify_fail();
  $native = new WC_Shipping_Rate('petish-process-proof','Process proof',$args['cost'],[],Petish_Automatic_Shipping::METHOD);
  foreach ($args['meta_data'] as $name=>$value) $native->add_meta_data($name,$value);
  $cached = $package; $cached['rates'] = ['proof'=>$native];
  $kept = Petish_Automatic_Shipping::guard_cached_packages([$cached],$now);
  $invalidated = Petish_Automatic_Shipping::guard_cached_packages([$cached],$now+7200);
  petish_delivery_ops_mark('cached_rate_guard_verified', isset($kept[0]['rates']['proof']) && !isset($invalidated[0]['rates']['proof']));
 } finally {
  remove_filter('pre_option_' . Petish_Automatic_Shipping::OPTION,$option,PHP_INT_MAX);
  remove_filter('pre_option_petish_shipping_mode',$mode,PHP_INT_MAX);
 }
 $old_user = $GLOBALS['current_user'] ?? null;
 try {
  petish_delivery_ops_principal('shop_manager',['read'=>true,'manage_woocommerce'=>true]);
  $row = $identity + ['country'=>'AU','states'=>'VIC','postcodes'=>'3083','service'=>$rule['service'],'evidence_kind'=>'supplier_quote',
   'evidence_reference'=>$rule['evidence_reference'],'observed_utc'=>$rule['observed_utc'],'expires_utc'=>$rule['expires_utc'],
   'evidence_reviewed'=>'true','supplier_currency'=>'AUD','pricing_type'=>'exact_quantity','quantity'=>'2',
   'customer_shipping_ex_tax_aud'=>'5','supplier_freight_total'=>'4'];
  $preview = Petish_Delivery_Operations_Admin::preview_csv(Petish_Delivery_Operations_Admin::csv([$row]),$now);
  petish_delivery_ops_mark('catalogue_csv_preview_verified', count($preview) === 1 && ($preview[0]['status'] ?? null) === 'valid' && $preview[0]['cost'] === '5');
 } finally { $GLOBALS['current_user'] = $old_user; }
}

if (defined('PETISH_DELIVERY_OPS_VERIFY_LIBRARY') && PETISH_DELIVERY_OPS_VERIFY_LIBRARY === true) return;
petish_club_verify_report_start();
$report += ['schema_version'=>1,'target'=>'staging','observations'=>[
 'catalogue_scenario_available'=>false,'hosted_order_mutations_not_exercised'=>true,'external_supplier_tracking_not_exercised'=>true], 'source_hashes'=>[]];
$body_passed = false; $before = null; $root = null; $manifest = null; $source_before = null; $shipping_before = null; $baseline = null;
// Registered after WordPress boot below, so final comparison includes its shutdown hooks.
$finalize = static function() use (&$body_passed,&$before,&$root,&$manifest,&$source_before,&$shipping_before,&$baseline,&$report) {
 try {
  $report['category'] = 'snapshot';
  petish_club_verify_state_equal($before,petish_club_verify_state($root));
  petish_delivery_ops_mark('shipping_options_unchanged', $shipping_before === [get_option('petish_shipping_mode','quote'),get_option(Petish_Automatic_Shipping::OPTION,['schema_version'=>1,'rules'=>[]]),
   get_option('petish_shipment_ledger_enabled','no'),get_option('petish_shipment_notifications_enabled','no')]);
  petish_delivery_ops_mark('source_unchanged', hash_equals($source_before,petish_delivery_ops_source($root,$manifest)));
  $report['category'] = 'readonly';
  petish_delivery_ops_mark('native_memory_cache_verified', petish_club_verify_runtime_cache($root));
  petish_delivery_ops_mark('sql_write_attempts_absent', petish_club_verify_read_only_scope($baseline));
  if ($body_passed) { $report['status'] = 'success'; $report['category'] = 'complete'; }
 } catch (Throwable $ignored) { $report['status'] = 'failure'; }
};
try {
 if (count($argv) !== 2 || !petish_club_verify_run($argv[1])) petish_club_verify_fail();
 $report['category'] = 'source';
 $manifest = petish_club_verify_read_json(__DIR__ . '/source-manifest.json');
 $candidate = getenv('HOME') . '/domains/staging.petish.biz/public_html';
 $source_before = petish_delivery_ops_source($candidate,$manifest);
 $report['source_hashes'] = $manifest;
 petish_delivery_ops_mark('source_manifest_verified', true);
 $root = petish_club_verify_bootstrap('staging',true);
 $baseline = $GLOBALS['petish_club_blocked_write_count'] ?? null;
 $report['category'] = 'snapshot'; $before = petish_club_verify_state($root);
 $shipping_before = [get_option('petish_shipping_mode','quote'),get_option(Petish_Automatic_Shipping::OPTION,['schema_version'=>1,'rules'=>[]]),
  get_option('petish_shipment_ledger_enabled','no'),get_option('petish_shipment_notifications_enabled','no')];
 register_shutdown_function($finalize);
 petish_delivery_ops_contracts($root);
 petish_delivery_ops_admin($root);
 $report['category'] = 'catalogue';
 $fixture = petish_delivery_ops_catalogue();
 $report['observations']['catalogue_scenario_available'] = $fixture !== null;
 if ($fixture !== null) { $report['category'] = 'verification'; petish_delivery_ops_scenarios($fixture); }
 $body_passed = true;
} catch (Throwable $ignored) { $report['status'] = 'failure'; }
// Success is finalized after native shutdown; sanitizer also requires every proof check.
exit($body_passed ? 0 : 1);
