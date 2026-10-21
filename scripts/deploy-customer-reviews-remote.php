<?php
/** Narrow production review release: exact staged files and one reversible option. */
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0);
const PCR_PRODUCTION_HOME = '/home/u839205597';
const PCR_PRODUCTION_ROOT = '/home/u839205597/domains/petish.biz/public_html';
const PETISH_CUSTOMER_REVIEWS_FILES = [
    'includes/class-petish-customer-reviews.php'=>'28a97bcfeaa605de388543ec81cb58153d1bd5270d934a003c695f1fdf2204a4',
    'includes/class-petish-customer-review-media.php'=>'1b0022973f5cf1a5fdf869e6f73b4817ec1eecf9641827b9d6c0bd0b62adce77',
    'includes/class-petish-customer-reviews-ui.php'=>'262968f8272b3307568946ee72a0418e29d3a7a0990603705058a2d1fbed3d43',
    'templates/customer-reviews-progress.php'=>'b7a069b0626a7172a3f69183f1878b360a7c0beaef0330c3c1c23b57c7a72eb9',
    'templates/customer-reviews-form.php'=>'882e81e74cfffc67e7a7c24c4639f4b2f7fba455bcacda8efccd55bce0bb9f91',
    'templates/customer-reviews-card.php'=>'fd93e21c0dcc9fe3ae25bc411982b8d989da8dc77f20743c0877178ceb2d69ad',
    'templates/customer-reviews-product.php'=>'782d8768acaf92eaa8a17b61cbe6f8b8f0cb20d0a26a1e7a55d517eb0294717c',
    'templates/customer-reviews-dashboard.php'=>'ed0702f68e94bb7ac6c102629b6c6b63d3ab570a2b13040906954893fece54d7',
    'assets/customer-reviews.css'=>'b4159fb54cd1776b0cb9dad644b4d8655c02d01c2c9354bf4a633ed2ead23668',
    'assets/customer-reviews.js'=>'3a572b39471fa9c70ad89876758a6c08e1361d38a78a1c636328808345c585a2',
    'includes/class-petish-passport-video-inspector.php'=>'823946702ade6d028b2b643093bd6e23e98f285a5e3597c86dd9f21aa7ccbd26',
    'includes/class-petish-passport-video.php'=>'e9a48491498ef74e20f39518ac0f3a0001f42b27101e41c2980948b8a5f75cd7',
    'assets/passport.js'=>'e4d55c9ec655ce90da907257db29c651dcc8cd2eab5fd39eec63870fac2205cc',
    'templates/moment-form.php'=>'c3a726d43d21e8a2a93966a66702493c3f2dea78f9ef184fb3a2d9bddf2c67c2',
    'petish-passport.php'=>'78b4ed9ea8dc265f32ff4074f42b7bc711e2cae982e5a1673ff7ca78d838b159',
];
const PETISH_CUSTOMER_REVIEWS_BASELINES = [
    'assets/customer-reviews.css'=>'ef60973cbfb496bfcb56343164c1482967c9a0eec3f972d025b6e741a4742afb',
    'includes/class-petish-passport-video-inspector.php'=>'b5a5a585a0bd2925614ff324af63c0d20ceca4daf8fa2aaff37b7bc038919cab',
    'includes/class-petish-passport-video.php'=>'da5ea805f7956d201985fe5ae4892ba4ef2aa4c857f43e98c5e63b29ac4d5f78',
    'assets/passport.js'=>'74a949e388871f852b674a4cd8029d0846e34122647d82759575417f41fd7ec4',
    'templates/moment-form.php'=>'19bb82196515510d09c22ded39768834d39cba713c91f77857d9a9b670731c0e',
    'petish-passport.php'=>'6c8ebd35ab44dbb1474548cff11b2391cc7204316f1a7296b21723c2b8caef2d',
];

