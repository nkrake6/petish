<?php
/** Native Pet Club proof. Production is read-only; staging owns every fixture. */
if (PHP_SAPI !== 'cli') exit(1);
const PETISH_CLUB_VERIFY_COMMON = [
 'target_paths_verified','target_environment_verified','woocommerce_runtime_verified',
 'all_outbound_http_blocked','mail_calls_blocked','remote_file_access_disabled','cron_and_async_runner_disabled',
 'pet_club_classes_loaded','private_record_registration_verified','private_owner_access_verified','directory_index_migration_not_required',
 'public_routes_registered','mutation_hooks_registered','legacy_assets_preserved','pet_club_assets_enqueued',
 'synthetic_public_render_verified','orders_unchanged','users_and_authentication_unchanged',
 'product_prices_and_stock_unchanged','settings_unchanged','existing_passports_unchanged','private_photo_files_unchanged',
];
const PETISH_CLUB_VERIFY_STAGING = [
 'staging_database_separate','staging_guard_verified','jetpack_sync_isolated','owned_fixture_users_created','owned_fixture_pet_created',
 'legacy_defaults_preserved','biography_and_traits_saved','foreign_owner_denied','private_media_owner_scoped',
 'private_moments_owner_scoped','unshared_pet_not_discoverable','explicit_public_snapshot_created',
 'public_projection_allowlisted','public_photos_are_separate','only_selected_moments_shared',
 'request_nonce_and_method_verified',
 'anonymous_heart_denied','self_heart_denied','heart_idempotent','heart_removal_verified',
 'report_deduplicated','moderation_hides_profile','unpublish_revokes_public_media',
 'photo_change_unpublishes','fixture_cleanup_verified',
 'video_inspection_limits_verified','private_video_owner_scoped','video_range_contract_verified',
 'public_video_snapshot_separate','video_snapshot_frozen','video_replacement_revokes_public',
 'directory_fixture_scope_verified','directory_legacy_snapshot_index_verified','directory_public_snapshot_filters_verified',
 'directory_explicit_reshare_filters_verified','directory_literal_search_verified','directory_pagination_verified',
 'favourites_session_isolation_verified','directory_visibility_revocation_verified','directory_return_url_verified',
];
const PETISH_CLUB_VERIFY_PRODUCTION = ['production_read_only_verified'];
const PETISH_CLUB_VERIFY_INDEX = ['target_paths_verified','target_environment_verified','woocommerce_runtime_verified',
 'all_outbound_http_blocked','mail_calls_blocked','remote_file_access_disabled','cron_and_async_runner_disabled',
 'directory_index_migration_not_required','orders_unchanged','users_and_authentication_unchanged',
 'product_prices_and_stock_unchanged','settings_unchanged','existing_passports_unchanged','private_photo_files_unchanged',
 'production_read_only_verified'];
const PETISH_CLUB_FIXTURE_PREPARE = ['target_paths_verified','target_environment_verified','woocommerce_runtime_verified',
 'all_outbound_http_blocked','mail_calls_blocked','remote_file_access_disabled','cron_and_async_runner_disabled',
 'staging_database_separate','staging_guard_verified','jetpack_sync_isolated','owned_fixture_users_created','owned_fixture_pet_created',
 'browser_input_written'];
const PETISH_CLUB_FIXTURE_CLEANUP = ['target_paths_verified','target_environment_verified','woocommerce_runtime_verified',
 'all_outbound_http_blocked','mail_calls_blocked','remote_file_access_disabled','cron_and_async_runner_disabled',
 'staging_database_separate','staging_guard_verified','jetpack_sync_isolated','fixture_cleanup_verified','orders_unchanged',
 'users_and_authentication_unchanged','product_prices_and_stock_unchanged','settings_unchanged',
 'existing_passports_unchanged','private_photo_files_unchanged'];
const PETISH_CLUB_FIXTURE_DIAGNOSE = ['target_paths_verified','target_environment_verified','woocommerce_runtime_verified',
 'all_outbound_http_blocked','mail_calls_blocked','remote_file_access_disabled','cron_and_async_runner_disabled',
 'staging_database_separate','staging_guard_verified','jetpack_sync_isolated','diagnostic_completed','diagnostic_read_only',
 'fixture_journal_valid','owned_users_absent','orders_unchanged','users_and_authentication_unchanged',
 'product_prices_and_stock_unchanged','existing_passports_unchanged','private_photo_files_unchanged'];
const PETISH_CLUB_FIXTURE_RETIRE = ['target_paths_verified','target_environment_verified','woocommerce_runtime_verified',
 'all_outbound_http_blocked','mail_calls_blocked','remote_file_access_disabled','cron_and_async_runner_disabled',
 'staging_database_separate','staging_guard_verified','jetpack_sync_isolated','fixture_journal_valid','owned_users_absent',
 'orders_unchanged','users_and_authentication_unchanged','product_prices_and_stock_unchanged',
 'existing_passports_unchanged','private_photo_files_unchanged','only_known_runtime_cache_drift',
 'theme_update_check_timestamp_verified','retirement_read_only','owned_guard_retired','browser_input_retired',
 'original_journal_retained'];
const PETISH_CLUB_RUNTIME_CACHE_KEYS = ['action_scheduler_lock_async-request-runner','the7_plugins_last_check',
 'wcpay_account_data','wcpay_multi_currency_cached_currencies'];
const PETISH_CLUB_DIAGNOSTIC_OBSERVATIONS = ['settings_unchanged','single_added_option_identified',
 'diagnostic_write_attempt_blocked',
 'only_new_nonce_key_and_salt_identified',
 'option_rows_baseline_available','option_delta_identified','options_added','options_removed','options_modified',
 'changed_option_runtime_transient','changed_option_runtime_litespeed','changed_option_runtime_action_scheduler',
 'changed_option_woocommerce','changed_option_petish','changed_option_other',
 'option_change_user_creation','option_change_pet_creation','option_change_moments',
 'option_change_sharing','option_change_hearts','option_change_moderation','option_change_cleanup',
 'option_event_trace_present','option_event_trace_nonempty','changed_option_events_found',
 'option_names_redacted','option_names_truncated',
 'option_names_code_literals_verified',
 'added_option_runtime_transient','added_option_runtime_litespeed','added_option_runtime_action_scheduler',
 'added_option_woocommerce','added_option_petish','added_option_other'];
/** Configuration key names are fixed metadata; their values are never reported. */
const PETISH_CLUB_KNOWN_OPTION_KEYS = [
 'action_scheduler_lock_async-request-runner','action_scheduler_lock_action_scheduler_run_queue','action_scheduler_migration_status',
 'woocommerce_admin_report_users_update','woocommerce_admin_report_customers_update','woocommerce_admin_import_status',
 'woocommerce_admin_import_stats','woocommerce_admin_install_timestamp','woocommerce_admin_customer_import',
 'woocommerce_admin_import_customers','woocommerce_queue_flush_rewrite_rules','woocommerce_cache_prefix',
 'wc_admin_import_stats','wc_admin_import_customers','wc_admin_import_status','wc_admin_report_customers_update','wc_admin_report_users_update',
 'woocommerce_allow_tracking','woocommerce_task_list_tracked_started_tasks','woocommerce_task_list_tracked_completed_tasks',
 'woocommerce_tracker_last_send','woocommerce_version','woocommerce_db_version','woocommerce_cot_migration_state',
 'woocommerce_cot_db_version','woocommerce_attribute_taxonomies','woocommerce_admin_daily',
 'petish_staging_mail_count','petish_staging_captured_mail','petish_passport_schema_version',
 'wp_user_roles','db_version','can_compress_scripts','initial_db_version','recently_activated',
 'sidebars_widgets','widget_block','sticky_posts','uninstall_plugins','finished_splitting_shared_terms',
 'nonce_key','nonce_salt','auth_key','auth_salt','secure_auth_key','secure_auth_salt','logged_in_key','logged_in_salt',
];
function petish_club_verify_known_option_checks() {
 return array_map(static fn($name)=>'changed_known_option_'.str_replace(['.','-'],'_',$name),PETISH_CLUB_KNOWN_OPTION_KEYS);
}

