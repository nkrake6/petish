<?php
/** Temporary, owned Jetpack isolation for the private browser rehearsal only. */
if (PHP_SAPI !== 'cli') exit(1);
function petish_club_jetpack_guard_fail() { throw new RuntimeException('Pet Club staging guard validation failed.'); }
function petish_club_jetpack_guard_path($path,$directory=false,$mode=null) {
 clearstatcache(true,$path);$info=@lstat($path);
 if(!$info||realpath($path)!==$path||is_link($path)
  ||($info['mode']&0170000)!==($directory?0040000:0100000)
  ||(!$directory&&$info['nlink']!==1)||($mode!==null&&($info['mode']&07777)!==$mode))petish_club_jetpack_guard_fail();
 return $info;
}
function petish_club_jetpack_guard_paths($run) {
 if(!is_string($run)||!preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$run))petish_club_jetpack_guard_fail();
 $home=getenv('HOME');if(!is_string($home)||$home==='')petish_club_jetpack_guard_fail();
 petish_club_jetpack_guard_path($home,true);
 $tools=$home.'/.petish-pet-club-deploy-'.$run;
 if(__DIR__!==$tools)petish_club_jetpack_guard_fail();
 petish_club_jetpack_guard_path($tools,true,0700);
 $marker=$tools.'/.pet-club-owned';petish_club_jetpack_guard_path($marker,false,0600);
 if(file_get_contents($marker)!==$run."\n")petish_club_jetpack_guard_fail();
 $root=$home.'/domains/staging.petish.biz/public_html';$mu=$root.'/wp-content/mu-plugins';
 foreach([$root,$root.'/wp-content',$mu]as$dir)petish_club_jetpack_guard_path($dir,true);
 $existing=$mu.'/petish-staging-guard.php';petish_club_jetpack_guard_path($existing);
 if(!hash_equals('f7615da9180aff6b8b85d81f5821ed1bd4933970adeee3462dd252a502681ee6',hash_file('sha256',$existing)))petish_club_jetpack_guard_fail();
 return [$root,$mu.'/zz-petish-pet-club-fixture.php',$tools.'/pet-club-jetpack-guard.json',$tools.'/pet-club-jetpack-guard.php.pending'];
}
function petish_club_jetpack_guard_code($root,$path,$receipt,$run,$token,$expires) {
 $values=var_export([$root,$path,$receipt,$run,$token,$expires],true);
 return <<<'CODE'
<?php
/** Owned temporary Pet Club staging rehearsal guard; no stored setting changes. */
(function(){
 [$root,$path,$receipt,$run,$token,$expires]=
CODE
 .$values.";\n".<<<'CODE'
 if(!defined('ABSPATH')||rtrim(ABSPATH,'/')!==$root||__FILE__!==$path||realpath($path)!==$path
  ||!defined('PETISH_STAGING_GUARD_ACTIVE')||PETISH_STAGING_GUARD_ACTIVE!==true
  ||!function_exists('wp_get_environment_type')||wp_get_environment_type()!=='staging'
  ||time()>$expires||time()<$expires-7200)return;
 clearstatcache(true,$receipt);$info=@lstat($receipt);$file=@lstat($path);
 $tools=dirname($receipt);$marker=$tools.'/.pet-club-owned';$directory=@lstat($tools);$owned=@lstat($marker);
 if(!$directory||!$owned||realpath($tools)!==$tools||($directory['mode']&0177777)!==0040700
  ||realpath($marker)!==$marker||($owned['mode']&0177777)!==0100600||$owned['nlink']!==1
  ||file_get_contents($marker)!==$run."\n")return;
 if(!$info||!$file||is_link($receipt)||realpath($receipt)!==$receipt||($info['mode']&0177777)!==0100600
  ||$info['nlink']!==1||$info['size']>4096||($file['mode']&0177777)!==0100600||$file['nlink']!==1)return;
 $value=json_decode(file_get_contents($receipt),true);
 if(!is_array($value)||array_keys($value)!==['format','run_id','token','expires','sha256']
  ||$value['format']!=='petish-club-jetpack-guard-v1'||$value['run_id']!==$run
  ||$value['token']!==$token||$value['expires']!==$expires||!is_string($value['sha256'])
  ||!hash_equals($value['sha256'],hash_file('sha256',$path)))return;
 add_filter('jetpack_sync_listener_should_load','__return_false',PHP_INT_MAX);
 add_filter('jetpack_sync_sender_should_load','__return_false',PHP_INT_MAX);
 add_filter('pre_option_jetpack_sync_settings_disable','__return_true',PHP_INT_MAX);
 define('PETISH_PET_CLUB_JETPACK_GUARD_RUN',$run);
})();
CODE
 ."\n";
}
function petish_club_jetpack_guard_write($path,$bytes) {
 $stream=@fopen($path,'x+b');if(!$stream)petish_club_jetpack_guard_fail();
 try{if(!chmod($path,0600)||fwrite($stream,$bytes)!==strlen($bytes)||!fflush($stream))petish_club_jetpack_guard_fail();}
 finally{fclose($stream);}
 petish_club_jetpack_guard_path($path,false,0600);
}
function petish_club_jetpack_guard_install($run) {
 global $report;
 $report['category']='guard_paths';
 [$root,$path,$receipt,$pending]=petish_club_jetpack_guard_paths($run);
 foreach([$path,$receipt,$pending]as$file)if(file_exists($file)||is_link($file))petish_club_jetpack_guard_fail();
 $token=bin2hex(random_bytes(24));$expires=time()+7200;
 $code=petish_club_jetpack_guard_code($root,$path,$receipt,$run,$token,$expires);
 $value=['format'=>'petish-club-jetpack-guard-v1','run_id'=>$run,'token'=>$token,'expires'=>$expires,'sha256'=>hash('sha256',$code)];
 // These finite checks distinguish host restrictions without disclosing paths/errors.
 $report['category']='guard_link_unavailable';
 if(!function_exists('link'))petish_club_jetpack_guard_fail();
 $report['category']='guard_cross_device';
 $source=stat(dirname($pending));$destination=stat(dirname($path));
 if(!$source||!$destination||$source['dev']!==$destination['dev'])petish_club_jetpack_guard_fail();
 // Write-ahead receipt permits same-run recovery after process interruption.
 $report['category']='guard_receipt';
 petish_club_jetpack_guard_write($receipt,json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
 $report['category']='guard_pending';
 petish_club_jetpack_guard_write($pending,$code);
 $report['category']='guard_publish';
 if(!link($pending,$path)||!unlink($pending))petish_club_jetpack_guard_fail();
 $report['category']='guard_verified';
 petish_club_jetpack_guard_path($path,false,0600);
 if(!hash_equals($value['sha256'],hash_file('sha256',$path)))petish_club_jetpack_guard_fail();
 return true;
}
function petish_club_jetpack_guard_remove($run) {
 [$root,$path,$receipt,$pending]=petish_club_jetpack_guard_paths($run);
 if(!file_exists($receipt)&&!is_link($receipt)){
  foreach([$path,$pending]as$file)if(file_exists($file)||is_link($file))petish_club_jetpack_guard_fail();
  return true;
 }
 petish_club_jetpack_guard_path($receipt,false,0600);
 if(filesize($receipt)>4096)petish_club_jetpack_guard_fail();
 $value=json_decode(file_get_contents($receipt),true,16,JSON_THROW_ON_ERROR);
 if(!is_array($value)||array_keys($value)!==['format','run_id','token','expires','sha256']
  ||$value['format']!=='petish-club-jetpack-guard-v1'||$value['run_id']!==$run
  ||!is_string($value['token'])||!preg_match('/\A[a-f0-9]{48}\z/D',$value['token'])
  ||!is_int($value['expires'])||$value['expires']<1||$value['expires']>time()+7200
  ||!is_string($value['sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$value['sha256']))petish_club_jetpack_guard_fail();
 $expected=petish_club_jetpack_guard_code($root,$path,$receipt,$run,$value['token'],$value['expires']);
 if(!hash_equals($value['sha256'],hash('sha256',$expected)))petish_club_jetpack_guard_fail();
 $present=[];
 foreach([$path,$pending]as$file){
  if(!file_exists($file)&&!is_link($file))continue;
  clearstatcache(true,$file);$info=@lstat($file);
  if(!$info||is_link($file)||realpath($file)!==$file||($info['mode']&0177777)!==0100600
   ||!in_array($info['nlink'],[1,2],true)||!hash_equals($value['sha256'],hash_file('sha256',$file)))petish_club_jetpack_guard_fail();
  $present[$file]=$info;
 }
 foreach($present as$info)if($info['nlink']===2&&(count($present)!==2
  ||$present[$path]['ino']!==$present[$pending]['ino']||$present[$path]['dev']!==$present[$pending]['dev']))petish_club_jetpack_guard_fail();
 // Validate every candidate before deleting any; changed or foreign files survive.
 foreach([$pending,$path]as$file)if(isset($present[$file])){
  clearstatcache(true,$file);$now=@lstat($file);
  if(!$now||$now['ino']!==$present[$file]['ino']||$now['dev']!==$present[$file]['dev']
   ||!hash_equals($value['sha256'],hash_file('sha256',$file))||!unlink($file))petish_club_jetpack_guard_fail();
 }
 if(!unlink($receipt))petish_club_jetpack_guard_fail();return true;
}
