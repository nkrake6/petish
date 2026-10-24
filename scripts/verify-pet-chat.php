<?php
/** The only persistent write permitted here is the fixed staging chat option. */
if (PHP_SAPI !== 'cli') exit(1);
define('PETISH_CLUB_VERIFY_LIBRARY', true);
require_once __DIR__ . '/verify-pet-club.php';

const PETISH_CHAT_PROOF_OPTION = 'petish_chat_enabled_v1';
const PETISH_CHAT_PROOF_SOURCE = [
 'petish-passport.php','includes/class-petish-chat.php','includes/class-petish-chat-ui.php',
 'templates/pet-chat.php','assets/pet-chat.css','assets/pet-chat.js',
];
const PETISH_CHAT_PROOF_CHECKS = [
 'native_memory_cache_verified','source_manifest_verified','source_unchanged','chat_classes_loaded',
 'reviewed_class_paths_verified','private_topic_registration_verified','chat_defaults_verified',
 'ui_routes_registered','mutation_hooks_registered','public_reader_verified','foreign_pending_reader_denied',
 'guest_mutations_denied','request_method_and_nonce_verified','synthetic_public_render_verified',
 'chat_assets_scoped','flag_enabled','single_flag_write_scope_verified','flag_transactional_table_verified',
 'flag_no_table_triggers','flag_transaction_committed','chat_records_unchanged',
 'settings_except_chat_flag_unchanged','existing_feature_flags_unchanged','sql_write_attempts_absent',
];
function petish_chat_proof_mark($name,$value) {
 global $report;
 if (!in_array($name,PETISH_CHAT_PROOF_CHECKS,true)||!is_bool($value)) petish_club_verify_fail();
 $report['checks'][$name]=$value;
 if (!$value) petish_club_verify_fail();
}
function petish_chat_proof_source($root,$manifest) {
 $plugin=$root.'/wp-content/plugins/petish-passport';
 if (!petish_club_verify_path($plugin,true)||!is_array($manifest)||count($manifest)>128
  ||array_diff(PETISH_CHAT_PROOF_SOURCE,array_keys($manifest))) petish_club_verify_fail();
 $actual=[];$total=0;
 foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin,FilesystemIterator::SKIP_DOTS)) as $file) {
  $path=$file->getPathname();$name=substr($path,strlen($plugin)+1);
  if (!petish_club_verify_path($path)||!preg_match('~\A(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:php|js|css|md|json|svg|png|jpg|jpeg|webp|txt)\z~D',$name)
   ||array_intersect(explode('/',$name),['.','..'])||count($actual)>=128
   ||filesize($path)<1||filesize($path)>1048576||($total+=filesize($path))>8388608) petish_club_verify_fail();
  $actual[$name]=hash_file('sha256',$path);
 }
 ksort($actual);ksort($manifest);
 foreach ($manifest as $name=>$digest)
  if (!is_string($name)||!is_string($digest)||!preg_match('/\A[a-f0-9]{64}\z/D',$digest)) petish_club_verify_fail();
 if ($manifest!==$actual) petish_club_verify_fail();
 return hash('sha256',json_encode($actual,JSON_THROW_ON_ERROR));
}
/** A grant is consumed once and matches the complete prepared statement. */
function petish_chat_proof_query_guard($sql) {
 if (!is_string($sql)) petish_club_verify_fail();
 if (($GLOBALS['petish_chat_proof_next_sql']??null)===$sql) {
  $GLOBALS['petish_chat_proof_next_sql']=null;
  return $sql;
 }
 $probe=preg_replace('~\A(?:\s+|/\*.*?\*/|--[^\r\n]*(?:\r?\n|$)|\#[^\r\n]*(?:\r?\n|$))*~s','',$sql);
 if (preg_match('/\A(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i',$probe)
  &&!preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i',$probe)&&!preg_match('/;\s*\S/s',rtrim($probe))) return $sql;
 $GLOBALS['petish_chat_proof_blocked']=($GLOBALS['petish_chat_proof_blocked']??0)+1;
 if (!empty($GLOBALS['petish_chat_proof_strict'])) petish_club_verify_fail();
 return 'SELECT 0 WHERE 1=0';
}
function petish_chat_proof_once($sql) {
 global $wpdb;
 if (!is_string($sql)||($GLOBALS['petish_chat_proof_next_sql']??null)!==null) petish_club_verify_fail();
 $GLOBALS['petish_chat_proof_next_sql']=$sql;
 $result=$wpdb->query($sql);
 if ($result===false||$wpdb->last_error!==''||($GLOBALS['petish_chat_proof_next_sql']??null)!==null) petish_club_verify_fail();
 return $result;
}
function petish_chat_proof_bootstrap() {
 $file=getenv('HOME').'/domains/staging.petish.biz/public_html/wp-includes/plugin.php';
 if (!petish_club_verify_path($file)) petish_club_verify_fail();
 require_once $file;
 add_filter('query','petish_chat_proof_query_guard',PHP_INT_MAX);
 add_action('all',static function($hook) {
  if ($hook==='query') {
   remove_filter('query','petish_chat_proof_query_guard',PHP_INT_MAX);
   add_filter('query','petish_chat_proof_query_guard',PHP_INT_MAX);
  }
 },PHP_INT_MAX);
 return petish_club_verify_bootstrap('staging',false);
}
function petish_chat_proof_option_rows() {
 global $wpdb;
 if (!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->options??'')) petish_club_verify_fail();
 return petish_club_verify_table_state($wpdb->options,$wpdb->prepare(' WHERE option_name<>%s',PETISH_CHAT_PROOF_OPTION));
}
function petish_chat_proof_flag_row($locked=false) {
 global $wpdb;
 $rows=$wpdb->get_results($wpdb->prepare('SELECT option_id,option_name,option_value,autoload FROM `'.$wpdb->options.'` WHERE option_name=%s',PETISH_CHAT_PROOF_OPTION).($locked?' FOR UPDATE':''),ARRAY_A);
 if ($wpdb->last_error!==''||!is_array($rows)||count($rows)>1) petish_club_verify_fail();
 if (!$rows) return null;
 $row=$rows[0];
 if (array_keys($row)!==['option_id','option_name','option_value','autoload']
  ||!preg_match('/\A[1-9][0-9]*\z/D',(string)$row['option_id'])||$row['option_name']!==PETISH_CHAT_PROOF_OPTION
  ||!in_array($row['option_value'],['no','yes'],true)||!in_array($row['autoload'],['yes','no','on','off','auto','auto-on','auto-off'],true)) petish_club_verify_fail();
 return $row;
}
function petish_chat_proof_records() {
 global $wpdb;
 $state=[];
 foreach (['posts','postmeta','comments','commentmeta','terms','termmeta','term_taxonomy','term_relationships'] as $key) {
  if (!is_string($wpdb->$key??null)) petish_club_verify_fail();
  $state[$key]=petish_club_verify_table_state($wpdb->$key);
 }
 return $state;
}
function petish_chat_proof_feature_flags() {
 $result=[];
 foreach (['petish_chat_moderation_v1','petish_customer_reviews_enabled','petish_cancellation_requests_enabled',
  'petish_cancellation_emails_enabled','petish_passport_schema_version'] as $key) $result[$key]=get_option($key,null);
 return $result;
}
function petish_chat_proof_principal($id=0) {
 $user=new WP_User(0);
 $user->ID=$id;$user->roles=$id?['customer']:[];$user->caps=$id?['read'=>true]:[];$user->allcaps=$user->caps;
 $GLOBALS['current_user']=$user;
}
function petish_chat_proof_transaction_start() {
 global $wpdb;
 if (!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->options??'')) petish_club_verify_fail();
 $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',DB_NAME,$wpdb->options));
 petish_chat_proof_mark('flag_transactional_table_verified',$wpdb->last_error===''&&$engine==='InnoDB');
 $triggers=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=%s AND EVENT_OBJECT_TABLE=%s',DB_NAME,$wpdb->options));
 petish_chat_proof_mark('flag_no_table_triggers',$wpdb->last_error===''&&(string)$triggers==='0');
 $GLOBALS['petish_chat_proof_strict']=true;
 petish_chat_proof_once('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
 petish_chat_proof_once('START TRANSACTION');
 $GLOBALS['petish_chat_proof_transaction']=true;
}
function petish_chat_proof_enable() {
 global $wpdb;
 if (empty($GLOBALS['petish_chat_proof_transaction'])||!defined('PETISH_STAGING_GUARD_ACTIVE')||PETISH_STAGING_GUARD_ACTIVE!==true
  ||wp_get_environment_type()!=='staging'||parse_url(home_url('/'),PHP_URL_HOST)!=='staging.petish.biz'
  ||Petish_Chat::OPTION_ENABLED!==PETISH_CHAT_PROOF_OPTION) petish_club_verify_fail();
 $before=petish_chat_proof_flag_row(true);
 if ($before===null) {
  $sql=$wpdb->prepare('INSERT INTO `'.$wpdb->options.'` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, %s)',PETISH_CHAT_PROOF_OPTION,'yes','off');
  if (petish_chat_proof_once($sql)!==1) petish_club_verify_fail();
 } elseif ($before['option_value']!=='yes') {
  $sql=$wpdb->prepare('UPDATE `'.$wpdb->options.'` SET `option_value` = %s WHERE `option_id` = %d AND `option_name` = %s AND `option_value` = %s','yes',(int)$before['option_id'],PETISH_CHAT_PROOF_OPTION,'no');
  if (petish_chat_proof_once($sql)!==1) petish_club_verify_fail();
 }
 wp_cache_delete(PETISH_CHAT_PROOF_OPTION,'options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');
 $after=petish_chat_proof_flag_row();
 petish_chat_proof_mark('flag_enabled',$after!==null&&$after['option_value']==='yes'&&Petish_Chat::enabled());
 petish_chat_proof_mark('single_flag_write_scope_verified',$before===null?($after['autoload']==='off'):
  $after===array_replace($before,['option_value'=>'yes']));
}
function petish_chat_proof_error($value,$code=null) {
 return is_wp_error($value)&&($code===null||$value->get_error_code()===$code);
}
function petish_chat_proof_public_items($listing) {
 $keys=['id','channel','kind','title','body','alias','created_gmt','updated_gmt','status','closed','accepted_answer',
  'replies_count','is_owner','can_reply','can_close','can_accept','can_report','revision'];
 if (!is_array($listing)||array_keys($listing)!==['items','page','pages','total']||!is_array($listing['items'])
  ||count($listing['items'])>Petish_Chat::PAGE_SIZE) return false;
 foreach ($listing['items'] as $item) {
  if (!is_array($item)||array_diff(array_keys($item),$keys)||array_diff($keys,array_keys($item))
   ||$item['status']!=='published'||$item['is_owner']!==false||$item['can_reply']!==false
   ||$item['can_close']!==false||$item['can_accept']!==false||$item['can_report']!==false) return false;
 }
 return true;
}
function petish_chat_proof_contracts($root) {
 global $wpdb,$wp_styles,$wp_scripts;
 petish_chat_proof_mark('chat_classes_loaded',class_exists('Petish_Chat',false)&&class_exists('Petish_Chat_UI',false));
 $base=$root.'/wp-content/plugins/petish-passport/';
 petish_chat_proof_mark('reviewed_class_paths_verified',(new ReflectionClass('Petish_Chat'))->getFileName()===$base.'includes/class-petish-chat.php'
  &&(new ReflectionClass('Petish_Chat_UI'))->getFileName()===$base.'includes/class-petish-chat-ui.php');
 $default_off=static fn()=>'no';add_filter('pre_option_'.PETISH_CHAT_PROOF_OPTION,$default_off,PHP_INT_MAX);
 try {$off=!Petish_Chat::enabled();} finally {remove_filter('pre_option_'.PETISH_CHAT_PROOF_OPTION,$default_off,PHP_INT_MAX);}
 petish_chat_proof_mark('chat_defaults_verified',$off&&Petish_Chat::OPTION_ENABLED===PETISH_CHAT_PROOF_OPTION
  &&Petish_Chat::OPTION_MODERATION==='petish_chat_moderation_v1'
  &&in_array(get_option(Petish_Chat::OPTION_MODERATION,'first_review'),['first_review','all_review'],true)
  &&Petish_Chat::TYPE==='petish_chat_topic'&&Petish_Chat::COMMENT_TYPE==='petish_chat');
 Petish_Chat::register_types();$type=get_post_type_object(Petish_Chat::TYPE);
 petish_chat_proof_mark('private_topic_registration_verified',(bool)($type&&!$type->public&&!$type->publicly_queryable
  &&!$type->show_in_rest&&!$type->has_archive&&!$type->rewrite&&!$type->query_var&&$type->exclude_from_search
  &&!$type->show_in_nav_menus&&!$type->show_in_admin_bar));
 petish_chat_proof_mark('ui_routes_registered',has_action('init',['Petish_Chat','register_types'])!==false
  &&has_action('template_redirect',['Petish_Chat_UI','route'])===8
  &&has_action('wp_enqueue_scripts',['Petish_Chat_UI','assets'])===55
  &&has_action('wp_ajax_petish_chat_refresh',['Petish_Chat_UI','refresh'])!==false
  &&has_action('wp_ajax_nopriv_petish_chat_refresh',['Petish_Chat_UI','refresh'])!==false);
 $hooks=Petish_Chat_UI::OPERATIONS===['create_topic','create_reply','accept_answer','set_closed','report','moderate'];
 foreach (Petish_Chat_UI::OPERATIONS as $operation)
  $hooks=$hooks&&has_action('admin_post_petish_chat_'.$operation)!==false&&has_action('admin_post_nopriv_petish_chat_'.$operation)!==false;
 petish_chat_proof_mark('mutation_hooks_registered',$hooks);
 $user=$GLOBALS['current_user']??null;$server=$_SERVER;$get=$_GET;$styles=$wp_styles??null;$scripts=$wp_scripts??null;
 try {
  petish_chat_proof_principal();$_SERVER['REQUEST_METHOD']='GET';unset($_GET['petish_chat']);
  $listing=Petish_Chat::threads([],0);$forged=Petish_Chat::threads([],PHP_INT_MAX);
  petish_chat_proof_mark('public_reader_verified',petish_chat_proof_public_items($listing)&&$listing===$forged
   &&Petish_Chat::identity(PHP_INT_MAX)===['alias'=>'','can_post'=>false]);
  $pending=$wpdb->get_col($wpdb->prepare('SELECT ID FROM `'.$wpdb->posts.'` WHERE post_type=%s AND post_status=%s ORDER BY ID LIMIT 20',Petish_Chat::TYPE,'pending'));
  if ($wpdb->last_error!==''||!is_array($pending)||count($pending)>20) petish_club_verify_fail();
  $private=petish_chat_proof_error(Petish_Chat::thread(0,PHP_INT_MAX),'petish_chat_missing');
  foreach ($pending as $id) $private=$private&&petish_chat_proof_error(Petish_Chat::thread((int)$id,PHP_INT_MAX),'petish_chat_missing');
  $viewer=new ReflectionMethod('Petish_Chat','viewer');$viewer->setAccessible(true);
  petish_chat_proof_mark('foreign_pending_reader_denied',$private&&$viewer->invoke(null,PHP_INT_MAX)===0);
  $_SERVER['REQUEST_METHOD']='POST';$guest=true;
  foreach (Petish_Chat_UI::OPERATIONS as $operation)
   $guest=$guest&&petish_chat_proof_error(Petish_Chat_UI::request($operation,['topic_id'=>PHP_INT_MAX]),'petish_chat_ui_signin');
  petish_chat_proof_mark('guest_mutations_denied',$guest
   &&petish_chat_proof_error(Petish_Chat::create_topic(0,[]),'petish_chat_signin')
   &&petish_chat_proof_error(Petish_Chat::create_reply(0,0,[]),'petish_chat_signin')
   &&petish_chat_proof_error(Petish_Chat::moderation_queue(),'petish_chat_permission'));
  petish_chat_proof_principal(PHP_INT_MAX);$_SERVER['REQUEST_METHOD']='GET';
  $method=petish_chat_proof_error(Petish_Chat_UI::request('create_topic',[]),'petish_chat_ui_signin');
  $_SERVER['REQUEST_METHOD']='POST';
  $nonce=petish_chat_proof_error(Petish_Chat_UI::request('create_topic',[Petish_Chat_UI::NONCE_FIELD=>'invalid-proof-nonce']),'petish_chat_ui_nonce');
  petish_chat_proof_principal();$filters=Petish_Chat_UI::filters([]);
  $input=$filters+['action'=>Petish_Chat_UI::REFRESH_ACTION,'context'=>Petish_Chat_UI::context($filters)];
  $_SERVER['REQUEST_METHOD']='GET';$read_method=petish_chat_proof_error(Petish_Chat_UI::refresh_payload($input),'petish_chat_ui_origin');
  $_SERVER['REQUEST_METHOD']='POST';$_SERVER['HTTP_ORIGIN']='https://outside.invalid';
  $origin=petish_chat_proof_error(Petish_Chat_UI::refresh_payload($input),'petish_chat_ui_origin');
  $site=wp_parse_url(home_url('/'));
  $_SERVER['HTTP_ORIGIN']=$site['scheme'].'://'.$site['host'].(isset($site['port'])?':'.$site['port']:'');
  unset($_SERVER['HTTP_SEC_FETCH_SITE']);
  $context=petish_chat_proof_error(Petish_Chat_UI::refresh_payload(array_replace($input,['context'=>'invalid'])),'petish_chat_ui_context');
  $uid=petish_chat_proof_error(Petish_Chat_UI::refresh_payload($input+['user_id'=>PHP_INT_MAX]),'petish_chat_ui_filters');
  $payload=Petish_Chat_UI::refresh_payload($input);
  petish_chat_proof_mark('request_method_and_nonce_verified',$method&&$nonce&&$read_method&&$origin&&$context&&$uid
   &&is_array($payload)&&($payload['schema']??null)===1&&($payload['signed_in']??null)===false
   &&($payload['context']??null)===$input['context']&&is_string($payload['html']??null)&&strlen($payload['html'])<=1048576);
  $state=Petish_Chat_UI::state($filters);
  if (!is_array($state)||($state['signed_in']??null)!==false) petish_club_verify_fail();
  $html=petish_club_verify_capture(static fn()=>Petish_Chat_UI::render($state));
  petish_chat_proof_mark('synthetic_public_render_verified',is_string($html)&&strlen($html)>0&&strlen($html)<=1048576
   &&str_contains($html,'petish-chat')&&str_contains($html,'Pet Chat')&&!str_contains($html,'data-chat-private')
   &&!str_contains($html,'type="file"')&&!str_contains($html,'gravatar.com'));
  $wp_styles=new WP_Styles();$wp_scripts=new WP_Scripts();unset($_GET['petish_chat']);Petish_Chat_UI::assets();
  $absent=!wp_style_is('petish-chat','enqueued')&&!wp_script_is('petish-chat','enqueued');
  $_GET['petish_chat']='1';Petish_Chat_UI::assets();
  petish_chat_proof_mark('chat_assets_scoped',$absent&&wp_style_is('petish-chat','enqueued')&&wp_script_is('petish-chat','enqueued')
   &&$wp_styles->registered['petish-chat']->ver===substr(hash_file('sha256',$base.'assets/pet-chat.css'),0,16)
   &&$wp_scripts->registered['petish-chat']->ver===substr(hash_file('sha256',$base.'assets/pet-chat.js'),0,16));
 } finally {$GLOBALS['current_user']=$user;$_SERVER=$server;$_GET=$get;$wp_styles=$styles;$wp_scripts=$scripts;}
}