function petish_club_verify_fail() { throw new RuntimeException(); }
function petish_club_verify_path($path,$directory=false) {
 if(!is_string($path)||$path===''||$path[0]!=='/'||str_contains($path,"\0"))return false;
 $cursor='';foreach(explode('/',substr($path,1))as$part){
  if($part===''||$part==='.'||$part==='..')return false;$cursor.='/'.$part;
  if(is_link($cursor)||realpath($cursor)!==$cursor)return false;
 }
 $info=@lstat($path);return $info&&($directory?is_dir($path):(is_file($path)&&$info['nlink']===1));
}
function petish_club_verify_mark($name,$value) {
 global $report;
 $allowed=array_unique(array_merge(PETISH_CLUB_VERIFY_COMMON,PETISH_CLUB_VERIFY_STAGING,
  PETISH_CLUB_VERIFY_PRODUCTION,PETISH_CLUB_FIXTURE_PREPARE,PETISH_CLUB_FIXTURE_CLEANUP,
  PETISH_CLUB_FIXTURE_DIAGNOSE,PETISH_CLUB_FIXTURE_RETIRE,PETISH_CLUB_DIAGNOSTIC_OBSERVATIONS,petish_club_verify_known_option_checks()));
 if(!in_array($name,$allowed,true)||!is_bool($value))petish_club_verify_fail();
 $report['checks'][$name]=$value;if(!$value)petish_club_verify_fail();
}
function petish_club_verify_report_start() {
 global $report;
 ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(0);umask(0077);
 $report=['status'=>'failure','category'=>'input','checks'=>[]];
 ob_start(static function($ignored,$phase)use(&$report){
  if(!($phase&PHP_OUTPUT_HANDLER_FINAL))return '';
  $public=$report;$public['checks']=(object)$public['checks'];
  return json_encode($public,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
 },0,0);
}
function petish_club_verify_run($run) {
 return is_string($run)&&preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$run)===1;
}
function petish_club_verify_write_json($path,$value,$replace=false) {
 $raw=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
 if(strlen($raw)>1048576)petish_club_verify_fail();
 if(!petish_club_verify_path(dirname($path),true)||(! $replace&&(file_exists($path)||is_link($path))))petish_club_verify_fail();
 if($replace&&(!petish_club_verify_path($path)||(fileperms($path)&07777)!==0600))petish_club_verify_fail();
 $handle=@fopen($path,$replace?'c+b':'x+b');if(!$handle)petish_club_verify_fail();
 try{if(!flock($handle,LOCK_EX)||!ftruncate($handle,0)||!chmod($path,0600))petish_club_verify_fail();
  if(fwrite($handle,$raw)!==strlen($raw)||!fflush($handle))petish_club_verify_fail();
 }finally{fclose($handle);}
}
function petish_club_verify_read_json($path) {
 if(!petish_club_verify_path($path)||(fileperms($path)&07777)!==0600||filesize($path)>1048576)petish_club_verify_fail();
 $value=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
 if(!is_array($value))petish_club_verify_fail();return $value;
}
/** No production mutation is allowed during the application checks. */
function petish_club_verify_query_guard($sql) {
 if(!is_string($sql))petish_club_verify_fail();
 $probe=preg_replace('~\A(?:\s+|/\*.*?\*/|--[^\r\n]*(?:\r?\n|$)|\#[^\r\n]*(?:\r?\n|$))*~s','',$sql);
 if(preg_match('/\A(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i',$probe)
  &&!preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i',$probe)&&!preg_match('/;\s*\S/s',rtrim($probe)))return $sql;
 $GLOBALS['petish_club_write_attempted']=true;
 $GLOBALS['petish_club_blocked_write_count']=($GLOBALS['petish_club_blocked_write_count']??0)+1;
 return 'SELECT 0 WHERE 1=0';
}
function petish_club_verify_read_only_scope($bootstrap_blocked) {
 return is_int($bootstrap_blocked)&&$bootstrap_blocked>=0
  &&($GLOBALS['petish_club_blocked_write_count']??null)===$bootstrap_blocked
  &&has_filter('query','petish_club_verify_query_guard')===PHP_INT_MAX;
}
/** AS acquires its option lock before checking allow_async_request_runner. */
function petish_club_verify_disable_async_dispatch() {
 if(!class_exists('ActionScheduler_QueueRunner',false))return;
 $runner=ActionScheduler_QueueRunner::instance();
 if(!is_callable([$runner,'unhook_dispatch_async_request']))petish_club_verify_fail();
 $runner->unhook_dispatch_async_request();
 if(has_action('shutdown',[$runner,'maybe_dispatch_async_request'])!==false)petish_club_verify_fail();
}
function petish_club_verify_photo_state($root) {
 $base=dirname($root).'/private/petish-passport';$files=[];
 if(file_exists($base)){
  if(!petish_club_verify_path($base,true))petish_club_verify_fail();
  $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
  foreach($iterator as$file){
   $path=$file->getPathname();if(!petish_club_verify_path($path)||count($files)>20000)petish_club_verify_fail();
   $files[substr($path,strlen($base)+1)]=hash_file('sha256',$path);
  }
 }
 ksort($files);return hash('sha256',json_encode($files,JSON_THROW_ON_ERROR));
}
/** Hash complete rows in SQL, never returning customer values to the proof. */
function petish_club_verify_table_state($table,$where='') {
 global $wpdb;
 if(!is_string($table)||!preg_match('/\A[A-Za-z0-9_]+\z/D',$table))petish_club_verify_fail();
 $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
 if($wpdb->last_error!=='')petish_club_verify_fail();
 if($exists===null)return null;if($exists!==$table)petish_club_verify_fail();
 $columns=$wpdb->get_results("SHOW COLUMNS FROM `{$table}`",ARRAY_A);$names=[];
 if($wpdb->last_error!==''||!is_array($columns)||!$columns)petish_club_verify_fail();
 foreach($columns as$column){$name=$column['Field']??'';
  if(!is_string($name)||!preg_match('/\A[A-Za-z0-9_]+\z/D',$name))petish_club_verify_fail();$names[]='`'.$name.'`';}
 $rows=$wpdb->get_col('SELECT SHA2(JSON_ARRAY('.implode(',',$names)."),256) AS row_state FROM `{$table}`".$where.' ORDER BY row_state');
 if($wpdb->last_error!==''||!is_array($rows))petish_club_verify_fail();foreach($rows as$row)if(!is_string($row)||!preg_match('/\A[a-f0-9]{64}\z/D',$row))petish_club_verify_fail();
 return hash('sha256',json_encode([$names,$rows],JSON_THROW_ON_ERROR));
}
function petish_club_verify_settings_rows() {
 global $wpdb;
 if(!is_string($wpdb->options??null)||!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->options))petish_club_verify_fail();
 $rows=$wpdb->get_results("SELECT option_name,SHA2(COALESCE(option_value,''),256) AS value_state FROM `{$wpdb->options}`"
  ." WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'"
  ." AND option_name NOT IN ('cron','rewrite_rules','user_count') ORDER BY option_name",ARRAY_A);
 if($wpdb->last_error!==''||!is_array($rows)||count($rows)>5000)petish_club_verify_fail();
 foreach($rows as$row)if(!is_array($row)||array_keys($row)!==['option_name','value_state']
  ||!is_string($row['option_name'])||strlen($row['option_name'])>191
  ||!is_string($row['value_state'])||!preg_match('/\A[a-f0-9]{64}\z/D',$row['value_state']))petish_club_verify_fail();
 return $rows;
}
/** Exact aggregate equality after virtual omission proves a single newly added row. */
function petish_club_verify_single_added_option($baseline,$rows) {
 if(!is_string($baseline)||!preg_match('/\A[a-f0-9]{64}\z/D',$baseline)||!is_array($rows)||count($rows)>5000)petish_club_verify_fail();
 if(hash_equals($baseline,hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))))return null;
 $found=null;foreach(array_keys($rows)as$index){$candidate=$rows;array_splice($candidate,$index,1);
  if(hash_equals($baseline,hash('sha256',json_encode($candidate,JSON_THROW_ON_ERROR)))){
   if($found!==null)petish_club_verify_fail();$found=$rows[$index];
  }
 }
 return $found;
}
function petish_club_verify_new_nonce_options($baseline,$rows) {
 if(!is_string($baseline)||!preg_match('/\A[a-f0-9]{64}\z/D',$baseline)||!is_array($rows))petish_club_verify_fail();
 $kept=[];$found=[];foreach($rows as$row){
  if(in_array($row['option_name'],['nonce_key','nonce_salt'],true))$found[]=$row['option_name'];else$kept[]=$row;
 }
 sort($found);return $found===['nonce_key','nonce_salt']
  &&hash_equals($baseline,hash('sha256',json_encode($kept,JSON_THROW_ON_ERROR)));
}
function petish_club_verify_option_category($name) {
 if(preg_match('/\A_(?:site_)?transient_/',$name))return 'runtime_transient';
 if(preg_match('/\A_?litespeed[._]/',$name))return 'runtime_litespeed';
 if(str_starts_with($name,'action_scheduler_'))return 'runtime_action_scheduler';
 if(str_starts_with($name,'woocommerce_')||str_starts_with($name,'wc_'))return 'woocommerce';
 if(str_starts_with($name,'petish_'))return 'petish';
 return 'other';
}
/** Publish software identifiers only, never numbered/user/token-bearing keys. */
function petish_club_verify_safe_option_name($name) {
 if(!is_string($name)||strlen($name)>100
  ||!preg_match('/\A_?[a-z]{1,24}(?:[_-][a-z]{1,24}){1,15}\z/D',$name))return false;
 foreach(preg_split('/[_-]+/',trim($name,'_'))as$part)
  if(strlen($part)>=8&&preg_match('/\A[a-f]+\z/D',$part))return false;
 return true;
}
/** Match metadata literals in trusted software, never config, uploads or themes. */
function petish_club_verify_literal_option_names($root,$names) {
 $names=array_values(array_unique($names));if(count($names)>12)petish_club_verify_fail();
 $verified=[];$pending=[];foreach($names as$name){
  if(!petish_club_verify_safe_option_name($name))petish_club_verify_fail();
  if(in_array($name,PETISH_CLUB_KNOWN_OPTION_KEYS,true))$verified[]=$name;else$pending[]=$name;
 }
 $files=0;$bytes=0;
 foreach(['wp-includes','wp-admin','wp-content/plugins/woocommerce','wp-content/plugins/woocommerce-payments']as$relative){
  if(!$pending)break;$directory=$root.'/'.$relative;if(!petish_club_verify_path($directory,true))continue;
  $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS));
  foreach($iterator as$entry){
   if(!$entry->isFile()||$entry->isLink()||strtolower($entry->getExtension())!=='php')continue;
   if(++$files>10000)return $verified;$path=$entry->getPathname();
   if(!petish_club_verify_path($path))continue;$size=filesize($path);
   if($size>2097152)continue;if($bytes+$size>67108864)return $verified;$bytes+=$size;
   $source=file_get_contents($path);if(!is_string($source))continue;
   $possible=[];foreach($pending as$name)if(str_contains($source,"'".$name."'")||str_contains($source,'"'.$name.'"'))$possible[]=$name;
   if(!$possible)continue;
   foreach(token_get_all($source)as$token){
    if(!is_array($token)||$token[0]!==T_CONSTANT_ENCAPSED_STRING)continue;
    foreach($possible as$name)if($token[1]==="'".$name."'"||$token[1]==='"'.$name.'"'){
     $verified[]=$name;$pending=array_values(array_diff($pending,[$name]));$possible=array_values(array_diff($possible,[$name]));
    }
    if(!$possible)break;
   }
   if(!$pending)return array_values(array_unique($verified));
  }
 }
 return array_values(array_unique($verified));
}
const PETISH_CLUB_OPTION_PREFIXES = ['_woocommerce_','woocommerce_','_action_scheduler_','action_scheduler_',
 '_litespeed_','litespeed_','_petish_','petish_','_wp_','wp_','_wc_','wc_','_as_','as_'];
