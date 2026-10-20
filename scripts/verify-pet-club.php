<?php
/** Native Pet Club proof. Production is read-only; staging owns every fixture. */
if (PHP_SAPI !== 'cli') exit(1);
const PETISH_CLUB_VERIFY_COMMON = [
 'target_paths_verified','target_environment_verified','woocommerce_runtime_verified',
 'all_outbound_http_blocked','mail_calls_blocked','remote_file_access_disabled','cron_and_async_runner_disabled',
 'pet_club_classes_loaded','private_record_registration_verified','private_owner_access_verified',
 'public_routes_registered','mutation_hooks_registered','legacy_assets_preserved','pet_club_assets_enqueued',
 'synthetic_public_render_verified','orders_unchanged','users_and_authentication_unchanged',
 'product_prices_and_stock_unchanged','settings_unchanged','existing_passports_unchanged','private_photo_files_unchanged',
];
const PETISH_CLUB_VERIFY_STAGING = [
 'staging_database_separate','staging_guard_verified','owned_fixture_users_created','owned_fixture_pet_created',
 'legacy_defaults_preserved','biography_and_traits_saved','foreign_owner_denied','private_media_owner_scoped',
 'private_moments_owner_scoped','unshared_pet_not_discoverable','explicit_public_snapshot_created',
 'public_projection_allowlisted','public_photos_are_separate','only_selected_moments_shared',
 'request_nonce_and_method_verified',
 'anonymous_heart_denied','self_heart_denied','heart_idempotent','heart_removal_verified',
 'report_deduplicated','moderation_hides_profile','unpublish_revokes_public_media',
 'photo_change_unpublishes','fixture_cleanup_verified',
];
const PETISH_CLUB_VERIFY_PRODUCTION = ['production_read_only_verified'];
const PETISH_CLUB_FIXTURE_PREPARE = ['target_paths_verified','target_environment_verified','woocommerce_runtime_verified',
 'all_outbound_http_blocked','mail_calls_blocked','remote_file_access_disabled','cron_and_async_runner_disabled',
 'staging_database_separate','staging_guard_verified','owned_fixture_users_created','owned_fixture_pet_created',
 'browser_input_written'];
