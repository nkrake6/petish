<?php
/** Private staging fixture lifecycle. Never run against the production database. */
if (PHP_SAPI !== 'cli') exit(1);
define('PETISH_CLUB_VERIFY_LIBRARY',true);
require __DIR__.'/verify-pet-club.php';
function petish_club_fixture_main($argv) {
 global $report;
 petish_club_verify_report_start();
 try {
  if(count($argv)!==3||!in_array($argv[1],['prepare','cleanup'],true)||!petish_club_verify_run($argv[2]))petish_club_verify_fail();
  $mode=$argv[1];$run=$argv[2];$root=petish_club_verify_bootstrap('staging');
  $input=__DIR__.'/pet-club-browser-input.json';
  if($mode==='prepare') {
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
