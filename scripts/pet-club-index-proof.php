<?php
/** Exercise the production migration algorithms using one owned staging pet. */
if(PHP_SAPI!=='cli')exit(1);

function petish_club_verify_index_migration($root,$state) {
 global $wpdb,$report;
 if(wp_get_environment_type()!=='staging'||!is_array($state)||($state['kind']??'')!=='native')petish_club_verify_fail();
 petish_club_verify_fixture_owned($state,$state['run']??'','native');
 $owner=$state['users']['owner']['id']??0;$id=$state['pet_id']??0;
 if(!is_int($owner)||$owner<1||!is_int($id)||$id<1)petish_club_verify_fail();
 $user=get_user_by('id',$owner);$post=get_post($id);
 if(!$user||$user->user_login!==$state['users']['owner']['login']||$user->user_email!==$state['users']['owner']['email']
  ||get_user_meta($owner,'_petish_club_rehearsal_ownership',true)!==$state['ownership']
  ||!$post||(int)$post->post_author!==$owner||$post->post_type!==Petish_Passport_Store::TYPE||$post->post_status!=='private'
  ||Petish_Passport_Community::pending_index_count()!==0)petish_club_verify_fail();
 $library=__DIR__.'/pet-club-index-migration.php';
 if(!petish_club_verify_path($library))petish_club_verify_fail();
 if(!defined('PETISH_CLUB_INDEX_LIBRARY'))define('PETISH_CLUB_INDEX_LIBRARY',true);
 require_once $library;
 $previous_user=get_current_user_id();$previous_phase=$GLOBALS['petish_club_fixture_phase']??'fixture';
 $path=__DIR__.'/pet-club-index-fixture.json';
 if(file_exists($path)||is_link($path))petish_club_verify_fail();
 $report['category']='index';$GLOBALS['petish_club_fixture_phase']='sharing';
 try{
  wp_set_current_user($owner);
  $shared=Petish_Passport_Community::publish($id,$owner,false,[]);
  if(is_wp_error($shared)||!$shared['shared'])petish_club_verify_fail();
  $frozen=get_post_meta($id,Petish_Passport_Community::META,true);
  $keys=[Petish_Passport_Community::INDEX_NAME,Petish_Passport_Community::INDEX_SPECIES,Petish_Passport_Community::INDEX_VARIETY];
  if(!is_array($frozen)||count(array_unique($keys))!==3)petish_club_verify_fail();
  $normalise=static function($value){$value=remove_accents($value,'en_US');return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);};
  $expected=[$normalise($frozen['name']??''),$frozen['species']??'',$normalise($frozen['variety']??'')];
  // Only this exact owned fixture becomes a legacy unindexed profile.
  foreach($keys as$key)if(!delete_post_meta($id,$key))petish_club_verify_fail();
  if(Petish_Passport_Community::pending_index_count()!==1)petish_club_verify_fail();
  $before=petish_club_verify_state($root);
  $posts=petish_club_verify_table_state($wpdb->posts);
  $where=$wpdb->prepare(' WHERE NOT (post_id=%d AND meta_key IN (%s,%s,%s))',$id,...$keys);
  $other_meta=petish_club_verify_table_state($wpdb->postmeta,$where);
  $journal=Petish_Club_Index_Migration::with_gate(static fn()=>Petish_Club_Index_Migration::plan($root,$state['run'],[$id]));
  petish_club_verify_mark('index_migration_plan_verified',is_array($journal)&&($journal['scope']??'')==='fixture'
   &&count($journal['entries']??[])===1&&($journal['entries'][0]['pet_id']??0)===$id
   &&($journal['entries'][0]['owner']??0)===$owner&&array_keys($journal['entries'][0]['fields']??[])===$keys
   &&$before===petish_club_verify_state($root));
  Petish_Club_Index_Migration::write($path,$journal);
  $applied=Petish_Club_Index_Migration::with_gate(static function()use($root,&$journal,$path){return Petish_Club_Index_Migration::apply($root,$journal,$path);});
  petish_club_verify_mark('index_migration_apply_verified',$applied===true&&Petish_Passport_Community::pending_index_count()===0
   &&get_post_meta($id,$keys[0],true)===$expected[0]
   &&get_post_meta($id,$keys[1],true)===$expected[1]
   &&get_post_meta($id,$keys[2],true)===$expected[2]);
  $verified=Petish_Club_Index_Migration::with_gate(static fn()=>Petish_Club_Index_Migration::verify($root,$journal));
  $during=petish_club_verify_state($root);$non_index_equal=true;
  foreach($before as$key=>$value)if($key!=='passports'&&$value!==($during[$key]??null))$non_index_equal=false;
  petish_club_verify_mark('index_migration_exact_delta_verified',$verified===true&&$non_index_equal
   &&$posts===petish_club_verify_table_state($wpdb->posts)&&$other_meta===petish_club_verify_table_state($wpdb->postmeta,$where)
   &&get_post_meta($id,Petish_Passport_Community::META,true)===$frozen);
  $rolled_back=Petish_Club_Index_Migration::with_gate(static function()use($root,&$journal,$path){return Petish_Club_Index_Migration::rollback($root,$journal,$path);});
  $missing=true;foreach($keys as$key)if(metadata_exists('post',$id,$key))$missing=false;
  petish_club_verify_mark('index_migration_rollback_verified',$rolled_back===true&&$missing
   &&Petish_Passport_Community::pending_index_count()===1&&$before===petish_club_verify_state($root));
  // Leave later fixture checks with a complete index; no other pet is pending.
  $backfill=Petish_Passport_Community::backfill_index(1);
  if(is_wp_error($backfill)||$backfill!==['processed'=>1,'remaining'=>false])petish_club_verify_fail();
  if(!petish_club_verify_path($path)||(fileperms($path)&07777)!==0600||!unlink($path))petish_club_verify_fail();
 }finally{wp_set_current_user($previous_user);$GLOBALS['petish_club_fixture_phase']=$previous_phase;}
}