function petish_club_verify_option_prefix($name) {
 foreach(PETISH_CLUB_OPTION_PREFIXES as$prefix)if(str_starts_with($name,$prefix))return $prefix;
 return null;
}
function petish_club_verify_option_delta($before,$after) {
 $old=[];$new=[];foreach([$before,$after]as$rows){
  if(!is_array($rows)||count($rows)>5000)petish_club_verify_fail();
  foreach($rows as$row)if(!is_array($row)||array_keys($row)!==['option_name','value_state']
   ||!is_string($row['option_name'])||strlen($row['option_name'])>191
   ||!is_string($row['value_state'])||!preg_match('/\A[a-f0-9]{64}\z/D',$row['value_state']))petish_club_verify_fail();
 }
 foreach($before as$row){if(isset($old[$row['option_name']]))petish_club_verify_fail();$old[$row['option_name']]=$row['value_state'];}
 foreach($after as$row){if(isset($new[$row['option_name']]))petish_club_verify_fail();$new[$row['option_name']]=$row['value_state'];}
 $delta=['added'=>[],'removed'=>[],'modified'=>[]];
 foreach($new as$name=>$hash){if(!isset($old[$name]))$delta['added'][]=['option_name'=>$name,'after_hash'=>$hash];
  elseif(!hash_equals($old[$name],$hash))$delta['modified'][]=['option_name'=>$name,'before_hash'=>$old[$name],'after_hash'=>$hash];}
 foreach($old as$name=>$hash)if(!isset($new[$name]))$delta['removed'][]=['option_name'=>$name,'before_hash'=>$hash];
 return $delta;
}
/** Classify the retained failed run without restoring or accepting its settings. */
function petish_club_verify_retirement_delta($before,$current) {
 foreach(['orders','accounts','products','passports','photos']as$key)
  if(!array_key_exists($key,$before)||!array_key_exists($key,$current)||$before[$key]!==$current[$key])return false;
 if(!isset($before['settings_rows'],$before['settings'],$current['settings_rows'],$current['settings'])
  ||!hash_equals($before['settings'],hash('sha256',json_encode($before['settings_rows'],JSON_THROW_ON_ERROR)))
  ||!hash_equals($current['settings'],hash('sha256',json_encode($current['settings_rows'],JSON_THROW_ON_ERROR)))
  ||hash_equals($before['settings'],$current['settings']))return false;
 $delta=petish_club_verify_option_delta($before['settings_rows'],$current['settings_rows']);
 $keys=array_column($delta['modified'],'option_name');sort($keys);
 return !$delta['added']&&!$delta['removed']&&$keys===PETISH_CLUB_RUNTIME_CACHE_KEYS;
}
/** Trusted installed theme code must actually write this key as time(). */
function petish_club_verify_theme_timestamp_source($root) {
 $path=$root.'/wp-content/themes/dt-the7/inc/mods/tgmpa/tgmpa.php';
 if(!petish_club_verify_path($path)||filesize($path)>2097152)return false;
 $source=file_get_contents($path);if(!is_string($source)||!str_contains($source,'the7_plugins_last_check'))return false;
  $tokens=array_values(array_filter(token_get_all($source,TOKEN_PARSE),static fn($token)=>!is_array($token)
   ||!in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT,T_OPEN_TAG],true)));
  foreach($tokens as$i=>$token){
   if(!is_array($token)||!in_array($token[0],[T_STRING,T_NAME_FULLY_QUALIFIED],true)
    ||!in_array(strtolower($token[1]),['update_site_option','\\update_site_option'],true))continue;
   $previous=$tokens[$i-1]??null;
   if(is_array($previous)&&in_array($previous[0],[T_OBJECT_OPERATOR,T_DOUBLE_COLON,T_NULLSAFE_OBJECT_OPERATOR],true))continue;
   $key=$tokens[$i+2]??null;$time=$tokens[$i+4]??null;
   if(($tokens[$i+1]??null)==='('&&is_array($key)&&$key[0]===T_CONSTANT_ENCAPSED_STRING
    &&in_array($key[1],["'the7_plugins_last_check'",'"the7_plugins_last_check"'],true)
    &&($tokens[$i+3]??null)===','&&is_array($time)&&in_array($time[0],[T_STRING,T_NAME_FULLY_QUALIFIED],true)
    &&in_array(strtolower($time[1]),['time','\\time'],true)&&($tokens[$i+5]??null)==='('
    &&($tokens[$i+6]??null)===')'&&in_array($tokens[$i+7]??null,[')',','],true))return true;
  }
 return false;
}
function petish_club_verify_observe_options($run,$kind) {
 $GLOBALS['petish_club_option_events']=['format'=>'petish-club-option-events-v1','run'=>$run,'kind'=>$kind,'events'=>[]];
 $file=__DIR__.'/pet-club-'.$kind.'-option-events.json';
 if(file_exists($file)||is_link($file))petish_club_verify_fail();
 petish_club_verify_write_json($file,$GLOBALS['petish_club_option_events']);
 foreach(['added_option','updated_option','deleted_option']as$hook)add_action($hook,static function($name)use($hook,$file){
  if(!is_string($name)||strlen($name)>191)petish_club_verify_fail();
  $phase=$GLOBALS['petish_club_fixture_phase']??'fixture';
  if(!in_array($phase,['fixture','user_creation','pet_creation','moments','sharing','hearts','moderation','cleanup'],true))petish_club_verify_fail();
  $trace=&$GLOBALS['petish_club_option_events'];
  if(count($trace['events'])>=500)petish_club_verify_fail();
  $trace['events'][]=['option_name'=>$name,'operation'=>$hook,'phase'=>$phase];
  petish_club_verify_write_json($file,$trace,true);
 },PHP_INT_MAX,1);
}
/** Values and digests remain private; only equality booleans are published. */
function petish_club_verify_state($root) {
 global $wpdb;
 foreach(['posts','postmeta','options','users','usermeta','prefix']as$key)
  if(!is_string($wpdb->$key??null)||!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->$key))petish_club_verify_fail();
 $products=$wpdb->get_results("SELECT p.ID,p.post_type,p.post_parent,p.post_status,m.meta_id,m.meta_key,"
  ."SHA2(COALESCE(m.meta_value,''),256) AS value_state FROM `{$wpdb->posts}` p LEFT JOIN `{$wpdb->postmeta}` m ON m.post_id=p.ID"
  ." WHERE p.post_type IN ('product','product_variation') ORDER BY p.ID,m.meta_id",ARRAY_A);
 $pets=$wpdb->get_results("SELECT p.ID,p.post_type,p.post_parent,p.post_author,p.post_status,"
  ."SHA2(CONCAT(p.post_title,p.post_content,p.post_excerpt,p.post_modified_gmt),256) AS post_state,"
  ."m.meta_id,m.meta_key,SHA2(COALESCE(m.meta_value,''),256) AS value_state FROM `{$wpdb->posts}` p"
  ." LEFT JOIN `{$wpdb->postmeta}` m ON m.post_id=p.ID WHERE p.post_type IN ('petish_pet','petish_pet_moment') ORDER BY p.ID,m.meta_id",ARRAY_A);
 $settings=petish_club_verify_settings_rows();
 $active=$wpdb->get_results("SELECT user_id,meta_key,SHA2(meta_value,256) AS value_state FROM `{$wpdb->usermeta}`"
  ." WHERE meta_key='_petish_active_pet' ORDER BY user_id,umeta_id",ARRAY_A);
 foreach([$products,$pets,$settings,$active]as$rows)if(!is_array($rows))petish_club_verify_fail();
 $orders=['counts'=>petish_email_preview_order_counts(),
  'posts'=>petish_club_verify_table_state($wpdb->posts," WHERE post_type IN ('shop_order','shop_order_refund')"),
  'postmeta'=>petish_club_verify_table_state($wpdb->postmeta," WHERE post_id IN (SELECT ID FROM `{$wpdb->posts}` WHERE post_type IN ('shop_order','shop_order_refund'))")];
 foreach(['wc_orders','wc_orders_meta','wc_order_addresses','wc_order_operational_data','woocommerce_order_items','woocommerce_order_itemmeta']as$suffix)
  $orders[$suffix]=petish_club_verify_table_state($wpdb->prefix.$suffix);
 return ['orders'=>$orders,'accounts'=>['auth'=>petish_email_preview_account_state(),
  'records'=>petish_club_verify_table_state($wpdb->users),'metadata'=>petish_club_verify_table_state($wpdb->usermeta)],
  'products'=>hash('sha256',json_encode($products,JSON_THROW_ON_ERROR)),
  'settings'=>hash('sha256',json_encode($settings,JSON_THROW_ON_ERROR)),
  'settings_rows'=>$settings,
  'passports'=>hash('sha256',json_encode([$pets,$active],JSON_THROW_ON_ERROR)),
  'photos'=>petish_club_verify_photo_state($root)];
}
function petish_club_verify_state_equal($before,$after) {
 petish_club_verify_mark('orders_unchanged',$before['orders']===$after['orders']);
 petish_club_verify_mark('users_and_authentication_unchanged',$before['accounts']===$after['accounts']);
 petish_club_verify_mark('product_prices_and_stock_unchanged',hash_equals($before['products'],$after['products']));
 petish_club_verify_mark('settings_unchanged',hash_equals($before['settings'],$after['settings']));
 petish_club_verify_mark('existing_passports_unchanged',hash_equals($before['passports'],$after['passports']));
 petish_club_verify_mark('private_photo_files_unchanged',hash_equals($before['photos'],$after['photos']));
}
function petish_club_verify_bootstrap($target,$read_only=false) {
 global $report;
 if(!in_array($target,['staging','production'],true)||ini_get('allow_url_fopen'))petish_club_verify_fail();
 $host=$target==='staging'?'staging.petish.biz':'petish.biz';$home=getenv('HOME');
 $report['category']='paths';if(!petish_club_verify_path($home,true))petish_club_verify_fail();
 $root=$home.'/domains/'.$host.'/public_html';
 foreach([$root,$root.'/wp-includes']as$path)if(!petish_club_verify_path($path,true))petish_club_verify_fail();
 foreach([$root.'/wp-config.php',$root.'/wp-load.php',$root.'/wp-includes/plugin.php',
  __DIR__.'/email-branding-preview-remote.php']as$path)if(!petish_club_verify_path($path))petish_club_verify_fail();
 if($target==='staging'){
  $guard=$root.'/wp-content/mu-plugins/petish-staging-guard.php';
  if(!petish_club_verify_path($guard)||!hash_equals('f7615da9180aff6b8b85d81f5821ed1bd4933970adeee3462dd252a502681ee6',hash_file('sha256',$guard)))petish_club_verify_fail();
 }
 petish_club_verify_mark('target_paths_verified',true);$report['category']='bootstrap';
 foreach(['WP_HTTP_BLOCK_EXTERNAL'=>true,'WP_ACCESSIBLE_HOSTS'=>'','DISABLE_WP_CRON'=>true,
  'AUTOMATIC_UPDATER_DISABLED'=>true,'DISALLOW_FILE_MODS'=>true,'WP_USE_THEMES'=>false]as$name=>$value){
  if(defined($name))petish_club_verify_fail();define($name,$value);
 }
 $_SERVER['HTTP_HOST']=$host;$_SERVER['SERVER_NAME']=$host;$_SERVER['SERVER_PORT']='443';$_SERVER['HTTPS']='on';
 $_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/my-account/my-pets/';$_SERVER['SERVER_PROTOCOL']='HTTP/1.1';$_SERVER['REMOTE_ADDR']='127.0.0.1';
 require_once$root.'/wp-includes/plugin.php';
 if($read_only){$GLOBALS['petish_club_write_attempted']=false;$GLOBALS['petish_club_blocked_write_count']=0;
  add_filter('query','petish_club_verify_query_guard',PHP_INT_MAX);}
 $http=static fn()=>new WP_Error('petish_club_verify_http_blocked','Pet Club verification blocks outbound requests.');
 $mail=static fn()=>true;$stop=static function(){throw new \PHPMailer\PHPMailer\Exception('Pet Club verification blocks delivery.');};
 $false=static fn()=>false;$zero=static fn()=>0;
 // WooPayments bundles Jetpack Sync. These documented request-local hooks
 // stop its queue listener/sender without changing settings or clearing queues.
 if($target==='staging'){
  add_filter('jetpack_sync_listener_should_load',$false,PHP_INT_MAX);
  add_filter('jetpack_sync_sender_should_load',$false,PHP_INT_MAX);
  add_filter('pre_option_jetpack_sync_settings_disable',$mail,PHP_INT_MAX);
 }
 add_filter('pre_http_request',$http,PHP_INT_MAX,3);add_filter('pre_wp_mail',$mail,PHP_INT_MAX,2);
 add_action('phpmailer_init',$stop,PHP_INT_MAX);add_filter('action_scheduler_allow_async_request_runner',$false,PHP_INT_MAX);
 add_filter('action_scheduler_queue_runner_concurrent_batches',$zero,PHP_INT_MAX);
 add_action('wp_loaded',static function(){remove_all_actions('action_scheduler_run_queue');petish_club_verify_disable_async_dispatch();},PHP_INT_MAX);
 add_action('all',static function($hook)use($http,$mail,$stop,$false,$zero,$read_only,$target){
  if($hook==='shutdown')petish_club_verify_disable_async_dispatch();
  if($read_only&&$hook==='query'){
   remove_filter('query','petish_club_verify_query_guard',PHP_INT_MAX);
   add_filter('query','petish_club_verify_query_guard',PHP_INT_MAX);
  }
  foreach(['pre_http_request'=>[$http,3],'pre_wp_mail'=>[$mail,2],'phpmailer_init'=>[$stop,1],
   'action_scheduler_allow_async_request_runner'=>[$false,1],'action_scheduler_queue_runner_concurrent_batches'=>[$zero,1]]as$name=>$entry)
   if($hook===$name){remove_filter($name,$entry[0],PHP_INT_MAX);add_filter($name,$entry[0],PHP_INT_MAX,$entry[1]);}
  if($target==='staging')foreach(['jetpack_sync_listener_should_load'=>$false,
   'jetpack_sync_sender_should_load'=>$false,'pre_option_jetpack_sync_settings_disable'=>$mail]as$name=>$callback)
   if($hook===$name){remove_filter($name,$callback,PHP_INT_MAX);add_filter($name,$callback,PHP_INT_MAX);}
 },PHP_INT_MAX,1);
 require$root.'/wp-load.php';$report['category']='runtime';
 petish_club_verify_mark('target_environment_verified',wp_get_environment_type()===$target
  &&parse_url(home_url('/'),PHP_URL_HOST)===$host&&parse_url(site_url('/'),PHP_URL_HOST)===$host
  &&($target==='staging'?(defined('PETISH_STAGING_GUARD_ACTIVE')&&PETISH_STAGING_GUARD_ACTIVE===true)
   :(!defined('PETISH_STAGING_GUARD_ACTIVE')||PETISH_STAGING_GUARD_ACTIVE!==true)));
 petish_club_verify_mark('woocommerce_runtime_verified',defined('WC_VERSION')&&WC_VERSION==='11.2.1'&&get_stylesheet()==='dt-the7-child');
 if($target==='staging'){
  $production=getenv('PETISH_REHEARSAL_PRODUCTION_DB_NAME');
  petish_club_verify_mark('staging_database_separate',is_string($production)&&$production!==''&&defined('DB_NAME')&&DB_NAME!==$production);
  petish_club_verify_mark('staging_guard_verified',defined('WCPAY_DEV_MODE')&&WCPAY_DEV_MODE===true&&defined('PETISH_STAGING_GUARD_ACTIVE')&&PETISH_STAGING_GUARD_ACTIVE===true);
  $sync='Automattic\\Jetpack\\Sync\\Actions';
  petish_club_verify_mark('jetpack_sync_isolated',apply_filters('jetpack_sync_listener_should_load',true)===false
   &&apply_filters('jetpack_sync_sender_should_load',true)===false&&get_option('jetpack_sync_settings_disable')===true
   &&(!class_exists($sync)||($sync::$listener===null&&$sync::$sender===null)));
 }
 $blocked=apply_filters('pre_http_request',false,['method'=>'POST'],'https://www.dsers.com/petish-club-verification');
 petish_club_verify_mark('all_outbound_http_blocked',is_wp_error($blocked)&&$blocked->get_error_code()==='petish_club_verify_http_blocked');
 petish_club_verify_mark('mail_calls_blocked',apply_filters('pre_wp_mail',null,[])===true);
 petish_club_verify_mark('remote_file_access_disabled',!ini_get('allow_url_fopen'));
 $async_absent=!class_exists('ActionScheduler_QueueRunner',false)
  ||has_action('shutdown',[ActionScheduler_QueueRunner::instance(),'maybe_dispatch_async_request'])===false;
 petish_club_verify_mark('cron_and_async_runner_disabled',DISABLE_WP_CRON===true&&apply_filters('action_scheduler_allow_async_request_runner',true)===false
  &&apply_filters('action_scheduler_queue_runner_concurrent_batches',1)===0&&$async_absent);
 require_once __DIR__.'/email-branding-preview-remote.php';return $root;
}
function petish_club_verify_capture($callback) { ob_start();try{$callback();return ob_get_contents();}finally{ob_end_clean();} }
/** Matches the new directory's eligibility rules without requiring its newer class. */
function petish_club_verify_pending_index_count() {
 global $wpdb;
 foreach(['posts','postmeta','users']as$table)if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->$table??''))petish_club_verify_fail();
 $sql="SELECT COUNT(*) FROM `{$wpdb->posts}` p INNER JOIN `{$wpdb->users}` owner ON owner.ID=p.post_author"
  ." WHERE p.post_type=%s AND p.post_status='private'"
  ." AND EXISTS (SELECT 1 FROM `{$wpdb->postmeta}` pt WHERE pt.post_id=p.ID AND pt.meta_key=%s)"
  ." AND NOT EXISTS (SELECT 1 FROM `{$wpdb->postmeta}` pr WHERE pr.post_id=p.ID AND pr.meta_key IN (%s,%s))";
 $args=['petish_pet',Petish_Passport_Community::TOKEN,Petish_Passport_Community::REVOKED,Petish_Passport_Community::BLOCKED];$missing=[];
 foreach(['_petish_club_find_name','_petish_club_find_species','_petish_club_find_variety']as$key){
  $missing[]="NOT EXISTS (SELECT 1 FROM `{$wpdb->postmeta}` mi WHERE mi.post_id=p.ID AND mi.meta_key=%s)";$args[]=$key;}
 $count=$wpdb->get_var($wpdb->prepare($sql.' AND ('.implode(' OR ',$missing).')',...$args));
 if($count===null||!empty($wpdb->last_error))petish_club_verify_fail();return max(0,(int)$count);
}
function petish_club_verify_contracts($root) {
 global $wp,$report;
 $report['category']='contracts';
 $classes=['Petish_Passport_Store','Petish_Passport_Media','Petish_Passport_Video','Petish_Passport_Video_Inspector','Petish_Passport_Moments','Petish_Passport_Community','Petish_Passport_UI'];
 petish_club_verify_mark('pet_club_classes_loaded',count(array_filter($classes,'class_exists'))===count($classes));
 petish_club_verify_mark('directory_index_migration_not_required',petish_club_verify_pending_index_count()===0
  &&Petish_Passport_Community::pending_index_count()===0);
 $private=true;foreach(['petish_pet','petish_pet_moment']as$type){$o=get_post_type_object($type);
  $private=$private&&$o&&!$o->public&&!$o->publicly_queryable&&!$o->show_in_rest&&!$o->has_archive&&$o->exclude_from_search;}
 petish_club_verify_mark('private_record_registration_verified',(bool)$private);
 petish_club_verify_mark('private_owner_access_verified',is_wp_error(Petish_Passport_Store::get(0,0))
  &&Petish_Passport_Store::all_for_user(0)===[]&&is_wp_error(Petish_Passport_Moments::get(0,0))
  &&Petish_Passport_Media::file_for_owner('1/'.str_repeat('a',48).'.png',0)===false
  &&Petish_Passport_Video::file_for_owner('1/'.str_repeat('a',48).'.mp4',0)===false
  &&(Petish_Passport_Store::varieties('rabbit')['mini-lop']??null)==='Mini Lop'
  &&Petish_Passport_Store::variety_label(['species'=>'rabbit','variety'=>'mini-lop','variety_other'=>''])==='Mini Lop');
 petish_club_verify_mark('public_routes_registered',has_action('template_redirect',['Petish_Passport_Community','route'])!==false
  &&has_action('admin_post_petish_club_photo',['Petish_Passport_Community','serve_photo'])!==false
  &&has_action('admin_post_nopriv_petish_club_photo',['Petish_Passport_Community','serve_photo'])!==false
  &&has_action('admin_post_petish_club_video',['Petish_Passport_Community','serve_video'])!==false
  &&has_action('admin_post_nopriv_petish_club_video',['Petish_Passport_Community','serve_video'])!==false
  &&has_action('admin_post_petish_pet_moment_video',['Petish_Passport_Moments','serve_video'])!==false
  &&has_action('admin_post_nopriv_petish_pet_moment_video',['Petish_Passport_Moments','serve_video'])!==false
  &&is_wp_error(Petish_Passport_Community::public_profile('not-a-token')));
 $actions=true;foreach(['publish','unpublish','heart','report','moderate']as$action)
  $actions=$actions&&has_action('admin_post_petish_club_'.$action,['Petish_Passport_Community','handle_'.$action])!==false;
 petish_club_verify_mark('mutation_hooks_registered',$actions);
 $base=$root.'/wp-content/plugins/petish-passport/assets/';
 petish_club_verify_mark('legacy_assets_preserved',hash_equals('5885712bde19288a62d9660ced39f98d48c27067fea7179e42b2bad48f311e75',hash_file('sha256',$base.'account-navigation.js'))
  &&hash_equals('e5c41cec8708f7d0e6ccddf8de76e43b807f66a612e3eac95871ab6c8a581076',hash_file('sha256',$base.'passport.css')));
 $query=$wp->query_vars;$account=static fn()=>true;add_filter('woocommerce_is_account_page',$account,PHP_INT_MAX);
 try{$wp->query_vars=['my-pets'=>''];Petish_Passport_UI::enqueue();
  $styles=wp_styles();$club=false;foreach($styles->registered as$item)
   if(str_ends_with(parse_url($item->src,PHP_URL_PATH)??'','/petish-passport/assets/pet-club.css'))$club=true;
  petish_club_verify_mark('pet_club_assets_enqueued',$club&&wp_style_is('petish-passport','enqueued')
   &&wp_script_is('petish-account-navigation','enqueued')&&wp_script_is('petish-passport','enqueued'));
 }finally{$wp->query_vars=$query;remove_filter('woocommerce_is_account_page',$account,PHP_INT_MAX);}
 $sample=['token'=>str_repeat('a',32),'url'=>home_url('/?petish_pet='.str_repeat('a',32)),
  'name'=>'Synthetic <img src=x onerror=alert(1)> pet','species'=>'rabbit','variety'=>'Mini Lop <svg onload=alert(1)>','age'=>'','bio'=>'A <script>alert(1)</script> sample biography.',
  'traits'=>['<b>Curious</b>'],'photo_url'=>'','moments'=>[],'published'=>'2026-10-21 00:00:00',
  'hearts'=>0,'hearted'=>false,'is_owner'=>false,'can_heart'=>false];
 $html=petish_club_verify_capture(static fn()=>Petish_Passport_UI::render_public($sample,[]));
 petish_club_verify_mark('synthetic_public_render_verified',is_string($html)&&strlen($html)>100&&strlen($html)<1048576
  &&str_contains($html,'Synthetic')&&str_contains($html,'Mini Lop')&&!preg_match('~<(?:script|img|svg)\b[^>]*(?:alert\(|src=x)~i',$html)
  &&!str_contains($html,'onerror=alert(1)>')&&!str_contains($html,'onload=alert(1)>')&&!str_contains($html,'@petish.invalid'));
}

