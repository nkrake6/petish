<?php
/** Real WordPress integration and generated MP4 fixtures; never run against a hosted site. */
if(!in_array(PHP_SAPI,array('cli','cli-server'),true))exit(2);
$native=getenv('PETISH_CHAT_NATIVE_ROOT')?:'/srv/petish-native';
if(!is_file($native.'/wp-load.php')||(!is_file($native.'/.petish-native-owned')&&!is_file(dirname($native).'/pet-chat-proof-owner'))){fwrite(STDERR,"A marked disposable WordPress sandbox is required.\n");exit(2);}
require $native.'/wp-load.php';
if(!str_ends_with((string)parse_url(home_url(),PHP_URL_HOST),'.invalid')){fwrite(STDERR,"An invalid sandbox hostname is required.\n");exit(2);}
require_once ABSPATH.'wp-admin/includes/user.php';
$include=dirname(__DIR__).'/wordpress/wp-content/plugins/petish-passport/includes/';
if(!class_exists('Petish_Chat_Stream'))require_once $include.'class-petish-chat-stream.php';
if(!class_exists('Petish_Chat_Stream_Media'))require_once $include.'class-petish-chat-stream-media.php';
if(!class_exists('Petish_Passport_Video_Inspector'))require_once $include.'class-petish-passport-video-inspector.php';
$fixtures=$argv[1]??'';$checks=array();$users=array();$temp=array();$messages=array();$options=array();$failure=null;
$tag=bin2hex(random_bytes(10));$old=get_current_user_id();
function pcm_check($name,$value){global $checks;$checks[$name]=$value===true;if(!$checks[$name])throw new RuntimeException($name);}
function pcm_ok($value,$name){pcm_check($name,!is_wp_error($value));return $value;}
function pcm_error($value,$name){pcm_check($name,is_wp_error($value));}
function pcm_option($name,$value){global $options;if(!array_key_exists($name,$options))$options[$name]=get_option($name,null);update_option($name,$value,false);}
function pcm_user($role){global $tag,$users;$id=pcm_ok(wp_insert_user(array('user_login'=>'pcm-'.$tag.'-'.$role.'-'.count($users),'user_pass'=>bin2hex(random_bytes(24)),'user_email'=>'pcm-'.count($users).'-'.$tag.'@example.invalid','role'=>$role)),'native_user_created_'.count($users));$users[]=(int)$id;return(int)$id;}
function pcm_reflect($method,...$args){$r=new ReflectionMethod(Petish_Chat_Stream_Media::class,$method);return $r->invoke(null,...$args);}
function pcm_temp($bytes){global$temp;$f=tempnam(sys_get_temp_dir(),'petish-chat-media-test-');$temp[]=$f;file_put_contents($f,$bytes);return$f;}
function pcm_num($s,$at){return unpack('N',substr($s,$at,4))[1];}
function pcm_boxes($s,$start=0,$end=null){$end=$end??strlen($s);$out=array();while($start<$end){$n=pcm_num($s,$start);if($n<8||$n>$end-$start)throw new RuntimeException('fixture_boxes');$type=substr($s,$start+4,4);$body=$start+8;$out[]=array('type'=>$type,'start'=>$start,'body'=>$body,'size'=>$n);$skip=null;if(in_array($type,array('moov','trak','mdia','minf','stbl','dinf','edts'),true))$skip=0;elseif(in_array($type,array('stsd','dref'),true))$skip=8;elseif($type==='avc1')$skip=78;elseif($type==='mp4a')$skip=28;if(null!==$skip)$out=array_merge($out,pcm_boxes($s,$body+$skip,$start+$n));$start+=$n;}return$out;}
function pcm_named($s,$type){foreach(pcm_boxes($s)as$b)if($b['type']===$type)return$b;throw new RuntimeException('missing_fixture_box_'.$type);}
function pcm_field($s,$type,$at,$value){$b=pcm_named($s,$type);return substr_replace($s,pack('N',$value),$b['body']+$at,4);}
function pcm_reject($name,$bytes){pcm_error(Petish_Chat_Stream_MP4_Inspector::inspect(pcm_temp($bytes)),$name);}
function pcm_record($uid,$mid,$source){$token=bin2hex(random_bytes(24));$dir=pcm_ok(pcm_reflect('directory',$uid,$mid,true),'private_directory_created');$path=$dir.'/'.$token.'.mp4';copy($source,$path);chmod($path,0600);$info=pcm_ok(Petish_Chat_Stream_MP4_Inspector::inspect($path),'private_fixture_inspected');return array('owner_id'=>$uid,'message_id'=>$mid,'token'=>$token,'key'=>$uid.'/'.$mid.'/'.$token.'.mp4','kind'=>'video','mime'=>'video/mp4','bytes'=>filesize($path),'width'=>$info['width'],'height'=>$info['height'],'duration'=>$info['duration'],'sha256'=>hash_file('sha256',$path));}
/** Optional loopback-only multipart fixture. Secret and identities stay outside the webroot. */
$http_state=dirname(untrailingslashit(ABSPATH)).'/media-http-fixture.json';
if(PHP_SAPI==='cli-server') {
 $ip=$_SERVER['REMOTE_ADDR']??'';
 if(!in_array($ip,array('127.0.0.1','::1'),true)||is_link($http_state)||!is_file($http_state)){http_response_code(404);exit;}
 $state=json_decode(file_get_contents($http_state),true);
 if(!is_array($state)||!is_string($_SERVER['HTTP_X_PETISH_MEDIA_TEST']??null)||!hash_equals($state['secret']??'',$_SERVER['HTTP_X_PETISH_MEDIA_TEST'])){http_response_code(404);exit;}
 $case=$_GET['case']??'';$fixture=is_string($case)?($state['cases'][$case]??null):null;if(!$fixture){http_response_code(404);exit;}
 $uid=(int)$fixture['uid'];$mid=(int)$fixture['mid'];$op=$_GET['op']??'';wp_set_current_user($uid);
 if('serve'===$op) {
  $viewer=$_GET['viewer']??'owner';wp_set_current_user(array('owner'=>$uid,'other'=>$state['other'],'anonymous'=>0,'admin'=>$state['admin'])[$viewer]??0);
  $_GET['message_id']=(string)$mid;Petish_Chat_Stream_Media::serve();exit;
 }
 nocache_headers();header('Content-Type: application/json');$result=null;
 if('upload'===$op&&($_SERVER['REQUEST_METHOD']??'')==='POST') {
  $records=Petish_Chat_Stream_Media::save_uploads($_FILES,$uid,$mid);
  if(is_wp_error($records))$result=$records;
  else {
   $attached=Petish_Chat_Stream::attach_media($uid,$mid,$records);
   if(is_wp_error($attached)){Petish_Chat_Stream_Media::delete_manifest($records,$uid,$mid);$result=$attached;}
   else {$commit=Petish_Chat_Stream_Media::commit_manifest($records,$uid,$mid);$result=array('status'=>'stored','media'=>array_map(static fn($r)=>Petish_Chat_Stream_Media::public_record($r,$mid),$records),'journal_committed'=>true===$commit);}
  }
 } elseif('approve'===$op&&($_SERVER['REQUEST_METHOD']??'')==='POST') {
  wp_set_current_user((int)$state['admin']);$result=Petish_Chat_Stream::moderate((int)$state['admin'],array('op'=>'approve','id'=>$mid));
 } elseif('delete'===$op&&($_SERVER['REQUEST_METHOD']??'')==='POST') {
  $result=Petish_Chat_Stream::remove($uid,$mid);
 } elseif('inspect'===$op) {
  $token=$_GET['token']??'';$record=Petish_Chat_Stream::media_for_viewer($mid,$token,$uid);
  if(is_wp_error($record))$result=$record;
  else {
   $path=Petish_Chat_Stream_Media::file_for_record($record,$uid,$mid);$photo='photo'===$record['kind'];$bytes=$path&&$photo&&$record['bytes']<1048576?file_get_contents($path):'';
   $result=array('status'=>'inspected','private_mode'=>$path?(fileperms($path)&0777):0,'outside_public'=>$path&&!str_starts_with($path,realpath(ABSPATH).'/'),'photo_metadata_present'=>strpos($bytes,'GPS_TEST_METADATA')!==false,'bytes'=>$record['bytes'],'width'=>$record['width'],'height'=>$record['height']);
  }
 }else{http_response_code(404);exit;}
 if(is_wp_error($result)){http_response_code(400);echo wp_json_encode(array('status'=>'error','code'=>$result->get_error_code()));}
 else echo wp_json_encode($result);exit;
}
if(($argv[1]??'')==='--http-prepare') {
 if(file_exists($http_state)||is_link($http_state)){fwrite(STDERR,"The isolated HTTP fixture already exists.\n");exit(2);}
 pcm_option('petish_chat_enabled_v1','yes');pcm_option(Petish_Chat_Stream::OPTION_ENABLED,'yes');
 $admin=pcm_user('administrator');$other=pcm_user('customer');wp_set_current_user($admin);pcm_ok(Petish_Chat_Stream::install_schema(),'http_schema');$cases=array();
 foreach(array('video','boundary','overlimit','photo','mismatch','mime','rate')as$name){
  $uid=pcm_user('customer');wp_set_current_user($uid);$r=pcm_ok(Petish_Chat_Stream::send($uid,'general',array('body'=>'','alias'=>'Clover keeper','consent'=>'1','attachment_pending'=>true,'request_id'=>bin2hex(random_bytes(16)))),'http_draft_'.$name);$cases[$name]=array('uid'=>$uid,'mid'=>$r['message']['id']);
 }
 foreach($users as$uid)update_user_meta($uid,'_pcm_media_http_owner',$tag);
 $state=array('secret'=>bin2hex(random_bytes(32)),'owner'=>$tag,'users'=>$users,'admin'=>$admin,'other'=>$other,'cases'=>$cases,'options'=>$options,'created'=>time());
 if(false===file_put_contents($http_state,wp_json_encode($state),LOCK_EX)||!chmod($http_state,0600)){fwrite(STDERR,"HTTP fixture state could not be stored.\n");exit(1);}
 echo wp_json_encode(array('status'=>'prepared','cases'=>array_keys($cases))),"\n";exit;
}
if(($argv[1]??'')==='--http-cleanup') {
 if(is_link($http_state)||!is_file($http_state)){fwrite(STDERR,"No owned HTTP fixture exists.\n");exit(2);}$state=json_decode(file_get_contents($http_state),true);
 if(!is_array($state)||!preg_match('/^[a-f0-9]{20}$/D',$state['owner']??'')){fwrite(STDERR,"Invalid HTTP fixture ownership.\n");exit(2);}
 global$wpdb;
 foreach($state['users']as$uid){if(get_user_meta($uid,'_pcm_media_http_owner',true)!==$state['owner']){fwrite(STDERR,"HTTP fixture ownership changed.\n");exit(1);}$wpdb->delete($wpdb->prefix.'petish_chat_stream_reactions_v1',array('actor_id'=>$uid));$wpdb->delete($wpdb->prefix.'petish_chat_stream_messages_v1',array('author_id'=>$uid));Petish_Chat_Stream_Media::cleanup_user($uid);wp_delete_user($uid);}
 foreach($state['options']as$key=>$value){if(null===$value)delete_option($key);else update_option($key,$value,false);}unlink($http_state);echo "{\"status\":\"cleaned\"}\n";exit;
}