function pcr_release_fail() { throw new RuntimeException(); }
function pcr_release_path($path, $directory=false) {
    if (!is_string($path) || $path==='' || $path[0]!=='/' || strpos($path,"\0")!==false) pcr_release_fail();
    $cursor='';
    foreach(explode('/',substr($path,1)) as $part) {
        if($part==='' || $part==='.' || $part==='..') pcr_release_fail();
        $cursor.='/'.$part;
        if(is_link($cursor) || realpath($cursor)!==$cursor) pcr_release_fail();
    }
    $info=lstat($path);
    if(!$info || ($directory ? !is_dir($path) : (!is_file($path) || $info['nlink']!==1))) pcr_release_fail();
    return $path;
}
function pcr_release_json($path) {
    pcr_release_path($path);
    if((fileperms($path)&07777)!==0600 || filesize($path)>1048576) pcr_release_fail();
    $value=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($value)) pcr_release_fail();
    return $value;
}
function pcr_release_write($path,$content,$mode=0600) {
    pcr_release_path(dirname($path),true);
    if(file_exists($path) || is_link($path)) pcr_release_fail();
    $handle=fopen($path,'xb'); if(!$handle) pcr_release_fail();
    try {
        if(!chmod($path,$mode) || fwrite($handle,$content)!==strlen($content) || !fflush($handle)) pcr_release_fail();
    } catch(Throwable $error) {
        $owned=fstat($handle);$current=lstat($path);
        if($owned&&$current&&!is_link($path)&&$owned['dev']===$current['dev']&&$owned['ino']===$current['ino'])unlink($path);
        throw $error;
    } finally { fclose($handle); }
}
function pcr_release_atomic($path,$content,$mode,$run) {
    pcr_release_path(dirname($path),true);
    if(file_exists($path) || is_link($path)) pcr_release_path($path);
    $temporary=dirname($path).'/.petish-customer-reviews-'.$run.'-'.bin2hex(random_bytes(8));
    try {
        pcr_release_write($temporary,$content,$mode);
        if(!rename($temporary,$path)) pcr_release_fail();
    } finally { if(is_file($temporary) && !is_link($temporary)) unlink($temporary); }
}
function pcr_release_tree($root,$exclude=[]) {
    pcr_release_path($root,true); $files=[];
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as$file) {
        $path=$file->getPathname();
        if($file->isLink() || (!$file->isFile()&&!$file->isDir())) pcr_release_fail();
        pcr_release_path($path,$file->isDir());
        if($file->isFile()) {
            $relative=substr($path,strlen($root)+1);
            if(!in_array($relative,$exclude,true)) $files[$relative]=hash_file('sha256',$path);
            if(count($files)>25000) pcr_release_fail();
        }
    }
    ksort($files); return $files;
}
function pcr_release_protected($root,$plugin) {
    $state=['child_theme'=>pcr_release_tree($root.'/wp-content/themes/dt-the7-child'),
        'passport'=>pcr_release_tree($plugin,array_keys(PETISH_CUSTOMER_REVIEWS_FILES)),
        'fulfilment'=>pcr_release_tree($root.'/wp-content/plugins/petish-fulfilment'),
        'mu'=>pcr_release_tree($root.'/wp-content/mu-plugins',['zz-petish-customer-reviews-capacity.php'])];
    $private=dirname($root).'/private/petish-passport';
    $state['private_passport']=file_exists($private)||is_link($private)?pcr_release_tree($private):null;
    foreach(['wp-config.php','.htaccess','.user.ini'] as$name) {
        $path=$root.'/'.$name;
        if(file_exists($path)||is_link($path)) { pcr_release_path($path); $state[$name]=hash_file('sha256',$path); }
        elseif($name==='wp-config.php') pcr_release_fail();
    }
    return $state;
}
function pcr_release_payload($payload) {
    pcr_release_path($payload,true);
    if((fileperms($payload)&07777)!==0700) pcr_release_fail();
    $manifest=pcr_release_json($payload.'/manifest.json');
    $expected=PETISH_CUSTOMER_REVIEWS_FILES; ksort($expected); ksort($manifest);
    if($manifest!==$expected) pcr_release_fail();
    $found=pcr_release_tree($payload,['manifest.json']);
    if($found!==$expected) pcr_release_fail();
    foreach(PETISH_CUSTOMER_REVIEWS_FILES as$name=>$hash) if(pathinfo($name,PATHINFO_EXTENSION)==='php') token_get_all(file_get_contents($payload.'/'.$name),TOKEN_PARSE);

}
function pcr_release_live($plugin) {
    $state=[]; $changed=0;
    foreach(PETISH_CUSTOMER_REVIEWS_FILES as$name=>$expected) {
        $path=$plugin.'/'.$name;
        if(file_exists($path)||is_link($path)) {
            pcr_release_path($path); $hash=hash_file('sha256',$path);
            if($hash!==$expected && $hash!==(PETISH_CUSTOMER_REVIEWS_BASELINES[$name]??null)) pcr_release_fail();
            $state[$name]=['present'=>true,'sha256'=>$hash,'mode'=>fileperms($path)&0777];
        } else {
            if(array_key_exists($name,PETISH_CUSTOMER_REVIEWS_BASELINES)) pcr_release_fail();
            // Existing ancestors must also be canonical, even for absent files.
            $parent=dirname($path);
            while(!file_exists($parent) && !is_link($parent)) $parent=dirname($parent);
            pcr_release_path($parent,true);
            $state[$name]=['present'=>false,'sha256'=>null,'mode'=>null];
        }
        if(!$state[$name]['present']||$state[$name]['sha256']!==$expected) $changed++;
    }
    return [$state,$changed];
}
/** Refuse an edit made since the private snapshot was captured. */
function pcr_release_preimage($path,$entry) {
    clearstatcache(true,$path);
    if($entry['present']) {
        pcr_release_path($path);
        if(!hash_equals($entry['sha256'],hash_file('sha256',$path))
            || (fileperms($path)&0777)!==$entry['mode']) pcr_release_fail();
    }elseif(file_exists($path)||is_link($path)) pcr_release_fail();
}
/** A rollback may replace only this release's candidate or its exact preimage. */
function pcr_release_rollback_destination($path,$name,$entry) {
    clearstatcache(true,$path);
    if(file_exists($path)||is_link($path)) {
        pcr_release_path($path); $hash=hash_file('sha256',$path);
        if($hash!==PETISH_CUSTOMER_REVIEWS_FILES[$name] && (!$entry['present']||$hash!==$entry['sha256'])) pcr_release_fail();
    }elseif($entry['present']) pcr_release_fail();
}
function pcr_release_require_candidates($plugin) {
    foreach(PETISH_CUSTOMER_REVIEWS_FILES as$name=>$expected) {
        pcr_release_path($plugin.'/'.$name);
        if(!hash_equals($expected,hash_file('sha256',$plugin.'/'.$name))) pcr_release_fail();
    }
}
function pcr_release_lock($base,$run,$acquire=false) {
    $lock=$base.'/deploy.lock';
    if($acquire) {
        if(file_exists($lock)||is_link($lock)||!mkdir($lock,0700)) pcr_release_fail();
        pcr_release_write($lock.'/owner',$run."\n");
    }
    pcr_release_path($lock,true); pcr_release_path($lock.'/owner');
    if((fileperms($lock)&07777)!==0700 || trim(file_get_contents($lock.'/owner'))!==$run) pcr_release_fail();
    return $lock;
}
function pcr_release_unlock($lock) {
    if(!unlink($lock.'/owner')||!rmdir($lock)) pcr_release_fail();
}
const PETISH_CUSTOMER_REVIEWS_RELEASE_CHECKS = [
 'fixed_production_paths','candidate_manifest_verified','candidate_syntax_verified','live_files_compatible',
 'protected_state_unchanged','rollback_snapshot_created','files_deployed','flag_journal_written','flag_enabled',
 'flag_restored','rollback_completed','release_committed','lock_owned','maintenance_guard_created',
 'maintenance_guard_removed','maintenance_guard_retained','capacity_probe_installed','capacity_probe_removed',
 'production_environment','database_writes_scoped','cache_purge_probe_ready','rollback_not_needed',
];
const PETISH_CUSTOMER_REVIEWS_RELEASE_REQUIRED = [
 'inspect'=>['fixed_production_paths','candidate_manifest_verified','candidate_syntax_verified','live_files_compatible','production_environment','database_writes_scoped'],
 'apply'=>['fixed_production_paths','candidate_manifest_verified','candidate_syntax_verified','live_files_compatible','rollback_snapshot_created','files_deployed','protected_state_unchanged','lock_owned','maintenance_guard_created','maintenance_guard_retained','capacity_probe_installed'],
 'enable'=>['flag_journal_written','flag_enabled','production_environment','database_writes_scoped','protected_state_unchanged','lock_owned','maintenance_guard_retained'],
 'resume'=>['files_deployed','flag_enabled','protected_state_unchanged','lock_owned','maintenance_guard_removed','cache_purge_probe_ready'],
 'rollback'=>['flag_restored','rollback_completed','maintenance_guard_removed','protected_state_unchanged','cache_purge_probe_ready'],
 'rollback-finalize'=>['flag_restored','rollback_completed','maintenance_guard_removed','capacity_probe_removed','protected_state_unchanged'],
 'commit'=>['files_deployed','flag_enabled','protected_state_unchanged','release_committed','maintenance_guard_removed','capacity_probe_removed'],
];
const PETISH_CUSTOMER_REVIEWS_RELEASE_CATEGORIES = ['input','paths','payload','drift','snapshot','maintenance','lock','deployment','flag','bootstrap','cache','probe','rollback','commit','complete'];
function pcr_release_mark($key,$pass=true){global$pcr_release_report;if(!array_key_exists($key,$pcr_release_report['checks'])||!is_bool($pass))pcr_release_fail();$pcr_release_report['checks'][$key]=$pass;if(!$pass)pcr_release_fail();}
function pcr_release_journal($release,$run){
 $journal=pcr_release_json($release.'/journal.json');
 if(array_keys($journal)!==['run','files','protected']||$journal['run']!==$run||!is_array($journal['files'])||array_keys($journal['files'])!==array_keys(PETISH_CUSTOMER_REVIEWS_FILES)||!is_array($journal['protected']))pcr_release_fail();
 foreach($journal['files']as$name=>$entry){
  if(!is_array($entry)||array_keys($entry)!==['present','sha256','mode']||!is_bool($entry['present']))pcr_release_fail();
  if($entry['present']){$allowed=[PETISH_CUSTOMER_REVIEWS_FILES[$name],PETISH_CUSTOMER_REVIEWS_BASELINES[$name]??null];
   if(!in_array($entry['sha256'],$allowed,true)||!is_int($entry['mode'])||$entry['mode']<0||$entry['mode']>0777)pcr_release_fail();$backup=$release.'/backup/'.array_search($name,array_keys(PETISH_CUSTOMER_REVIEWS_FILES),true);pcr_release_path($backup);if(hash_file('sha256',$backup)!==$entry['sha256'])pcr_release_fail();
  }elseif($entry['sha256']!==null||$entry['mode']!==null)pcr_release_fail();
 }return$journal;
}
function pcr_release_check_protected($root,$plugin,$journal,$allow_private_changes=false){
 $current=pcr_release_protected($root,$plugin);$expected=$journal['protected'];
 // Customers may add their own photos after resume. Never overwrite their files.
 if($allow_private_changes)$expected['private_passport']=$current['private_passport'];
 pcr_release_mark('protected_state_unchanged',$current===$expected);return$current;
}
function pcr_release_maintenance($root,$release,$run,$remove=false){
 $path=$root.'/.maintenance';$receipt=$release.'/maintenance.json';$content="<?php /* Petish customer reviews ".$run." */\n\$upgrading = time();\n";$hash=hash('sha256',$content);
 if(file_exists($receipt)||is_link($receipt)){$data=pcr_release_json($receipt);if($data!==['run'=>$run,'sha256'=>$hash])pcr_release_fail();}
 elseif($remove){if(file_exists($path)||is_link($path))pcr_release_fail();return;}
 else pcr_release_write($receipt,json_encode(['run'=>$run,'sha256'=>$hash],JSON_THROW_ON_ERROR));
 if(file_exists($path)||is_link($path)){pcr_release_path($path);if((fileperms($path)&07777)!==0600||hash_file('sha256',$path)!==$hash)pcr_release_fail();}
 elseif(!$remove)pcr_release_write($path,$content);
 if($remove&&file_exists($path)&&!unlink($path))pcr_release_fail();
 pcr_release_mark($remove?'maintenance_guard_removed':'maintenance_guard_created');
}
function pcr_release_probe($root,$release,$run,$remove=false){
 $path=$root.'/wp-content/mu-plugins/zz-petish-customer-reviews-capacity.php';$receipt=$release.'/capacity.json';
 if($remove){
  if(file_exists($path)||is_link($path)){$record=pcr_release_json($receipt);pcr_release_path($path);if(($record['run']??null)!==$run||!hash_equals($record['sha256']??'',hash_file('sha256',$path))||!unlink($path))pcr_release_fail();}
  pcr_release_mark('capacity_probe_removed');return;
 }
 if(file_exists($path)||is_link($path)||file_exists($receipt)||is_link($receipt))pcr_release_fail();$expires=time()+3600;
 $token_path=__DIR__.'/.probe-token';pcr_release_path($token_path);$token=file_get_contents($token_path);if((fileperms($token_path)&07777)!==0600||!preg_match('/\A[a-f0-9]{64}\n\z/D',$token))pcr_release_fail();$token_hash=hash('sha256',trim($token));unset($token);
 $prefix="<?php\n// Temporary run-owned upload capacity probe; no normal request is affected.\n(function(){\n";
 $code=$prefix.'[$root,$run,$expires,$token_hash]='.var_export([$root,$run,$expires,$token_hash],true).";\n".<<<'PROBE'
 if(!defined('ABSPATH')||rtrim(ABSPATH,'/')!==$root||time()>$expires||time()<$expires-3600||($_SERVER['REQUEST_METHOD']??'')!=='GET'||parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)!=='/wp-admin/admin-post.php'||count($_GET)!==2||($_GET['action']??null)!=='petish_customer_review_capacity'||($_GET['run']??null)!==$run||!is_string($_SERVER['HTTP_X_PETISH_REVIEW_PROBE']??null)||!hash_equals($token_hash,hash('sha256',$_SERVER['HTTP_X_PETISH_REVIEW_PROBE'])))return;
 if(wp_get_environment_type()!=='production')return;
 $bytes=static function($name){$raw=trim((string)ini_get($name));if(!preg_match('/\A([0-9]{1,15})([KMG]?)\z/iD',$raw,$m))return-1;return(int)$m[1]*(1024**([''=>0,'K'=>1,'M'=>2,'G'=>3][strtoupper($m[2])]));};
 $post=$bytes('post_max_size');$ok=filter_var(ini_get('file_uploads'),FILTER_VALIDATE_BOOLEAN)&&($post===0||$post>=76*1024*1024)&&$bytes('upload_max_filesize')>=50*1024*1024&&(int)ini_get('max_file_uploads')>=6;
 if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);if(!defined('DISABLE_WP_CRON'))define('DISABLE_WP_CRON',true);
 add_action('phpmailer_init',static function(){throw new \PHPMailer\PHPMailer\Exception('Delivery blocked for release probe.');},PHP_INT_MAX);
 add_filter('pre_spawn_cron',static fn()=>false,PHP_INT_MAX);
 $no=static fn()=>false;$yes=static fn()=>true;$zero=static fn()=>0;$http=static fn()=>new WP_Error('petish_release_probe','Outbound requests blocked for this release probe.');
 $read=static function($sql){$probe=preg_replace('~\A(?:\s+|/\*.*?\*/|--[^\r\n]*(?:\r?\n|$)|\#[^\r\n]*(?:\r?\n|$))*~s','',$sql);return preg_match('/\A(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i',$probe)&&!preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i',$probe)&&!preg_match('/;\s*\S/s',rtrim($probe))?$sql:'SELECT 0 WHERE 1=0';};
 $hooks=['query'=>[$read,1],'pre_http_request'=>[$http,3],'pre_wp_mail'=>[$yes,2],'jetpack_sync_listener_should_load'=>[$no,1],'jetpack_sync_sender_should_load'=>[$no,1],'pre_option_jetpack_sync_settings_disable'=>[$yes,1],'action_scheduler_allow_async_request_runner'=>[$no,1],'action_scheduler_queue_runner_concurrent_batches'=>[$zero,1],'litespeed_conf_load_option_purge-post_all'=>[$no,1]];
 foreach($hooks as$hook=>$entry)add_filter($hook,$entry[0],PHP_INT_MAX,$entry[1]);
 add_action('all',static function($hook)use($hooks){if(isset($hooks[$hook])){remove_filter($hook,$hooks[$hook][0],PHP_INT_MAX);add_filter($hook,$hooks[$hook][0],PHP_INT_MAX,$hooks[$hook][1]);}},PHP_INT_MAX);
 add_action('wp_loaded',static function()use($ok,$root){
  global$wpdb;remove_all_actions('action_scheduler_run_queue');if(class_exists('ActionScheduler_QueueRunner',false))ActionScheduler_QueueRunner::instance()->unhook_dispatch_async_request();
  $good=class_exists('LiteSpeed\\Purge')&&has_action('litespeed_purge_post')!==false&&has_action('litespeed_purge_url')!==false&&!headers_sent()&&!defined('LITESPEED_CLI');
  if($good)$good=!LiteSpeed\Purge::get_instance()->conf('purge-post_all');
  $ids=$good?$wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' ORDER BY ID LIMIT 10001"):[];
  $good=$good&&$wpdb->last_error===''&&is_array($ids)&&count($ids)>0&&count($ids)<=10000;
  if($good){foreach($ids as$id)do_action('litespeed_purge_post',(int)$id);$account=(int)get_option('woocommerce_myaccount_page_id');if($account)do_action('litespeed_purge_post',$account);
   foreach(['assets/customer-reviews.css','assets/customer-reviews.js','assets/passport.js']as$name)do_action('litespeed_purge_url',plugins_url($name,$root.'/wp-content/plugins/petish-passport/petish-passport.php'));
   $purge=LiteSpeed\Purge::output();$good=is_string($purge)&&str_starts_with(strtolower($purge),'x-litespeed-purge:')&&!str_contains($purge,'*');if($good)header($purge);
  }
  header('Cache-Control: private, no-store, max-age=0');header('Content-Type: text/plain; charset=utf-8');header('X-Petish-Review-Upload-Limits: '.($ok?'sufficient':'insufficient'));header('X-Petish-Review-Cache-Purge: '.($good?'dispatched':'failed'));echo"Petish release probe.\n";exit;
 },PHP_INT_MAX);
})();
PROBE;
 pcr_release_write($receipt,json_encode(['run'=>$run,'sha256'=>hash('sha256',$code)],JSON_THROW_ON_ERROR));pcr_release_write($path,$code,0644);pcr_release_mark('capacity_probe_installed');
}
function pcr_release_boot($persistent=false){
 static$booted=false;if($booted)return;$booted=true;
 define('PETISH_CUSTOMER_REVIEWS_VERIFY_LIBRARY',true);pcr_release_path(__DIR__.'/verify-customer-reviews.php');require __DIR__.'/verify-customer-reviews.php';pcr_verify_bootstrap($persistent);
 pcr_release_mark('production_environment');pcr_release_mark('database_writes_scoped',has_filter('query','pcr_verify_query')===PHP_INT_MAX);
}
function pcr_release_flag_row(){global$wpdb;if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->options??''))pcr_release_fail();$row=$wpdb->get_row($wpdb->prepare("SELECT option_value,autoload FROM `{$wpdb->options}` WHERE option_name=%s",'petish_customer_reviews_enabled'),ARRAY_A);if($wpdb->last_error!=='')pcr_release_fail();
 if($row!==null&&(!is_array($row)||array_keys($row)!==['option_value','autoload']||!in_array($row['option_value'],['yes','no'],true)||!in_array($row['autoload'],['yes','no','on','off','auto','auto-on','auto-off'],true)))pcr_release_fail();return$row;
}
function pcr_release_flag_sql($sql){global$wpdb;$GLOBALS['pcr_exact_option_sql']=$sql;$changed=$wpdb->query($sql);if(isset($GLOBALS['pcr_exact_option_sql'])||$changed===false||$wpdb->last_error!=='')pcr_release_fail();foreach(['petish_customer_reviews_enabled','alloptions','notoptions']as$key)wp_cache_delete($key,'options');}
function pcr_release_enable($release){
 global$wpdb;pcr_release_boot(true);if(!pcr_verify_upload_limits())pcr_release_fail();$old=pcr_release_flag_row();
 if(file_exists($release.'/flag.json')||is_link($release.'/flag.json'))pcr_release_fail();pcr_release_write($release.'/flag.json',json_encode(['previous'=>$old],JSON_THROW_ON_ERROR));pcr_release_mark('flag_journal_written');
 if($old===null)$sql=$wpdb->prepare("INSERT INTO `{$wpdb->options}` (option_name,option_value,autoload) VALUES (%s,%s,%s)",'petish_customer_reviews_enabled','yes','off');
 else$sql=$wpdb->prepare("UPDATE `{$wpdb->options}` SET option_value=%s,autoload=%s WHERE option_name=%s AND option_value=%s AND autoload=%s",'yes','off','petish_customer_reviews_enabled',$old['option_value'],$old['autoload']);
 pcr_release_flag_sql($sql);pcr_release_mark('flag_enabled',pcr_release_flag_row()===['option_value'=>'yes','autoload'=>'off']);
}
function pcr_release_flag_restore($release){
 if(!file_exists($release.'/flag.json')&&!is_link($release.'/flag.json')){pcr_release_mark('flag_restored');return;}
 pcr_release_boot(true);global$wpdb;$record=pcr_release_json($release.'/flag.json');if(array_keys($record)!==['previous'])pcr_release_fail();$old=$record['previous'];
 if($old!==null&&(!is_array($old)||array_keys($old)!==['option_value','autoload']||!in_array($old['option_value'],['yes','no'],true)||!in_array($old['autoload'],['yes','no','on','off','auto','auto-on','auto-off'],true)))pcr_release_fail();
 $now=pcr_release_flag_row();if($now===$old){foreach(['petish_customer_reviews_enabled','alloptions','notoptions']as$key)wp_cache_delete($key,'options');pcr_release_mark('flag_restored',get_option('petish_customer_reviews_enabled',null)===($old['option_value']??null));return;}if($now!==['option_value'=>'yes','autoload'=>'off'])pcr_release_fail();
 if($old===null)$sql=$wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name=%s AND option_value=%s AND autoload=%s",'petish_customer_reviews_enabled','yes','off');
 else$sql=$wpdb->prepare("UPDATE `{$wpdb->options}` SET option_value=%s,autoload=%s WHERE option_name=%s AND option_value=%s AND autoload=%s",$old['option_value'],$old['autoload'],'petish_customer_reviews_enabled','yes','off');
 pcr_release_flag_sql($sql);pcr_release_mark('flag_restored',pcr_release_flag_row()===$old);
}
function pcr_release_ensure_probe($root,$release,$run){
 $path=$root.'/wp-content/mu-plugins/zz-petish-customer-reviews-capacity.php';$receipt=$release.'/capacity.json';
 if(!file_exists($path)&&!is_link($path)){
  if(file_exists($receipt)||is_link($receipt)){$record=pcr_release_json($receipt);if(array_keys($record)!==['run','sha256']||$record['run']!==$run||!preg_match('/\A[a-f0-9]{64}\z/D',$record['sha256']??'')||!unlink($receipt))pcr_release_fail();}
  pcr_release_probe($root,$release,$run);
 }
}
function pcr_release_purge_ready($root,$release,$run){
 $record=pcr_release_json($release.'/capacity.json');$path=$root.'/wp-content/mu-plugins/zz-petish-customer-reviews-capacity.php';pcr_release_path($path);
 pcr_release_mark('cache_purge_probe_ready',($record['run']??null)===$run&&hash_equals($record['sha256']??'',hash_file('sha256',$path)));
}
function pcr_release_restore($root,$plugin,$release,$run){
 $journal=pcr_release_journal($release,$run);pcr_release_check_protected($root,$plugin,$journal,true);
 foreach($journal['files']as$name=>$entry)pcr_release_rollback_destination($plugin.'/'.$name,$name,$entry);
 pcr_release_maintenance($root,$release,$run);pcr_release_flag_restore($release);
 // Bootstrap is last on install, first on rollback; no customer sees mixed code.
 foreach(array_reverse(array_keys(PETISH_CUSTOMER_REVIEWS_FILES))as$name){$entry=$journal['files'][$name];$path=$plugin.'/'.$name;pcr_release_rollback_destination($path,$name,$entry);
  if($entry['present'])pcr_release_atomic($path,file_get_contents($release.'/backup/'.array_search($name,array_keys(PETISH_CUSTOMER_REVIEWS_FILES),true)),$entry['mode'],$run);
  elseif(file_exists($path)&&!unlink($path))pcr_release_fail();
 }
 foreach($journal['files']as$name=>$entry)pcr_release_preimage($plugin.'/'.$name,$entry);
 pcr_release_ensure_probe($root,$release,$run);pcr_release_purge_ready($root,$release,$run);pcr_release_check_protected($root,$plugin,$journal,true);
 pcr_release_maintenance($root,$release,$run,true);if(!file_exists($release.'/rolled-back'))pcr_release_write($release.'/rolled-back',"verified\n");pcr_release_mark('rollback_completed');
}
$pcr_release_report=['status'=>'failure','category'=>'input','checks'=>array_fill_keys(PETISH_CUSTOMER_REVIEWS_RELEASE_CHECKS,false),'changed_file_count'=>0];
ob_start(static function($ignored,$phase)use(&$pcr_release_report){return($phase&PHP_OUTPUT_HANDLER_FINAL)?json_encode($pcr_release_report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n":'';},0,0);
$mutation=false;$lock=null;$root=null;$plugin=null;$release=null;$run=null;$apply_complete=false;
try{
 if($argc!==4||!array_key_exists($argv[1],PETISH_CUSTOMER_REVIEWS_RELEASE_REQUIRED)||!preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$argv[2]))pcr_release_fail();[$script,$phase,$run,$payload]=$argv;
 $work=PCR_PRODUCTION_HOME.'/.petish-customer-reviews-deploy-'.$run;if(getenv('HOME')!==PCR_PRODUCTION_HOME||__DIR__!==$work||$payload!==$work.'/payload'||ini_get('allow_url_fopen'))pcr_release_fail();
 $pcr_release_report['category']='paths';pcr_release_path($work,true);pcr_release_path($work.'/.owned');if((fileperms($work)&07777)!==0700||(fileperms($work.'/.owned')&07777)!==0600||file_get_contents($work.'/.owned')!==$run."\n")pcr_release_fail();
 $root=PCR_PRODUCTION_ROOT;$plugin=$root.'/wp-content/plugins/petish-passport';pcr_release_path($root,true);pcr_release_path($plugin,true);pcr_release_path($root.'/wp-config.php');pcr_release_mark('fixed_production_paths');
 $pcr_release_report['category']='payload';pcr_release_payload($payload);pcr_release_mark('candidate_manifest_verified');pcr_release_mark('candidate_syntax_verified');
 $base=PCR_PRODUCTION_HOME.'/.petish-customer-reviews-releases';$release=$base.'/'.$run;
 if($phase==='rollback'&&!file_exists($release.'/journal.json')&&!is_link($release.'/journal.json')){
  // Apply may have stopped before a durable snapshot, before any live write.
  if(file_exists($root.'/.maintenance')||is_link($root.'/.maintenance')||file_exists($root.'/wp-content/mu-plugins/zz-petish-customer-reviews-capacity.php')||is_link($root.'/wp-content/mu-plugins/zz-petish-customer-reviews-capacity.php'))pcr_release_fail();
  foreach(['flag.json','pending','resumed','maintenance.json','capacity.json','committed','rolled-back']as$n)if(file_exists($release.'/'.$n)||is_link($release.'/'.$n))pcr_release_fail();
  [$live,$changed]=pcr_release_live($plugin);$pcr_release_report['changed_file_count']=$changed;
  if(file_exists($base.'/deploy.lock')){$lock=pcr_release_lock($base,$run);pcr_release_unlock($lock);$lock=null;}
  foreach(['flag_restored','rollback_completed','maintenance_guard_removed','capacity_probe_removed','protected_state_unchanged','rollback_not_needed','cache_purge_probe_ready']as$k)pcr_release_mark($k);
 }elseif(in_array($phase,['inspect','apply'],true)){
  $pcr_release_report['category']='drift';[$live,$changed]=pcr_release_live($plugin);$pcr_release_report['changed_file_count']=$changed;pcr_release_mark('live_files_compatible');
  if($phase==='inspect'){pcr_release_protected($root,$plugin);pcr_release_boot();}
  else{
   if(file_exists($root.'/.maintenance')||is_link($root.'/.maintenance'))pcr_release_fail();$pcr_release_report['category']='lock';
   if(!file_exists($base)&&!is_link($base)&&!mkdir($base,0700))pcr_release_fail();pcr_release_path($base,true);if((fileperms($base)&07777)!==0700)pcr_release_fail();$lock=pcr_release_lock($base,$run,true);pcr_release_mark('lock_owned');
   $pcr_release_report['category']='snapshot';if(file_exists($release)||is_link($release)||!mkdir($release,0700)||!mkdir($release.'/backup',0700))pcr_release_fail();
   foreach(['includes','templates','assets']as$d)pcr_release_path($plugin.'/'.$d,true);$protected=pcr_release_protected($root,$plugin);
   foreach($live as$name=>$entry)if($entry['present'])pcr_release_write($release.'/backup/'.array_search($name,array_keys(PETISH_CUSTOMER_REVIEWS_FILES),true),file_get_contents($plugin.'/'.$name));
   pcr_release_write($release.'/journal.json',json_encode(['run'=>$run,'files'=>$live,'protected'=>$protected],JSON_THROW_ON_ERROR));$journal=pcr_release_journal($release,$run);pcr_release_mark('rollback_snapshot_created');
   $pcr_release_report['category']='maintenance';$mutation=true;pcr_release_maintenance($root,$release,$run);pcr_release_probe($root,$release,$run);
   $pcr_release_report['category']='deployment';foreach($live as$name=>$entry)pcr_release_preimage($plugin.'/'.$name,$entry);pcr_release_check_protected($root,$plugin,$journal);
   foreach(PETISH_CUSTOMER_REVIEWS_FILES as$name=>$hash){pcr_release_preimage($plugin.'/'.$name,$live[$name]);if($live[$name]['present']&&$live[$name]['sha256']===$hash)continue;pcr_release_atomic($plugin.'/'.$name,file_get_contents($payload.'/'.$name),0644,$run);}
   pcr_release_require_candidates($plugin);pcr_release_mark('files_deployed');pcr_release_check_protected($root,$plugin,$journal);pcr_release_write($release.'/pending',"verified\n");pcr_release_mark('maintenance_guard_retained');$apply_complete=true;
  }
 }else{
  $pcr_release_report['category']='lock';pcr_release_path($base,true);pcr_release_path($release,true);if(file_exists($release.'/committed')||is_link($release.'/committed'))pcr_release_fail();
  if(in_array($phase,['rollback','rollback-finalize'],true)&&file_exists($release.'/rolled-back')){
   $journal=pcr_release_journal($release,$run);foreach($journal['files']as$name=>$entry)pcr_release_preimage($plugin.'/'.$name,$entry);pcr_release_check_protected($root,$plugin,$journal,true);pcr_release_flag_restore($release);if($phase==='rollback-finalize')pcr_release_probe($root,$release,$run,true);else pcr_release_purge_ready($root,$release,$run);pcr_release_maintenance($root,$release,$run,true);pcr_release_mark('rollback_completed');
   if($phase==='rollback-finalize'&&file_exists($base.'/deploy.lock')){$lock=pcr_release_lock($base,$run);pcr_release_unlock($lock);$lock=null;}
  }else{
   if($phase==='rollback-finalize')pcr_release_fail();$lock=pcr_release_lock($base,$run);pcr_release_mark('lock_owned');$journal=pcr_release_journal($release,$run);
   if($phase==='rollback'){$pcr_release_report['category']='rollback';pcr_release_restore($root,$plugin,$release,$run);}
   else{
    pcr_release_path($release.'/pending');pcr_release_require_candidates($plugin);pcr_release_mark('files_deployed');pcr_release_check_protected($root,$plugin,$journal,$phase==='commit');
    if($phase==='enable'){$pcr_release_report['category']='flag';pcr_release_maintenance($root,$release,$run);pcr_release_enable($release);pcr_release_mark('maintenance_guard_retained');}
    elseif($phase==='resume'){$pcr_release_report['category']='cache';pcr_release_boot();pcr_release_mark('flag_enabled',pcr_release_flag_row()===['option_value'=>'yes','autoload'=>'off']);pcr_release_purge_ready($root,$release,$run);pcr_release_maintenance($root,$release,$run,true);pcr_release_write($release.'/resumed',"verified\n");}
    elseif($phase==='commit'){$pcr_release_report['category']='commit';if(file_exists($root.'/.maintenance')||is_link($root.'/.maintenance'))pcr_release_fail();pcr_release_path($release.'/resumed');pcr_release_boot();pcr_release_mark('flag_enabled',pcr_release_flag_row()===['option_value'=>'yes','autoload'=>'off']);pcr_release_probe($root,$release,$run,true);pcr_release_check_protected($root,$plugin,$journal,true);pcr_release_mark('maintenance_guard_removed');pcr_release_write($release.'/committed',"verified\n");pcr_release_mark('release_committed');pcr_release_unlock($lock);$lock=null;}
   }
  }
 }
 foreach(PETISH_CUSTOMER_REVIEWS_RELEASE_REQUIRED[$phase]as$k)if(!$pcr_release_report['checks'][$k])pcr_release_fail();$pcr_release_report['status']='success';$pcr_release_report['category']='complete';
}catch(Throwable$ignored){
 if(($phase??null)==='apply'&&$mutation&&!$apply_complete){try{pcr_release_restore($root,$plugin,$release,$run);}catch(Throwable$ignored_again){$pcr_release_report['category']='rollback';}}
 elseif(($phase??null)==='apply'&&!$mutation&&$lock!==null){try{pcr_release_unlock($lock);$lock=null;}catch(Throwable$ignored_again){}}
}
exit($pcr_release_report['status']==='success'?0:1);
