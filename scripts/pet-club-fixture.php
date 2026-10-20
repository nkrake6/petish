<?php
/** Private staging fixture lifecycle. Never run against the production database. */
if (PHP_SAPI !== 'cli') exit(1);
define('PETISH_CLUB_VERIFY_LIBRARY',true);
require __DIR__.'/verify-pet-club.php';
function petish_club_fixture_diagnose($root,$run) {
 global $report;
 $report['category']='diagnose';$states=[];
 foreach(['native','browser']as$kind){$path=petish_club_verify_fixture_state_path($kind);
  if(!file_exists($path)&&!is_link($path))continue;
  $state=petish_club_verify_read_json($path);petish_club_verify_fixture_owned($state,$run,$kind);$states[]=$state;
 }
 petish_club_verify_mark('fixture_journal_valid',count($states)===1);
 $state=$states[0];foreach($state['users']as$user)if(get_user_by('login',$user['login']))petish_club_verify_fail();
 petish_club_verify_mark('owned_users_absent',true);
 if(has_filter('query','petish_club_verify_query_guard')!==PHP_INT_MAX)petish_club_verify_fail();
  $current=petish_club_verify_state($root);$before=$state['before'];
  foreach(['orders_unchanged'=>'orders','users_and_authentication_unchanged'=>'accounts',
   'product_prices_and_stock_unchanged'=>'products','existing_passports_unchanged'=>'passports',
   'private_photo_files_unchanged'=>'photos']as$check=>$key)petish_club_verify_mark($check,$before[$key]===$current[$key]);
  $rows=$current['settings_rows'];$added=petish_club_verify_single_added_option($before['settings'],$rows);
  foreach(array_merge(PETISH_CLUB_DIAGNOSTIC_OBSERVATIONS,petish_club_verify_known_option_checks())as$key)$report['checks'][$key]=false;
  $report['checks']['settings_unchanged']=hash_equals($before['settings'],$current['settings']);
  $report['checks']['single_added_option_identified']=$added!==null;
  $report['checks']['only_new_nonce_key_and_salt_identified']=petish_club_verify_new_nonce_options($before['settings'],$rows);
  if($added!==null)$report['checks']['added_option_'.petish_club_verify_option_category($added['option_name'])]=true;
  $delta=null;
  if(isset($before['settings_rows'])){
   if(!hash_equals($before['settings'],hash('sha256',json_encode($before['settings_rows'],JSON_THROW_ON_ERROR))))petish_club_verify_fail();
   $delta=petish_club_verify_option_delta($before['settings_rows'],$rows);
   $report['checks']['option_rows_baseline_available']=true;$report['checks']['option_delta_identified']=true;
   foreach($delta as$operation=>$changes){$report['checks']['options_'.$operation]=count($changes)>0;
    foreach($changes as$change){$name=$change['option_name'];
     $report['checks']['changed_option_'.petish_club_verify_option_category($name)]=true;
     if(in_array($name,PETISH_CLUB_KNOWN_OPTION_KEYS,true))$report['checks']['changed_known_option_'.str_replace(['.','-'],'_',$name)]=true;
    }
   }
  }
  $events=[];$event_file=__DIR__.'/pet-club-'.$state['kind'].'-option-events.json';
  if(file_exists($event_file)||is_link($event_file)){
   $trace=petish_club_verify_read_json($event_file);
   if(($trace['format']??'')!=='petish-club-option-events-v1'||($trace['run']??'')!==$run
    ||($trace['kind']??'')!==$state['kind']||!is_array($trace['events']??null)||count($trace['events'])>500)petish_club_verify_fail();
   $names=[];foreach($delta??[]as$changes)foreach($changes as$change)$names[]=$change['option_name'];
   foreach($trace['events']as$event){
    if(!is_array($event)||!is_string($event['option_name']??null)||strlen($event['option_name'])>191
     ||!in_array($event['operation']??'',['added_option','updated_option','deleted_option'],true)
     ||!in_array($event['phase']??'',['fixture','user_creation','pet_creation','moments','sharing','hearts','moderation','cleanup'],true))petish_club_verify_fail();
    if(!in_array($event['option_name'],$names,true))continue;$events[]=$event;
    if($event['phase']!=='fixture')$report['checks']['option_change_'.$event['phase']]=true;
   }
  }
  $details=['format'=>'petish-club-diagnostic-v1','run'=>$run,'kind'=>$state['kind'],
   'baseline_settings_hash'=>$before['settings'],'current_settings_hash'=>$current['settings'],
   'single_added_option'=>$added,'option_delta'=>$delta,'option_events'=>$events];
  // Only option names and SHA-256 values go into this 0600 private file. Nothing
  // here restores, deletes or accepts a changed WordPress setting.
  petish_club_verify_write_json(__DIR__.'/pet-club-diagnostic.json',$details,
   file_exists(__DIR__.'/pet-club-diagnostic.json'));
  $report['checks']['diagnostic_write_attempt_blocked']=!empty($GLOBALS['petish_club_write_attempted']);
  petish_club_verify_mark('diagnostic_read_only',has_filter('query','petish_club_verify_query_guard')===PHP_INT_MAX);
 petish_club_verify_mark('diagnostic_completed',true);
}
function petish_club_fixture_main($argv) {
 global $report;
 petish_club_verify_report_start();
 try {
  if(count($argv)!==3||!in_array($argv[1],['prepare','cleanup','diagnose'],true)||!petish_club_verify_run($argv[2]))petish_club_verify_fail();
  $mode=$argv[1];$run=$argv[2];$root=petish_club_verify_bootstrap('staging',$mode==='diagnose');
  $input=__DIR__.'/pet-club-browser-input.json';
  if($mode==='diagnose') {
   petish_club_fixture_diagnose($root,$run);$expected=PETISH_CLUB_FIXTURE_DIAGNOSE;
  } elseif($mode==='prepare') {
   $report['category']='fixture';
   // A prior unfinished fixture must be cleaned, never silently replaced.
   foreach(['native','browser']as$kind)if(file_exists(petish_club_verify_fixture_state_path($kind))||is_link(petish_club_verify_fixture_state_path($kind)))petish_club_verify_fail();
   if(file_exists($input)||is_link($input))petish_club_verify_fail();
   $state=petish_club_verify_fixture_begin($root,$run,'browser');
   $pet=petish_club_verify_fixture_content($root,$state);
   $pet=Petish_Passport_Store::update($pet['id'],$state['users']['owner']['id'],[
    'name'=>'Petish Club Test Rabbit','species'=>'rabbit','age'=>'1-3','interest'=>'browsing',
    'bio'=>'A curious synthetic rabbit.','traits'=>['Curious','Gentle']]);
   if(is_wp_error($pet))petish_club_verify_fail();
   petish_club_verify_write_json($input,['format'=>'petish-club-browser-v1','url'=>'https://staging.petish.biz',
    'run'=>$run,'owner'=>$state['users']['owner'],'member'=>$state['users']['member'],
    'pet_id'=>$pet['id'],'photo_moment_id'=>$state['moments']['photo'],'journal_moment_id'=>$state['moments']['journal'],
    'name'=>$pet['name'],'private_photo_url'=>Petish_Passport_Media::url($pet['id']),
    'private_moment_url'=>Petish_Passport_Moments::url($state['moments']['photo']),
    'safety'=>['environment_staging'=>true,'staging_database_separate'=>true,'guard_runtime_verified'=>true,
     'owned_fixture_only'=>true,'suppliers_blocked'=>true,'mail_blocked'=>true,'payments_blocked'=>true]]);
   petish_club_verify_mark('browser_input_written',true);
   $expected=PETISH_CLUB_FIXTURE_PREPARE;
  } else {
   $report['category']='cleanup';$before=petish_club_verify_state($root);$found=false;
   foreach(['browser','native']as$kind)$found=petish_club_verify_fixture_cleanup($root,$run,$kind)||$found;
   if(!$found){petish_club_verify_state_equal($before,petish_club_verify_state($root));petish_club_verify_mark('fixture_cleanup_verified',true);}
   if(file_exists($input)||is_link($input)){
    $value=petish_club_verify_read_json($input);
    if(($value['format']??'')!=='petish-club-browser-v1'||($value['run']??'')!==$run||!unlink($input))petish_club_verify_fail();
   }
   $expected=PETISH_CLUB_FIXTURE_CLEANUP;
  }
  if(array_diff($expected,array_keys($report['checks'])))petish_club_verify_fail();
  $report['status']='success';$report['category']='complete';
 }catch(Throwable$ignored){$report['status']='failure';}
 return $report['status']==='success'?0:1;
}
exit(petish_club_fixture_main($argv));