try{
 $valid=file_get_contents($fixtures.'/valid.mp4');$long=file_get_contents($fixtures.'/long-stereo.mp4');$silent=file_get_contents($fixtures.'/long-silent.mp4');
 pcm_check('real_generated_fixtures_exist',is_string($valid)&&is_string($long)&&is_string($silent));
 $info=pcm_ok(Petish_Chat_Stream_MP4_Inspector::inspect(pcm_temp($valid)),'real_silent_mp4_accepted');
 pcm_check('technical_metadata_only',array_keys($info)===array('duration','width','height','coded_width','coded_height','video_codec','audio_codec','bytes'));
 $long_path=pcm_temp($long);$info=pcm_ok(Petish_Chat_Stream_MP4_Inspector::inspect($long_path),'sixty_five_second_stereo_mp4_accepted');
 pcm_check('no_twenty_second_chat_policy',$info['duration']>=65&&$info['audio_codec']==='mp4a');
 pcm_ok(Petish_Chat_Stream_MP4_Inspector::inspect(pcm_temp($silent)),'sixty_five_second_silent_mp4_accepted');
 pcm_error(Petish_Passport_Video_Inspector::inspect($long_path),'passport_duration_policy_unchanged');
 pcm_check('passport_byte_limit_unchanged',Petish_Passport_Video_Inspector::MAX_BYTES===52428800&&Petish_Passport_Video_Inspector::MAX_SECONDS===20);
 pcm_check('chat_byte_limit_250_mib',Petish_Chat_Stream_Media::MAX_BYTES===262144000);
 pcm_reject('truncated_mp4_rejected',substr($valid,0,-1));
 pcm_reject('external_media_reference_rejected',pcm_field($valid,'url ',0,0));
 pcm_reject('wrong_movie_duration_rejected',pcm_field($valid,'mvhd',16,500));
 pcm_reject('wrong_media_duration_rejected',pcm_field($valid,'mdhd',16,1));
 pcm_reject('zero_timescale_rejected',pcm_field($valid,'mvhd',12,0));
 pcm_reject('invalid_chunk_offset_rejected',pcm_field($valid,'stco',8,strlen($valid)+1));
 pcm_reject('metadata_referenced_as_media_rejected',pcm_field($valid,'stco',8,40));
 pcm_reject('sample_table_budget_rejected',pcm_field($valid,'stsz',8,250001));
 pcm_reject('zero_sample_timing_rejected',pcm_field($valid,'stts',12,0));
 $avc=pcm_named($valid,'avc1');pcm_reject('hevc_codec_not_mislabeled_h264',substr_replace($valid,'hvc1',$avc['start']+4,4));
 pcm_reject('encrypted_media_rejected',substr_replace($valid,'encv',$avc['start']+4,4));
 pcm_reject('mov_container_not_supported',substr_replace($valid,'qt  ',8,4));
 pcm_reject('fragmented_mp4_not_supported',$valid.pack('N',8).'moof');
 pcm_reject('arbitrary_trailing_content_rejected',$valid.'trailer');
 pcm_reject('unbounded_free_box_count_rejected',$valid.str_repeat(pack('N',8).'free',2049));
 $large=pcm_temp($valid);$fp=fopen($large,'r+b');fseek($fp,strlen($valid));fwrite($fp,pack('N',262144000-strlen($valid)).'free');ftruncate($fp,262144000);fclose($fp);
 pcm_ok(Petish_Chat_Stream_MP4_Inspector::inspect($large),'exact_250_mib_sparse_structural_boundary_accepted');
 $fp=fopen($large,'a');fwrite($fp,'x');fclose($fp);clearstatcache(true,$large);pcm_error(Petish_Chat_Stream_MP4_Inspector::inspect($large),'over_250_mib_rejected_before_parsing');
 $source=pcm_temp($valid);$link=$source.'-link';$temp[]=$link;symlink($source,$link);pcm_error(Petish_Chat_Stream_MP4_Inspector::inspect($link),'input_symlink_rejected');unlink($link);
 link($source,$link);pcm_error(Petish_Chat_Stream_MP4_Inspector::inspect($source),'input_hardlink_rejected');unlink($link);
 foreach(array('',null)as$i=>$range){$r=Petish_Chat_Stream_Media::parse_range($range,262144000);pcm_check('full_range_'.$i,$r===array('start'=>0,'end'=>262143999,'partial'=>false));}
 $r=Petish_Chat_Stream_Media::parse_range('bytes=262143999-',262144000);pcm_check('last_byte_range',$r['start']===262143999&&$r['end']===262143999);
 $r=Petish_Chat_Stream_Media::parse_range('bytes=-128',262144000);pcm_check('suffix_range',$r['start']===262143872&&$r['end']===262143999);
 foreach(array('bytes=0-1,3-5','bytes=262144000-','bytes=2-1','bytes=-0','bytes=-','units=0-1',array(),str_repeat('x',81))as$i=>$range)pcm_error(Petish_Chat_Stream_Media::parse_range($range,262144000),'malformed_range_rejected_'.$i);
 $admin=pcm_user('administrator');$member=pcm_user('customer');$viewer=pcm_user('customer');wp_set_current_user($admin);
 pcm_option('petish_chat_enabled_v1','yes');pcm_option(Petish_Chat_Stream::OPTION_ENABLED,'yes');pcm_ok(Petish_Chat_Stream::install_schema(),'native_chat_schema_provisioned');
 wp_set_current_user($member);$draft=pcm_ok(Petish_Chat_Stream::send($member,'general',array('body'=>'','alias'=>'Clover keeper','consent'=>'1','attachment_pending'=>true,'request_id'=>bin2hex(random_bytes(16)))),'native_media_draft_reserved');$mid=$draft['message']['id'];$messages[]=$mid;
 $record=pcm_record($member,$mid,pcm_temp($valid));$path=Petish_Chat_Stream_Media::file_for_record($record,$member,$mid);
 pcm_check('file_private_outside_public_root',is_string($path)&&!str_starts_with($path,realpath(ABSPATH).'/')&&(fileperms($path)&0777)===0600);
 pcm_check('exact_owner_message_binding',Petish_Chat_Stream_Media::valid_manifest(array($record),$member,$mid));
 pcm_check('foreign_owner_denied',false===Petish_Chat_Stream_Media::file_for_record($record,$viewer,$mid));
 pcm_check('foreign_message_denied',false===Petish_Chat_Stream_Media::file_for_record($record,$member,$mid+1));
 $bad=$record;$bad['key']='../'.$record['key'];pcm_check('path_traversal_rejected',false===Petish_Chat_Stream_Media::valid_record($bad,$member,$mid));
 $bad=$record;$bad['mime']='text/html';pcm_check('mime_spoof_rejected',false===Petish_Chat_Stream_Media::valid_record($bad,$member,$mid));
 pcm_check('attachment_token_only_url',str_contains(Petish_Chat_Stream_Media::media_url($mid,$record['token']),'token='.$record['token'])&&!str_contains(Petish_Chat_Stream_Media::media_url($mid,$record['token']),$record['key']));
 $public=Petish_Chat_Stream_Media::public_record($record,$mid);pcm_check('safe_public_record_omits_private_identity',!isset($public['key'],$public['owner_id'],$public['message_id'],$public['sha256'])&&$public['duration']>0);
 update_user_meta($member,Petish_Chat_Stream_Media::JOURNAL,array(array('key'=>$record['key'],'created'=>time()-7200)));
 pcm_ok(Petish_Chat_Stream::attach_media($member,$mid,array($record)),'native_media_manifest_bound');
 pcm_ok(Petish_Chat_Stream_Media::commit_manifest(array($record),$member,$mid),'durable_binding_retires_journal');
 pcm_error(Petish_Chat_Stream::media_for_viewer($mid,$record['token'],0),'unapproved_media_hidden_from_public');
 pcm_ok(Petish_Chat_Stream::media_for_viewer($mid,$record['token'],$member),'pending_owner_preview_authorized');
 wp_set_current_user($viewer);pcm_error(Petish_Chat_Stream::media_for_viewer($mid,$record['token'],$viewer),'pending_other_member_denied');
 wp_set_current_user($admin);pcm_ok(Petish_Chat_Stream::moderate($admin,array('op'=>'approve','id'=>$mid)),'native_media_approved');
 wp_set_current_user(0);pcm_ok(Petish_Chat_Stream::media_for_viewer($mid,$record['token'],0),'approved_media_public');
 wp_set_current_user($viewer);$state=pcm_ok(Petish_Chat_Stream::state('general',$viewer),'native_channel_state');$item=array_values(array_filter($state['messages'],static fn($m)=>$m['id']===$mid))[0];
 update_user_meta($viewer,Petish_Chat_Stream::PREFIX.'mutes',array($item['member_id']=>true));pcm_error(Petish_Chat_Stream::media_for_viewer($mid,$record['token'],$viewer),'personal_mute_blocks_attachment');delete_user_meta($viewer,Petish_Chat_Stream::PREFIX.'mutes');
 wp_set_current_user($admin);pcm_ok(Petish_Chat_Stream::moderate($admin,array('op'=>'hide','id'=>$mid)),'media_hidden_by_staff');
 wp_set_current_user(0);pcm_error(Petish_Chat_Stream::media_for_viewer($mid,$record['token'],0),'hidden_media_immediately_unresolvable');
 update_user_meta($member,Petish_Chat_Stream_Media::JOURNAL,array(array('key'=>$record['key'],'created'=>time()-7200)));pcm_check('hidden_binding_survives_orphan_cleanup',Petish_Chat_Stream_Media::cleanup_unattached($member)&&is_file($path));
 pcm_option(Petish_Chat_Stream::OPTION_ENABLED,'no');pcm_error(Petish_Chat_Stream::media_for_viewer($mid,$record['token'],0),'disabled_stream_revokes_media');
 pcm_check('no_live_streaming_capability',!isset(Petish_Chat_Stream_Media::capabilities()['broadcast'])&&Petish_Chat_Stream_Media::capabilities()['duration_limit']===null);
 pcm_option(Petish_Chat_Stream::OPTION_ENABLED,'yes');wp_set_current_user($member);pcm_ok(Petish_Chat_Stream::remove($member,$mid),'native_message_deletion_revokes_then_erases_media');
 pcm_check('deleted_file_removed',!is_file($path));pcm_error(Petish_Chat_Stream::media_for_viewer($mid,$record['token'],$member),'deleted_attachment_unresolvable');
 pcm_check('cleanup_user_private_orphans',Petish_Chat_Stream_Media::cleanup_user($member));
}catch(Throwable$e){$failure=$e->getMessage();}
finally{
 global $wpdb;
 foreach($users as$uid){$wpdb->delete($wpdb->prefix.'petish_chat_stream_reactions_v1',array('actor_id'=>$uid));$wpdb->delete($wpdb->prefix.'petish_chat_stream_messages_v1',array('author_id'=>$uid));Petish_Chat_Stream_Media::cleanup_user($uid);wp_delete_user($uid);}
 foreach($options as$key=>$value){if(null===$value)delete_option($key);else update_option($key,$value,false);}wp_set_current_user($old);
 foreach($temp as$file)if(is_file($file)||is_link($file))@unlink($file);
}
echo wp_json_encode(array('status'=>$failure?'failure':'success','checks'=>$checks,'failure'=>$failure)),"\n";
exit($failure?1:0);
