<?php
/** Private SSH payload only: encrypt before publication. Never emits option values. */
if(PHP_SAPI!=='cli')exit(1);
if(!defined('PETISH_CLUB_VERIFY_LIBRARY'))define('PETISH_CLUB_VERIFY_LIBRARY',true);
require_once __DIR__.'/verify-pet-club.php';
function petish_club_private_option_payload($run,$before,$current) {
 if(!petish_club_verify_run($run))petish_club_verify_fail();
 foreach(['orders','accounts','products','passports','photos']as$key)
  if(!array_key_exists($key,$before)||!array_key_exists($key,$current)||$before[$key]!==$current[$key])petish_club_verify_fail();
 if(!isset($before['settings_rows'],$before['settings'],$current['settings_rows'])
  ||!hash_equals($before['settings'],hash('sha256',json_encode($before['settings_rows'],JSON_THROW_ON_ERROR))))petish_club_verify_fail();
 $delta=petish_club_verify_option_delta($before['settings_rows'],$current['settings_rows']);
 $changes=array_merge($delta['added'],$delta['modified']);
 if($delta['removed']||!$changes||count($changes)>32)petish_club_verify_fail();
 $names=[];foreach($changes as$row){$name=$row['option_name'];
  if(!is_string($name)||$name===''||strlen($name)>191||preg_match('/[\x00-\x1f\x7f]/',$name))petish_club_verify_fail();
  $names[]=$name;
 }
 sort($names);return ['format'=>'petish-club-private-options-v1','run_id'=>$run,'option_names'=>$names];
}
function petish_club_private_option_names_main($argv) {
 global $report;
 ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(0);umask(0077);
 $report=['status'=>'failure','category'=>'input','checks'=>[]];$payload=null;
 ob_start(static function($ignored,$phase)use(&$payload){
  if(!($phase&PHP_OUTPUT_HANDLER_FINAL))return '';
  return json_encode($payload??['status'=>'failure'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
 },0,0);
 try{
  if(count($argv)!==2||!petish_club_verify_run($argv[1]))petish_club_verify_fail();
  $run=$argv[1];$root=petish_club_verify_bootstrap('staging',true);$states=[];
  foreach(['native','browser']as$kind){$file=petish_club_verify_fixture_state_path($kind);
   if(!file_exists($file)&&!is_link($file))continue;
   $state=petish_club_verify_read_json($file);petish_club_verify_fixture_owned($state,$run,$kind);$states[]=$state;
  }
  if(count($states)!==1)petish_club_verify_fail();$state=$states[0];
  foreach($state['users']as$user)if(get_user_by('login',$user['login']))petish_club_verify_fail();
  $current=petish_club_verify_state($root);$candidate=petish_club_private_option_payload($run,$state['before'],$current);
  if(has_filter('query','petish_club_verify_query_guard')!==PHP_INT_MAX)petish_club_verify_fail();
  // Only this small payload crosses SSH. The caller must encrypt it with the
  // reviewed ephemeral public key before including anything in a receipt.
  $payload=$candidate;
  return 0;
 }catch(Throwable$ignored){return 1;}
}
if(!defined('PETISH_CLUB_PRIVATE_NAMES_LIBRARY'))exit(petish_club_private_option_names_main($argv));
