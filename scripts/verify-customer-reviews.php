<?php
/** Read-only native production review verification; also supplies the isolated bootstrap. */
if (PHP_SAPI !== 'cli') exit(1);
const PETISH_CUSTOMER_REVIEWS_VERIFY_CHECKS = [
 'fixed_production_paths','production_environment','woocommerce_runtime','outbound_blocked','mail_blocked',
 'async_blocked','jetpack_isolated','persistent_cache_isolated','database_read_only','feature_enabled',
 'module_classes_loaded','account_endpoint_registered','mutation_hooks_registered','moderation_hooks_registered',
 'privacy_hooks_registered','private_media_metadata','photo_video_limits','upload_limits_sufficient',
 'reviewer_tiers_verified','green_regular_reviewer','financial_rewards_inactive','guest_context_verified',
 'public_product_available','product_review_section_rendered','guest_submission_controls_private',
 'rating_and_media_form_contract','review_assets_registered','staff_curation_unchanged','review_records_unchanged',
 'customer_accounts_unchanged','commerce_records_unchanged','settings_unchanged','schema_unchanged','private_media_unchanged',
];
function pcr_verify_fail(){throw new RuntimeException();}
function pcr_verify_path($path,$dir=false){
 if(!is_string($path)||$path===''||$path[0]!=='/'||str_contains($path,"\0"))pcr_verify_fail();$cursor='';
 foreach(explode('/',substr($path,1))as$part){if($part===''||$part==='.'||$part==='..')pcr_verify_fail();$cursor.='/'.$part;if(is_link($cursor)||realpath($cursor)!==$cursor)pcr_verify_fail();}
 $st=lstat($path);if(!$st||($dir?!is_dir($path):(!is_file($path)||$st['nlink']!==1)))pcr_verify_fail();return$st;
}
function pcr_verify_mark($key,$pass){global$pcr_verify_report;if(!array_key_exists($key,$pcr_verify_report['checks'])||!is_bool($pass))pcr_verify_fail();$pcr_verify_report['checks'][$key]=$pass;if(!$pass)pcr_verify_fail();}
function pcr_verify_query($sql){
 if(!is_string($sql))pcr_verify_fail();
 if(isset($GLOBALS['pcr_exact_option_sql'])&&hash_equals($GLOBALS['pcr_exact_option_sql'],$sql)){
  unset($GLOBALS['pcr_exact_option_sql']);return$sql;
 }
 $probe=preg_replace('~\A(?:\s+|/\*.*?\*/|--[^\r\n]*(?:\r?\n|$)|\#[^\r\n]*(?:\r?\n|$))*~s','',$sql);
 if(preg_match('/\A(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i',$probe)&&!preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i',$probe)&&!preg_match('/;\s*\S/s',rtrim($probe)))return$sql;
 $GLOBALS['pcr_blocked_writes']=($GLOBALS['pcr_blocked_writes']??0)+1;return'SELECT 0 WHERE 1=0';
}
function pcr_verify_stop_async(){remove_all_actions('action_scheduler_run_queue');if(class_exists('ActionScheduler_QueueRunner',false)){
 $runner=ActionScheduler_QueueRunner::instance();if(!is_callable([$runner,'unhook_dispatch_async_request']))pcr_verify_fail();$runner->unhook_dispatch_async_request();
}}
function pcr_verify_bootstrap($persistent_cache=false){
 $root='/home/u839205597/domains/petish.biz/public_html';if(getenv('HOME')!=='/home/u839205597'||ini_get('allow_url_fopen'))pcr_verify_fail();
 foreach([$root,$root.'/wp-includes']as$p)pcr_verify_path($p,true);foreach(['wp-config.php','wp-load.php','wp-includes/plugin.php']as$p)pcr_verify_path($root.'/'.$p);
 foreach(['WP_HTTP_BLOCK_EXTERNAL'=>true,'WP_ACCESSIBLE_HOSTS'=>'','DISABLE_WP_CRON'=>true,'AUTOMATIC_UPDATER_DISABLED'=>true,'DISALLOW_FILE_MODS'=>true,'WP_USE_THEMES'=>false]as$k=>$v){if(defined($k))pcr_verify_fail();define($k,$v);}
 $_SERVER=['HTTP_HOST'=>'petish.biz','SERVER_NAME'=>'petish.biz','SERVER_PORT'=>'443','HTTPS'=>'on','REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/my-account/','SERVER_PROTOCOL'=>'HTTP/1.1','REMOTE_ADDR'=>'127.0.0.1'];
 require_once$root.'/wp-includes/plugin.php';$GLOBALS['pcr_blocked_writes']=0;add_filter('query','pcr_verify_query',PHP_INT_MAX);
 $no=static fn()=>false;$yes=static fn()=>true;$zero=static fn()=>0;$http=static fn()=>new WP_Error('petish_review_probe_http_blocked','Outbound requests are blocked during verification.');
 $stop=static function(){throw new \PHPMailer\PHPMailer\Exception('Delivery blocked during verification.');};
 $hooks=['enable_maintenance_mode'=>[$no,2],'enable_loading_advanced_cache_dropin'=>[$no,1],
  'jetpack_sync_listener_should_load'=>[$no,1],'jetpack_sync_sender_should_load'=>[$no,1],
  'pre_option_jetpack_sync_settings_disable'=>[$yes,1],'pre_http_request'=>[$http,3],'pre_wp_mail'=>[$yes,2],
  'action_scheduler_allow_async_request_runner'=>[$no,1],'action_scheduler_queue_runner_concurrent_batches'=>[$zero,1]];
 if(!$persistent_cache)$hooks['enable_loading_object_cache_dropin']=[$no,1];
 foreach($hooks as$hook=>$entry)add_filter($hook,$entry[0],PHP_INT_MAX,$entry[1]);add_action('phpmailer_init',$stop,PHP_INT_MAX);
 add_action('wp_loaded','pcr_verify_stop_async',PHP_INT_MAX);
 add_action('all',static function($hook)use($hooks,$stop){
  if($hook==='shutdown')pcr_verify_stop_async();
  if($hook==='query'){remove_filter('query','pcr_verify_query',PHP_INT_MAX);add_filter('query','pcr_verify_query',PHP_INT_MAX);}
  if(isset($hooks[$hook])){remove_filter($hook,$hooks[$hook][0],PHP_INT_MAX);add_filter($hook,$hooks[$hook][0],PHP_INT_MAX,$hooks[$hook][1]);}
  if($hook==='phpmailer_init'){remove_action($hook,$stop,PHP_INT_MAX);add_action($hook,$stop,PHP_INT_MAX);}
 },PHP_INT_MAX);
 require$root.'/wp-load.php';
 if(rtrim(ABSPATH,'/')!==$root||wp_get_environment_type()!=='production'||parse_url(home_url('/'),PHP_URL_HOST)!=='petish.biz'||parse_url(site_url('/'),PHP_URL_HOST)!=='petish.biz'||(defined('PETISH_STAGING_GUARD_ACTIVE')&&PETISH_STAGING_GUARD_ACTIVE))pcr_verify_fail();
 return$root;
}
function pcr_verify_upload_limits(){
 $bytes=static function($name){$raw=trim((string)ini_get($name));if(!preg_match('/\A([0-9]{1,15})([KMG]?)\z/iD',$raw,$m))return-1;return(int)$m[1]*(1024**([''=>0,'K'=>1,'M'=>2,'G'=>3][strtoupper($m[2])]));};
 $post=$bytes('post_max_size');return filter_var(ini_get('file_uploads'),FILTER_VALIDATE_BOOLEAN)&&($post===0||$post>=76*1024*1024)&&$bytes('upload_max_filesize')>=50*1024*1024&&(int)ini_get('max_file_uploads')>=6;
}
function pcr_verify_hash_rows($sql){global$wpdb;$rows=$wpdb->get_results($sql,ARRAY_A);if($wpdb->last_error!==''||!is_array($rows)||count($rows)>250000)pcr_verify_fail();return hash('sha256',serialize($rows));}
function pcr_verify_table($table,$where=''){
 global$wpdb;if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$table))pcr_verify_fail();
 $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));if($wpdb->last_error!=='')pcr_verify_fail();if($exists===null)return null;if($exists!==$table)pcr_verify_fail();
 $columns=$wpdb->get_results("SHOW COLUMNS FROM `{$table}`",ARRAY_A);if($wpdb->last_error!==''||!is_array($columns)||!$columns)pcr_verify_fail();$names=[];
 foreach($columns as$c){if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$c['Field']??''))pcr_verify_fail();$names[]='`'.$c['Field'].'`';}
 return pcr_verify_hash_rows('SELECT SHA2(JSON_ARRAY('.implode(',',$names)."),256) AS row_state FROM `{$table}`".$where.' ORDER BY row_state');
}
function pcr_verify_media_state(){
 $root='/home/u839205597/domains/petish.biz/private/petish-passport';if(!file_exists($root)&&!is_link($root))return null;pcr_verify_path($root,true);$state=[];
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST)as$item){$p=$item->getPathname();pcr_verify_path($p,$item->isDir());if($item->isFile()){$state[substr($p,strlen($root)+1)]=hash_file('sha256',$p);if(count($state)>25000)pcr_verify_fail();}}
 ksort($state);return hash('sha256',serialize($state));
}
function pcr_verify_state(){global$wpdb;$state=[];
 foreach(['posts','postmeta','comments','commentmeta','users','usermeta','options']as$k)if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->$k??''))pcr_verify_fail();
 $state['curation']=pcr_verify_table($wpdb->postmeta," WHERE meta_key LIKE '\\_petish\\_review\\_%'");
 $state['reviews']=[pcr_verify_table($wpdb->comments),pcr_verify_table($wpdb->commentmeta)];
 $state['accounts']=[pcr_verify_table($wpdb->users),pcr_verify_table($wpdb->usermeta)];
 $state['commerce']=[pcr_verify_table($wpdb->posts),pcr_verify_table($wpdb->postmeta)];
 foreach(['wc_orders','wc_order_addresses','wc_order_operational_data','wc_orders_meta','woocommerce_order_items','woocommerce_order_itemmeta']as$s)$state['commerce'][$s]=pcr_verify_table($wpdb->prefix.$s);
 $state['settings']=pcr_verify_table($wpdb->options);$tables=$wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($wpdb->prefix).'%'));if($wpdb->last_error!==''||!is_array($tables)||count($tables)>500)pcr_verify_fail();$schema=[];
 foreach($tables as$t){if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$t))pcr_verify_fail();$row=$wpdb->get_row("SHOW CREATE TABLE `{$t}`",ARRAY_N);if($wpdb->last_error!==''||!is_array($row))pcr_verify_fail();$schema[$t]=hash('sha256',preg_replace('/ AUTO_INCREMENT=[0-9]+/','',$row[1]??''));}
 ksort($schema);$state['schema']=hash('sha256',serialize($schema));$state['media']=pcr_verify_media_state();return$state;
}
function pcr_verify_capture($callback){ob_start();try{$callback();return ob_get_contents();}finally{ob_end_clean();}}
function pcr_verify_contracts(){
 global$wpdb,$product,$post;
 pcr_verify_mark('feature_enabled',get_option('petish_customer_reviews_enabled')==='yes'&&Petish_Customer_Reviews::enabled());
 pcr_verify_mark('module_classes_loaded',class_exists('Petish_Customer_Reviews')&&class_exists('Petish_Customer_Review_Media')&&class_exists('Petish_Customer_Reviews_UI'));
 $menu=apply_filters('woocommerce_account_menu_items',['dashboard'=>'Dashboard','customer-logout'=>'Log out']);$query=apply_filters('woocommerce_get_query_vars',[]);
 pcr_verify_mark('account_endpoint_registered',($query['my-reviews']??null)==='my-reviews'&&isset($menu['my-reviews'])&&has_action('woocommerce_account_my-reviews_endpoint',['Petish_Customer_Reviews_UI','dashboard'])!==false);
 $mutations=true;foreach(['submit','vote','withdraw','report']as$n)$mutations=$mutations&&has_action('admin_post_petish_customer_review_'.$n,['Petish_Customer_Reviews_UI','handle_'.$n])!==false;
 pcr_verify_mark('mutation_hooks_registered',$mutations&&has_action('admin_post_petish_customer_review_media',['Petish_Customer_Review_Media','serve'])!==false);
 pcr_verify_mark('moderation_hooks_registered',has_action('transition_comment_status',['Petish_Customer_Reviews','status_changed'])!==false&&has_filter('pre_comment_approved',['Petish_Customer_Reviews','native_submission_guard'])!==false&&has_action('add_meta_boxes_comment',['Petish_Customer_Reviews_UI','moderation_box'])!==false);
 pcr_verify_mark('privacy_hooks_registered',has_filter('wp_privacy_personal_data_exporters',['Petish_Customer_Reviews','exporters'])!==false&&has_filter('wp_privacy_personal_data_erasers',['Petish_Customer_Reviews','erasers'])!==false);
 $meta=get_registered_meta_keys('comment');$private=true;foreach([Petish_Customer_Reviews::MEDIA,Petish_Customer_Reviews::READY,Petish_Customer_Reviews::MANAGED]as$k)$private=$private&&isset($meta[$k])&&$meta[$k]['show_in_rest']===false;
 pcr_verify_mark('private_media_metadata',$private);
 pcr_verify_mark('photo_video_limits',Petish_Customer_Review_Media::MAX_PHOTOS===4&&Petish_Customer_Review_Media::MAX_PHOTO_BYTES===5*1024*1024&&Petish_Customer_Review_Media::MAX_VIDEO_BYTES===50*1024*1024&&Petish_Passport_Video_Inspector::MAX_SECONDS===20);
 pcr_verify_mark('upload_limits_sufficient',pcr_verify_upload_limits());
 $tiers=[];foreach([0,50,100,250,500]as$c)$tiers[$c]=Petish_Customer_Reviews::tier($c);pcr_verify_mark('reviewer_tiers_verified',array_column($tiers,'threshold')===[0,50,100,250,500]);
 pcr_verify_mark('green_regular_reviewer',($tiers[100]['color']??'')==='#4d9866'&&($tiers[100]['title']??'')==='Regular Reviewer');
 $financial=true;foreach($tiers as$t)$financial=$financial&&($t['benefits_active']??null)===false;
 foreach($GLOBALS['wp_filter']as$hook=>$object)if(preg_match('/(?:coupon|discount|shipping_rates|package_rates|cart_calculate_fees)/i',$hook))foreach($object->callbacks??[]as$callbacks)foreach($callbacks as$entry){$cb=$entry['function']??null;if(is_array($cb)&&is_string($cb[0]??null)&&str_starts_with($cb[0],'Petish_Customer_Review'))$financial=false;}
 pcr_verify_mark('financial_rewards_inactive',$financial);pcr_verify_mark('guest_context_verified',get_current_user_id()===0&&!is_user_logged_in());
 $id=(int)$wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' AND post_password='' AND comment_status='open' ORDER BY ID LIMIT 1");
 $product=$id?wc_get_product($id):false;$post=$id?get_post($id):null;pcr_verify_mark('public_product_available',$product instanceof WC_Product&&$product->get_reviews_allowed());
 $html=pcr_verify_capture(['Petish_Customer_Reviews_UI','product']);pcr_verify_mark('product_review_section_rendered',str_contains($html,'id="petish-customer-reviews"')&&str_contains($html,'Little pets. Honest stories.'));
 pcr_verify_mark('guest_submission_controls_private',!str_contains($html,'name="pcr_nonce"')&&!str_contains($html,'petish_customer_review_submit'));
 $form=pcr_verify_capture(static function()use($product){Petish_Customer_Reviews_UI::template('form',['product'=>$product,'values'=>[]]);});
 pcr_verify_mark('rating_and_media_form_contract',substr_count($form,'type="radio" name="rating"')===5&&str_contains($form,'name="pcr_nonce"')&&str_contains($form,'name="photos[]"')&&str_contains($form,'name="video"')&&str_contains($form,'name="avatar"')&&str_contains($form,'Submit for moderation'));
 // Only the review assets are registered for this read-only synthetic query context.
 $GLOBALS['wp_query']=new WP_Query();$GLOBALS['wp_query']->is_single=true;$GLOBALS['wp_query']->is_singular=true;$GLOBALS['wp_query']->queried_object=$post;$GLOBALS['wp_query']->queried_object_id=$id;$GLOBALS['wp_query']->post=$post;
 Petish_Customer_Reviews_UI::enqueue();pcr_verify_mark('review_assets_registered',wp_style_is('petish-customer-reviews','enqueued')&&wp_script_is('petish-customer-reviews','enqueued'));
}
if(!defined('PETISH_CUSTOMER_REVIEWS_VERIFY_LIBRARY')){
 ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(0);
 $pcr_verify_report=['status'=>'failure','category'=>'input','checks'=>array_fill_keys(PETISH_CUSTOMER_REVIEWS_VERIFY_CHECKS,false)];
 ob_start(static function($ignored,$phase)use(&$pcr_verify_report){return($phase&PHP_OUTPUT_HANDLER_FINAL)?json_encode($pcr_verify_report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n":'';},0,0);
 try{
  if($argc!==2||!preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$argv[1]))pcr_verify_fail();$run=$argv[1];$work='/home/u839205597/.petish-customer-reviews-deploy-'.$run;
  if(__DIR__!==$work)pcr_verify_fail();$st=pcr_verify_path($work,true);pcr_verify_path($work.'/.owned');if(($st['mode']&07777)!==0700||file_get_contents($work.'/.owned')!==$run."\n")pcr_verify_fail();
  pcr_verify_mark('fixed_production_paths',true);$pcr_verify_report['category']='bootstrap';$root=pcr_verify_bootstrap();pcr_verify_mark('production_environment',true);
  pcr_verify_mark('woocommerce_runtime',defined('WC_VERSION')&&WC_VERSION==='11.2.1'&&get_stylesheet()==='dt-the7-child');
  $blocked=apply_filters('pre_http_request',false,[],'https://www.dsers.com/');pcr_verify_mark('outbound_blocked',is_wp_error($blocked)&&$blocked->get_error_code()==='petish_review_probe_http_blocked');
  pcr_verify_mark('mail_blocked',apply_filters('pre_wp_mail',null,[])===true);pcr_verify_mark('async_blocked',DISABLE_WP_CRON&&apply_filters('action_scheduler_allow_async_request_runner',true)===false&&apply_filters('action_scheduler_queue_runner_concurrent_batches',1)===0);
  pcr_verify_mark('jetpack_isolated',apply_filters('jetpack_sync_listener_should_load',true)===false&&apply_filters('jetpack_sync_sender_should_load',true)===false);
  pcr_verify_mark('persistent_cache_isolated',!wp_using_ext_object_cache()&&apply_filters('enable_loading_advanced_cache_dropin',true)===false);
  $pcr_verify_report['category']='snapshot';$before=pcr_verify_state();$pcr_verify_report['category']='contracts';pcr_verify_contracts();$after=pcr_verify_state();
  foreach(['curation'=>'staff_curation_unchanged','reviews'=>'review_records_unchanged','accounts'=>'customer_accounts_unchanged','commerce'=>'commerce_records_unchanged','settings'=>'settings_unchanged','schema'=>'schema_unchanged','media'=>'private_media_unchanged']as$k=>$check)pcr_verify_mark($check,$before[$k]===$after[$k]);
  pcr_verify_mark('database_read_only',has_filter('query','pcr_verify_query')===PHP_INT_MAX&&!isset($GLOBALS['pcr_exact_option_sql']));$pcr_verify_report['status']='success';$pcr_verify_report['category']='complete';
 }catch(Throwable$ignored){}exit($pcr_verify_report['status']==='success'?0:1);
}
