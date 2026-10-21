<?php
/** Staging-only review rollout controller. Never accepts a target host/path. */
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0); umask(0077);
const PCR_ROOT = '/home/u839205597/domains/staging.petish.biz/public_html';
const PCR_HOME = '/home/u839205597';
const PCR_GUARD = 'f7615da9180aff6b8b85d81f5821ed1bd4933970adeee3462dd252a502681ee6';
const PCR_COMPONENTS = ['wp-content/themes/dt-the7-child','wp-content/plugins/petish-passport','wp-content/plugins/petish-fulfilment'];
const PCR_PHASES = ['prepare','source','enable','verify','cleanup','rollback-flag','rollback-code','commit'];
const PCR_CHECKS = ['fixed_staging_paths','configuration_isolated','guard_pinned','source_verified','code_snapshot_verified',
 'environment_staging','database_separate','outbound_blocked','mail_blocked','async_blocked','jetpack_isolated','upload_limits_sufficient',
 'flag_journal_written','flag_enabled','flag_restored','code_restored','protected_files_unchanged','browser_guard_installed',
 'browser_guard_removed','native_proof_passed','fixture_cleanup_verified','release_committed'];
$report=['status'=>'failure','category'=>'input','checks'=>[]];
ob_start(static function($ignored,$phase)use(&$report){return ($phase&PHP_OUTPUT_HANDLER_FINAL)?json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n":'';},0,0);
function pcr_fail(){throw new RuntimeException('Staging review verification failed.');}
function pcr_mark($key,$ok){global $report;if(!in_array($key,PCR_CHECKS,true)||!is_bool($ok))pcr_fail();$report['checks'][$key]=$ok;if(!$ok)pcr_fail();}
function pcr_path($path,$directory=false){
 if(!is_string($path)||$path===''||$path[0]!=='/'||str_contains($path,"\0"))pcr_fail();
 $cursor='';foreach(explode('/',substr($path,1))as$part){if($part===''||$part==='.'||$part==='..')pcr_fail();$cursor.='/'.$part;if(is_link($cursor)||realpath($cursor)!==$cursor)pcr_fail();}
 $info=lstat($path);if(!$info||($directory?!is_dir($path):(!is_file($path)||$info['nlink']!==1)))pcr_fail();return$info;
}
function pcr_write($path,$data){
 pcr_path(dirname($path),true);if(file_exists($path)||is_link($path))pcr_fail();$bytes=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
 if(strlen($bytes)>8388608)pcr_fail();$handle=fopen($path,'xb');if(!$handle)pcr_fail();try{if(!chmod($path,0600)||fwrite($handle,$bytes)!==strlen($bytes)||!fflush($handle))pcr_fail();}finally{fclose($handle);}
}
function pcr_read($path){$st=pcr_path($path);if(($st['mode']&07777)!==0600||$st['size']>8388608)pcr_fail();$value=json_decode(file_get_contents($path),true,128,JSON_THROW_ON_ERROR);if(!is_array($value))pcr_fail();return$value;}
function pcr_tokens($path){pcr_path($path);return array_values(array_filter(token_get_all(file_get_contents($path),TOKEN_PARSE),static fn($t)=>!is_array($t)||!in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)));}
function pcr_literal($token){if(!is_array($token)||$token[0]!==T_CONSTANT_ENCAPSED_STRING||str_contains($token[1],'\\'))return null;return substr($token[1],1,-1);}
function pcr_defines($tokens,$names){
 $found=[];$mentions=[];$depth=0;foreach($tokens as$i=>$token){if($token==='{')$depth++;if($token==='}')$depth--;$name=pcr_literal($token);if(!in_array($name,$names,true))continue;$mentions[$name]=($mentions[$name]??0)+1;
  $start=$i-2;$previous=$tokens[$start-1]??null;if($depth!==0||!is_array($tokens[$start]??null)||$tokens[$start][0]!==T_STRING||strtolower($tokens[$start][1])!=='define'||($tokens[$i-1]??null)!=='('||($tokens[$i+1]??null)!==','||($tokens[$i+3]??null)!==')'||($tokens[$i+4]??null)!==';'||($previous!==';'&&!(is_array($previous)&&$previous[0]===T_OPEN_TAG)))continue;
  $raw=$tokens[$i+2]??null;$value=pcr_literal($raw);if(is_array($raw)&&$raw[0]===T_STRING&&in_array(strtolower($raw[1]),['true','false'],true))$value=strtolower($raw[1])==='true';if($value!==null)$found[$name]=$value;
 }foreach($names as$name)if(($mentions[$name]??0)!==1)unset($found[$name]);return$found;
}
function pcr_configuration(){
 foreach([PCR_ROOT,PCR_HOME.'/.petish-staging',PCR_ROOT.'/wp-content/mu-plugins']as$p)pcr_path($p,true);
 $private=PCR_HOME.'/.petish-staging/config.php';$loader=PCR_ROOT.'/wp-config.php';$guard=PCR_ROOT.'/wp-content/mu-plugins/petish-staging-guard.php';
 pcr_path($guard);pcr_mark('guard_pinned',hash_equals(PCR_GUARD,hash_file('sha256',$guard)));
 $tokens=pcr_tokens($loader);$requires=0;$depth=0;foreach($tokens as$i=>$token){if($token==='{')$depth++;if($token==='}')$depth--;if(is_array($token)&&in_array($token[0],[T_REQUIRE,T_REQUIRE_ONCE],true)&&$depth===0&&pcr_literal($tokens[$i+1]??null)===$private&&($tokens[$i+2]??null)===';')$requires++;}
 $defs=pcr_defines(array_merge($tokens,pcr_tokens($private)),['DB_NAME','DB_HOST','WP_ENVIRONMENT_TYPE','WCPAY_DEV_MODE','DISABLE_WP_CRON']);
 $production=[];foreach(['DB_NAME','DB_HOST']as$name){$value=getenv('PETISH_REHEARSAL_PRODUCTION_'.$name);if(!is_string($value)||$value===''||strlen($value)>255||preg_match('/[\x00-\x1f\x7f]/',$value))pcr_fail();$production[$name]=$value;}
 pcr_mark('configuration_isolated',$requires===1&&($defs['WP_ENVIRONMENT_TYPE']??null)==='staging'&&($defs['WCPAY_DEV_MODE']??null)===true&&($defs['DISABLE_WP_CRON']??null)===true&&is_string($defs['DB_NAME']??null)&&is_string($defs['DB_HOST']??null)&&$defs['DB_NAME']!==''&&$defs['DB_NAME']!==$production['DB_NAME']);
 return$defs;
}
function pcr_tree($root){
 pcr_path($root,true);$out=[];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
 foreach($it as$item){$path=$item->getPathname();if($item->isDir()){pcr_path($path,true);continue;}$st=pcr_path($path);if($st['size']>33554432||count($out)>=20000)pcr_fail();$name=substr($path,strlen($root)+1);$out[$name]=hash_file('sha256',$path);}
 ksort($out);return$out;
}
function pcr_protected(){
 $out=[];foreach([PCR_ROOT.'/wp-config.php',PCR_HOME.'/.petish-staging/config.php']as$p){pcr_path($p);$out[$p]=hash_file('sha256',$p);}
 $mu=pcr_tree(PCR_ROOT.'/wp-content/mu-plugins');unset($mu['zz-petish-customer-reviews-fixture.php']);$out['mu']=$mu;
 foreach(['.htaccess','.user.ini']as$n)if(file_exists(PCR_ROOT.'/'.$n)){pcr_path(PCR_ROOT.'/'.$n);$out[$n]=hash_file('sha256',PCR_ROOT.'/'.$n);}return$out;
}
function pcr_source($work,$sha){
 $manifest=pcr_read($work.'/manifest.json');if(($manifest['format']??null)!=='petish-staging-source-v1'||($manifest['source_sha']??null)!==$sha||($manifest['components']??null)!==PCR_COMPONENTS||!is_array($manifest['files']??null))pcr_fail();
 $want=[];foreach($manifest['files']as$entry){if(!is_string($entry['path']??null)||!is_string($entry['sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$entry['sha256']))pcr_fail();$want[$entry['path']]=$entry['sha256'];}
 $actual=[];foreach(PCR_COMPONENTS as$c)foreach(pcr_tree(PCR_ROOT.'/'.$c)as$name=>$hash)$actual[$c.'/'.$name]=$hash;ksort($want);ksort($actual);pcr_mark('source_verified',$actual===$want);return$actual;
}
function pcr_copy_tree($from,$to){
 if(file_exists($to)||is_link($to)||!mkdir($to,0700))pcr_fail();$directory=pcr_path($from,true);
 foreach(new DirectoryIterator($from)as$item){if($item->isDot())continue;$path=$item->getPathname();$dest=$to.'/'.$item->getFilename();if($item->isDir()){pcr_path($path,true);pcr_copy_tree($path,$dest);}else{$file=pcr_path($path);if(!copy($path,$dest)||!chmod($dest,$file['mode']&0777))pcr_fail();}}
 // Preserve public traversal permissions when this snapshot is restored. The
 // enclosing private workspace remains 0700 throughout the operation.
 if(!chmod($to,$directory['mode']&0777))pcr_fail();
}
function pcr_prepare($work,$sha){
 if(file_exists($work.'/baseline.json')||is_link($work.'/baseline.json')||file_exists($work.'/backup'))pcr_fail();$baseline=['source'=>$sha,'protected'=>pcr_protected(),'components'=>[]];
 if(!mkdir($work.'/backup',0700))pcr_fail();foreach(PCR_COMPONENTS as$i=>$c){$before=pcr_tree(PCR_ROOT.'/'.$c);pcr_copy_tree(PCR_ROOT.'/'.$c,$work.'/backup/'.$i);if($before!==pcr_tree($work.'/backup/'.$i)||$before!==pcr_tree(PCR_ROOT.'/'.$c))pcr_fail();$baseline['components'][$c]=$before;}
 pcr_write($work.'/baseline.json',$baseline);pcr_mark('code_snapshot_verified',true);
}
function pcr_verify_protected($work){$baseline=pcr_read($work.'/baseline.json');pcr_mark('protected_files_unchanged',($baseline['protected']??null)===pcr_protected());}
function pcr_bootstrap($defs,$persistent_option_cache=false){
 foreach(['WP_HTTP_BLOCK_EXTERNAL'=>true,'WP_ACCESSIBLE_HOSTS'=>'','DISABLE_WP_CRON'=>true,'AUTOMATIC_UPDATER_DISABLED'=>true,'DISALLOW_FILE_MODS'=>true,'WP_USE_THEMES'=>false]as$k=>$v){if(defined($k))pcr_fail();define($k,$v);}
 $_SERVER=['HTTP_HOST'=>'staging.petish.biz','SERVER_NAME'=>'staging.petish.biz','SERVER_PORT'=>'443','HTTPS'=>'on','REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/my-account/','SERVER_PROTOCOL'=>'HTTP/1.1','REMOTE_ADDR'=>'127.0.0.1'];
 require_once PCR_ROOT.'/wp-includes/plugin.php';
 $no=static fn()=>false;$yes=static fn()=>true;$zero=static fn()=>0;$http=static fn()=>new WP_Error('petish_reviews_http_blocked','Staging verification blocks outbound requests.');
 foreach(['enable_loading_advanced_cache_dropin','jetpack_sync_listener_should_load','jetpack_sync_sender_should_load','action_scheduler_allow_async_request_runner']as$h)add_filter($h,$no,PHP_INT_MAX);
 if(!$persistent_option_cache)add_filter('enable_loading_object_cache_dropin',$no,PHP_INT_MAX);
 add_filter('pre_option_jetpack_sync_settings_disable',$yes,PHP_INT_MAX);add_filter('pre_http_request',$http,PHP_INT_MAX,3);add_filter('pre_wp_mail',$yes,PHP_INT_MAX,2);add_filter('action_scheduler_queue_runner_concurrent_batches',$zero,PHP_INT_MAX);
 $async=static function(){remove_all_actions('action_scheduler_run_queue');if(class_exists('ActionScheduler_QueueRunner',false)){ $r=ActionScheduler_QueueRunner::instance();if(is_callable([$r,'unhook_dispatch_async_request']))$r->unhook_dispatch_async_request(); }};
 add_action('wp_loaded',$async,PHP_INT_MAX);add_action('all',static function($hook)use($no,$yes,$zero,$http,$async){if($hook==='shutdown')$async();$map=['pre_http_request'=>[$http,3],'pre_wp_mail'=>[$yes,2],'jetpack_sync_listener_should_load'=>[$no,1],'jetpack_sync_sender_should_load'=>[$no,1],'pre_option_jetpack_sync_settings_disable'=>[$yes,1],'action_scheduler_allow_async_request_runner'=>[$no,1],'action_scheduler_queue_runner_concurrent_batches'=>[$zero,1]];if(isset($map[$hook])){remove_filter($hook,$map[$hook][0],PHP_INT_MAX);add_filter($hook,$map[$hook][0],PHP_INT_MAX,$map[$hook][1]);}},PHP_INT_MAX);
 require PCR_ROOT.'/wp-load.php';
 pcr_mark('environment_staging',wp_get_environment_type()==='staging'&&rtrim(ABSPATH,'/')===PCR_ROOT&&parse_url(home_url('/'),PHP_URL_HOST)==='staging.petish.biz'&&parse_url(site_url('/'),PHP_URL_HOST)==='staging.petish.biz'&&defined('PETISH_STAGING_GUARD_ACTIVE')&&PETISH_STAGING_GUARD_ACTIVE===true&&defined('WCPAY_DEV_MODE')&&WCPAY_DEV_MODE===true);
 pcr_mark('database_separate',defined('DB_NAME')&&DB_NAME===$defs['DB_NAME']&&defined('DB_HOST')&&DB_HOST===$defs['DB_HOST']);
 $blocked=apply_filters('pre_http_request',false,[],'https://www.dsers.com/');pcr_mark('outbound_blocked',is_wp_error($blocked)&&$blocked->get_error_code()==='petish_reviews_http_blocked');pcr_mark('mail_blocked',apply_filters('pre_wp_mail',null,[])===true);
 pcr_mark('async_blocked',DISABLE_WP_CRON===true&&apply_filters('action_scheduler_allow_async_request_runner',true)===false&&apply_filters('action_scheduler_queue_runner_concurrent_batches',1)===0);
 pcr_mark('jetpack_isolated',apply_filters('jetpack_sync_listener_should_load',true)===false&&apply_filters('jetpack_sync_sender_should_load',true)===false);
}
function pcr_flag_row(){global$wpdb;return$wpdb->get_row($wpdb->prepare("SELECT option_value,autoload FROM {$wpdb->options} WHERE option_name=%s",'petish_customer_reviews_enabled'),ARRAY_A);}
function pcr_upload_limits(){
 $bytes=static function($name){$raw=trim((string)ini_get($name));if(!preg_match('/\A([0-9]{1,15})([KMG]?)\z/iD',$raw,$m))return-1;return(int)$m[1]*(1024**([''=>0,'K'=>1,'M'=>2,'G'=>3][strtoupper($m[2])]));};
 $post=$bytes('post_max_size');return filter_var(ini_get('file_uploads'),FILTER_VALIDATE_BOOLEAN)&&($post===0||$post>=46*1024*1024)&&$bytes('upload_max_filesize')>=20*1024*1024&&(int)ini_get('max_file_uploads')>=6;
}
function pcr_enable($work){
 pcr_mark('upload_limits_sufficient',pcr_upload_limits());
 $old=pcr_flag_row();if($old!==null&&(!is_array($old)||!in_array($old['option_value']??null,['yes','no'],true)||!is_string($old['autoload']??null)))pcr_fail();
 pcr_write($work.'/flag.json',['previous'=>$old]);pcr_mark('flag_journal_written',true);update_option('petish_customer_reviews_enabled','yes',false);pcr_mark('flag_enabled',get_option('petish_customer_reviews_enabled')==='yes');
}
function pcr_flag_rollback($work){
 if(!file_exists($work.'/flag.json')){pcr_mark('flag_restored',true);return;}$journal=pcr_read($work.'/flag.json');if(!array_key_exists('previous',$journal))pcr_fail();$old=$journal['previous'];$now=pcr_flag_row();if($now===$old){pcr_mark('flag_restored',true);return;}
 if(!is_array($now)||$now['option_value']!=='yes')pcr_fail();global$wpdb;
 if($old===null){delete_option('petish_customer_reviews_enabled');}else{if(!is_array($old)||!in_array($old['option_value']??null,['yes','no'],true)||!is_string($old['autoload']??null))pcr_fail();$wpdb->update($wpdb->options,$old,['option_name'=>'petish_customer_reviews_enabled','option_value'=>'yes']);wp_cache_delete('petish_customer_reviews_enabled','options');wp_cache_delete('alloptions','options');}
 pcr_mark('flag_restored',pcr_flag_row()===$old);
}
function pcr_guard_install($work,$run){
 $file=PCR_ROOT.'/wp-content/mu-plugins/zz-petish-customer-reviews-fixture.php';$receipt=$work.'/browser-guard.json';if(file_exists($file)||is_link($file))pcr_fail();$expires=time()+3600;
 $prefix="<?php\n// Temporary owned staging review isolation; expires automatically.\n(function(){\n";
 $values=var_export([PCR_ROOT,$work,$run,$expires],true);
 $code=$prefix.'[$root,$work,$run,$expires]='.$values.";\n".<<<'GUARD'
 if(!defined('ABSPATH')||rtrim(ABSPATH,'/')!==$root||!defined('PETISH_STAGING_GUARD_ACTIVE')||PETISH_STAGING_GUARD_ACTIVE!==true||time()>$expires||time()<$expires-3600||wp_get_environment_type()!=='staging')return;
 $marker=$work.'/.owned';$receipt=$work.'/browser-guard.json';
 if(is_link($work)||realpath($work)!==$work||!is_file($marker)||is_link($marker)||file_get_contents($marker)!==$run."\n"||!is_file($receipt)||is_link($receipt))return;
 $state=json_decode(file_get_contents($receipt),true);if(!is_array($state)||($state['run']??null)!==$run||($state['expires']??null)!==$expires||!is_string($state['hash']??null)||!hash_equals($state['hash'],hash_file('sha256',__FILE__)))return;
 // Verify web PHP separately: CLI and web server limits can differ. Only the
 // finite outcome is exposed, never host configuration values or fixture data.
 $bytes=static function($name){$raw=trim((string)ini_get($name));if(!preg_match('/\A([0-9]{1,15})([KMG]?)\z/iD',$raw,$m))return-1;return(int)$m[1]*(1024**([''=>0,'K'=>1,'M'=>2,'G'=>3][strtoupper($m[2])]));};
 $post=$bytes('post_max_size');$upload_ok=filter_var(ini_get('file_uploads'),FILTER_VALIDATE_BOOLEAN)&&($post===0||$post>=46*1024*1024)&&$bytes('upload_max_filesize')>=20*1024*1024&&(int)ini_get('max_file_uploads')>=6;
 if(!headers_sent())header('X-Petish-Review-Upload-Limits: '.($upload_ok?'sufficient':'insufficient'));
 $no=static fn()=>false;$yes=static fn()=>true;$zero=static fn()=>0;$http=static fn()=>new WP_Error('petish_reviews_http_blocked','Staging verification blocks outbound requests.');
 foreach(['jetpack_sync_listener_should_load','jetpack_sync_sender_should_load','action_scheduler_allow_async_request_runner']as$hook)add_filter($hook,$no,PHP_INT_MAX);
 add_filter('pre_option_jetpack_sync_settings_disable',$yes,PHP_INT_MAX);add_filter('pre_http_request',$http,PHP_INT_MAX,3);add_filter('pre_wp_mail',$yes,PHP_INT_MAX,2);add_filter('action_scheduler_queue_runner_concurrent_batches',$zero,PHP_INT_MAX);
 $async=static function(){remove_all_actions('action_scheduler_run_queue');if(class_exists('ActionScheduler_QueueRunner',false)){ $runner=ActionScheduler_QueueRunner::instance();if(is_callable([$runner,'unhook_dispatch_async_request']))$runner->unhook_dispatch_async_request(); }};
 add_action('wp_loaded',$async,PHP_INT_MAX);add_action('all',static function($hook)use($no,$yes,$zero,$http,$async){if($hook==='shutdown')$async();$map=['pre_http_request'=>[$http,3],'pre_wp_mail'=>[$yes,2],'jetpack_sync_listener_should_load'=>[$no,1],'jetpack_sync_sender_should_load'=>[$no,1],'pre_option_jetpack_sync_settings_disable'=>[$yes,1],'action_scheduler_allow_async_request_runner'=>[$no,1],'action_scheduler_queue_runner_concurrent_batches'=>[$zero,1]];if(isset($map[$hook])){remove_filter($hook,$map[$hook][0],PHP_INT_MAX);add_filter($hook,$map[$hook][0],PHP_INT_MAX,$map[$hook][1]);}},PHP_INT_MAX);
 define('PETISH_CUSTOMER_REVIEWS_BROWSER_GUARD',$run);
})();
GUARD;
 pcr_write($receipt,['run'=>$run,'expires'=>$expires,'hash'=>hash('sha256',$code)]);$f=fopen($file,'xb');if(!$f)pcr_fail();try{if(!chmod($file,0600)||fwrite($f,$code)!==strlen($code)||!fflush($f))pcr_fail();}finally{fclose($f);}pcr_mark('browser_guard_installed',true);
}
function pcr_guard_remove($work,$run){
 $file=PCR_ROOT.'/wp-content/mu-plugins/zz-petish-customer-reviews-fixture.php';if(!file_exists($file)&&!is_link($file)){pcr_mark('browser_guard_removed',true);return;}$receipt=pcr_read($work.'/browser-guard.json');pcr_path($file);if(($receipt['run']??null)!==$run||!hash_equals($receipt['hash']??'',hash_file('sha256',$file))||!unlink($file))pcr_fail();pcr_mark('browser_guard_removed',true);
}
function pcr_code_rollback($work,$sha){
 $baseline=pcr_read($work.'/baseline.json');$manifest=pcr_read($work.'/manifest.json');
 if(($baseline['source']??null)!==$sha||($manifest['source_sha']??null)!==$sha)pcr_fail();
 $candidate=array_fill_keys(PCR_COMPONENTS,[]);
 foreach($manifest['files']??[] as$entry){$matched=false;foreach(PCR_COMPONENTS as$c){if(str_starts_with($entry['path']??'',$c.'/')){$candidate[$c][substr($entry['path'],strlen($c)+1)]=$entry['sha256'];$matched=true;break;}}if(!$matched)pcr_fail();}
 foreach($candidate as&$entries)ksort($entries);unset($entries);
 // Validate every component before changing any. Accept only this operation's
 // candidate or exact baseline, including interruption between its two renames.
 $states=[];
 foreach(PCR_COMPONENTS as$i=>$c){
  $target=PCR_ROOT.'/'.$c;$backup=$work.'/backup/'.$i;$failed=$work.'/failed-'.$i;
  $current=file_exists($target)?pcr_tree($target):null;if(is_link($target)||is_link($backup)||is_link($failed))pcr_fail();
  $old=$baseline['components'][$c]??null;if(!is_array($old)||!$candidate[$c])pcr_fail();
  if($current===$old){$states[$i]='done';continue;}
  if(!is_dir($backup)||pcr_tree($backup)!==$old)pcr_fail();
  if($current===$candidate[$c]&&!file_exists($failed))$states[$i]='move';
  elseif($current===null&&is_dir($failed)&&pcr_tree($failed)===$candidate[$c])$states[$i]='resume';
  else pcr_fail();
 }
 foreach(PCR_COMPONENTS as$i=>$c){
  if($states[$i]==='done')continue;$target=PCR_ROOT.'/'.$c;$backup=$work.'/backup/'.$i;$failed=$work.'/failed-'.$i;
  if($states[$i]==='move'&&!rename($target,$failed))pcr_fail();
  if(!rename($backup,$target))pcr_fail();
 }
 foreach(PCR_COMPONENTS as$c)if(pcr_tree(PCR_ROOT.'/'.$c)!==$baseline['components'][$c])pcr_fail();pcr_verify_protected($work);pcr_mark('code_restored',true);
}

try{
 if(count($argv)!==4||!in_array($argv[1],PCR_PHASES,true)||!preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$argv[2])||!preg_match('/\A[a-f0-9]{40}\z/D',$argv[3]))pcr_fail();
 [$script,$phase,$run,$sha]=$argv;$work=PCR_HOME.'/.petish-customer-reviews-'.$run;
 if(__DIR__!==$work||getenv('HOME')!==PCR_HOME)pcr_fail();$st=pcr_path($work,true);if(($st['mode']&07777)!==0700)pcr_fail();$marker=$work.'/.owned';$st=pcr_path($marker);if(($st['mode']&07777)!==0600||file_get_contents($marker)!==$run."\n")pcr_fail();pcr_mark('fixed_staging_paths',true);
 $report['category']='configuration';$defs=pcr_configuration();$report['category']=$phase;
 if($phase==='prepare')pcr_prepare($work,$sha);
 elseif($phase==='source'){pcr_source($work,$sha);pcr_verify_protected($work);}
 elseif($phase==='rollback-code')pcr_code_rollback($work,$sha);
 else{
  if($phase==='enable'){pcr_source($work,$sha);pcr_guard_install($work,$run);}
  pcr_bootstrap($defs,in_array($phase,['enable','rollback-flag'],true));
  if($phase==='enable')pcr_enable($work);
  elseif($phase==='rollback-flag'){pcr_flag_rollback($work);pcr_guard_remove($work,$run);}
  elseif($phase==='verify'){
   pcr_source($work,$sha);if(!class_exists('Petish_Customer_Reviews')||!Petish_Customer_Reviews::enabled())pcr_fail();require_once$work.'/customer-reviews-proof.php';
   $native=petish_customer_reviews_proof($work,true);if(!is_array($native)||!is_array($native['checks']??null))pcr_fail();
   pcr_write($work.'/native-result.json',$native);pcr_mark('native_proof_passed',($native['status']??null)==='success');
  }elseif($phase==='cleanup'){
   require_once$work.'/customer-reviews-proof.php';$clean=petish_customer_reviews_cleanup($work);if(!is_array($clean))pcr_fail();$result_file=$work.'/cleanup-result.json';if(file_exists($result_file)){pcr_path($result_file);if(!unlink($result_file))pcr_fail();}pcr_write($result_file,$clean);pcr_mark('fixture_cleanup_verified',($clean['status']??null)==='success');pcr_guard_remove($work,$run);pcr_verify_protected($work);
  }elseif($phase==='commit'){pcr_source($work,$sha);pcr_verify_protected($work);pcr_mark('flag_enabled',get_option('petish_customer_reviews_enabled')==='yes');pcr_guard_remove($work,$run);pcr_mark('release_committed',true);}
 }
 $report['status']='success';$report['category']='complete';
}catch(Throwable$ignored){}
exit($report['status']==='success'?0:1);
