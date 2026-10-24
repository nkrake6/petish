<?php
/** Fixed production cancellation/FAQ release. No activation, messages or order mutations. */
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0);
const CXL_PRODUCTION_HOME = '/home/u839205597';
const CXL_PRODUCTION_ROOT = '/home/u839205597/domains/petish.biz/public_html';
const PETISH_CANCELLATION_RELEASE_FILES = [
 'plugins/petish-fulfilment/README.md'=>'2d2c989c1a9a43db4d1a132db291d05d4f661e2df4fbbc9c9777b5e3c62a6d63',
 'plugins/petish-fulfilment/assets/cancellation-faq.css'=>'f0618bfc28ddb49665eb8b039af5d7fddac6d326deeea728d1ff6b68ecf19f2b',
 'plugins/petish-fulfilment/assets/order-cancellation.css'=>'c0e47076f43b81695da555bca2bd83d992c536208c6527347a0337ebc697793d',
 'plugins/petish-fulfilment/assets/shipment-tracking.css'=>'255f07a4879d5f9337f01a8253bd41df9b12bff6fa0d00769c6902122e5e5963',
 'plugins/petish-fulfilment/assets/shipping-quotes.css'=>'1d085c2efa41433b694da9444b8b824483085914661fdcb046bb0056694e4de3',
 'plugins/petish-fulfilment/assets/shipping-quotes.js'=>'c310810a94dc29bf9ee71a23671b5df06cd9ab3f62ff396c8238d0f0c020a732',
 'plugins/petish-fulfilment/includes/class-petish-automatic-shipping.php'=>'7751d08a2608d04be46724d909a2cfb6f734ddc13d29b4c842b3359390b7c797',
 'plugins/petish-fulfilment/includes/class-petish-cancellation-policy.php'=>'3f12eb702d11d871ffef36f8809188c45fdf08b2f5b39369909e2b8f6fa5dd61',
 'plugins/petish-fulfilment/includes/class-petish-delivery-cost-sheet-admin.php'=>'4abd44f9544ce6f911af4cf33ba0197029d1f420d00595260abde8044742983e',
 'plugins/petish-fulfilment/includes/class-petish-delivery-cost-sheet.php'=>'f994698c143841cc7cf0a741e46d13cd5c7194ca1370e6eafe16f20b084f4d0d',
 'plugins/petish-fulfilment/includes/class-petish-delivery-operations-admin.php'=>'4552b66d5cf7ec78b4cb44acf491f9f90355cc2a04264e16d4b55aa6795ff29f',
 'plugins/petish-fulfilment/includes/class-petish-delivery-rate-schedules.php'=>'052f5c06b0f93db41c3c580b225f1ba18fd609f272161e6203a3c004d827bb3f',
 'plugins/petish-fulfilment/includes/class-petish-order-cancellation-admin.php'=>'2038838a9a95070a0bd7460126be938068265cff9a45cdf984979696b34aa358',
 'plugins/petish-fulfilment/includes/class-petish-order-cancellation-customer.php'=>'ef6b190c1d8eae51daa47fb43f23e4419aa172a86e42a05b8006caab6864f1de',
 'plugins/petish-fulfilment/includes/class-petish-order-cancellation-emails.php'=>'0cd4e17ee34809e0af7f7338b26f9fde83f8e527a9ca187124d4cbd33ecb7cd1',
 'plugins/petish-fulfilment/includes/class-petish-order-cancellation-requests.php'=>'fc2cab97df47db0672b47c2f62924f1c0256996f67ed6bb787a7f317802cfd04',
 'plugins/petish-fulfilment/includes/class-petish-quote-currency.php'=>'e551aa4444b0bf3b719a430287bd43818b17bd6e51b996dc887315f7f93f3ddd',
 'plugins/petish-fulfilment/includes/class-petish-shipment-customer-view.php'=>'97331b381719cc78062acf56e557421512c0200bebd0d4b2cdeb29a8a5a332aa',
 'plugins/petish-fulfilment/includes/class-petish-shipment-ledger.php'=>'81e6cffbf0b11ee6bfe508b9e1c8f0fa6d6c1d2c0a22e85f451526a5426672f1',
 'plugins/petish-fulfilment/includes/class-petish-shipment-notifications.php'=>'701a88496aa4f18fd70ce211dd109b619579184db107687a398df75ced465b1a',
 'plugins/petish-fulfilment/includes/class-petish-shipping-quote-requests.php'=>'b5990b5862b569224b42b27eb86f6eae4a62fa4e530a6e35a7414d9ba0fc8336',
 'plugins/petish-fulfilment/includes/class-petish-supplier-quotes.php'=>'c93c77efe95a6c2af05d7d7dea50f0728552bfca5beae3f60f9170dca9abee8b',
 'plugins/petish-fulfilment/includes/class-petish-supplier-shipping.php'=>'150d867fd61db71a34662bebb4e53c885761cadfa9bfb340e39cf33ab9c3189b',
 'plugins/petish-passport/includes/class-petish-customer-reviews-ui.php'=>'932c47ed580dca1e0db77dfa5e6b64f63d1cc08eb5988dd1d8ef418776818dc1',
 'plugins/petish-passport/templates/customer-reviews-dashboard.php'=>'1e42b1f98d7cbf16550720183a232b13625884ca8ade08e37467a6e01cc1cef0',
 'plugins/petish-passport/assets/customer-reviews.css'=>'8de4b0253b926cccf7f7170b144ea35844f4fcd51a65d7a797dcbffe508e97c3',
 'plugins/petish-fulfilment/petish-fulfilment.php'=>'dd8f361c5f0bd6d671d0c4d8b516e5faf544baa08904051d8c98ff9295138bf2',
];
const PETISH_CANCELLATION_RELEASE_BASELINES = [
 'plugins/petish-fulfilment/README.md'=>'56659169f68542424d4a8769b97c9c680930d70f857e9d3e635b5409766bf0f9',
 'plugins/petish-fulfilment/assets/shipping-quotes.css'=>'1d085c2efa41433b694da9444b8b824483085914661fdcb046bb0056694e4de3',
 'plugins/petish-fulfilment/assets/shipping-quotes.js'=>'c310810a94dc29bf9ee71a23671b5df06cd9ab3f62ff396c8238d0f0c020a732',
 'plugins/petish-fulfilment/includes/class-petish-automatic-shipping.php'=>'2b4682deecb4793f5499a1b7a5cc6b680d5673eebabd5b6e4960913de781a18b',
 'plugins/petish-fulfilment/includes/class-petish-delivery-cost-sheet-admin.php'=>'4abd44f9544ce6f911af4cf33ba0197029d1f420d00595260abde8044742983e',
 'plugins/petish-fulfilment/includes/class-petish-delivery-cost-sheet.php'=>'f994698c143841cc7cf0a741e46d13cd5c7194ca1370e6eafe16f20b084f4d0d',
 'plugins/petish-fulfilment/includes/class-petish-quote-currency.php'=>'e551aa4444b0bf3b719a430287bd43818b17bd6e51b996dc887315f7f93f3ddd',
 'plugins/petish-fulfilment/includes/class-petish-shipping-quote-requests.php'=>'b5990b5862b569224b42b27eb86f6eae4a62fa4e530a6e35a7414d9ba0fc8336',
 'plugins/petish-fulfilment/includes/class-petish-supplier-quotes.php'=>'c93c77efe95a6c2af05d7d7dea50f0728552bfca5beae3f60f9170dca9abee8b',
 'plugins/petish-fulfilment/includes/class-petish-supplier-shipping.php'=>'150d867fd61db71a34662bebb4e53c885761cadfa9bfb340e39cf33ab9c3189b',
 'plugins/petish-passport/includes/class-petish-customer-reviews-ui.php'=>'262968f8272b3307568946ee72a0418e29d3a7a0990603705058a2d1fbed3d43',
 'plugins/petish-passport/templates/customer-reviews-dashboard.php'=>'ed0702f68e94bb7ac6c102629b6c6b63d3ab570a2b13040906954893fece54d7',
 'plugins/petish-passport/assets/customer-reviews.css'=>'b4159fb54cd1776b0cb9dad644b4d8655c02d01c2c9354bf4a633ed2ead23668',
 'plugins/petish-fulfilment/petish-fulfilment.php'=>'3506e5e4e1afa802ccda41a17a9ddaae61f3a0a715b53fc9097fde374c8133d0',
];
function cxl_release_fail() { throw new RuntimeException(); }
function cxl_release_path($path, $directory=false) {
    if (!is_string($path) || $path==='' || $path[0]!=='/' || strpos($path,"\0")!==false) cxl_release_fail();
    $cursor='';
    foreach(explode('/',substr($path,1)) as $part) {
        if($part==='' || $part==='.' || $part==='..') cxl_release_fail();
        $cursor.='/'.$part;
        if(is_link($cursor) || realpath($cursor)!==$cursor) cxl_release_fail();
    }
    $info=lstat($path);
    if(!$info || ($directory ? !is_dir($path) : (!is_file($path) || $info['nlink']!==1))) cxl_release_fail();
    return $path;
}
function cxl_release_json($path) {
    cxl_release_path($path);
    if((fileperms($path)&07777)!==0600 || filesize($path)>1048576) cxl_release_fail();
    $value=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($value)) cxl_release_fail();
    return $value;
}
function cxl_release_write($path,$content,$mode=0600) {
    cxl_release_path(dirname($path),true);
    if(file_exists($path) || is_link($path)) cxl_release_fail();
    $handle=fopen($path,'xb'); if(!$handle) cxl_release_fail();
    try {
        if(!chmod($path,$mode) || fwrite($handle,$content)!==strlen($content) || !fflush($handle) || !function_exists('fsync') || !fsync($handle)) cxl_release_fail();
    } catch(Throwable $error) {
        $owned=fstat($handle);$current=lstat($path);
        if($owned&&$current&&!is_link($path)&&$owned['dev']===$current['dev']&&$owned['ino']===$current['ino'])unlink($path);
        throw $error;
    } finally { fclose($handle); }
}
function cxl_release_atomic($path,$content,$mode,$run) {
    cxl_release_path(dirname($path),true);
    if(file_exists($path) || is_link($path)) cxl_release_path($path);
    $temporary=dirname($path).'/.petish-cancellation-'.$run.'-'.bin2hex(random_bytes(8));
    try {
        cxl_release_write($temporary,$content,$mode);
        if(!rename($temporary,$path)) cxl_release_fail();
    } finally { if(is_file($temporary) && !is_link($temporary)) unlink($temporary); }
}
function cxl_release_tree($root,$exclude=[]) {
    cxl_release_path($root,true); $files=[];
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as$file) {
        $path=$file->getPathname();
        if($file->isLink() || (!$file->isFile()&&!$file->isDir())) cxl_release_fail();
        cxl_release_path($path,$file->isDir());
        if($file->isFile()) {
            $relative=substr($path,strlen($root)+1);
            if(!in_array($relative,$exclude,true)) $files[$relative]=hash_file('sha256',$path);
            if(count($files)>25000) cxl_release_fail();
        }
    }
    ksort($files); return $files;
}
function cxl_release_protected($root,$content) {
 $passport=[];$fulfilment=[];
 foreach(array_keys(PETISH_CANCELLATION_RELEASE_FILES)as$name){
  if(str_starts_with($name,'plugins/petish-passport/'))$passport[]=substr($name,24);
  if(str_starts_with($name,'plugins/petish-fulfilment/'))$fulfilment[]=substr($name,26);
 }
 $state=['child_theme'=>cxl_release_tree($content.'/themes/dt-the7-child'),
  'passport'=>cxl_release_tree($content.'/plugins/petish-passport',$passport),
  'fulfilment'=>cxl_release_tree($content.'/plugins/petish-fulfilment',$fulfilment),
  'mu'=>cxl_release_tree($content.'/mu-plugins',['zz-petish-cancellation-cache.php'])];
 $private=dirname($root).'/private/petish-passport';
 $state['private_passport']=file_exists($private)||is_link($private)?cxl_release_tree($private):null;
 foreach(['wp-config.php','.htaccess','.user.ini']as$name){$path=$root.'/'.$name;
  if(file_exists($path)||is_link($path)){cxl_release_path($path);$state[$name]=hash_file('sha256',$path);}elseif($name==='wp-config.php')cxl_release_fail();}
 return $state;
}
function cxl_release_payload($payload) {
    cxl_release_path($payload,true);
    if((fileperms($payload)&07777)!==0700) cxl_release_fail();
    $manifest=cxl_release_json($payload.'/manifest.json');
    $expected=PETISH_CANCELLATION_RELEASE_FILES; ksort($expected); ksort($manifest);
    if($manifest!==$expected) cxl_release_fail();
    $found=cxl_release_tree($payload,['manifest.json']);
    if($found!==$expected) cxl_release_fail();
    foreach(PETISH_CANCELLATION_RELEASE_FILES as$name=>$hash) if(pathinfo($name,PATHINFO_EXTENSION)==='php') token_get_all(file_get_contents($payload.'/'.$name),TOKEN_PARSE);

}
function cxl_release_live($plugin) {
    $state=[]; $changed=0;
    foreach(PETISH_CANCELLATION_RELEASE_FILES as$name=>$expected) {
        $path=$plugin.'/'.$name;
        if(file_exists($path)||is_link($path)) {
            cxl_release_path($path); $hash=hash_file('sha256',$path);
            if($hash!==$expected && $hash!==(PETISH_CANCELLATION_RELEASE_BASELINES[$name]??null)) cxl_release_fail();
            $state[$name]=['present'=>true,'sha256'=>$hash,'mode'=>fileperms($path)&0777];
        } else {
            if(array_key_exists($name,PETISH_CANCELLATION_RELEASE_BASELINES)) cxl_release_fail();
            // Existing ancestors must also be canonical, even for absent files.
            $parent=dirname($path);
            while(!file_exists($parent) && !is_link($parent)) $parent=dirname($parent);
            cxl_release_path($parent,true);
            $state[$name]=['present'=>false,'sha256'=>null,'mode'=>null];
        }
        if(!$state[$name]['present']||$state[$name]['sha256']!==$expected) $changed++;
    }
    return [$state,$changed];
}
/** Refuse an edit made since the private snapshot was captured. */
function cxl_release_preimage($path,$entry) {
    clearstatcache(true,$path);
    if($entry['present']) {
        cxl_release_path($path);
        if(!hash_equals($entry['sha256'],hash_file('sha256',$path))
            || (fileperms($path)&0777)!==$entry['mode']) cxl_release_fail();
    }elseif(file_exists($path)||is_link($path)) cxl_release_fail();
}
/** A rollback may replace only this release's candidate or its exact preimage. */
function cxl_release_rollback_destination($path,$name,$entry) {
    clearstatcache(true,$path);
    if(file_exists($path)||is_link($path)) {
        cxl_release_path($path); $hash=hash_file('sha256',$path);
        if($hash!==PETISH_CANCELLATION_RELEASE_FILES[$name] && (!$entry['present']||$hash!==$entry['sha256'])) cxl_release_fail();
    }elseif($entry['present']) cxl_release_fail();
}
function cxl_release_require_candidates($plugin) {
    foreach(PETISH_CANCELLATION_RELEASE_FILES as$name=>$expected) {
        cxl_release_path($plugin.'/'.$name);
        if(!hash_equals($expected,hash_file('sha256',$plugin.'/'.$name))) cxl_release_fail();
    }
}
function cxl_release_lock($base,$run,$acquire=false) {
    $lock=$base.'/deploy.lock';
    if($acquire) {
        if(file_exists($lock)||is_link($lock)||!mkdir($lock,0700)) cxl_release_fail();
        cxl_release_write($lock.'/owner',$run."\n");
    }
    cxl_release_path($lock,true); cxl_release_path($lock.'/owner');
    if((fileperms($lock)&07777)!==0700 || trim(file_get_contents($lock.'/owner'))!==$run) cxl_release_fail();
    return $lock;
}
function cxl_release_unlock($lock) {
    if(!unlink($lock.'/owner')||!rmdir($lock)) cxl_release_fail();
}