if (defined('PETISH_CHAT_VERIFY_LIBRARY')) return;
petish_club_verify_report_start();
$report+=['schema_version'=>1,'target'=>'staging','mode'=>'verify-enable','source_hashes'=>[],
 'observations'=>['persisted_fixtures_not_created'=>true,'message_mutations_not_exercised'=>true,
  'browser_interaction_not_exercised'=>true,'production_not_bootstrapped'=>true]];
$passed=false;$lock=false;$root=null;$before=null;$source_before=null;$manifest=null;$options_before=null;$records_before=null;$flags_before=null;
$finalize=static function()use(&$passed,&$lock,&$root,&$before,&$source_before,&$manifest,&$options_before,&$records_before,&$flags_before,&$baseline,&$report) {
 $body_category=$report['category'];$committed=false;
 try {
  if (!$passed) petish_club_verify_fail();
  $report['category']='snapshot';$after=petish_club_verify_state($root);
  foreach (['orders'=>'orders_unchanged','accounts'=>'users_and_authentication_unchanged','products'=>'product_prices_and_stock_unchanged',
   'passports'=>'existing_passports_unchanged','photos'=>'private_photo_files_unchanged'] as $key=>$check)
   petish_club_verify_mark($check,$before[$key]===$after[$key]);
  petish_chat_proof_mark('chat_records_unchanged',$records_before===petish_chat_proof_records());
  petish_chat_proof_mark('settings_except_chat_flag_unchanged',$options_before===petish_chat_proof_option_rows());
  petish_chat_proof_mark('existing_feature_flags_unchanged',$flags_before===petish_chat_proof_feature_flags());
  petish_chat_proof_mark('source_unchanged',hash_equals($source_before,petish_chat_proof_source($root,$manifest)));
  petish_chat_proof_mark('native_memory_cache_verified',petish_club_verify_runtime_cache($root));
  petish_chat_proof_mark('sql_write_attempts_absent',($GLOBALS['petish_chat_proof_blocked']??0)===$baseline
   &&has_filter('query','petish_chat_proof_query_guard')===PHP_INT_MAX&&($GLOBALS['petish_chat_proof_next_sql']??null)===null);
  // WordPress's shutdown hook has already run. No deferred plugin write can be
  // mistaken for a successful single-option activation.
  petish_chat_proof_once('COMMIT');$GLOBALS['petish_chat_proof_transaction']=false;$committed=true;
  petish_chat_proof_mark('flag_transaction_committed',true);
 } catch (Throwable $ignored) {$passed=false;}
 finally {
  try {
   $GLOBALS['petish_chat_proof_next_sql']=null;
   if (!empty($GLOBALS['petish_chat_proof_transaction'])) {petish_chat_proof_once('ROLLBACK');$GLOBALS['petish_chat_proof_transaction']=false;}
   global $wpdb;
   if ($lock&&((string)$wpdb->get_var("SELECT RELEASE_LOCK('petish-chat-staging-proof-v1')")!=='1'||$wpdb->last_error!=='')) $passed=false;
  } catch (Throwable $ignored) {$passed=false;}
 }
 if ($passed&&$committed) {$report['status']='success';$report['category']='complete';}
 else {$report['status']='failure';if ($report['category']==='snapshot') $report['category']=$body_category;exit(1);}
};
try {
 if (count($argv)!==2||!petish_club_verify_run($argv[1])) petish_club_verify_fail();
 $report['category']='source';$manifest=petish_club_verify_read_json(__DIR__.'/source-manifest.json');
 $root=getenv('HOME').'/domains/staging.petish.biz/public_html';
 $source_before=petish_chat_proof_source($root,$manifest);$report['source_hashes']=$manifest;
 petish_chat_proof_mark('source_manifest_verified',true);
 $root=petish_chat_proof_bootstrap();
 $baseline=$GLOBALS['petish_chat_proof_blocked']??0;$GLOBALS['petish_chat_proof_strict']=true;
 $report['category']='snapshot';$before=petish_club_verify_state($root);
 $options_before=petish_chat_proof_option_rows();$records_before=petish_chat_proof_records();$flags_before=petish_chat_proof_feature_flags();
 register_shutdown_function($finalize);
 if ((string)$wpdb->get_var("SELECT GET_LOCK('petish-chat-staging-proof-v1',5)")!=='1'||$wpdb->last_error!=='') petish_club_verify_fail();
 $lock=true;$report['category']='activation';petish_chat_proof_transaction_start();petish_chat_proof_enable();
 $report['category']='contracts';petish_chat_proof_contracts($root);
 $passed=true;
} catch (Throwable $ignored) {$report['status']='failure';}
finally {
 // The guard blocks and counts deferred bootstrap/plugin writes too. Keep the
 // transaction open until the registered finalizer checks the complete scope.
 $GLOBALS['petish_chat_proof_strict']=false;
}
exit($passed?0:1);
