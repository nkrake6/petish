<?php
/** Synthetic staging clips only. Real multipart upload is checked by the browser. */
if(!defined('PETISH_CLUB_VERIFY_LIBRARY')&&PHP_SAPI!=='cli')exit(1);
function petish_club_verify_video_fixture($name) {
 $hashes=['synthetic-valid.mp4'=>'729c392a8f77dea6990329f71c6f8e8e7e832f232910efe6b0c5e8a23a16013d',
  'synthetic-over-duration.mp4'=>'ac91bd681120bd0dfb3830c45ed2d800abbdf8b147131db3b1ff5ecb7ce4b8fc'];
 if(!isset($hashes[$name]))petish_club_verify_fail();
 $path=__DIR__.'/'.$name;
 if(!petish_club_verify_path($path)||filesize($path)<32||filesize($path)>262144
  ||!hash_equals($hashes[$name],hash_file('sha256',$path)))petish_club_verify_fail();
 return $path;
}
function petish_club_verify_video($root,&$state) {
 global $report;$report['category']='video';
 if(wp_get_environment_type()!=='staging')petish_club_verify_fail();
 petish_club_verify_fixture_owned($state,$state['run'],$state['kind']);
 $owner=$state['users']['owner']['id'];$member=$state['users']['member']['id'];$pet=$state['pet_id'];wp_set_current_user($owner);
 $valid=petish_club_verify_video_fixture('synthetic-valid.mp4');$long=petish_club_verify_video_fixture('synthetic-over-duration.mp4');
 $info=Petish_Passport_Video::inspect($valid);$duration_error=Petish_Passport_Video::inspect($long);
 $oversize=__DIR__.'/pet-club-owned-oversize.mp4';$handle=@fopen($oversize,'x+b');if(!$handle)petish_club_verify_fail();
 try{if(!ftruncate($handle,Petish_Passport_Video::MAX_BYTES+1))petish_club_verify_fail();fclose($handle);$handle=null;
  $size_error=Petish_Passport_Video::inspect($oversize);
 }finally{if(is_resource($handle))fclose($handle);if(is_link($oversize)||!unlink($oversize))petish_club_verify_fail();}
 $local_upload=Petish_Passport_Video::save(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$valid,'name'=>'synthetic.mp4'],$owner);
 petish_club_verify_mark('video_inspection_limits_verified',!is_wp_error($info)&&$info['duration']>0&&$info['duration']<=20
  &&$info['video_codec']==='avc1'&&$info['bytes']===filesize($valid)&&is_wp_error($duration_error)
  &&is_wp_error($size_error)&&$size_error->get_error_code()==='petish_video_size'&&is_wp_error($local_upload)
  &&Petish_Passport_Video::MAX_BYTES===20971520&&Petish_Passport_Video::MAX_SECONDS===20);
 $moment=Petish_Passport_Moments::create($pet,$owner,['kind'=>'journal','caption'=>'Synthetic video caption.']);
 if(is_wp_error($moment))petish_club_verify_fail();$id=$moment['id'];$state['moments']['video']=$id;petish_club_verify_fixture_save($state);
 $base=dirname($root).'/private/petish-passport/videos';
 foreach([$base,$base.'/'.$owner]as$dir){if(!file_exists($dir)&&!mkdir($dir,0700))petish_club_verify_fail();
  if(!petish_club_verify_path($dir,true)||(fileperms($dir)&07777)!==0700)petish_club_verify_fail();}
 $key=$owner.'/'.bin2hex(random_bytes(24)).'.mp4';$path=$base.'/'.$key;
 if(Petish_Passport_Video::queue_removal($id,$owner,$key)!==true||!copy($valid,$path)||!chmod($path,0600))petish_club_verify_fail();
 update_post_meta($id,'_petish_moment_kind','video');update_post_meta($id,'_petish_moment_video',$key);
 update_post_meta($id,'_petish_moment_video_info',array_intersect_key($info,array_flip(['duration','width','height','bytes'])));
 if(Petish_Passport_Video::flush_removals($id,$owner,$key)!==true)petish_club_verify_fail();
 $moment=Petish_Passport_Moments::get($id,$owner);
 petish_club_verify_mark('private_video_owner_scoped',!is_wp_error($moment)&&$moment['kind']==='video'
  &&$moment['duration']===(float)$info['duration']&&Petish_Passport_Video::file_for_owner($key,$owner)===$path
  &&Petish_Passport_Video::file_for_owner($key,$member)===false&&Petish_Passport_Video::file_for_owner($key,0)===false
  &&is_wp_error(Petish_Passport_Moments::get($id,$member))&&(fileperms($path)&07777)===0600&&!str_starts_with($path,$root.'/'));
 $size=filesize($path);
 petish_club_verify_mark('video_range_contract_verified',Petish_Passport_Video::parse_range('bytes=0-31',$size)===['start'=>0,'end'=>31,'partial'=>true]
  &&Petish_Passport_Video::parse_range('bytes=-8',$size)===['start'=>$size-8,'end'=>$size-1,'partial'=>true]
  &&is_wp_error(Petish_Passport_Video::parse_range('bytes=0-1,3-4',$size))
  &&is_wp_error(Petish_Passport_Video::parse_range('bytes='.$size.'-',$size)));
 $shared=Petish_Passport_Community::publish($pet,$owner,false,[$id]);if(is_wp_error($shared))petish_club_verify_fail();
 $token=$shared['token'];$public=Petish_Passport_Community::public_profile($token);
 $copy=get_post_meta($pet,Petish_Passport_Community::META,true);
 $resolved=Petish_Passport_Community::resolve_video($token,$public['moments'][0]['token']);
 $projection=$public['moments'][0];$fields=['token','kind','caption','photo_url','video_url','duration_seconds','created'];
 petish_club_verify_mark('public_video_snapshot_separate',count($public['moments'])===1&&$projection['kind']==='video'
  &&!array_diff(array_keys($projection),$fields)&&count($projection)===count($fields)&&$projection['photo_url']===''
  &&$projection['duration_seconds']===(float)$info['duration']&&!is_wp_error($resolved)&&$resolved['path']!==$path
  &&$copy['moments'][0]['video']!==$key&&is_file($resolved['path'])&&(fileperms($resolved['path'])&07777)===0600
  &&hash_equals(hash_file('sha256',$path),hash_file('sha256',$resolved['path']))
  &&!str_contains(json_encode($projection),$key)&&!str_contains(json_encode($projection),'/private/'));
 $edited=Petish_Passport_Moments::update($id,$owner,['kind'=>'video','caption'=>'New private video caption.']);
 $frozen=Petish_Passport_Community::public_profile($token);
 petish_club_verify_mark('video_snapshot_frozen',!is_wp_error($edited)&&!is_wp_error($frozen)
  &&$frozen['moments'][0]['caption']==='Synthetic video caption.'&&$edited['caption']==='New private video caption.');
 // A real media-kind replacement must revoke the copied clip and retire its private source.
 $replaced=Petish_Passport_Moments::update($id,$owner,['kind'=>'journal','caption'=>'Private replacement journal.']);
 petish_club_verify_mark('video_replacement_revokes_public',!is_wp_error($replaced)&&$replaced['video']===''
  &&is_wp_error(Petish_Passport_Community::public_profile($token))
  &&is_wp_error(Petish_Passport_Community::resolve_video($token,$projection['token']))
  &&!file_exists($resolved['path'])&&!file_exists($path));
}
