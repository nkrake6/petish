<?php
/** Private staging fixture lifecycle. Never run against the production database. */
if (PHP_SAPI !== 'cli') exit(1);
define('PETISH_CLUB_VERIFY_LIBRARY',true);
require __DIR__.'/verify-pet-club.php';
require __DIR__.'/pet-club-jetpack-guard.php';
/** Retire owned files only. The failed settings proof and journal stay intact. */
function petish_club_fixture_retire($root,$run) {
 global $report,$wpdb;
 $report['category']='retire';$states=[];
 foreach(['native','browser']as$kind){$path=petish_club_verify_fixture_state_path($kind);
  if(!file_exists($path)&&!is_link($path))continue;
  $state=petish_club_verify_read_json($path);petish_club_verify_fixture_owned($state,$run,$kind);
  $states[]=['path'=>$path,'sha256'=>hash_file('sha256',$path),'state'=>$state];
 }
 petish_club_verify_mark('fixture_journal_valid',count($states)===1&&$states[0]['state']['kind']==='browser');
 $entry=$states[0];$state=$entry['state'];
 $diagnostic=__DIR__.'/pet-club-diagnostic.json';$diagnostic_hash=null;
 if(file_exists($diagnostic)||is_link($diagnostic)){
  $details=petish_club_verify_read_json($diagnostic);
  if(($details['format']??'')!=='petish-club-diagnostic-v1'||($details['run']??'')!==$run||($details['kind']??'')!=='browser')petish_club_verify_fail();
  $diagnostic_hash=hash_file('sha256',$diagnostic);
 }
 foreach($state['users']as$user)if(get_user_by('login',$user['login']))petish_club_verify_fail();
 petish_club_verify_mark('owned_users_absent',true);
 $current=petish_club_verify_state($root);$before=$state['before'];
 foreach(['orders_unchanged'=>'orders','users_and_authentication_unchanged'=>'accounts',
  'product_prices_and_stock_unchanged'=>'products','existing_passports_unchanged'=>'passports',
  'private_photo_files_unchanged'=>'photos']as$check=>$key)petish_club_verify_mark($check,$before[$key]===$current[$key]);
 petish_club_verify_mark('only_known_runtime_cache_drift',petish_club_verify_retirement_delta($before,$current));
 $timestamp=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE BINARY option_name=%s",'the7_plugins_last_check'));
 petish_club_verify_mark('theme_update_check_timestamp_verified',!is_multisite()&&is_string($timestamp)&&preg_match('/\A[0-9]{10}\z/D',$timestamp)
  &&(int)$timestamp>=946684800&&(int)$timestamp<=time()+60&&petish_club_verify_theme_timestamp_source($root));
 $input=__DIR__.'/pet-club-browser-input.json';
 if(file_exists($input)||is_link($input)){
  $value=petish_club_verify_read_json($input);
  if(($value['format']??'')!=='petish-club-browser-v1'||($value['run']??'')!==$run
   ||($value['owner']??null)!==$state['users']['owner']||($value['member']??null)!==$state['users']['member']
   ||($value['pet_id']??null)!==$state['pet_id']||($value['photo_moment_id']??null)!==$state['moments']['photo']
   ||($value['journal_moment_id']??null)!==$state['moments']['journal'])petish_club_verify_fail();
 }
 petish_club_verify_mark('retirement_read_only',has_filter('query','petish_club_verify_query_guard')===PHP_INT_MAX);
 petish_club_jetpack_guard_remove($run);petish_club_verify_mark('owned_guard_retired',true);
 if((file_exists($input)||is_link($input))&&!unlink($input))petish_club_verify_fail();
 petish_club_verify_mark('browser_input_retired',!file_exists($input)&&!is_link($input));
 // Compare against the current state only to prove artifact retirement itself
 // changed no database state; the original settings mismatch stays unresolved.
 if(petish_club_verify_state($root)!==$current)petish_club_verify_fail();
 petish_club_verify_mark('original_journal_retained',petish_club_verify_path($entry['path'])
  &&hash_equals($entry['sha256'],hash_file('sha256',$entry['path']))
  &&($diagnostic_hash===null||(petish_club_verify_path($diagnostic)&&hash_equals($diagnostic_hash,hash_file('sha256',$diagnostic)))));
}
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
  $delta=null;$candidate_names=[];
  $report['option_names']=[];$report['option_name_prefixes']=[];
  if(isset($before['settings_rows'])){
   if(!hash_equals($before['settings'],hash('sha256',json_encode($before['settings_rows'],JSON_THROW_ON_ERROR))))petish_club_verify_fail();
   $delta=petish_club_verify_option_delta($before['settings_rows'],$rows);
   $report['checks']['option_rows_baseline_available']=true;$report['checks']['option_delta_identified']=true;
   foreach($delta as$operation=>$changes){$report['checks']['options_'.$operation]=count($changes)>0;
    foreach($changes as$change){$name=$change['option_name'];
     if(petish_club_verify_safe_option_name($name))$candidate_names[]=$name;
     else{$report['checks']['option_names_redacted']=true;$prefix=petish_club_verify_option_prefix($name);
      if($prefix!==null)$report['option_name_prefixes'][]=$prefix;}
     $report['checks']['changed_option_'.petish_club_verify_option_category($name)]=true;
     if(in_array($name,PETISH_CLUB_KNOWN_OPTION_KEYS,true))$report['checks']['changed_known_option_'.str_replace(['.','-'],'_',$name)]=true;
    }
   }
   $candidate_names=array_values(array_unique($candidate_names));sort($candidate_names);
   $report['checks']['option_names_truncated']=count($candidate_names)>12;
   $candidate_names=array_slice($candidate_names,0,12);
   $verified=petish_club_verify_literal_option_names($root,$candidate_names);
   foreach(array_diff($candidate_names,$verified)as$name){
    $report['checks']['option_names_redacted']=true;$prefix=petish_club_verify_option_prefix($name);
    if($prefix!==null)$report['option_name_prefixes'][]=$prefix;
   }
   $report['option_names']=$verified;sort($report['option_names']);
   $report['checks']['option_names_code_literals_verified']=true;
   $report['option_name_prefixes']=array_values(array_unique($report['option_name_prefixes']));sort($report['option_name_prefixes']);
  }
  $events=[];$event_file=__DIR__.'/pet-club-'.$state['kind'].'-option-events.json';
  if(file_exists($event_file)||is_link($event_file)){
   $report['checks']['option_event_trace_present']=true;
   $trace=petish_club_verify_read_json($event_file);
   if(($trace['format']??'')!=='petish-club-option-events-v1'||($trace['run']??'')!==$run
    ||($trace['kind']??'')!==$state['kind']||!is_array($trace['events']??null)||count($trace['events'])>500)petish_club_verify_fail();
   $report['checks']['option_event_trace_nonempty']=count($trace['events'])>0;
   $names=[];foreach($delta??[]as$changes)foreach($changes as$change)$names[]=$change['option_name'];
   foreach($trace['events']as$event){
    if(!is_array($event)||!is_string($event['option_name']??null)||strlen($event['option_name'])>191
     ||!in_array($event['operation']??'',['added_option','updated_option','deleted_option'],true)
     ||!in_array($event['phase']??'',['fixture','user_creation','pet_creation','moments','sharing','hearts','moderation','cleanup'],true))petish_club_verify_fail();
    if(!in_array($event['option_name'],$names,true))continue;$events[]=$event;
    $report['checks']['changed_option_events_found']=true;
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
  if(count($argv)!==3||!in_array($argv[1],['prepare','cleanup','diagnose','retire'],true)||!petish_club_verify_run($argv[2]))petish_club_verify_fail();
  $mode=$argv[1];$run=$argv[2];
  if($mode==='prepare')petish_club_jetpack_guard_verify($run);
  $root=petish_club_verify_bootstrap('staging',in_array($mode,['diagnose','retire'],true));
  if($mode==='prepare'&&(!defined('PETISH_PET_CLUB_JETPACK_GUARD_RUN')||PETISH_PET_CLUB_JETPACK_GUARD_RUN!==$run))petish_club_verify_fail();
  $input=__DIR__.'/pet-club-browser-input.json';
  if($mode==='retire') {
   petish_club_fixture_retire($root,$run);$expected=PETISH_CLUB_FIXTURE_RETIRE;
  } elseif($mode==='diagnose') {
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
   foreach(['browser','native']as$kind)$found=petish_club_verify_fixture_cleanup($root,$run,$kind,true)||$found;
   if(!$found){petish_club_verify_state_equal($before,petish_club_verify_state($root));petish_club_verify_mark('fixture_cleanup_verified',true);}
   petish_club_jetpack_guard_remove($run);
   if(file_exists($input)||is_link($input)){
    $value=petish_club_verify_read_json($input);
    if(($value['format']??'')!=='petish-club-browser-v1'||($value['run']??'')!==$run||!unlink($input))petish_club_verify_fail();
   }
   // Keep original baseline evidence until every owned browser artifact has
   // been removed; a failed guard/input cleanup must remain retryable.
   foreach(['browser','native']as$kind){$path=petish_club_verify_fixture_state_path($kind);
    if(file_exists($path)||is_link($path)){
     $state=petish_club_verify_read_json($path);petish_club_verify_fixture_owned($state,$run,$kind);
     if(!unlink($path))petish_club_verify_fail();
    }
   }
   $expected=PETISH_CLUB_FIXTURE_CLEANUP;
  }
  if(array_diff($expected,array_keys($report['checks'])))petish_club_verify_fail();
  $report['status']='success';$report['category']='complete';
 }catch(Throwable$ignored){$report['status']='failure';}
 return $report['status']==='success'?0:1;
}
exit(petish_club_fixture_main($argv));