const PETISH_CLUB_FIXTURE_CLEANUP = ['target_paths_verified','target_environment_verified','woocommerce_runtime_verified',
 'all_outbound_http_blocked','mail_calls_blocked','remote_file_access_disabled','cron_and_async_runner_disabled',
 'staging_database_separate','staging_guard_verified','fixture_cleanup_verified','orders_unchanged',
 'users_and_authentication_unchanged','product_prices_and_stock_unchanged','settings_unchanged',
 'existing_passports_unchanged','private_photo_files_unchanged'];

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
  PETISH_CLUB_VERIFY_PRODUCTION,PETISH_CLUB_FIXTURE_PREPARE,PETISH_CLUB_FIXTURE_CLEANUP));
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
 if(!petish_club_verify_path(dirname($path),true)||(! $replace&&(file_exists($path)||is_link($path))))petish_club_verify_fail();
 if($replace&&(!petish_club_verify_path($path)||(fileperms($path)&07777)!==0600))petish_club_verify_fail();
 $handle=@fopen($path,$replace?'c+b':'x+b');if(!$handle)petish_club_verify_fail();
 try{if(!flock($handle,LOCK_EX)||!ftruncate($handle,0)||!chmod($path,0600))petish_club_verify_fail();
  $raw=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
  if(fwrite($handle,$raw)!==strlen($raw)||!fflush($handle))petish_club_verify_fail();
 }finally{fclose($handle);}
}
function petish_club_verify_read_json($path) {
 if(!petish_club_verify_path($path)||(fileperms($path)&07777)!==0600||filesize($path)>131072)petish_club_verify_fail();
 $value=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
 if(!is_array($value))petish_club_verify_fail();return $value;
}
/** No production mutation is allowed during the application checks. */
function petish_club_verify_query_guard($sql) {
 if(!is_string($sql))petish_club_verify_fail();
 $probe=preg_replace('~\A(?:\s+|/\*.*?\*/|--[^\r\n]*(?:\r?\n|$)|\#[^\r\n]*(?:\r?\n|$))*~s','',$sql);
 if(preg_match('/\A(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i',$probe)
  &&!preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i',$probe)&&!preg_match('/;\s*\S/s',rtrim($probe)))return $sql;
 $GLOBALS['petish_club_write_attempted']=true;return 'SELECT 0 WHERE 1=0';
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
 $settings=$wpdb->get_results("SELECT option_name,SHA2(COALESCE(option_value,''),256) AS value_state FROM `{$wpdb->options}`"
  ." WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'"
  ." AND option_name NOT IN ('cron','rewrite_rules','user_count') ORDER BY option_name",ARRAY_A);
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
function petish_club_verify_bootstrap($target) {
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
 $http=static fn()=>new WP_Error('petish_club_verify_http_blocked','Pet Club verification blocks outbound requests.');
 $mail=static fn()=>true;$stop=static function(){throw new \PHPMailer\PHPMailer\Exception('Pet Club verification blocks delivery.');};
 $false=static fn()=>false;$zero=static fn()=>0;
 add_filter('pre_http_request',$http,PHP_INT_MAX,3);add_filter('pre_wp_mail',$mail,PHP_INT_MAX,2);
 add_action('phpmailer_init',$stop,PHP_INT_MAX);add_filter('action_scheduler_allow_async_request_runner',$false,PHP_INT_MAX);
 add_filter('action_scheduler_queue_runner_concurrent_batches',$zero,PHP_INT_MAX);
 add_action('wp_loaded',static function(){remove_all_actions('action_scheduler_run_queue');},PHP_INT_MAX);
 add_action('all',static function($hook)use($http,$mail,$stop,$false,$zero){
  foreach(['pre_http_request'=>[$http,3],'pre_wp_mail'=>[$mail,2],'phpmailer_init'=>[$stop,1],
   'action_scheduler_allow_async_request_runner'=>[$false,1],'action_scheduler_queue_runner_concurrent_batches'=>[$zero,1]]as$name=>$entry)
   if($hook===$name){remove_filter($name,$entry[0],PHP_INT_MAX);add_filter($name,$entry[0],PHP_INT_MAX,$entry[1]);}
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
 }
 $blocked=apply_filters('pre_http_request',false,['method'=>'POST'],'https://www.dsers.com/petish-club-verification');
 petish_club_verify_mark('all_outbound_http_blocked',is_wp_error($blocked)&&$blocked->get_error_code()==='petish_club_verify_http_blocked');
 petish_club_verify_mark('mail_calls_blocked',apply_filters('pre_wp_mail',null,[])===true);
 petish_club_verify_mark('remote_file_access_disabled',!ini_get('allow_url_fopen'));
 petish_club_verify_mark('cron_and_async_runner_disabled',DISABLE_WP_CRON===true&&apply_filters('action_scheduler_allow_async_request_runner',true)===false&&apply_filters('action_scheduler_queue_runner_concurrent_batches',1)===0);
 require_once __DIR__.'/email-branding-preview-remote.php';return $root;
}
function petish_club_verify_capture($callback) { ob_start();try{$callback();return ob_get_contents();}finally{ob_end_clean();} }
function petish_club_verify_contracts($root) {
 global $wp,$report;
 $report['category']='contracts';
 $classes=['Petish_Passport_Store','Petish_Passport_Media','Petish_Passport_Moments','Petish_Passport_Community','Petish_Passport_UI'];
 petish_club_verify_mark('pet_club_classes_loaded',count(array_filter($classes,'class_exists'))===count($classes));
 $private=true;foreach(['petish_pet','petish_pet_moment']as$type){$o=get_post_type_object($type);
  $private=$private&&$o&&!$o->public&&!$o->publicly_queryable&&!$o->show_in_rest&&!$o->has_archive&&$o->exclude_from_search;}
 petish_club_verify_mark('private_record_registration_verified',(bool)$private);
 petish_club_verify_mark('private_owner_access_verified',is_wp_error(Petish_Passport_Store::get(0,0))
  &&Petish_Passport_Store::all_for_user(0)===[]&&is_wp_error(Petish_Passport_Moments::get(0,0))
  &&Petish_Passport_Media::file_for_owner('1/'.str_repeat('a',48).'.png',0)===false);
 petish_club_verify_mark('public_routes_registered',has_action('template_redirect',['Petish_Passport_Community','route'])!==false
  &&has_action('admin_post_petish_club_photo',['Petish_Passport_Community','serve_photo'])!==false
  &&has_action('admin_post_nopriv_petish_club_photo',['Petish_Passport_Community','serve_photo'])!==false
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
  'name'=>'Synthetic <img src=x onerror=alert(1)> pet','species'=>'rabbit','age'=>'','bio'=>'A <script>alert(1)</script> sample biography.',
  'traits'=>['<b>Curious</b>'],'photo_url'=>'','moments'=>[],'published'=>'2026-10-21 00:00:00',
  'hearts'=>0,'hearted'=>false,'is_owner'=>false,'can_heart'=>false];
 $html=petish_club_verify_capture(static fn()=>Petish_Passport_UI::render_public($sample,[]));
 petish_club_verify_mark('synthetic_public_render_verified',is_string($html)&&strlen($html)>100&&strlen($html)<1048576
  &&str_contains($html,'Synthetic')&&!preg_match('~<(?:script|img)\b[^>]*(?:alert\(|src=x)~i',$html)
  &&!str_contains($html,'onerror=alert(1)>')&&!str_contains($html,'@petish.invalid'));
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
 $suffix=substr(hash('sha256',$run.'-'.$kind.'-'.bin2hex(random_bytes(12))),0,18);
 $state=['format'=>'petish-club-fixture-v1','run'=>$run,'kind'=>$kind,'created'=>time(),
  'ownership'=>bin2hex(random_bytes(24)),'before'=>petish_club_verify_state($root),'users'=>[], 'pet_id'=>0,'moments'=>[]];
 foreach(['owner','member']as$role){$login='petish-club-'.$role.'-'.$suffix;
  if(username_exists($login)||email_exists($login.'@petish.invalid'))petish_club_verify_fail();
  $state['users'][$role]=['id'=>0,'login'=>$login,'email'=>$login.'@petish.invalid','password'=>bin2hex(random_bytes(24))];}
 petish_club_verify_write_json(petish_club_verify_fixture_state_path($kind),$state);
 foreach(['owner','member']as$role){$u=$state['users'][$role];
  $id=wp_insert_user(['user_login'=>$u['login'],'user_email'=>$u['email'],'user_pass'=>$u['password'],
   'display_name'=>'Petish isolated Passport fixture','role'=>'customer']);
  if(is_wp_error($id)||!$id)petish_club_verify_fail();$state['users'][$role]['id']=(int)$id;
  update_user_meta($id,'_petish_club_rehearsal_ownership',$state['ownership']);
  petish_club_verify_fixture_save($state);
  if(get_user_by('id',$id)->roles!==['customer'])petish_club_verify_fail();
 }
 petish_club_verify_mark('owned_fixture_users_created',true);
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
/** Deletes only the users whose private write-ahead identities and passwords match. */
function petish_club_verify_fixture_cleanup($root,$run,$kind) {
 global $report,$wpdb;
 $path=petish_club_verify_fixture_state_path($kind);
 if(!file_exists($path)&&!is_link($path))return false;
 $state=petish_club_verify_read_json($path);petish_club_verify_fixture_owned($state,$run,$kind);
 if(wp_get_environment_type()!=='staging')petish_club_verify_fail();
 require_once$root.'/wp-admin/includes/user.php';
 foreach(['member','owner']as$role){$u=$state['users'][$role];$user=get_user_by('login',$u['login']);if(!$user)continue;
  wp_set_current_user((int)$user->ID);
  $pets=Petish_Passport_Store::all_for_user((int)$user->ID);
  foreach($pets as$pet)if(is_wp_error(Petish_Passport_Store::delete($pet['id'],(int)$user->ID)))petish_club_verify_fail();
  // WooCommerce may have opened a session during browser login. Its key is the
  // already verified synthetic account ID, never a guest or another customer.
  $sessions=$wpdb->prefix.'woocommerce_sessions';
  if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($sessions)))===$sessions
   &&false===$wpdb->delete($sessions,['session_key'=>(string)$user->ID],['%s']))petish_club_verify_fail();
  if(!Petish_Passport_Media::cleanup_user((int)$user->ID)||!wp_delete_user((int)$user->ID))petish_club_verify_fail();
 }
 wp_set_current_user(0);
 foreach($state['users']as$u)if(get_user_by('login',$u['login']))petish_club_verify_fail();
 petish_club_verify_state_equal($state['before'],petish_club_verify_state($root));
 petish_club_verify_mark('fixture_cleanup_verified',true);
 if(!unlink($path))petish_club_verify_fail();
 return true;
}
function petish_club_verify_staging($root,$run) {
 global $report,$wpdb;
 $report['category']='fixture';$state=petish_club_verify_fixture_begin($root,$run,'native');
 try{
  $owner=$state['users']['owner']['id'];$member=$state['users']['member']['id'];$id=$state['pet_id'];
  $pet=Petish_Passport_Store::get($id,$owner);
  petish_club_verify_mark('legacy_defaults_preserved',($pet['bio']??null)===''&&($pet['traits']??null)===[]);
  $pet=Petish_Passport_Store::update($id,$owner,['name'=>'Petish Club Test Rabbit','species'=>'rabbit','age'=>'1-3',
   'interest'=>'browsing','bio'=>'A curious synthetic rabbit.','traits'=>['Curious','Gentle']]);
  petish_club_verify_mark('biography_and_traits_saved',!is_wp_error($pet)&&$pet['bio']==='A curious synthetic rabbit.'&&$pet['traits']===['Curious','Gentle']);
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
  $report['category']='sharing';wp_set_current_user($owner);
  $sharing=Petish_Passport_Community::publish($id,$owner,false,[$state['moments']['photo']]);
  petish_club_verify_mark('explicit_public_snapshot_created',!is_wp_error($sharing)&&$sharing['shared']===true
   &&preg_match('/\A[a-f0-9]{32}\z/D',$sharing['token'])===1);
  $token=$sharing['token'];$public=Petish_Passport_Community::public_profile($token);
  $allowed=['token','url','name','species','age','bio','traits','photo_url','moments','published','hearts','hearted','is_owner','can_heart'];
  $copy=get_post_meta($id,Petish_Passport_Community::META,true);
  petish_club_verify_mark('public_projection_allowlisted',!is_wp_error($public)&&!array_diff(array_keys($public),$allowed)
   &&count($public)===count($allowed)&&$public['age']===''&&$public['name']===$pet['name']
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
  $_SERVER['REQUEST_METHOD']='GET';$report['category']='hearts';
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
  $report['category']='moderation';$one=Petish_Passport_Community::report($token,$member,'privacy');
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
   'interest'=>$pet['interest'],'bio'=>$pet['bio'],'traits'=>$pet['traits'],'remove_photo'=>true]);
  petish_club_verify_mark('photo_change_unpublishes',!is_wp_error($changed)&&$changed['photo']===''
   &&is_wp_error(Petish_Passport_Community::public_profile($newtoken)));
 }finally{
  $previous=$report['category'];$report['category']='cleanup';petish_club_verify_fixture_cleanup($root,$run,'native');$report['category']=$previous;
 }
}
function petish_club_verify_main($argv) {
 global $report;
 petish_club_verify_report_start();$root=null;$target=null;
 try{
  if(count($argv)!==3||!petish_club_verify_run($argv[1])||!in_array($argv[2],['production','staging'],true))petish_club_verify_fail();
  $run=$argv[1];$target=$argv[2];$root=petish_club_verify_bootstrap($target);
  $report['category']='snapshot';$before=petish_club_verify_state($root);
  if($target==='production'){
   $GLOBALS['petish_club_write_attempted']=false;add_filter('query','petish_club_verify_query_guard',PHP_INT_MAX);
   try{petish_club_verify_contracts($root);}finally{remove_filter('query','petish_club_verify_query_guard',PHP_INT_MAX);}
   petish_club_verify_mark('production_read_only_verified',!$GLOBALS['petish_club_write_attempted']);
  }else{petish_club_verify_contracts($root);petish_club_verify_staging($root,$run);}
  $report['category']='snapshot';petish_club_verify_state_equal($before,petish_club_verify_state($root));
  $expected=array_merge(PETISH_CLUB_VERIFY_COMMON,$target==='staging'?PETISH_CLUB_VERIFY_STAGING:PETISH_CLUB_VERIFY_PRODUCTION);
  if(array_diff($expected,array_keys($report['checks'])))petish_club_verify_fail();
  $report['status']='success';$report['category']='complete';
 }catch(Throwable$ignored){$report['status']='failure';}
 return $report['status']==='success'?0:1;
}
if(!defined('PETISH_CLUB_VERIFY_LIBRARY'))exit(petish_club_verify_main($argv));
