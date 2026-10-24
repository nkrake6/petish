<?php
/** Fixed staging-only FAQ preparation or read-only cancellation application proof. */
if (PHP_SAPI !== 'cli') exit(1);
define('PETISH_CLUB_VERIFY_LIBRARY', true);
require_once __DIR__ . '/verify-pet-club.php';

const PETISH_CANCEL_FAQ_CONTENT = "\n\n<!-- petish-cancellation-faq:v1 -->\n[petish_faqs]\n<!-- /petish-cancellation-faq:v1 -->\n";
const PETISH_CANCEL_DELIVERY_SHA = 'e5c6e2575f72e4bd5c1c9e45fb05dd33f27f816c7db0ae3b63b2805e41b9b065';
const PETISH_CANCEL_LEGACY_DELIVERY_SHA = 'a6be7ca22b83ce301bf3fa0969f4559f3fa539f716ecaf7236472fa06ac9c000';
const PETISH_CANCEL_OBSERVED_STAGING_SHA = '4b6552241b12f4bbafecfed151c0ac177c2a6c9cbabcd785abd9a2bda6be9c10';
const PETISH_CANCEL_FAQ_MARKER = '_petish_cancellation_faq_managed_v1';
const PETISH_CANCEL_FAQ_OWNER = 'petish-cancellation-faq-v1';
const PETISH_CANCEL_CHECKS = [
 'source_manifest_verified','source_unchanged','cancellation_classes_loaded','reviewed_class_paths_verified',
 'request_type_private','customer_hooks_registered','staff_hooks_registered','email_hook_registered','faq_shortcode_registered',
 'feature_flags_disabled','customer_inactive_output_absent','customer_style_not_enqueued','customer_guest_denied',
 'customer_nonce_verified','staff_access_denied','staff_nonce_verified','staff_preview_permission_verified',
 'email_preview_permission_verified','disabled_mutations_rejected','manager_access_verified','staff_queue_rendered',
 'canonical_policy_verified','faq_email_reference_verified','faq_shortcode_rendered','faq_page_verified','faq_marker_verified',
 'cancellation_records_unchanged','settings_options_unchanged','native_memory_cache_verified','sql_write_attempts_absent',
 'faq_section_prepared','faq_transactional_tables','faq_no_table_triggers','faq_checkpoint_saved','delivery_page_only_write_scope_verified','faq_existing_content_safe','faq_no_cache_dropins',
 'theme_source_verified','theme_source_unchanged','header_dropdown_hooks_verified','header_guest_payload_private','header_reviews_url_verified',
 'hero_settings_hooks_verified','hero_default_resolver_verified','hero_guest_settings_denied','hero_option_unchanged',
 'staff_settings_hook_verified','staff_settings_nonce_verified',
 'faq_page_slug_indexed','faq_transaction_isolation_verified',
 'faq_append_plan_verified','faq_prefix_handling_verified',
];
function petish_cancel_mark($key,$valid) {
 global $report;
 if (!in_array($key,PETISH_CANCEL_CHECKS,true) || !is_bool($valid)) petish_club_verify_fail();
 $report['checks'][$key]=$valid;
 if (!$valid) petish_club_verify_fail();
}
function petish_cancel_error($value,$code=null) {
 return is_wp_error($value) && ($code===null || $value->get_error_code()===$code);
}
function petish_cancel_source($root,$manifest) {
 $plugin=$root.'/wp-content/plugins/petish-fulfilment';
 if (!petish_club_verify_path($plugin,true) || !is_array($manifest) || count($manifest)<8 || count($manifest)>128) petish_club_verify_fail();
 foreach (['petish-fulfilment.php','includes/class-petish-cancellation-policy.php','includes/class-petish-order-cancellation-requests.php',
  'includes/class-petish-order-cancellation-customer.php','includes/class-petish-order-cancellation-admin.php',
  'includes/class-petish-order-cancellation-emails.php','assets/order-cancellation.css','assets/cancellation-faq.css'] as $name)
  if (!isset($manifest[$name])) petish_club_verify_fail();
 foreach ($manifest as $name=>$digest) {
  if (!is_string($name) || !preg_match('~\A(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:php|js|css|md|json|svg|png|jpg|jpeg|webp|txt)\z~D',$name)
   || array_intersect(explode('/',$name),['.','..']) || !is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/D',$digest)
   || !petish_club_verify_path($plugin.'/'.$name) || filesize($plugin.'/'.$name)>1048576
   || !hash_equals($digest,hash_file('sha256',$plugin.'/'.$name))) petish_club_verify_fail();
 }
 $files=[];$total=0;
 foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin,FilesystemIterator::SKIP_DOTS)) as $file) {
  $path=$file->getPathname();
  if (!petish_club_verify_path($path) || count($files)>=256 || ($size=filesize($path))>1048576 || ($total+=$size)>12582912) petish_club_verify_fail();
  $files[substr($path,strlen($plugin)+1)]=hash_file('sha256',$path);
 }
 ksort($files);return hash('sha256',json_encode($files,JSON_THROW_ON_ERROR));
}
function petish_cancel_principal($role,$caps) {
 $user=new WP_User(0);$user->roles=$role===null?[]:[$role];$user->caps=$caps;$user->allcaps=$caps;$GLOBALS['current_user']=$user;
}
function petish_cancel_theme_source($root,$manifest) {
 $names=['includes/header-navigation.php','includes/account-dropdown.php','assets/css/account-dropdown.css','assets/js/account-dropdown.js',
  'includes/hero-images.php','assets/js/hero-images-admin.js','functions.php','front-page.php','assets/css/home.css'];
 if (!is_array($manifest)||count($manifest)!==count($names)||array_diff($names,array_keys($manifest))) petish_club_verify_fail();
 $theme=$root.'/wp-content/themes/dt-the7-child';
 foreach ($names as $name) {
  $digest=$manifest[$name];$path=$theme.'/'.$name;
  if (!is_string($digest)||!preg_match('/\A[a-f0-9]{64}\z/D',$digest)||!petish_club_verify_path($path)||filesize($path)>1048576
   ||!hash_equals($digest,hash_file('sha256',$path))) petish_club_verify_fail();
 }
 ksort($manifest);return hash('sha256',json_encode($manifest,JSON_THROW_ON_ERROR));
}
function petish_cancel_header($root) {
 global $wpdb;
 petish_cancel_mark('header_dropdown_hooks_verified',class_exists('Petish_Account_Dropdown',false)
  &&(new ReflectionClass('Petish_Account_Dropdown'))->getFileName()===$root.'/wp-content/themes/dt-the7-child/includes/account-dropdown.php'
  &&has_filter('wp_nav_menu_objects',['Petish_Account_Dropdown','items'])===30
  &&has_action('wp_enqueue_scripts',['Petish_Account_Dropdown','assets'])===46
  &&has_action('wp_ajax_'.Petish_Account_Dropdown::AJAX_ACTION,['Petish_Account_Dropdown','state'])!==false
  &&has_action('wp_ajax_nopriv_'.Petish_Account_Dropdown::AJAX_ACTION,['Petish_Account_Dropdown','state'])!==false);
 $old=$GLOBALS['current_user']??null;
 try {
  petish_cancel_principal(null,[]);$queries=$wpdb->num_queries;
  petish_cancel_mark('header_guest_payload_private',Petish_Account_Dropdown::payload()===['signed_in'=>false,'avatar'=>'','initial'=>'','items'=>[]]
   &&$queries===$wpdb->num_queries);
  $url=wc_get_account_endpoint_url('my-reviews');$query=[];parse_str(wp_parse_url($url,PHP_URL_QUERY)??'',$query);
  petish_cancel_mark('header_reviews_url_verified',is_string($url)&&wp_parse_url($url,PHP_URL_HOST)==='staging.petish.biz'&&($query['my-reviews']??null)==='1');
 } finally {$GLOBALS['current_user']=$old;}
}
function petish_cancel_hero($root) {
 petish_cancel_mark('hero_settings_hooks_verified',class_exists('Petish_Hero_Images',false)
  &&(new ReflectionClass('Petish_Hero_Images'))->getFileName()===$root.'/wp-content/themes/dt-the7-child/includes/hero-images.php'
  &&Petish_Hero_Images::OPTION==='petish_home_hero_images_v1'
  &&has_action('admin_menu',['Petish_Hero_Images','menu'])!==false
  &&has_action('admin_init',['Petish_Hero_Images','register'])!==false
  &&has_action('admin_enqueue_scripts',['Petish_Hero_Images','admin_assets'])!==false);
 $before=get_option(Petish_Hero_Images::OPTION,[]);$old=$GLOBALS['current_user']??null;
 $empty=static fn()=>[];
 add_filter('pre_option_'.Petish_Hero_Images::OPTION,$empty,PHP_INT_MAX);
 try {
  $defaults=[['slug'=>'rabbit','image'=>'rabbit-v6.webp','width'=>2400,'height'=>1597,'name'=>'Rabbits',
   'alt'=>'Process-only default photo','author'=>'Original author','source'=>'https://example.com/default-photo',
   'license_name'=>'Original photo license','license_url'=>'https://example.com/license']];
  petish_cancel_mark('hero_default_resolver_verified',Petish_Hero_Images::resolve($defaults)===$defaults);
  petish_cancel_principal(null,[]);
  petish_cancel_mark('hero_guest_settings_denied',petish_cancel_denied(static fn()=>Petish_Hero_Images::render()));
 } finally {remove_filter('pre_option_'.Petish_Hero_Images::OPTION,$empty,PHP_INT_MAX);$GLOBALS['current_user']=$old;}
 petish_cancel_mark('hero_option_unchanged',$before===get_option(Petish_Hero_Images::OPTION,[]));
}
function petish_cancel_denied($callback) {
 $handler=static fn()=>static function(){throw new RuntimeException('petish_cancel_proof_denied');};
 add_filter('wp_die_handler',$handler,PHP_INT_MAX);
 try {$callback();return false;}
 catch (RuntimeException $error) {return $error->getMessage()==='petish_cancel_proof_denied';}
 finally {remove_filter('wp_die_handler',$handler,PHP_INT_MAX);}
}
function petish_cancel_contracts($root) {
 global $report;
 $report['category']='contracts';
 $classes=['Petish_Cancellation_Policy'=>'class-petish-cancellation-policy.php',
  'Petish_Order_Cancellation_Requests'=>'class-petish-order-cancellation-requests.php',
  'Petish_Order_Cancellation_Customer'=>'class-petish-order-cancellation-customer.php',
  'Petish_Order_Cancellation_Admin'=>'class-petish-order-cancellation-admin.php',
  'Petish_Order_Cancellation_Emails'=>'class-petish-order-cancellation-emails.php'];
 petish_cancel_mark('cancellation_classes_loaded',array_reduce(array_keys($classes),static fn($ok,$name)=>$ok&&class_exists($name,false),true));
 foreach ($classes as $name=>$file) if ((new ReflectionClass($name))->getFileName()!==$root.'/wp-content/plugins/petish-fulfilment/includes/'.$file) petish_club_verify_fail();
 petish_cancel_mark('reviewed_class_paths_verified',true);
 $type=get_post_type_object(Petish_Order_Cancellation_Requests::POST_TYPE);
 petish_cancel_mark('request_type_private',$type && $type->public===false && $type->publicly_queryable===false && $type->show_in_rest===false
  && $type->exclude_from_search===true && $type->show_ui===false && $type->query_var===false);
 petish_cancel_mark('customer_hooks_registered',has_filter('woocommerce_my_account_my_orders_actions',['Petish_Order_Cancellation_Customer','order_actions'])!==false
  && has_action('woocommerce_order_details_after_order_table',['Petish_Order_Cancellation_Customer','render'])===30
  && has_action('admin_post_'.Petish_Order_Cancellation_Customer::POST_ACTION,['Petish_Order_Cancellation_Customer','submit'])!==false);
 petish_cancel_mark('staff_hooks_registered',has_action('admin_menu',['Petish_Order_Cancellation_Admin','menu'])!==false
  && has_action('admin_post_'.Petish_Order_Cancellation_Admin::ACTION,['Petish_Order_Cancellation_Admin','submit'])!==false
  && has_action('admin_post_'.Petish_Order_Cancellation_Admin::PREVIEW_ACTION,['Petish_Order_Cancellation_Admin','preview_email'])!==false
  && has_action('woocommerce_admin_order_data_after_order_details',['Petish_Order_Cancellation_Admin','order_notice'])!==false);
 petish_cancel_mark('staff_settings_hook_verified',has_action('admin_post_'.Petish_Order_Cancellation_Admin::SETTINGS_ACTION,['Petish_Order_Cancellation_Admin','save_settings'])!==false);
 petish_cancel_mark('email_hook_registered',has_action('petish_cancellation_request_event',['Petish_Order_Cancellation_Emails','dispatch_pending'])!==false);
 petish_cancel_mark('faq_shortcode_registered',shortcode_exists('petish_faqs'));
 petish_cancel_mark('feature_flags_disabled',get_option('petish_cancellation_requests_enabled','no')==='no'
  && get_option('petish_cancellation_emails_enabled','no')==='no'
  && !Petish_Order_Cancellation_Requests::enabled() && !Petish_Order_Cancellation_Emails::enabled());
}
function petish_cancel_controls() {
 global $report,$wpdb;
 $report['category']='controls';
 $old_user=$GLOBALS['current_user']??null;$old_get=$_GET;$old_post=$_POST;$old_files=$_FILES;$old_method=$_SERVER['REQUEST_METHOD'];
 try {
  $_GET=[];$_POST=[];$_FILES=[];$_SERVER['REQUEST_METHOD']='GET';
  petish_cancel_principal(null,[]);
  $sentinel=new WC_Order();$sentinel->set_id(PHP_INT_MAX);
  petish_cancel_mark('customer_inactive_output_absent',petish_club_verify_capture(static fn()=>Petish_Order_Cancellation_Customer::render($sentinel))==='');
  Petish_Order_Cancellation_Customer::enqueue_style();
  petish_cancel_mark('customer_style_not_enqueued',!wp_style_is(Petish_Order_Cancellation_Customer::STYLE_HANDLE,'enqueued'));
  petish_cancel_mark('staff_access_denied',!Petish_Order_Cancellation_Admin::authorized()
   && petish_cancel_denied(static fn()=>Petish_Order_Cancellation_Admin::render()));
  petish_cancel_mark('staff_preview_permission_verified',petish_cancel_error(Petish_Order_Cancellation_Admin::preview_request([]),'petish_cancellation_admin_permission'));
  $settings_guest=Petish_Order_Cancellation_Admin::settings_request(['_wpnonce'=>'invalid-process-only-nonce']);
  petish_cancel_mark('email_preview_permission_verified',petish_cancel_error(Petish_Order_Cancellation_Emails::preview(0,str_repeat('a',64),'customer'),'petish_cancellation_email_permission'));
  petish_cancel_mark('disabled_mutations_rejected',petish_cancel_error(Petish_Order_Cancellation_Requests::create(0,[],'Process-only proof'),'petish_cancel_disabled')
   && petish_cancel_error(Petish_Order_Cancellation_Emails::dispatch_pending(0),'petish_cancellation_email_disabled'));
  $enable=static fn()=>'yes';add_filter('pre_option_petish_cancellation_requests_enabled',$enable,PHP_INT_MAX);
  try {
   $queries=$wpdb->num_queries;
   $guest=Petish_Order_Cancellation_Customer::handle_request(['order_id'=>'1','_wpnonce'=>'invalid-process-only-nonce']);
   petish_cancel_mark('customer_guest_denied',petish_cancel_error($guest,'petish_cancel_customer_permission')
    && petish_club_verify_capture(static fn()=>Petish_Order_Cancellation_Customer::render($sentinel))==='' && $wpdb->num_queries===$queries);
   // A process-only user carries an ID for native logged-in checks, never a saved account.
   petish_cancel_principal('customer',['read'=>true]);$GLOBALS['current_user']->ID=PHP_INT_MAX;
   $queries=$wpdb->num_queries;
   petish_cancel_mark('customer_nonce_verified',petish_cancel_error(Petish_Order_Cancellation_Customer::handle_request(['order_id'=>'1','_wpnonce'=>'invalid-process-only-nonce']),'petish_cancel_customer_nonce')
    && $wpdb->num_queries===$queries);
  } finally {remove_filter('pre_option_petish_cancellation_requests_enabled',$enable,PHP_INT_MAX);}
  petish_cancel_principal('shop_manager',['read'=>true,'manage_woocommerce'=>true]);
  petish_cancel_mark('manager_access_verified',Petish_Order_Cancellation_Admin::authorized());
  $queries=$wpdb->num_queries;
  petish_cancel_mark('staff_nonce_verified',petish_cancel_error(Petish_Order_Cancellation_Admin::action_request(['case_id'=>'1','revision'=>'1','operation'=>'reviewing','_wpnonce'=>'invalid-process-only-nonce']),'petish_cancellation_admin_request')
   && petish_cancel_error(Petish_Order_Cancellation_Admin::preview_request(['case_id'=>'1','revision'=>'1','event_id'=>str_repeat('a',64),'audience'=>'customer','_wpnonce'=>'invalid-process-only-nonce']),'petish_cancellation_admin_request')
   && $wpdb->num_queries===$queries);
  $queries=$wpdb->num_queries;
  $_SERVER['REQUEST_METHOD']='POST';
  $invalid_settings=Petish_Order_Cancellation_Admin::settings_request(['_wpnonce'=>'invalid-process-only-nonce','accept_requests'=>'yes','send_emails'=>'yes']);
  $_SERVER['REQUEST_METHOD']='GET';
  petish_cancel_mark('staff_settings_nonce_verified',petish_cancel_error($settings_guest,'petish_cancellation_admin_permission')
   &&petish_cancel_error($invalid_settings,'petish_cancellation_admin_settings')&&$queries===$wpdb->num_queries);
  require_once ABSPATH.'wp-admin/includes/template.php';
  $queue=petish_club_verify_capture(static fn()=>Petish_Order_Cancellation_Admin::render());
  petish_cancel_mark('staff_queue_rendered',is_string($queue)&&strlen($queue)<1048576&&str_contains($queue,'Cancellation requests'));
 } finally {$GLOBALS['current_user']=$old_user;$_GET=$old_get;$_POST=$old_post;$_FILES=$old_files;$_SERVER['REQUEST_METHOD']=$old_method;}
}
function petish_cancel_policy() {
 $policy=Petish_Cancellation_Policy::cancellation_faq_html();
 $text=html_entity_decode(wp_strip_all_tags($policy),ENT_QUOTES,'UTF-8');
 petish_cancel_mark('canonical_policy_verified',is_string($policy)&&strlen($policy)<65536
  && str_contains($text,'14 days') && stripos($text,'unused')!==false && stripos($text,'original packaging')!==false
  && stripos($text,'processing')!==false && stripos($text,'permitted')!==false
  && stripos($text,'Consumer Law')!==false && !str_contains($text,'$10'));
 petish_cancel_mark('faq_email_reference_verified',Petish_Order_Cancellation_Emails::policy_text()===Petish_Cancellation_Policy::fee_text());
 $html=do_shortcode('[petish_faqs]');
 petish_cancel_mark('faq_shortcode_rendered',is_string($html)&&strlen($html)<131072&&str_contains($html,'change-of-mind')
  && stripos($html,'14 days')!==false&&stripos($html,'original packaging')!==false);
}
function petish_cancel_case_state() {
 global $wpdb;
 return [petish_club_verify_table_state($wpdb->posts," WHERE post_type='petish_cancel_req'"),
  petish_club_verify_table_state($wpdb->postmeta," WHERE post_id IN (SELECT ID FROM `{$wpdb->posts}` WHERE post_type='petish_cancel_req')")];
}
function petish_cancel_options() {return [get_option('petish_cancellation_requests_enabled','no'),get_option('petish_cancellation_emails_enabled','no')];}
function petish_cancel_faq_base() {
 $path=__DIR__.'/delivery-page-content.html';
 if (!petish_club_verify_path($path)||filesize($path)>32768||!hash_equals(PETISH_CANCEL_DELIVERY_SHA,hash_file('sha256',$path))) petish_club_verify_fail();
 $value=file_get_contents($path);if (!is_string($value)||$value==='') petish_club_verify_fail();return $value;
}
function petish_cancel_faq_rows($for_update=false) {
 global $wpdb;
 $rows=$wpdb->get_results("SELECT * FROM `{$wpdb->posts}`".($for_update?' FORCE INDEX (`post_name`)':'')
  ." WHERE post_name='delivery-shipping' AND post_type='page' ORDER BY ID".($for_update?' FOR UPDATE':''),ARRAY_A);
 if (!is_array($rows)||$wpdb->last_error!=='') petish_club_verify_fail();return $rows;
}
function petish_cancel_delivery_valid($row) {
 return is_array($row)&&(int)$row['ID']>0&&$row['post_type']==='page'&&$row['post_status']==='publish'&&$row['post_name']==='delivery-shipping'
  &&is_string($row['post_content'])&&$row['post_password']===''&&(int)$row['post_parent']===0;
}
/** Pure append plan. Production callers use the fixed observed hash; no CLI input overrides it. */
function petish_cancel_faq_plan($current,$reviewed,$observed_sha=PETISH_CANCEL_OBSERVED_STAGING_SHA) {
 if (!is_string($current)||!is_string($reviewed)||!is_string($observed_sha)||!preg_match('/\A[a-f0-9]{64}\z/D',$observed_sha)
  ||hash('sha256',$reviewed)!==PETISH_CANCEL_DELIVERY_SHA
  ||str_contains($current,'[petish_faqs]')||str_contains($current,'petish-cancellation-faq:v1')) return false;
 $hash=hash('sha256',$current);$aligned=$hash===PETISH_CANCEL_LEGACY_DELIVERY_SHA;
 if (!$aligned&&!in_array($hash,[PETISH_CANCEL_DELIVERY_SHA,$observed_sha],true)) return false;
 $prefix=$aligned?$reviewed:$current;
 return ['content'=>$prefix.PETISH_CANCEL_FAQ_CONTENT,'prefix_sha256'=>hash('sha256',$prefix),'aligned'=>$aligned];
}
/** Validate exactly one owned suffix while binding it to the preserved known prefix. */
function petish_cancel_faq_prefix($content,$observed_sha=PETISH_CANCEL_OBSERVED_STAGING_SHA) {
 if (!is_string($content)||!is_string($observed_sha)||!preg_match('/\A[a-f0-9]{64}\z/D',$observed_sha)
  ||!str_ends_with($content,PETISH_CANCEL_FAQ_CONTENT)) return false;
 $prefix=substr($content,0,-strlen(PETISH_CANCEL_FAQ_CONTENT));$hash=hash('sha256',$prefix);
 if (!in_array($hash,[PETISH_CANCEL_DELIVERY_SHA,$observed_sha],true)
  ||str_contains($prefix,'[petish_faqs]')||str_contains($prefix,'petish-cancellation-faq:v1')) return false;
 return ['content'=>$prefix,'sha256'=>$hash];
}
function petish_cancel_faq_valid($row) {
 return petish_cancel_delivery_valid($row)&&petish_cancel_faq_prefix($row['post_content'])!==false;
}
function petish_cancel_faq_owner($content,$observed_sha=PETISH_CANCEL_OBSERVED_STAGING_SHA) {
 $prefix=petish_cancel_faq_prefix($content,$observed_sha);
 return $prefix===false?false:PETISH_CANCEL_FAQ_OWNER.'|'.$prefix['sha256'];
}
function petish_cancel_faq_markers($id,$for_update=false) {
 global $wpdb;
 $values=$wpdb->get_col($wpdb->prepare("SELECT meta_value FROM `{$wpdb->postmeta}` WHERE post_id=%d AND meta_key=%s ORDER BY meta_id".($for_update?' FOR UPDATE':''),$id,PETISH_CANCEL_FAQ_MARKER));
 if (!is_array($values)||$wpdb->last_error!=='') petish_club_verify_fail();return $values;
}
function petish_cancel_faq_marker($id,$content=null) {
 global $wpdb;
 if ($content===null) $content=$wpdb->get_var($wpdb->prepare("SELECT post_content FROM `{$wpdb->posts}` WHERE ID=%d AND post_type='page'",$id));
 $owner=petish_cancel_faq_owner($content);
 return $owner!==false&&petish_cancel_faq_markers($id)===[$owner];
}
function petish_cancel_inspect_delivery($rows,$for_update=false) {
 global $report;
 $class='unchecked';$hash=null;$present=count($rows)>0;$unique=count($rows)===1;$structure=false;
 if (!$rows) $class='absent';
 elseif (!$unique) $class='duplicate';
 else {
  $row=$rows[0];$structure=petish_cancel_delivery_valid($row);
  if (is_string($row['post_content']??null)) $hash=hash('sha256',$row['post_content']);
  if (!$structure) $class='structure_unknown';
  elseif (petish_cancel_faq_markers((int)$row['ID'],$for_update)) $class=petish_cancel_faq_valid($row)&&petish_cancel_faq_marker((int)$row['ID'],$row['post_content'])?'owned':'marker_unknown';
  elseif ($hash===PETISH_CANCEL_DELIVERY_SHA) $class='current';
  elseif ($hash===PETISH_CANCEL_LEGACY_DELIVERY_SHA) $class='legacy';
  elseif ($hash===PETISH_CANCEL_OBSERVED_STAGING_SHA) $class='observed_staging_copy';
  else $class='manual_content';
 }
 $report['delivery_page']=['classification'=>$class,'content_sha256'=>$hash];
 foreach (['delivery_page_present'=>$present,'delivery_page_unique'=>$unique,'delivery_page_structure_known'=>$structure,
  'delivery_copy_current'=>$class==='current','delivery_copy_legacy'=>$class==='legacy','delivery_copy_observed'=>$class==='observed_staging_copy'] as $name=>$value) $report['observations'][$name]=$value;
 return $class;
}
function petish_cancel_faq_verify() {
 $rows=petish_cancel_faq_rows();petish_cancel_mark('faq_page_verified',count($rows)===1&&petish_cancel_faq_valid($rows[0]));
 petish_cancel_mark('faq_marker_verified',petish_cancel_faq_marker((int)$rows[0]['ID'],$rows[0]['post_content']));
}
/** Exact prepared-statement grants; every unrelated write is rejected. */
function petish_cancel_prepare_guard($sql) {
 if (!is_string($sql)) petish_club_verify_fail();
 if (($GLOBALS['petish_cancel_next_sql']??null)===$sql) {$GLOBALS['petish_cancel_next_sql']=null;return $sql;}
 $probe=preg_replace('~\A(?:\s+|/\*.*?\*/|--[^\r\n]*(?:\r?\n|$)|\#[^\r\n]*(?:\r?\n|$))*~s','',$sql);
 if (preg_match('/\A(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i',$probe)&&!preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i',$probe)&&!preg_match('/;\s*\S/s',rtrim($probe))) return $sql;
 $GLOBALS['petish_cancel_blocked']=($GLOBALS['petish_cancel_blocked']??0)+1;
 if (!empty($GLOBALS['petish_cancel_strict'])) petish_club_verify_fail();return 'SELECT 0 WHERE 1=0';
}
function petish_cancel_once($sql) {
 global $wpdb;
 if (($GLOBALS['petish_cancel_next_sql']??null)!==null) petish_club_verify_fail();$GLOBALS['petish_cancel_next_sql']=$sql;
 $result=$wpdb->query($sql);if ($result===false||$wpdb->last_error!==''||($GLOBALS['petish_cancel_next_sql']??null)!==null) petish_club_verify_fail();return $result;
}
function petish_cancel_prepare_bootstrap() {
 $file=getenv('HOME').'/domains/staging.petish.biz/public_html/wp-includes/plugin.php';
 if (!petish_club_verify_path($file)) petish_club_verify_fail();require_once $file;
 add_filter('query','petish_cancel_prepare_guard',PHP_INT_MAX);
 add_action('all',static function($hook){if($hook==='query'){remove_filter('query','petish_cancel_prepare_guard',PHP_INT_MAX);add_filter('query','petish_cancel_prepare_guard',PHP_INT_MAX);}},PHP_INT_MAX);
 $root=petish_club_verify_bootstrap('staging',false);
 petish_cancel_mark('faq_no_cache_dropins',!file_exists($root.'/wp-content/object-cache.php')&&!file_exists($root.'/wp-content/advanced-cache.php'));
 return $root;
}
function petish_cancel_other_posts($omit=0) {
 global $wpdb;$id=(int)$omit;
 return [petish_club_verify_table_state($wpdb->posts,' WHERE ID<>'.$id),petish_club_verify_table_state($wpdb->postmeta,' WHERE post_id<>'.$id)];
}
function petish_cancel_checkpoint($run,$value) {
 $dir=getenv('HOME').'/.petish-cancellation-faq-checkpoints';
 if (!file_exists($dir)&&!mkdir($dir,0700)) petish_club_verify_fail();
 if (!petish_club_verify_path($dir,true)||(fileperms($dir)&07777)!==0700) petish_club_verify_fail();
 $path=$dir.'/'.$run.'.json';petish_club_verify_write_json($path,$value);
 $handle=fopen($path,'r+b');if (!$handle) petish_club_verify_fail();
 try {if(!fsync($handle))petish_club_verify_fail();} finally {fclose($handle);}
 if (petish_club_verify_read_json($path)!==$value) petish_club_verify_fail();petish_cancel_mark('faq_checkpoint_saved',true);
}
function petish_cancel_native_insert($table,$data,$formats) {
 global $wpdb;
 $columns='`'.implode('`, `',array_keys($data)).'`';
 $sql=$wpdb->prepare('INSERT INTO `'.$table.'` ('.$columns.') VALUES ('.implode(', ',$formats).')',array_values($data));
 $GLOBALS['petish_cancel_next_sql']=$sql;
 $result=$wpdb->insert($table,$data,$formats);
 if ($result!==1||$wpdb->last_error!==''||($GLOBALS['petish_cancel_next_sql']??null)!==null) petish_club_verify_fail();return (int)$wpdb->insert_id;
}
function petish_cancel_prepare($run) {
 global $wpdb,$report;
 foreach ([$wpdb->posts,$wpdb->postmeta] as $table) {
  if (!preg_match('/\A[A-Za-z0-9_]+\z/D',$table)) petish_club_verify_fail();
  $row=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$wpdb->esc_like($table)),ARRAY_A);
  petish_cancel_mark('faq_transactional_tables',is_array($row)&&strtoupper($row['Engine']??'')==='INNODB');
  $triggers=$wpdb->get_results($wpdb->prepare('SHOW TRIGGERS WHERE `Table`=%s',$table),ARRAY_A);
  petish_cancel_mark('faq_no_table_triggers',$wpdb->last_error===''&&$triggers===[]);
 }
 $indexes=$wpdb->get_results("SHOW INDEX FROM `{$wpdb->posts}` WHERE Key_name='post_name'",ARRAY_A);
 petish_cancel_mark('faq_page_slug_indexed',is_array($indexes)&&count($indexes)>0&&$wpdb->last_error==='');
 $lock='petish-faq-'.substr(hash('sha256',DB_NAME.'|delivery-shipping'),0,45);
 if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$lock))!=='1') petish_club_verify_fail();
 $transaction=false;$appended=false;$aligned=false;$created=false;
 try {
  $GLOBALS['petish_cancel_strict']=true;
  petish_cancel_once('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
  petish_cancel_once('START TRANSACTION');$transaction=true;
  petish_cancel_mark('faq_transaction_isolation_verified',true);
  $rows=petish_cancel_faq_rows(true);$classification=petish_cancel_inspect_delivery($rows,true);
  petish_cancel_mark('faq_existing_content_safe',in_array($classification,['absent','current','legacy','observed_staging_copy','owned'],true));
  $base=petish_cancel_faq_base();$before_row=null;$after_row=null;$markers=[];$page=0;$prefix_sha=PETISH_CANCEL_DELIVERY_SHA;$expected_content=$base.PETISH_CANCEL_FAQ_CONTENT;
  if ($classification==='absent') {
   // A counterpart of the existing live delivery page; never a separate /faqs/ page.
   $before=petish_cancel_other_posts();$date=current_time('mysql');$gmt=current_time('mysql',true);
   $data=['post_author'=>0,'post_date'=>$date,'post_date_gmt'=>$gmt,'post_content'=>$base.PETISH_CANCEL_FAQ_CONTENT,'post_title'=>'Delivery & shipping','post_excerpt'=>'',
    'post_status'=>'publish','comment_status'=>'closed','ping_status'=>'closed','post_password'=>'','post_name'=>'delivery-shipping','to_ping'=>'','pinged'=>'',
    'post_modified'=>$date,'post_modified_gmt'=>$gmt,'post_content_filtered'=>'','post_parent'=>0,'guid'=>'','menu_order'=>0,'post_type'=>'page','post_mime_type'=>'','comment_count'=>0];
   $formats=array_map(static fn($value)=>is_int($value)?'%d':'%s',$data);
   $page=petish_cancel_native_insert($wpdb->posts,$data,$formats);
   if ($page<1) petish_club_verify_fail();
   $guid=home_url('/?page_id='.$page);$sql=$wpdb->prepare('UPDATE `'.$wpdb->posts.'` SET `guid` = %s WHERE `ID` = %d',$guid,$page);
   $GLOBALS['petish_cancel_next_sql']=$sql;
   if ($wpdb->update($wpdb->posts,['guid'=>$guid],['ID'=>$page],['%s'],['%d'])!==1||$wpdb->last_error!==''||($GLOBALS['petish_cancel_next_sql']??null)!==null) petish_club_verify_fail();
   $created=true;$appended=true;
  } else {
   $before_row=$rows[0];$page=(int)$before_row['ID'];$before=petish_cancel_other_posts($page);$markers=petish_cancel_faq_markers($page);
   if ($classification!=='owned') {
    $plan=petish_cancel_faq_plan($before_row['post_content'],$base);
    petish_cancel_mark('faq_append_plan_verified',is_array($plan));
    $aligned=$plan['aligned'];$prefix_sha=$plan['prefix_sha256'];$expected_content=$plan['content'];
    $data=['post_content'=>$expected_content,'post_modified'=>current_time('mysql'),'post_modified_gmt'=>current_time('mysql',true)];
    $sql=$wpdb->prepare('UPDATE `'.$wpdb->posts.'` SET `post_content` = %s, `post_modified` = %s, `post_modified_gmt` = %s WHERE `ID` = %d',[$data['post_content'],$data['post_modified'],$data['post_modified_gmt'],$page]);
    $GLOBALS['petish_cancel_next_sql']=$sql;
    if ($wpdb->update($wpdb->posts,$data,['ID'=>$page],['%s','%s','%s'],['%d'])!==1||$wpdb->last_error!==''||($GLOBALS['petish_cancel_next_sql']??null)!==null) petish_club_verify_fail();
    $appended=true;
   } else {$prefix=petish_cancel_faq_prefix($before_row['post_content']);$prefix_sha=$prefix['sha256'];$expected_content=$before_row['post_content'];}
  }
  petish_cancel_mark('faq_append_plan_verified',true);
  if ($appended) petish_cancel_native_insert($wpdb->postmeta,['post_id'=>$page,'meta_key'=>PETISH_CANCEL_FAQ_MARKER,'meta_value'=>PETISH_CANCEL_FAQ_OWNER.'|'.$prefix_sha],['%d','%s','%s']);
  petish_cancel_faq_verify();$after_rows=petish_cancel_faq_rows();$after_row=$after_rows[0];
  petish_cancel_mark('faq_prefix_handling_verified',$after_row['post_content']===$expected_content);
  petish_cancel_mark('delivery_page_only_write_scope_verified',$before===petish_cancel_other_posts($page));
  petish_cancel_checkpoint($run,['schema_version'=>1,'target'=>'staging','page_id'=>$page,'appended'=>$appended,'delivery_counterpart_created'=>$created,'delivery_copy_aligned'=>$aligned,
   'before'=>$before_row,'after'=>$after_row,'before_markers'=>$markers,'after_marker'=>PETISH_CANCEL_FAQ_OWNER.'|'.$prefix_sha]);
  petish_cancel_once('COMMIT');$transaction=false;
  $report['observations']['faq_appended']=$appended;$report['observations']['faq_already_owned']=!$appended;
  $report['observations']['delivery_copy_aligned']=$aligned;$report['observations']['delivery_counterpart_created']=$created;
  petish_cancel_mark('faq_section_prepared',true);petish_cancel_faq_verify();
 } finally {
  $GLOBALS['petish_cancel_next_sql']=null;
  if ($transaction) petish_cancel_once('ROLLBACK');
  $GLOBALS['petish_cancel_strict']=false;
  if ((string)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock))!=='1') petish_club_verify_fail();
 }
}