function petish_club_verify_fixture_state_path($kind) {
 if(!in_array($kind,['native','browser'],true))petish_club_verify_fail();
 return __DIR__.'/pet-club-'.$kind.'-state.json';
}
function petish_club_verify_fixture_save(&$state) {
 petish_club_verify_write_json(petish_club_verify_fixture_state_path($state['kind']),$state,true);
}
/** Write-ahead identities allow cleanup after failure immediately following user insertion. */
function petish_club_verify_fixture_begin($root,$run,$kind) {
 if(wp_get_environment_type()!=='staging'||!petish_club_verify_run($run))petish_club_verify_fail();
 if(petish_club_verify_pending_index_count()!==0||Petish_Passport_Community::pending_index_count()!==0)petish_club_verify_fail();
 $suffix=substr(hash('sha256',$run.'-'.$kind.'-'.bin2hex(random_bytes(12))),0,18);
 $state=['format'=>'petish-club-fixture-v1','run'=>$run,'kind'=>$kind,'created'=>time(),
  'ownership'=>bin2hex(random_bytes(24)),'before'=>petish_club_verify_state($root),'users'=>[], 'pet_id'=>0,'moments'=>[]];
 foreach(['owner','member']as$role){$login='petish-club-'.$role.'-'.$suffix;
  if(username_exists($login)||email_exists($login.'@petish.invalid'))petish_club_verify_fail();
  $state['users'][$role]=['id'=>0,'login'=>$login,'email'=>$login.'@petish.invalid','password'=>bin2hex(random_bytes(24))];}
 petish_club_verify_write_json(petish_club_verify_fixture_state_path($kind),$state);
 petish_club_verify_observe_options($run,$kind);$GLOBALS['petish_club_fixture_phase']='user_creation';
 foreach(['owner','member']as$role){$u=$state['users'][$role];
  $id=wp_insert_user(['user_login'=>$u['login'],'user_email'=>$u['email'],'user_pass'=>$u['password'],
   'display_name'=>'Petish isolated Passport fixture','role'=>'customer']);
  if(is_wp_error($id)||!$id)petish_club_verify_fail();$state['users'][$role]['id']=(int)$id;
  update_user_meta($id,'_petish_club_rehearsal_ownership',$state['ownership']);
  petish_club_verify_fixture_save($state);
  if(get_user_by('id',$id)->roles!==['customer'])petish_club_verify_fail();
 }
 petish_club_verify_mark('owned_fixture_users_created',true);
 $GLOBALS['petish_club_fixture_phase']='pet_creation';
 $owner=$state['users']['owner']['id'];wp_set_current_user($owner);
 $pet=Petish_Passport_Store::create($owner,['name'=>'Petish Club Test Rabbit','species'=>'rabbit','age'=>'1-3','interest'=>'browsing']);
 if(is_wp_error($pet))petish_club_verify_fail();$state['pet_id']=$pet['id'];petish_club_verify_fixture_save($state);
 petish_club_verify_mark('owned_fixture_pet_created',get_post($pet['id'])->post_status==='private');
 return $state;
}
function petish_club_verify_fixture_photo($root,$uid) {
 if(wp_get_environment_type()!=='staging'||!is_int($uid)||$uid<1)petish_club_verify_fail();
 $base=dirname($root).'/private/petish-passport';
 foreach([dirname($root).'/private',$base,$base.'/'.$uid]as$dir){
  if(!file_exists($dir)&&!mkdir($dir,0700))petish_club_verify_fail();
  if(!petish_club_verify_path($dir,true)||(fileperms($dir)&07777)!==0700)petish_club_verify_fail();
 }
 $key=$uid.'/'.bin2hex(random_bytes(24)).'.png';$path=$base.'/'.$key;
 // A solid synthetic image, never a customer photograph.
 $image=imagecreatetruecolor(48,48);$fill=imagecolorallocate($image,211,190,239);imagefill($image,0,0,$fill);
 if(!imagepng($image,$path)||!chmod($path,0600))petish_club_verify_fail();imagedestroy($image);
 if(!petish_club_verify_path($path)||Petish_Passport_Media::file_for_owner($key,$uid)!==$path)petish_club_verify_fail();
 return $key;
}
function petish_club_verify_fixture_content($root,&$state) {
 $GLOBALS['petish_club_fixture_phase']='moments';
 $uid=$state['users']['owner']['id'];$pet=$state['pet_id'];
 $photo=petish_club_verify_fixture_photo($root,$uid);update_post_meta($pet,'_petish_photo',$photo);
 $moment=Petish_Passport_Moments::create($pet,$uid,['kind'=>'journal','caption'=>'A synthetic photo caption.']);
 if(is_wp_error($moment))petish_club_verify_fail();
 update_post_meta($moment['id'],'_petish_moment_kind','photo');
 update_post_meta($moment['id'],'_petish_moment_photo',petish_club_verify_fixture_photo($root,$uid));
 $journal=Petish_Passport_Moments::create($pet,$uid,['kind'=>'journal','caption'=>'Private synthetic journal entry.']);
 if(is_wp_error($journal))petish_club_verify_fail();
 $state['moments']=['photo'=>$moment['id'],'journal'=>$journal['id']];petish_club_verify_fixture_save($state);
 return Petish_Passport_Store::get($pet,$uid);
}
function petish_club_verify_fixture_owned($state,$run,$kind) {
 if(!is_array($state)||($state['format']??'')!=='petish-club-fixture-v1'||($state['run']??null)!==$run
  ||($state['kind']??null)!==$kind||!is_int($state['created']??null)||$state['created']>time()+60
  ||!preg_match('/\A[a-f0-9]{48}\z/D',$state['ownership']??'')||array_keys($state['users']??[])!==['owner','member'])petish_club_verify_fail();
 foreach($state['users']as$role=>$u){
  if(!is_array($u)||!preg_match('/\Apetish-club-'.$role.'-[a-f0-9]{18}\z/D',$u['login']??'')
   ||($u['email']??'')!==$u['login'].'@petish.invalid'||!preg_match('/\A[a-f0-9]{48}\z/D',$u['password']??''))petish_club_verify_fail();
  $user=get_user_by('login',$u['login']);if(!$user)continue;
  if($user->user_email!==$u['email']||!wp_check_password($u['password'],$user->user_pass,$user->ID)
   ||($u['id']&&$u['id']!==(int)$user->ID))petish_club_verify_fail();
  $marker=get_user_meta($user->ID,'_petish_club_rehearsal_ownership',true);
  if($marker!==''&&!hash_equals($state['ownership'],$marker))petish_club_verify_fail();
 }
 return true;
}
/** Inspect inert serialized queue records; never instantiate their PHP classes. */
function petish_club_verify_sync_tree($value,$depth=0,&$nodes=0) {
 if($depth>16||++$nodes>10000)return false;
 if(is_object($value)){
  if(get_class($value)!=='__PHP_Incomplete_Class')return false;
  $value=(array)$value;
  if(!in_array($value['__PHP_Incomplete_Class_Name']??'', ['WP_User','WP_Post','stdClass'],true))return false;
 }
 if(is_array($value)){foreach($value as$key=>$item)
  if((!is_int($key)&&(!is_string($key)||strlen($key)>255))||!petish_club_verify_sync_tree($item,$depth+1,$nodes))return false;
  return true;}
 return $value===null||is_bool($value)||is_int($value)||(is_float($value)&&is_finite($value))
  ||(is_string($value)&&strlen($value)<=262144);
}
function petish_club_verify_sync_object($value,$class) {
 if(!is_object($value)||get_class($value)!=='__PHP_Incomplete_Class')return null;
 $fields=(array)$value;return ($fields['__PHP_Incomplete_Class_Name']??'')===$class?$fields:null;
}
function petish_club_verify_sync_id($value) {
 return is_int($value)&&$value>=0?$value:(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/D',$value)?(int)$value:null);
}
function petish_club_verify_sync_user($value,$users) {
 $user=petish_club_verify_sync_object($value,'WP_User');if($user===null)return false;
 $data=petish_club_verify_sync_object($user['data']??null,'stdClass');
 $id=petish_club_verify_sync_id($user['ID']??null);
 if($data===null||!$id||!isset($users[$id])||petish_club_verify_sync_id($data['ID']??null)!==$id)return false;
 return ($data['user_login']??null)===$users[$id]['login']&&($data['user_email']??null)===$users[$id]['email']
  &&!array_key_exists('user_pass',$data);
}
/** Known Jetpack events must name only the exact journal-owned subject/actor. */
function petish_club_verify_sync_owned($raw,$state) {
 if(!is_string($raw)||strlen($raw)>524288)return false;
 $event=@unserialize($raw,['allowed_classes'=>false,'max_depth'=>16]);$nodes=0;
 if(!is_array($event)||!array_is_list($event)||!in_array(count($event),[5,6],true)
  ||!petish_club_verify_sync_tree($event,0,$nodes)||!is_string($event[0])
  ||!is_array($event[1])||!array_is_list($event[1])||!is_int($event[2])
  ||(!is_int($event[3])&&!is_float($event[3]))||$event[3]<$state['created']-5||$event[3]>time()+60
  ||$event[4]!==false)return false;
 $users=[];foreach($state['users']as$user){if(!is_int($user['id'])||$user['id']<1)return false;$users[$user['id']]=$user;}
 if($event[2]!==0&&!isset($users[$event[2]]))return false;
 if(count($event)===6){$actor=$event[5];if(!is_array($actor)||array_is_list($actor))return false;
  $id=petish_club_verify_sync_id($actor['external_user_id']??0);
  if($id===null||$id!==$event[2]||(!empty($actor['wpcom_user_id'])))return false;
  if($id&&($actor['user_email']??null)!==$users[$id]['email'])return false;
  if(!$id&&!in_array($actor['user_email']??null,[null,''],true))return false;
 }
 [$hook,$args]=$event;
 if(in_array($hook,['jetpack_sync_register_user','jetpack_sync_save_user','jetpack_sync_add_user'],true))
  return count($args)===2&&is_array($args[1])&&petish_club_verify_sync_user($args[0],$users);
 if($hook==='jetpack_deleted_user')return count($args)===3&&isset($users[petish_club_verify_sync_id($args[0])??0])
  &&in_array($args[1],[null,'',0],true)&&$args[2]===false;
 $posts=[];if(is_int($state['pet_id']??null)&&$state['pet_id']>0)$posts[$state['pet_id']]='petish_pet';
 foreach($state['moments']??[]as$id)if(is_int($id)&&$id>0)$posts[$id]='petish_pet_moment';
 if($hook==='deleted_post')return count($args)===1&&isset($posts[petish_club_verify_sync_id($args[0])??0]);
 if($hook==='jetpack_sync_save_post'&&count($args)===4&&is_bool($args[2])&&is_array($args[3])){
  $id=petish_club_verify_sync_id($args[0]);$post=petish_club_verify_sync_object($args[1],'WP_Post');
  return isset($posts[$id??0])&&$post!==null&&petish_club_verify_sync_id($post['ID']??null)===$id
   &&petish_club_verify_sync_id($post['post_author']??null)===$state['users']['owner']['id']
   &&($post['post_type']??null)===$posts[$id]&&($post['post_status']??null)==='private';
 }
 return false;
}
/** Virtual omission must restore the original full settings digest exactly. */
function petish_club_verify_sync_removal_proof($before,$rows,$candidates) {
 if(!is_array($candidates)||count($candidates)>64)return false;
 if(isset($before['settings_rows'])&&!hash_equals($before['settings'],hash('sha256',json_encode($before['settings_rows'],JSON_THROW_ON_ERROR))))return false;
 $names=[];foreach($candidates as$row){
  if(!is_array($row)||array_keys($row)!==['option_name','value_state']||isset($names[$row['option_name']]))return false;
  $names[$row['option_name']]=$row['value_state'];
 }
 $remaining=[];$found=0;foreach($rows as$row){
  if(isset($names[$row['option_name']])){if(!hash_equals($names[$row['option_name']],$row['value_state']))return false;++$found;}
  else$remaining[]=$row;
 }
 return $found===count($candidates)&&hash_equals($before['settings'],hash('sha256',json_encode($remaining,JSON_THROW_ON_ERROR)));
}
/** Recover only queued synthetic events, including older aggregate-only journals. */
function petish_club_verify_sync_cleanup($root,$state) {
 global $wpdb;
 if(wp_get_environment_type()!=='staging'||get_option('jetpack_sync_settings_disable')!==true)petish_club_verify_fail();
 $current=petish_club_verify_state($root);$before=$state['before'];
 foreach(['orders','accounts','products','passports','photos']as$key)if($before[$key]!==$current[$key])petish_club_verify_fail();
 if(hash_equals($before['settings'],$current['settings']))return;
 $rows=$current['settings_rows'];$possible=[];
 if(isset($before['settings_rows'])){
  $delta=petish_club_verify_option_delta($before['settings_rows'],$rows);
  if($delta['removed']||$delta['modified'])petish_club_verify_fail();
  foreach($delta['added']as$row)$possible[$row['option_name']]=$row['after_hash'];
 }else foreach($rows as$row)$possible[$row['option_name']]=$row['value_state'];
 $candidates=[];$total=0;$seen=0;
 foreach($possible as$name=>$hash){
  if(!preg_match('/\Ajpsq_sync-[0-9]{10}\.[0-9]{1,8}-[0-9]{1,7}-[0-9]{1,5}\z/D',$name))continue;
  if(++$seen>256)petish_club_verify_fail();
  $size=$wpdb->get_var($wpdb->prepare("SELECT OCTET_LENGTH(option_value) FROM `{$wpdb->options}` WHERE BINARY option_name=%s",$name));
  if(!is_numeric($size)||(int)$size>524288||($total+=(int)$size)>16777216)petish_club_verify_fail();
  $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE BINARY option_name=%s",$name));
  if(!is_string($raw)||!hash_equals($hash,hash('sha256',$raw)))petish_club_verify_fail();
  if(petish_club_verify_sync_owned($raw,$state))$candidates[]=['option_name'=>$name,'value_state'=>$hash];
 }
 if(!$candidates||!petish_club_verify_sync_removal_proof($before,$rows,$candidates))petish_club_verify_fail();
 // Recheck every conditional DELETE: concurrent writes fail without discarding
 // a changed value. The original journal remains for safe partial-delete retry.
 foreach($candidates as$row){
  $deleted=$wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE BINARY option_name=%s AND SHA2(option_value,256)=%s",
   $row['option_name'],$row['value_state']));
  if($deleted!==1)petish_club_verify_fail();
 }
 if(!hash_equals($before['settings'],hash('sha256',json_encode(petish_club_verify_settings_rows(),JSON_THROW_ON_ERROR))))petish_club_verify_fail();
}
/** Deletes only the users whose private write-ahead identities and passwords match. */
function petish_club_verify_fixture_cleanup($root,$run,$kind,$retain_journal=false) {
 global $report,$wpdb;
 $path=petish_club_verify_fixture_state_path($kind);
 if(!file_exists($path)&&!is_link($path))return false;
 $state=petish_club_verify_read_json($path);petish_club_verify_fixture_owned($state,$run,$kind);
 if(wp_get_environment_type()!=='staging')petish_club_verify_fail();
 require_once$root.'/wp-admin/includes/user.php';
 $GLOBALS['petish_club_fixture_phase']='cleanup';
 foreach(['member','owner']as$role){$u=$state['users'][$role];$user=get_user_by('login',$u['login']);if(!$user)continue;
  wp_set_current_user((int)$user->ID);
  $pets=Petish_Passport_Store::all_for_user((int)$user->ID);
  foreach($pets as$pet)if(is_wp_error(Petish_Passport_Store::delete($pet['id'],(int)$user->ID)))petish_club_verify_fail();
  // WooCommerce may have opened a session during browser login. Its key is the
  // already verified synthetic account ID, never a guest or another customer.
  $sessions=$wpdb->prefix.'woocommerce_sessions';
  if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($sessions)))===$sessions
   &&false===$wpdb->delete($sessions,['session_key'=>(string)$user->ID],['%s']))petish_club_verify_fail();
  if(!Petish_Passport_Media::cleanup_user((int)$user->ID)
   ||(class_exists('Petish_Passport_Video')&&!Petish_Passport_Video::cleanup_user((int)$user->ID))
   ||!wp_delete_user((int)$user->ID))petish_club_verify_fail();
 }
 wp_set_current_user(0);
 foreach($state['users']as$u)if(get_user_by('login',$u['login']))petish_club_verify_fail();
 petish_club_verify_sync_cleanup($root,$state);
 petish_club_verify_state_equal($state['before'],petish_club_verify_state($root));
 petish_club_verify_mark('fixture_cleanup_verified',true);
 if(!$retain_journal&&!unlink($path))petish_club_verify_fail();
 return true;
}
function petish_club_verify_staging($root,$run) {
 global $report,$wpdb;
 $report['category']='fixture';$state=petish_club_verify_fixture_begin($root,$run,'native');
 try{
  $owner=$state['users']['owner']['id'];$member=$state['users']['member']['id'];$id=$state['pet_id'];
  $pet=Petish_Passport_Store::get($id,$owner);
  petish_club_verify_mark('legacy_defaults_preserved',($pet['bio']??null)===''&&($pet['traits']??null)===[]
   &&($pet['variety']??null)===''&&($pet['variety_other']??null)===''&&Petish_Passport_Store::variety_label($pet)==='');
  $pet=Petish_Passport_Store::update($id,$owner,['name'=>'Petish Club Test Rabbit','species'=>'rabbit','age'=>'1-3',
   'interest'=>'browsing','bio'=>'A curious synthetic rabbit.','traits'=>['Curious','Gentle'],'variety'=>'mini-lop','variety_other'=>'']);
  if(is_wp_error($pet))petish_club_verify_fail();
  $created=Petish_Passport_Store::create($owner,['name'=>'Synthetic Mini Lop','species'=>'rabbit','age'=>'1-3','interest'=>'browsing','variety'=>'mini-lop']);
  $created_variety=!is_wp_error($created)&&$created['variety']==='mini-lop'&&Petish_Passport_Store::variety_label($created)==='Mini Lop';
  if(!$created_variety||Petish_Passport_Store::delete($created['id'],$owner)!==true)petish_club_verify_fail();
  $invalid=Petish_Passport_Store::update($id,$owner,['name'=>$pet['name'],'species'=>'hamster','age'=>'1-3','interest'=>'browsing','variety'=>'mini-lop']);
  $unchanged=Petish_Passport_Store::get($id,$owner);
  petish_club_verify_mark('biography_and_traits_saved',$pet['bio']==='A curious synthetic rabbit.'&&$pet['traits']===['Curious','Gentle']
   &&$created_variety&&$pet['variety']==='mini-lop'&&Petish_Passport_Store::variety_label($pet)==='Mini Lop'
   &&is_wp_error($invalid)&&$invalid->get_error_code()==='petish_variety'&&!is_wp_error($unchanged)
   &&$unchanged['species']==='rabbit'&&$unchanged['variety']==='mini-lop');
  petish_club_verify_mark('foreign_owner_denied',is_wp_error(Petish_Passport_Store::get($id,$member))
   &&is_wp_error(Petish_Passport_Store::get($id,0))&&is_wp_error(Petish_Passport_Store::update($id,$member,['name'=>'Foreign'])));
  $pet=petish_club_verify_fixture_content($root,$state);$private=Petish_Passport_Media::file_for_owner($pet['photo'],$owner);
  petish_club_verify_mark('private_media_owner_scoped',is_string($private)&&is_file($private)
   &&Petish_Passport_Media::file_for_owner($pet['photo'],$member)===false&&Petish_Passport_Media::file_for_owner($pet['photo'],0)===false);
  $moments=Petish_Passport_Moments::all_for_pet($id,$owner);
  petish_club_verify_mark('private_moments_owner_scoped',count($moments)===2
   &&is_wp_error(Petish_Passport_Moments::get($state['moments']['photo'],$member))
   &&is_wp_error(Petish_Passport_Moments::get($state['moments']['journal'],0)));
  $sharing=Petish_Passport_Community::published_for_owner($id,$owner);
  petish_club_verify_mark('unshared_pet_not_discoverable',!is_wp_error($sharing)&&$sharing['shared']===false
   &&get_post_meta($id,Petish_Passport_Community::TOKEN,true)==='');
  $report['category']='sharing';$GLOBALS['petish_club_fixture_phase']='sharing';wp_set_current_user($owner);
  $sharing=Petish_Passport_Community::publish($id,$owner,false,[$state['moments']['photo']]);
  petish_club_verify_mark('explicit_public_snapshot_created',!is_wp_error($sharing)&&$sharing['shared']===true
   &&preg_match('/\A[a-f0-9]{32}\z/D',$sharing['token'])===1);
  $token=$sharing['token'];$public=Petish_Passport_Community::public_profile($token);
  if(is_wp_error($public)||($public['variety']??null)!=='Mini Lop')petish_club_verify_fail();
  $pet=Petish_Passport_Store::update($id,$owner,['name'=>$pet['name'],'species'=>'rabbit','age'=>'1-3',
   'interest'=>'browsing','variety'=>'other','variety_other'=>'Curly-eared rabbit']);
  $frozen=Petish_Passport_Community::public_profile($token);
  $snapshot_frozen=!is_wp_error($pet)&&Petish_Passport_Store::variety_label($pet)==='Curly-eared rabbit'
   &&!is_wp_error($frozen)&&$frozen['variety']==='Mini Lop';
  if(!$snapshot_frozen)petish_club_verify_fail();
  $sharing=Petish_Passport_Community::publish($id,$owner,false,[$state['moments']['photo']]);
  if(is_wp_error($sharing))petish_club_verify_fail();$token=$sharing['token'];$public=Petish_Passport_Community::public_profile($token);
  $allowed=['token','url','name','species','variety','age','bio','traits','photo_url','moments','published','hearts','hearted','is_owner','can_heart'];
  $copy=get_post_meta($id,Petish_Passport_Community::META,true);
  petish_club_verify_mark('public_projection_allowlisted',!is_wp_error($public)&&!array_diff(array_keys($public),$allowed)
   &&count($public)===count($allowed)&&$public['age']===''&&$public['name']===$pet['name']
   &&$snapshot_frozen&&$public['variety']==='Curly-eared rabbit'&&($copy['variety']??null)==='Curly-eared rabbit'
   &&!str_contains(json_encode($public),$state['users']['owner']['email'])&&!str_contains(json_encode($public),$pet['photo'])
   &&!str_contains(json_encode($public),'/private/')&&!str_contains(json_encode($public),'Private synthetic journal'));
  $photo=Petish_Passport_Community::resolve_photo($token);
  $moment_photo=Petish_Passport_Community::resolve_photo($token,$public['moments'][0]['token']);
  petish_club_verify_mark('public_photos_are_separate',!is_wp_error($photo)&&!is_wp_error($moment_photo)
   &&$copy['photo']!==$pet['photo']&&$photo['path']!==$private&&is_file($photo['path'])
   &&hash_equals(hash_file('sha256',$photo['path']),hash_file('sha256',$private))
   &&(fileperms($photo['path'])&07777)===0600&&!str_starts_with($photo['path'],$root.'/'));
  petish_club_verify_mark('only_selected_moments_shared',count($public['moments'])===1
   &&$public['moments'][0]['kind']==='photo'&&count($copy['moments'])===1
   &&(int)$copy['moments'][0]['source_id']===$state['moments']['photo']);
  $_SERVER['REQUEST_METHOD']='POST';$nonce=wp_create_nonce('petish_club_publish_'.$id);
  $nonce_ok=Petish_Passport_Community::authorized('publish',$id,$nonce)
   &&!Petish_Passport_Community::authorized('publish',$id,'invalid');
  $_SERVER['REQUEST_METHOD']='GET';$nonce_ok=$nonce_ok&&!Petish_Passport_Community::authorized('publish',$id,$nonce);
  wp_set_current_user(0);$_SERVER['REQUEST_METHOD']='POST';
  petish_club_verify_mark('request_nonce_and_method_verified',$nonce_ok&&!Petish_Passport_Community::authorized('publish',$id,$nonce));
  $_SERVER['REQUEST_METHOD']='GET';$report['category']='hearts';$GLOBALS['petish_club_fixture_phase']='hearts';
  petish_club_verify_mark('anonymous_heart_denied',is_wp_error(Petish_Passport_Community::set_heart($token,0,true))
   &&is_wp_error(Petish_Passport_Community::set_heart($token,$member,true)));
  wp_set_current_user($owner);
  petish_club_verify_mark('self_heart_denied',is_wp_error(Petish_Passport_Community::set_heart($token,$owner,true)));
  wp_set_current_user($member);$first=Petish_Passport_Community::set_heart($token,$member,true);
  $second=Petish_Passport_Community::set_heart($token,$member,true);
  petish_club_verify_mark('heart_idempotent',!is_wp_error($first)&&!is_wp_error($second)&&$first['hearts']===1&&$second['hearts']===1
   &&Petish_Passport_Community::heart_count($id)===1);
  $removed=Petish_Passport_Community::set_heart($token,$member,false);
  petish_club_verify_mark('heart_removal_verified',!is_wp_error($removed)&&$removed['hearts']===0&&$removed['hearted']===false);
  $report['category']='moderation';$GLOBALS['petish_club_fixture_phase']='moderation';$one=Petish_Passport_Community::report($token,$member,'privacy');
  $two=Petish_Passport_Community::report($token,$member,'spam');
  $reports=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name=%s",Petish_Passport_Community::REPORT.$id.'_'.$member));
  petish_club_verify_mark('report_deduplicated',$one===true&&$two===true&&$reports===1
   &&is_wp_error(Petish_Passport_Community::moderate($id,'hide')));
  $manager=wp_get_current_user();$caps=$manager->allcaps;$manager->allcaps['manage_woocommerce']=true;
  try{$hidden=Petish_Passport_Community::moderate($id,'hide');
   petish_club_verify_mark('moderation_hides_profile',!is_wp_error($hidden)&&is_wp_error(Petish_Passport_Community::public_profile($token)));
   if(is_wp_error(Petish_Passport_Community::moderate($id,'unblock')))petish_club_verify_fail();
  }finally{$manager->allcaps=$caps;}
  petish_club_verify_mark('unpublish_revokes_public_media',is_wp_error(Petish_Passport_Community::resolve_photo($token))
   &&is_wp_error(Petish_Passport_Community::resolve_photo($token,$public['moments'][0]['token']))
   &&!file_exists($photo['path'])&&!file_exists($moment_photo['path'])&&is_file($private));
  wp_set_current_user($owner);$republished=Petish_Passport_Community::publish($id,$owner,false,[]);
  if(is_wp_error($republished))petish_club_verify_fail();$newtoken=$republished['token'];
  $changed=Petish_Passport_Store::update($id,$owner,['name'=>$pet['name'],'species'=>$pet['species'],'age'=>$pet['age'],
   'interest'=>$pet['interest'],'bio'=>$pet['bio'],'traits'=>$pet['traits'],'variety'=>$pet['variety'],
   'variety_other'=>$pet['variety_other'],'remove_photo'=>true]);
  petish_club_verify_mark('photo_change_unpublishes',!is_wp_error($changed)&&$changed['photo']===''
   &&is_wp_error(Petish_Passport_Community::public_profile($newtoken)));
  require_once __DIR__.'/pet-club-directory-proof.php';petish_club_verify_directory($state);
  require_once __DIR__.'/pet-club-video-proof.php';petish_club_verify_video($root,$state);
 }finally{
  $previous=$report['category'];$report['category']='cleanup';petish_club_verify_fixture_cleanup($root,$run,'native');$report['category']=$previous;
 }
}
function petish_club_verify_main($argv) {
 global $report;
 petish_club_verify_report_start();$root=null;$target=null;
 try{
  if(count($argv)!==3||!petish_club_verify_run($argv[1])||!in_array($argv[2],['production','production-index','staging'],true))petish_club_verify_fail();
  $index_only=$argv[2]==='production-index';$run=$argv[1];$target=$index_only?'production':$argv[2];$root=petish_club_verify_bootstrap($target,$target==='production');
  // Bootstrap can attempt lazy runtime writes; the installed barrier blocks
  // them. Preserve that evidence and separately require our proof to only read.
  $bootstrap_blocked=$GLOBALS['petish_club_blocked_write_count']??0;
  $GLOBALS['petish_club_bootstrap_blocked_write_count']=$bootstrap_blocked;
  $report['category']='snapshot';$before=petish_club_verify_state($root);
  if($target==='production'){
   // The guard was installed before wp-load and remains through the final
   // snapshot and shutdown, including WordPress/plugin lazy bootstrap writes.
   if($index_only)petish_club_verify_mark('directory_index_migration_not_required',petish_club_verify_pending_index_count()===0);
   else petish_club_verify_contracts($root);
  }else{petish_club_verify_contracts($root);petish_club_verify_staging($root,$run);}
  $report['category']='snapshot';petish_club_verify_state_equal($before,petish_club_verify_state($root));
  if($target==='production')petish_club_verify_mark('production_read_only_verified',petish_club_verify_read_only_scope($bootstrap_blocked));
  $expected=$index_only?PETISH_CLUB_VERIFY_INDEX:array_merge(PETISH_CLUB_VERIFY_COMMON,$target==='staging'?PETISH_CLUB_VERIFY_STAGING:PETISH_CLUB_VERIFY_PRODUCTION);
  if(array_diff($expected,array_keys($report['checks'])))petish_club_verify_fail();
  $report['status']='success';$report['category']='complete';
 }catch(Throwable$ignored){$report['status']='failure';}
 return $report['status']==='success'?0:1;
}
if(!defined('PETISH_CLUB_VERIFY_LIBRARY'))exit(petish_club_verify_main($argv));