const PETISH_CANCELLATION_RELEASE_CHECKS = [
 'fixed_production_paths','candidate_manifest_verified','candidate_syntax_verified','live_files_compatible',
 'protected_state_unchanged','rollback_snapshot_created','files_deployed','rollback_completed','release_committed','lock_owned',
 'maintenance_guard_created','maintenance_guard_removed','maintenance_guard_retained','cache_probe_installed','cache_probe_removed','cache_purge_probe_ready',
 'production_environment','database_writes_scoped','feature_flags_unchanged','feature_flags_disabled','faq_existing_content_verified',
 'faq_transactional_tables','faq_row_locked','faq_checkpoint_saved','faq_section_prepared','faq_page_only_write_scope_verified',
 'faq_rollback_verified','native_contracts_verified','native_controls_verified','native_policy_verified','native_data_unchanged',
 'review_spacing_source_verified','review_spacing_native_render_verified','review_spacing_asset_version_verified','rollback_not_needed',
];
const PETISH_CANCELLATION_RELEASE_CATEGORIES = ['input','paths','payload','drift','snapshot','maintenance','lock','deployment','bootstrap','faq','verify','cache','probe','rollback','commit','complete'];
const PETISH_CANCELLATION_RELEASE_REQUIRED = [
 'inspect'=>['fixed_production_paths','candidate_manifest_verified','candidate_syntax_verified','live_files_compatible','production_environment','database_writes_scoped','feature_flags_disabled','faq_existing_content_verified','native_data_unchanged'],
 'apply'=>['fixed_production_paths','candidate_manifest_verified','candidate_syntax_verified','live_files_compatible','rollback_snapshot_created','files_deployed','protected_state_unchanged','lock_owned','maintenance_guard_created','maintenance_guard_retained','cache_probe_installed'],
 'faq'=>['files_deployed','production_environment','database_writes_scoped','feature_flags_disabled','feature_flags_unchanged','faq_existing_content_verified','faq_transactional_tables','faq_row_locked','faq_checkpoint_saved','faq_section_prepared','faq_page_only_write_scope_verified','native_data_unchanged','maintenance_guard_retained','lock_owned','protected_state_unchanged'],
 'verify'=>['files_deployed','production_environment','database_writes_scoped','feature_flags_disabled','feature_flags_unchanged','faq_section_prepared','native_contracts_verified','native_controls_verified','native_policy_verified','native_data_unchanged','review_spacing_source_verified','review_spacing_native_render_verified','review_spacing_asset_version_verified','maintenance_guard_retained','lock_owned','protected_state_unchanged'],
 'resume'=>['files_deployed','feature_flags_unchanged','production_environment','database_writes_scoped','faq_section_prepared','protected_state_unchanged','lock_owned','maintenance_guard_removed','cache_purge_probe_ready'],
 'commit'=>['files_deployed','feature_flags_unchanged','production_environment','database_writes_scoped','faq_section_prepared','protected_state_unchanged','release_committed','maintenance_guard_removed','cache_probe_removed'],
 'rollback'=>['faq_rollback_verified','rollback_completed','maintenance_guard_removed','protected_state_unchanged','cache_purge_probe_ready'],
 'rollback-finalize'=>['faq_rollback_verified','rollback_completed','maintenance_guard_removed','protected_state_unchanged','cache_probe_removed'],
];
function cxl_release_mark($key,$pass=true){global $cxl_release_report;if(!in_array($key,PETISH_CANCELLATION_RELEASE_CHECKS,true)||!is_bool($pass))cxl_release_fail();$cxl_release_report['checks'][$key]=$pass;if(!$pass)cxl_release_fail();}
function cxl_release_journal($release,$run){
 $journal=cxl_release_json($release.'/journal.json');
 if(array_keys($journal)!==['run','files','protected','flags']||$journal['run']!==$run||!is_array($journal['files'])||array_keys($journal['files'])!==array_keys(PETISH_CANCELLATION_RELEASE_FILES)||!is_array($journal['protected'])||!cxl_release_flags_shape($journal['flags']))cxl_release_fail();
 foreach($journal['files']as$name=>$entry){
  if(!is_array($entry)||array_keys($entry)!==['present','sha256','mode']||!is_bool($entry['present']))cxl_release_fail();
  if($entry['present']){$allowed=[PETISH_CANCELLATION_RELEASE_FILES[$name],PETISH_CANCELLATION_RELEASE_BASELINES[$name]??null];
   if(!in_array($entry['sha256'],$allowed,true)||!is_int($entry['mode'])||$entry['mode']<0||$entry['mode']>0777)cxl_release_fail();
   $backup=$release.'/backup/'.array_search($name,array_keys(PETISH_CANCELLATION_RELEASE_FILES),true);cxl_release_path($backup);if((fileperms($backup)&07777)!==0600||hash_file('sha256',$backup)!==$entry['sha256'])cxl_release_fail();
  }elseif(isset(PETISH_CANCELLATION_RELEASE_BASELINES[$name])||$entry['sha256']!==null||$entry['mode']!==null)cxl_release_fail();
 }return $journal;
}
function cxl_release_check_protected($root,$content,$journal,$allow_private=false){
 $current=cxl_release_protected($root,$content);$expected=$journal['protected'];
 if($allow_private)$expected['private_passport']=$current['private_passport'];
 cxl_release_mark('protected_state_unchanged',$current===$expected);return $current;
}
function cxl_release_maintenance($root,$release,$run,$remove=false){
 $path=$root.'/.maintenance';$receipt=$release.'/maintenance.json';
 $content="<?php /* Petish cancellation release ".$run." */\n\$upgrading = time();\n";$hash=hash('sha256',$content);
 if(file_exists($receipt)||is_link($receipt)){if(cxl_release_json($receipt)!==['run'=>$run,'sha256'=>$hash])cxl_release_fail();}
 elseif($remove){if(file_exists($path)||is_link($path))cxl_release_fail();cxl_release_mark('maintenance_guard_removed');return;}
 else cxl_release_write($receipt,json_encode(['run'=>$run,'sha256'=>$hash],JSON_THROW_ON_ERROR));
 if(file_exists($path)||is_link($path)){cxl_release_path($path);if((fileperms($path)&07777)!==0600||hash_file('sha256',$path)!==$hash)cxl_release_fail();}
 elseif(!$remove)cxl_release_write($path,$content);
 if($remove&&file_exists($path)&&!unlink($path))cxl_release_fail();
 cxl_release_mark($remove?'maintenance_guard_removed':'maintenance_guard_created');
}
/** All writes must consume an exact, single-use prepared SQL permit. */
function cxl_release_query($sql){
 if(!is_string($sql))cxl_release_fail();
 if(($GLOBALS['cxl_exact_sql']??null)===$sql){$GLOBALS['cxl_exact_sql']=null;return $sql;}
 $probe=preg_replace('~\A(?:\s+|/\*.*?\*/|--[^\r\n]*(?:\r?\n|$)|\#[^\r\n]*(?:\r?\n|$))*~s','',$sql);
 if(preg_match('/\A(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i',$probe)&&!preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i',$probe)&&!preg_match('/;\s*\S/s',rtrim($probe)))return $sql;
 if(!empty($GLOBALS['cxl_strict_sql']))cxl_release_fail();return 'SELECT 0 WHERE 1=0';
}
function cxl_release_once($sql){global $wpdb;if(($GLOBALS['cxl_exact_sql']??null)!==null)cxl_release_fail();$GLOBALS['cxl_exact_sql']=$sql;$result=$wpdb->query($sql);if(($GLOBALS['cxl_exact_sql']??null)!==null||$result===false||$wpdb->last_error!=='')cxl_release_fail();return $result;}
function cxl_release_boot(){
 static $booted=false;if($booted)return;$booted=true;
 foreach(['verify-cancellation-requests.php','verify-pet-club.php','email-branding-preview-remote.php','review-dashboard-spacing-release.php','delivery-page-content.html']as$name)cxl_release_path(__DIR__.'/'.$name);
 define('PETISH_CANCELLATION_VERIFY_LIBRARY',true);require_once __DIR__.'/verify-cancellation-requests.php';
 require_once CXL_PRODUCTION_ROOT.'/wp-includes/plugin.php';$no=static fn()=>false;$yes=static fn()=>true;
 $filters=['query'=>'cxl_release_query','enable_maintenance_mode'=>$no,'jetpack_sync_listener_should_load'=>$no,'jetpack_sync_sender_should_load'=>$no,'pre_option_jetpack_sync_settings_disable'=>$yes];
 foreach($filters as$hook=>$callback)add_filter($hook,$callback,PHP_INT_MAX);
 add_action('all',static function($hook)use($filters){if(isset($filters[$hook])){remove_filter($hook,$filters[$hook],PHP_INT_MAX);add_filter($hook,$filters[$hook],PHP_INT_MAX);}},PHP_INT_MAX);
 global $report;$saved=$report??null;$report=['checks'=>[],'category'=>'bootstrap'];
 try{$root=petish_club_verify_bootstrap('production',false);if($root!==CXL_PRODUCTION_ROOT||in_array(false,$report['checks'],true))cxl_release_fail();}
 finally{$report=$saved;}
 $sync='Automattic\\Jetpack\\Sync\\Actions';
 cxl_release_mark('production_environment',wp_get_environment_type()==='production'&&apply_filters('jetpack_sync_listener_should_load',true)===false&&apply_filters('jetpack_sync_sender_should_load',true)===false&&(!class_exists($sync)||($sync::$listener===null&&$sync::$sender===null)));
 cxl_release_mark('database_writes_scoped',has_filter('query','cxl_release_query')===PHP_INT_MAX&&petish_club_verify_runtime_cache(CXL_PRODUCTION_ROOT));
 $GLOBALS['cxl_strict_sql']=true;
}
function cxl_release_flag_names(){return ['petish_cancellation_requests_enabled','petish_cancellation_emails_enabled','petish_shipment_ledger_enabled','petish_shipment_notifications_enabled','petish_shipment_customer_tracking_enabled','petish_customer_reviews_enabled'];}
function cxl_release_flags_shape($flags){
 if(!is_array($flags)||array_keys($flags)!==cxl_release_flag_names())return false;
 foreach($flags as$row)if($row!==null&&(!is_array($row)||array_keys($row)!==['option_value','autoload']||!in_array($row['option_value'],['yes','no'],true)||!is_string($row['autoload'])||!in_array($row['autoload'],['yes','no','on','off','auto','auto-on','auto-off'],true)))return false;
 return true;
}
function cxl_release_flags(){
 global $wpdb;$out=[];
 if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->options??''))cxl_release_fail();
 foreach(cxl_release_flag_names()as$name){$out[$name]=$wpdb->get_row($wpdb->prepare("SELECT option_value,autoload FROM `{$wpdb->options}` WHERE option_name=%s",$name),ARRAY_A);if($wpdb->last_error!=='')cxl_release_fail();}
 if(!cxl_release_flags_shape($out))cxl_release_fail();return $out;
}
function cxl_release_disabled($flags){foreach($flags as$name=>$row)if($name!=='petish_customer_reviews_enabled'&&($row['option_value']??'no')!=='no')return false;return true;}
function cxl_release_snapshot($root){return ['data'=>petish_club_verify_state($root),'cases'=>petish_cancel_case_state(),'flags'=>cxl_release_flags()];}
function cxl_release_native_unchanged($before,$root){cxl_release_mark('native_data_unchanged',$before===cxl_release_snapshot($root));}
function cxl_release_faq_row($lock=false){
 global $wpdb;
 if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->posts??''))cxl_release_fail();
 $rows=$wpdb->get_results("SELECT * FROM `{$wpdb->posts}`".($lock?' FORCE INDEX (post_name)':'')." WHERE post_name='delivery-shipping' AND post_type='page'".($lock?' FOR UPDATE':''),ARRAY_A);
 if($wpdb->last_error!==''||!is_array($rows)||count($rows)!==1)cxl_release_fail();$row=$rows[0];
 if($row['post_status']!=='publish'||$row['post_password']!==''||(int)$row['post_parent']!==0||!ctype_digit($row['ID'])||(int)$row['ID']<1)cxl_release_fail();
 return $row;
}
function cxl_release_faq_meta($id,$lock=false){global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM `{$wpdb->postmeta}` WHERE post_id=%d AND meta_key=%s".($lock?' FOR UPDATE':''),$id,PETISH_CANCEL_FAQ_MARKER),ARRAY_A);if($wpdb->last_error!==''||!is_array($rows)||count($rows)>1)cxl_release_fail();return $rows;}
function cxl_release_faq_content($content,$managed=false){
 $base=petish_cancel_faq_base();return is_string($content)&&$content===($managed?$base.PETISH_CANCEL_FAQ_CONTENT:$base);
}
function cxl_release_faq_current($require_managed=false){
 $row=cxl_release_faq_row();$meta=cxl_release_faq_meta((int)$row['ID']);
 $managed=cxl_release_faq_content($row['post_content'],true)&&count($meta)===1&&$meta[0]['meta_value']===PETISH_CANCEL_FAQ_OWNER.'|'.PETISH_CANCEL_DELIVERY_SHA;
 $plain=cxl_release_faq_content($row['post_content'])&&$meta===[];
 if(!$managed&&($require_managed||!$plain))cxl_release_fail();return [$row,$meta,$managed];
}
function cxl_release_table_ready(){
 global $wpdb;
 foreach([$wpdb->posts,$wpdb->postmeta]as$table){if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$table))cxl_release_fail();$status=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$wpdb->esc_like($table)),ARRAY_A);$triggers=$wpdb->get_results($wpdb->prepare('SHOW TRIGGERS WHERE `Table`=%s',$table),ARRAY_A);if(strtoupper($status['Engine']??'')!=='INNODB'||$triggers!==[]||$wpdb->last_error!=='')cxl_release_fail();}
 $indexes=$wpdb->get_results("SHOW INDEX FROM `{$wpdb->posts}` WHERE Key_name='post_name'",ARRAY_A);if(!is_array($indexes)||count($indexes)!==1||$indexes[0]['Column_name']!=='post_name')cxl_release_fail();
 cxl_release_mark('faq_transactional_tables');
}
function cxl_release_page_scope($id){global $wpdb;return [petish_club_verify_table_state($wpdb->posts,' WHERE ID<>'.(int)$id),petish_club_verify_table_state($wpdb->postmeta,$wpdb->prepare(' WHERE NOT (post_id=%d AND meta_key=%s)',$id,PETISH_CANCEL_FAQ_MARKER))];}
function cxl_release_page_journal($release,$run){
 $j=cxl_release_json($release.'/page.json');
 if(array_keys($j)!==['run','before','after','marker_before','marker_after']||$j['run']!==$run||!is_array($j['before'])||!is_array($j['after'])||array_keys($j['before'])!==array_keys($j['after'])||!is_array($j['marker_before'])||!is_array($j['marker_after']))cxl_release_fail();
 foreach([$j['before'],$j['after']]as$row){
  foreach($row as$key=>$value)if(!is_string($key)||!preg_match('/\A[a-zA-Z_]+\z/D',$key)||!is_string($value))cxl_release_fail();
  if(!ctype_digit($row['ID']??'')||(int)$row['ID']<1||($row['post_name']??null)!=='delivery-shipping'||($row['post_type']??null)!=='page'||($row['post_status']??null)!=='publish'||($row['post_password']??null)!==''||(int)($row['post_parent']??-1)!==0)cxl_release_fail();
 }
 if($j['before']['ID']!==$j['after']['ID']||!cxl_release_faq_content($j['after']['post_content'],true))cxl_release_fail();
 foreach($j['before']as$key=>$value)if(!in_array($key,['post_content','post_modified','post_modified_gmt'],true)&&$j['after'][$key]!==$value)cxl_release_fail();
 foreach([$j['before'],$j['after']]as$row)foreach(['post_modified','post_modified_gmt']as$key)if(!preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D',$row[$key]??''))cxl_release_fail();
 if(count($j['marker_after'])!==1)cxl_release_fail();
 foreach(array_merge($j['marker_before'],$j['marker_after'])as$meta){
  if(!is_array($meta)||array_keys($meta)!==['meta_id','post_id','meta_key','meta_value']||!ctype_digit($meta['meta_id'])||(int)$meta['meta_id']<1||$meta['post_id']!==$j['before']['ID']||$meta['meta_key']!==PETISH_CANCEL_FAQ_MARKER||$meta['meta_value']!==PETISH_CANCEL_FAQ_OWNER.'|'.PETISH_CANCEL_DELIVERY_SHA)cxl_release_fail();
 }
 if($j['before']===$j['after']){if($j['marker_before']!==$j['marker_after'])cxl_release_fail();}
 elseif(!cxl_release_faq_content($j['before']['post_content'])||$j['marker_before']!==[])cxl_release_fail();
 return $j;
}
function cxl_release_faq($release,$run,$restore=false){
 global $wpdb;cxl_release_boot();cxl_release_table_ready();$before_state=cxl_release_snapshot(CXL_PRODUCTION_ROOT);$transaction=false;$lock=false;
 try{
  $name='petish-cancellation-delivery-faq-v1';if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$name))!=='1')cxl_release_fail();$lock=true;
  cxl_release_once('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');cxl_release_once('START TRANSACTION');$transaction=true;
  $row=cxl_release_faq_row(true);$id=(int)$row['ID'];$meta=cxl_release_faq_meta($id,true);$scope=cxl_release_page_scope($id);cxl_release_mark('faq_row_locked');
  if($restore){
   if(!file_exists($release.'/page.json')&&!is_link($release.'/page.json')){cxl_release_faq_current();cxl_release_mark('faq_rollback_verified');}
   else{$j=cxl_release_page_journal($release,$run);if($row!==$j['before']||$meta!==$j['marker_before']){
    if($row!==$j['after']||$meta!==$j['marker_after'])cxl_release_fail();
    if($j['before']!==$j['after']){
     $data=array_intersect_key($j['before'],array_flip(['post_content','post_modified','post_modified_gmt']));$sql=$wpdb->prepare("UPDATE `{$wpdb->posts}` SET `post_content` = %s, `post_modified` = %s, `post_modified_gmt` = %s WHERE `ID` = %d",[$data['post_content'],$data['post_modified'],$data['post_modified_gmt'],$id]);if(cxl_release_once($sql)!==1)cxl_release_fail();
     if(cxl_release_once($wpdb->prepare("DELETE FROM `{$wpdb->postmeta}` WHERE meta_id=%d AND post_id=%d AND meta_key=%s AND meta_value=%s",[(int)$meta[0]['meta_id'],$id,PETISH_CANCEL_FAQ_MARKER,$meta[0]['meta_value']]))!==1)cxl_release_fail();
    }
   }cxl_release_mark('faq_rollback_verified',cxl_release_faq_row()===$j['before']&&cxl_release_faq_meta($id)===$j['marker_before']);}
  }else{
   [$current,$markers,$managed]=cxl_release_faq_current();if($current!==$row||$markers!==$meta)cxl_release_fail();cxl_release_mark('faq_existing_content_verified');
   if(file_exists($release.'/page.json')||is_link($release.'/page.json')){$j=cxl_release_page_journal($release,$run);if($row!==$j['after']||$meta!==$j['marker_after'])cxl_release_fail();}
   else{
    if(!$managed){
     foreach(['_wp_page_template','_elementor_edit_mode','_elementor_data']as$key){$value=get_post_meta($id,$key,true);if(($key==='_wp_page_template'&&$value!==''&&$value!=='default')||($key==='_elementor_edit_mode'&&$value==='builder')||($key==='_elementor_data'&&$value!==''))cxl_release_fail();}
     $content=petish_cancel_faq_base().PETISH_CANCEL_FAQ_CONTENT;$modified=current_time('mysql');$gmt=current_time('mysql',true);
     $sql=$wpdb->prepare("UPDATE `{$wpdb->posts}` SET `post_content` = %s, `post_modified` = %s, `post_modified_gmt` = %s WHERE `ID` = %d",[$content,$modified,$gmt,$id]);if(cxl_release_once($sql)!==1)cxl_release_fail();
     if(cxl_release_once($wpdb->prepare("INSERT INTO `{$wpdb->postmeta}` (post_id,meta_key,meta_value) VALUES (%d,%s,%s)",[$id,PETISH_CANCEL_FAQ_MARKER,PETISH_CANCEL_FAQ_OWNER.'|'.PETISH_CANCEL_DELIVERY_SHA]))!==1)cxl_release_fail();
    }
    $j=['run'=>$run,'before'=>$row,'after'=>cxl_release_faq_row(),'marker_before'=>$meta,'marker_after'=>cxl_release_faq_meta($id)];
    cxl_release_write($release.'/page.json',json_encode($j,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));if(cxl_release_page_journal($release,$run)!==$j)cxl_release_fail();
   }
   cxl_release_mark('faq_checkpoint_saved');cxl_release_faq_current(true);cxl_release_mark('faq_section_prepared');
  }
  cxl_release_mark('faq_page_only_write_scope_verified',$scope===cxl_release_page_scope($id));cxl_release_native_unchanged($before_state,CXL_PRODUCTION_ROOT);
  cxl_release_once('COMMIT');$transaction=false;
 }finally{if($transaction){$GLOBALS['cxl_exact_sql']=null;cxl_release_once('ROLLBACK');}if($lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));}
}
function cxl_release_native_verify($root,$flags,$full=false){
 cxl_release_boot();$before=cxl_release_snapshot($root);cxl_release_mark('feature_flags_disabled',cxl_release_disabled($before['flags']));cxl_release_mark('feature_flags_unchanged',$before['flags']===$flags);cxl_release_faq_current(true);cxl_release_mark('faq_section_prepared');
 if($full){global $report;$saved=$report??null;try{
  foreach(['contracts','controls','policy']as$part){$report=['checks'=>[],'category'=>'verify'];('petish_cancel_'.$part)($root);cxl_release_mark('native_'.$part.'_verified',$report['checks']!==[]&&!in_array(false,$report['checks'],true));}
 }finally{$report=$saved;}
 require_once __DIR__.'/review-dashboard-spacing-release.php';foreach(petish_review_spacing_verify($root)as$key=>$pass)cxl_release_mark($key,$pass);
 }cxl_release_native_unchanged($before,$root);
}
function cxl_release_restore($root,$content,$release,$run){
 $journal=cxl_release_journal($release,$run);cxl_release_check_protected($root,$content,$journal,true);
 // Validate all filesystem preimages before making either kind of rollback write.
 foreach($journal['files']as$name=>$entry)cxl_release_rollback_destination($content.'/'.$name,$name,$entry);
 cxl_release_maintenance($root,$release,$run);
 if(file_exists($release.'/page.json')||is_link($release.'/page.json')){
  cxl_release_faq($release,$run,true);cxl_release_mark('feature_flags_unchanged',cxl_release_flags()===$journal['flags']);
 }else cxl_release_mark('faq_rollback_verified');
 // The Fulfilment bootstrap is last on install, first on rollback.
 foreach(array_reverse(array_keys(PETISH_CANCELLATION_RELEASE_FILES))as$name){$entry=$journal['files'][$name];$path=$content.'/'.$name;cxl_release_rollback_destination($path,$name,$entry);
  if($entry['present'])cxl_release_atomic($path,file_get_contents($release.'/backup/'.array_search($name,array_keys(PETISH_CANCELLATION_RELEASE_FILES),true)),$entry['mode'],$run);
  elseif(file_exists($path)&&!unlink($path))cxl_release_fail();
 }
 foreach($journal['files']as$name=>$entry)cxl_release_preimage($content.'/'.$name,$entry);
 cxl_release_ensure_probe($root,$release,$run);cxl_release_purge_ready($root,$release,$run);cxl_release_check_protected($root,$content,$journal,true);
 cxl_release_maintenance($root,$release,$run,true);if(!file_exists($release.'/rolled-back'))cxl_release_write($release.'/rolled-back',"verified\n");cxl_release_mark('rollback_completed');
}
function cxl_release_environment($run,$payload){
 if(!preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$run))cxl_release_fail();
 $work=CXL_PRODUCTION_HOME.'/.petish-cancellation-production-'.$run;
 if(getenv('HOME')!==CXL_PRODUCTION_HOME||__DIR__!==$work||$payload!==$work.'/payload'||ini_get('allow_url_fopen'))cxl_release_fail();
 cxl_release_path($work,true);cxl_release_path($work.'/.owned');
 if((fileperms($work)&07777)!==0700||(fileperms($work.'/.owned')&07777)!==0600||file_get_contents($work.'/.owned')!==$run."\n")cxl_release_fail();
 $root=CXL_PRODUCTION_ROOT;$content=$root.'/wp-content';foreach([$root,$content,$content.'/plugins/petish-fulfilment',$content.'/plugins/petish-passport']as$path)cxl_release_path($path,true);cxl_release_path($root.'/wp-config.php');
 return [$work,$root,$content,CXL_PRODUCTION_HOME.'/.petish-cancellation-releases'];
}
function cxl_release_main($argv){
 global $cxl_release_report;$cxl_release_report=['status'=>'failure','category'=>'input','checks'=>array_fill_keys(PETISH_CANCELLATION_RELEASE_CHECKS,false),'changed_file_count'=>0];
 $mutation=false;$apply_complete=false;$lock=null;$root=null;$content=null;$release=null;$run=null;$phase=null;
 try{
  if(count($argv)!==4||!array_key_exists($argv[1],PETISH_CANCELLATION_RELEASE_REQUIRED))cxl_release_fail();[$script,$phase,$run,$payload]=$argv;
  $cxl_release_report['category']='paths';[$work,$root,$content,$base]=cxl_release_environment($run,$payload);$release=$base.'/'.$run;cxl_release_mark('fixed_production_paths');
  $cxl_release_report['category']='payload';cxl_release_payload($payload);cxl_release_mark('candidate_manifest_verified');cxl_release_mark('candidate_syntax_verified');
  cxl_release_path(__DIR__.'/cancellation-production-cache.php');require_once __DIR__.'/cancellation-production-cache.php';
  if($phase==='rollback'&&!file_exists($release.'/journal.json')&&!is_link($release.'/journal.json')){
   if(file_exists($root.'/.maintenance')||is_link($root.'/.maintenance')||file_exists($content.'/mu-plugins/zz-petish-cancellation-cache.php')||is_link($content.'/mu-plugins/zz-petish-cancellation-cache.php'))cxl_release_fail();
   foreach(['page.json','pending','verified','resumed','maintenance.json','cache.json','committed','rolled-back']as$name)if(file_exists($release.'/'.$name)||is_link($release.'/'.$name))cxl_release_fail();
   [$live,$changed]=cxl_release_live($content);$cxl_release_report['changed_file_count']=$changed;
   if(file_exists($base.'/deploy.lock')){$lock=cxl_release_lock($base,$run);cxl_release_unlock($lock);$lock=null;}
   foreach(['faq_rollback_verified','rollback_completed','maintenance_guard_removed','cache_probe_removed','protected_state_unchanged','rollback_not_needed','cache_purge_probe_ready']as$key)cxl_release_mark($key);
  }elseif(in_array($phase,['inspect','apply'],true)){
   $cxl_release_report['category']='drift';
   if($phase==='inspect'){
    $observed=[];foreach(PETISH_CANCELLATION_RELEASE_FILES as$name=>$sha){$path=$content.'/'.$name;if(file_exists($path)||is_link($path)){cxl_release_path($path);$observed[$name]=hash_file('sha256',$path);}else$observed[$name]=null;}
    cxl_release_write($work.'/live-inspection.json',json_encode(['run'=>$run,'files'=>$observed],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
   }
   [$live,$changed]=cxl_release_live($content);$cxl_release_report['changed_file_count']=$changed;cxl_release_mark('live_files_compatible');
   if($phase==='inspect'){
    cxl_release_protected($root,$content);cxl_release_boot();$before=cxl_release_snapshot($root);cxl_release_mark('feature_flags_disabled',cxl_release_disabled($before['flags']));[$delivery]=cxl_release_faq_current();cxl_release_mark('faq_existing_content_verified');cxl_release_native_unchanged($before,$root);
    cxl_release_write($work.'/inspection.json',json_encode(['run'=>$run,'flags'=>$before['flags'],'delivery_id'=>(int)$delivery['ID']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
   }else{
    $inspection=cxl_release_json($work.'/inspection.json');if(array_keys($inspection)!==['run','flags','delivery_id']||$inspection['run']!==$run||!is_int($inspection['delivery_id'])||$inspection['delivery_id']<1||!cxl_release_flags_shape($inspection['flags'])||!cxl_release_disabled($inspection['flags']))cxl_release_fail();
    if(file_exists($root.'/.maintenance')||is_link($root.'/.maintenance'))cxl_release_fail();$cxl_release_report['category']='lock';
    if(!file_exists($base)&&!is_link($base)&&!mkdir($base,0700))cxl_release_fail();cxl_release_path($base,true);if((fileperms($base)&07777)!==0700)cxl_release_fail();$lock=cxl_release_lock($base,$run,true);cxl_release_mark('lock_owned');
    $cxl_release_report['category']='snapshot';if(file_exists($release)||is_link($release)||!mkdir($release,0700)||!mkdir($release.'/backup',0700))cxl_release_fail();
    foreach(PETISH_CANCELLATION_RELEASE_FILES as$name=>$sha)cxl_release_path(dirname($content.'/'.$name),true);$protected=cxl_release_protected($root,$content);
    foreach($live as$name=>$entry)if($entry['present'])cxl_release_write($release.'/backup/'.array_search($name,array_keys(PETISH_CANCELLATION_RELEASE_FILES),true),file_get_contents($content.'/'.$name));
    cxl_release_write($release.'/journal.json',json_encode(['run'=>$run,'files'=>$live,'protected'=>$protected,'flags'=>$inspection['flags']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$journal=cxl_release_journal($release,$run);cxl_release_mark('rollback_snapshot_created');
    $cxl_release_report['category']='maintenance';$mutation=true;cxl_release_maintenance($root,$release,$run);cxl_release_probe($root,$release,$run);
    $cxl_release_report['category']='deployment';foreach($live as$name=>$entry)cxl_release_preimage($content.'/'.$name,$entry);cxl_release_check_protected($root,$content,$journal);
    foreach(PETISH_CANCELLATION_RELEASE_FILES as$name=>$sha){cxl_release_preimage($content.'/'.$name,$live[$name]);if($live[$name]['present']&&$live[$name]['sha256']===$sha)continue;cxl_release_atomic($content.'/'.$name,file_get_contents($payload.'/'.$name),0644,$run);}
    cxl_release_require_candidates($content);cxl_release_mark('files_deployed');cxl_release_check_protected($root,$content,$journal);cxl_release_write($release.'/pending',"verified\n");cxl_release_mark('maintenance_guard_retained');$apply_complete=true;
   }
  }else{
   $cxl_release_report['category']='lock';cxl_release_path($base,true);cxl_release_path($release,true);if(file_exists($release.'/committed')||is_link($release.'/committed'))cxl_release_fail();
   $journal=cxl_release_journal($release,$run);
   if(in_array($phase,['rollback','rollback-finalize'],true)&&file_exists($release.'/rolled-back')){
    cxl_release_path($release.'/rolled-back');if(file_get_contents($release.'/rolled-back')!=="verified\n")cxl_release_fail();
    foreach($journal['files']as$name=>$entry)cxl_release_preimage($content.'/'.$name,$entry);cxl_release_check_protected($root,$content,$journal,true);
    if(file_exists($release.'/page.json')||is_link($release.'/page.json'))cxl_release_faq($release,$run,true);else cxl_release_mark('faq_rollback_verified');
    cxl_release_maintenance($root,$release,$run,true);cxl_release_mark('rollback_completed');
    if($phase==='rollback-finalize'){cxl_release_probe($root,$release,$run,true);if(file_exists($base.'/deploy.lock')){$lock=cxl_release_lock($base,$run);cxl_release_unlock($lock);$lock=null;}}
    else cxl_release_purge_ready($root,$release,$run);
   }else{
    if($phase==='rollback-finalize')cxl_release_fail();$lock=cxl_release_lock($base,$run);cxl_release_mark('lock_owned');
    if($phase==='rollback'){$cxl_release_report['category']='rollback';cxl_release_restore($root,$content,$release,$run);}
    else{
     cxl_release_path($release.'/pending');cxl_release_require_candidates($content);cxl_release_mark('files_deployed');cxl_release_check_protected($root,$content,$journal,$phase==='commit');
     if(in_array($phase,['faq','verify'],true)){
      cxl_release_maintenance($root,$release,$run);cxl_release_mark('maintenance_guard_retained');$cxl_release_report['category']=$phase;
      cxl_release_boot();cxl_release_mark('feature_flags_disabled',cxl_release_disabled(cxl_release_flags()));cxl_release_mark('feature_flags_unchanged',cxl_release_flags()===$journal['flags']);
      if($phase==='faq')cxl_release_faq($release,$run);
      else{cxl_release_native_verify($root,$journal['flags'],true);if(!file_exists($release.'/verified'))cxl_release_write($release.'/verified',"verified\n");}
     }elseif($phase==='resume'){
      cxl_release_path($release.'/verified');$cxl_release_report['category']='cache';cxl_release_native_verify($root,$journal['flags']);cxl_release_purge_ready($root,$release,$run);cxl_release_maintenance($root,$release,$run,true);cxl_release_write($release.'/resumed',"verified\n");
     }elseif($phase==='commit'){
      $cxl_release_report['category']='commit';if(file_exists($root.'/.maintenance')||is_link($root.'/.maintenance'))cxl_release_fail();cxl_release_path($release.'/resumed');cxl_release_native_verify($root,$journal['flags']);cxl_release_probe($root,$release,$run,true);cxl_release_check_protected($root,$content,$journal,true);cxl_release_mark('maintenance_guard_removed');cxl_release_write($release.'/committed',"verified\n");cxl_release_mark('release_committed');cxl_release_unlock($lock);$lock=null;
     }
    }
   }
  }
  foreach(PETISH_CANCELLATION_RELEASE_REQUIRED[$phase]as$key)if(!$cxl_release_report['checks'][$key])cxl_release_fail();$cxl_release_report['status']='success';$cxl_release_report['category']='complete';
 }catch(Throwable $ignored){
  if($phase==='apply'&&$mutation&&!$apply_complete){try{cxl_release_restore($root,$content,$release,$run);}catch(Throwable $again){$cxl_release_report['category']='rollback';}}
  elseif($phase==='apply'&&!$mutation&&$lock!==null){try{cxl_release_unlock($lock);$lock=null;}catch(Throwable $again){}}
 }
 return $cxl_release_report;
}
if(!defined('PETISH_CANCELLATION_RELEASE_LIBRARY')){
 ob_start(static function($ignored,$phase){global $cxl_release_report;return($phase&PHP_OUTPUT_HANDLER_FINAL)?json_encode($cxl_release_report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n":'';},0,0);
 $result=cxl_release_main($argv);exit($result['status']==='success'?0:1);
}