if (defined('PETISH_CANCELLATION_VERIFY_LIBRARY')) return;
petish_club_verify_report_start();
$report+=['schema_version'=>1,'target'=>'staging','mode'=>$argv[2]??'invalid','source_hashes'=>[],'theme_source_hashes'=>[],'delivery_page'=>['classification'=>'unchecked','content_sha256'=>null],
 'observations'=>['hosted_case_mutations_not_exercised'=>true,'external_supplier_cancellation_not_exercised'=>true,
  'gateway_refunds_not_exercised'=>true,'customer_email_body_not_exercised'=>true,'header_authenticated_payload_not_exercised'=>true,'faq_appended'=>false,'faq_already_owned'=>false,'delivery_copy_aligned'=>false,'delivery_counterpart_created'=>false,'hero_attachment_selection_not_exercised'=>true,
  'delivery_page_present'=>false,'delivery_page_unique'=>false,'delivery_page_structure_known'=>false,'delivery_copy_current'=>false,'delivery_copy_legacy'=>false,'delivery_copy_observed'=>false]];
$body_passed=false;$before=null;$root=null;$manifest=null;$source_before=null;$cases_before=null;$options_before=null;$baseline=null;$mode=$argv[2]??'';$theme_manifest=null;$theme_before=null;
$finalize=static function()use(&$body_passed,&$before,&$root,&$manifest,&$source_before,&$cases_before,&$options_before,&$baseline,&$mode,&$report,&$theme_manifest,&$theme_before){
 $body_category=$report['category'];
 try {
  $report['category']='snapshot';petish_club_verify_state_equal($before,petish_club_verify_state($root));
  petish_cancel_mark('cancellation_records_unchanged',$cases_before===petish_cancel_case_state());
  petish_cancel_mark('settings_options_unchanged',$options_before===petish_cancel_options());
  petish_cancel_mark('source_unchanged',hash_equals($source_before,petish_cancel_source($root,$manifest)));
  petish_cancel_mark('theme_source_unchanged',hash_equals($theme_before,petish_cancel_theme_source($root,$theme_manifest)));
  petish_cancel_mark('native_memory_cache_verified',petish_club_verify_runtime_cache($root));
  if ($mode==='verify') petish_cancel_mark('sql_write_attempts_absent',petish_club_verify_read_only_scope($baseline));
  if ($body_passed) {$report['status']='success';$report['category']='complete';}
  else {$report['category']=$body_category;}
 } catch (Throwable $ignored) {$report['status']='failure';}
};
try {
 if (count($argv)!==3||!petish_club_verify_run($argv[1])||!in_array($mode,['prepare','verify'],true)) petish_club_verify_fail();
 $report['category']='source';$manifest=petish_club_verify_read_json(__DIR__.'/source-manifest.json');$theme_manifest=petish_club_verify_read_json(__DIR__.'/theme-manifest.json');
 $candidate=getenv('HOME').'/domains/staging.petish.biz/public_html';$source_before=petish_cancel_source($candidate,$manifest);
 $report['source_hashes']=$manifest;petish_cancel_mark('source_manifest_verified',true);
 $theme_before=petish_cancel_theme_source($candidate,$theme_manifest);$report['theme_source_hashes']=$theme_manifest;petish_cancel_mark('theme_source_verified',true);
 $root=$mode==='prepare'?petish_cancel_prepare_bootstrap():petish_club_verify_bootstrap('staging',true);
 $baseline=$GLOBALS['petish_club_blocked_write_count']??null;
 $report['category']='snapshot';$before=petish_club_verify_state($root);$cases_before=petish_cancel_case_state();$options_before=petish_cancel_options();
 register_shutdown_function($finalize);
 petish_cancel_contracts($root);petish_cancel_policy();petish_cancel_header($root);petish_cancel_hero($root);
 if ($mode==='prepare') {$report['category']='prepare';petish_cancel_prepare($argv[1]);}
 else {petish_cancel_controls();$report['category']='faq';petish_cancel_inspect_delivery(petish_cancel_faq_rows());petish_cancel_faq_verify();$report['observations']['faq_already_owned']=true;}
 $body_passed=true;
} catch (Throwable $ignored) {$report['status']='failure';}
exit($body_passed?0:1);
