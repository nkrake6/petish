<?php
/** Behavioral fixture for video ownership, streaming and durable cleanup. */
error_reporting(E_ALL);
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$fixture=sys_get_temp_dir().'/petish-passport-video-'.bin2hex(random_bytes(8));
mkdir($fixture,0700);mkdir($fixture.'/public_html',0700);
define('ABSPATH',$fixture.'/public_html/');
class WP_Error {public $code;public $message;public $data;function __construct($code,$message='',$data=null){$this->code=$code;$this->message=$message;$this->data=$data;}function get_error_message(){return $this->message;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function absint($v){return abs((int)$v);}
function sanitize_text_field($v){return trim(preg_replace('/[\r\n\t]+/',' ',strip_tags($v)));}
function sanitize_textarea_field($v){return trim(strip_tags($v));}
function add_action(...$args){}
function add_filter(...$args){}
function remove_filter(...$args){}
function register_post_type($type,$args){$GLOBALS['types'][$type]=$args;}
function register_post_meta($type,$key,$args){$GLOBALS['meta_types'][$type][$key]=$args;}
function untrailingslashit($v){return rtrim($v,'/');}
function wp_mkdir_p($p){return mkdir($p,0700,true);}
function wp_delete_file($p){if(!in_array($p,$GLOBALS['deny_delete'],true)&&file_exists($p))unlink($p);}
function wp_slash($v){return $v;}
function current_time($type,$gmt=false){return '2026-10-21 01:00:00';}
function admin_url($path){return 'https://petish.invalid/wp-admin/'.$path;}
function add_query_arg($q,$url){return $url.'?'.http_build_query($q);}
function get_user_by($field,$value){foreach([11,22,33]as$id)if(($field==='id'&&(int)$value===$id)||($field==='email'&&$value==='person'.$id.'@example.invalid'))return(object)['ID'=>$id];return false;}
function get_user_meta($uid,$key,$single=true){return $GLOBALS['user_meta'][$uid][$key]??'';}
function update_user_meta($uid,$key,$v){$GLOBALS['user_meta'][$uid][$key]=$v;return true;}
function delete_user_meta($uid,$key){unset($GLOBALS['user_meta'][$uid][$key]);return true;}
function clean_user_cache($id){}
function clean_post_cache($id){unset($GLOBALS['post_cache'][$id],$GLOBALS['meta_cache'][$id]);$GLOBALS['cleaned'][]=(int)$id;}
function wp_cache_delete($id,$group){if($group==='post_meta')unset($GLOBALS['meta_cache'][$id]);return true;}
function get_post($id){if(array_key_exists($id,$GLOBALS['post_cache']))return $GLOBALS['post_cache'][$id];return $GLOBALS['post_cache'][$id]=isset($GLOBALS['posts'][$id])?clone$GLOBALS['posts'][$id]:null;}
function get_post_meta($id,$key,$single=true){if(!array_key_exists($id,$GLOBALS['meta_cache']))$GLOBALS['meta_cache'][$id]=$GLOBALS['meta'][$id]??[];return $GLOBALS['meta_cache'][$id][$key]??'';}
function update_post_meta($id,$key,$v){if($GLOBALS['fail_meta']===$key)return false;$GLOBALS['meta'][$id][$key]=$v;unset($GLOBALS['meta_cache'][$id]);return true;}
function get_posts($q){$rows=[];foreach($GLOBALS['posts']as$id=>$p){if($p->post_type!==$q['post_type']||(int)$p->post_author!==(int)$q['author'])continue;if(isset($q['post_parent'])&&(int)$p->post_parent!==(int)$q['post_parent'])continue;if($q['post_status']!=='any'&&$q['post_status']!==$p->post_status)continue;$rows[]=$id;}sort($rows);if(($q['order']??'ASC')==='DESC')$rows=array_reverse($rows);return $q['posts_per_page']<0?$rows:array_slice($rows,0,$q['posts_per_page']);}
function wp_insert_post($args,$error=false){if($GLOBALS['fail_insert'])return new WP_Error('fixture_insert');$id=++$GLOBALS['last_id'];$meta=$args['meta_input']??[];unset($args['meta_input']);$GLOBALS['posts'][$id]=(object)array_merge(['ID'=>$id,'post_parent'=>0,'post_date_gmt'=>'2026-10-20 00:00:00','post_modified_gmt'=>'2026-10-20 00:00:00'],$args);foreach($meta as$k=>$v)update_post_meta($id,$k,$v);clean_post_cache($id);return$id;}
function wp_update_post($args,$error=false){if($GLOBALS['fail_update'])return new WP_Error('fixture_update');$id=$args['ID'];if(!isset($GLOBALS['posts'][$id]))return new WP_Error('fixture_missing');$meta=$args['meta_input']??[];unset($args['meta_input']);foreach($args as$k=>$v)$GLOBALS['posts'][$id]->$k=$v;foreach($meta as$k=>$v)update_post_meta($id,$k,$v);clean_post_cache($id);return$id;}
function wp_delete_post($id,$force=false){if($GLOBALS['fail_delete'])return false;unset($GLOBALS['posts'][$id],$GLOBALS['meta'][$id]);clean_post_cache($id);return true;}
class FixtureDB {public $prefix='wp_fixture_';public $available=true;public $acquired=0;public $released=0;public $on_acquire=null;function prepare($q,...$a){return[$q,$a];}function get_var($v){if(str_contains($v[0],'GET_LOCK')){++$this->acquired;if(!$this->available)return '0';if($this->on_acquire){$cb=$this->on_acquire;$this->on_acquire=null;$cb();}return '1';}if(str_contains($v[0],'RELEASE_LOCK')){++$this->released;return '1';}throw new RuntimeException('Unexpected SQL');}}
class Petish_Passport_Community {public static $fail=false;public static $events=[];public static function unpublish($id,$uid){self::$events[]=['id'=>$id,'uid'=>$uid,'photo'=>get_post_meta($id,'_petish_photo',true)];return self::$fail?new WP_Error('fixture_unpublish'):true;}}
$GLOBALS['posts']=$GLOBALS['meta']=$GLOBALS['post_cache']=$GLOBALS['meta_cache']=$GLOBALS['user_meta']=$GLOBALS['types']=$GLOBALS['meta_types']=$GLOBALS['cleaned']=$GLOBALS['deny_delete']=[];
$GLOBALS['fail_meta']='';$GLOBALS['fail_insert']=$GLOBALS['fail_update']=$GLOBALS['fail_delete']=false;$GLOBALS['last_id']=100;$wpdb=new FixtureDB();
require dirname(__DIR__).'/wordpress/wp-content/plugins/petish-passport/includes/class-petish-passport-media.php';
require dirname(__DIR__).'/wordpress/wp-content/plugins/petish-passport/includes/class-petish-passport-store.php';
require dirname(__DIR__).'/wordpress/wp-content/plugins/petish-passport/includes/class-petish-passport-video-inspector.php';
require dirname(__DIR__).'/wordpress/wp-content/plugins/petish-passport/includes/class-petish-passport-video.php';
require dirname(__DIR__).'/wordpress/wp-content/plugins/petish-passport/includes/class-petish-passport-moments.php';
$checks=[];
function check($name,$value){global$checks;if(!$value)throw new RuntimeException('Failed: '.$name);$checks[$name]=true;}
function fields($extra=[]){return array_merge(['name'=>'Clover','species'=>'rabbit','age'=>'1-3','interest'=>'browsing'],$extra);}
function photo($uid,$letter='a'){$root=dirname(rtrim(ABSPATH,'/')).'/private/petish-passport';foreach([dirname($root),$root,$root.'/'.$uid]as$d)if(!is_dir($d))mkdir($d,0700);$key=$uid.'/'.str_repeat($letter,48).'.png';file_put_contents($root.'/'.$key,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN0sAAAAASUVORK5CYII='));chmod($root.'/'.$key,0600);return$key;}
function photo_path($key){return dirname(rtrim(ABSPATH,'/')).'/private/petish-passport/'.$key;}
function cleanup_tree($dir){foreach(scandir($dir)as$n){if($n==='.'||$n==='..')continue;$p=$dir.'/'.$n;if(is_dir($p)&&!is_link($p))cleanup_tree($p);else unlink($p);}rmdir($dir);}
function nocache_headers(){}
function status_header($status){$GLOBALS['response_status']=$status;}
function esc_html__($s,$domain=''){return $s;}
function wp_die($message,...$args){throw new RuntimeException($message);}
function video_path($key){return dirname(rtrim(ABSPATH,'/')).'/private/petish-passport/videos/'.$key;}
function clip($uid,$letter='a'){
 $key=$uid.'/'.str_repeat($letter,48).'.mp4';$path=video_path($key);
 if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);
 copy($GLOBALS['source_clip'],$path);chmod($path,0600);return $key;
}
function video_moment($pet_id,$uid,$letter='a'){
 $moment=Petish_Passport_Moments::create($pet_id,$uid,['kind'=>'journal','caption'=>'Fixture']);
 if(is_wp_error($moment))throw new RuntimeException($moment->message);
 $key=clip($uid,$letter);$id=$moment['id'];
 update_post_meta($id,'_petish_moment_kind','video');update_post_meta($id,'_petish_moment_video',$key);
 update_post_meta($id,'_petish_moment_video_info',['duration'=>1.0,'width'=>160,'height'=>120,'bytes'=>filesize(video_path($key))]);
 return Petish_Passport_Moments::get($id,$uid);
}
function streamed($path,$method='GET',$range=null,$if_range=null){
 $_SERVER['REQUEST_METHOD']=$method;unset($_SERVER['HTTP_RANGE'],$_SERVER['HTTP_IF_RANGE']);
 if(null!==$range)$_SERVER['HTTP_RANGE']=$range;if(null!==$if_range)$_SERVER['HTTP_IF_RANGE']=$if_range;
 ob_start();$result=Petish_Passport_Video::stream($path);$body=ob_get_clean();
 return [$result,$GLOBALS['response_status'],$body];
}
$GLOBALS['source_clip']=$argv[1]??'';
try{
 check('source_fixture_exists',is_file($GLOBALS['source_clip']));
 $valid=Petish_Passport_Video_Inspector::inspect($GLOBALS['source_clip']);
 check('source_fixture_server_inspected',!is_wp_error($valid)&&$valid['duration']<=20&&$valid['video_codec']==='avc1');
 Petish_Passport_Store::register();Petish_Passport_Moments::register();
 $pet=Petish_Passport_Store::create(11,fields());$id=$pet['id'];
 $other=Petish_Passport_Store::create(22,fields(['name'=>'Other']));
 $journal=Petish_Passport_Moments::create($id,11,['kind'=>'journal','caption'=>'Legacy']);
 check('legacy_video_fields_default',$journal['video']===''&&$journal['duration']===0.0);
 check('video_meta_private',!$GLOBALS['meta_types']['petish_pet_moment']['_petish_moment_video']['show_in_rest']&&!$GLOBALS['meta_types']['petish_pet_moment']['_petish_moment_video_info']['show_in_rest']);
 $before=count($GLOBALS['posts']);
 check('video_requires_upload',is_wp_error(Petish_Passport_Moments::create($id,11,['kind'=>'video','caption'=>''])));
 check('missing_upload_no_record',count($GLOBALS['posts'])===$before);
 check('anonymous_create_denied',is_wp_error(Petish_Passport_Moments::create($id,0,['kind'=>'video'])));
 check('foreign_create_denied',is_wp_error(Petish_Passport_Moments::create($id,22,['kind'=>'video'])));
 $fake=['name'=>'fixture.mp4','tmp_name'=>$GLOBALS['source_clip'],'error'=>UPLOAD_ERR_OK,'type'=>'video/mp4'];
 check('local_path_not_accepted_as_upload',is_wp_error(Petish_Passport_Video::save($fake,11)));
 check('upload_failure_reservation_removed',is_wp_error(Petish_Passport_Moments::create($id,11,['kind'=>'video','caption'=>''], $fake))&&count($GLOBALS['posts'])===$before);
 $too_large=Petish_Passport_Video::save(['name'=>'large.mp4','tmp_name'=>'','error'=>UPLOAD_ERR_INI_SIZE],11);
 check('php_upload_limit_explained',is_wp_error($too_large)&&str_contains($too_large->message,'20 MB'));
 check('malformed_upload_denied',is_wp_error(Petish_Passport_Video::save(['name'=>[],'tmp_name'=>[],'error'=>'0'],11)));
 $moment=video_moment($id,11);$mid=$moment['id'];$key=$moment['video'];$path=video_path($key);$bytes=file_get_contents($path);
 check('owner_file_resolved',Petish_Passport_Video::file_for_owner($key,11)===realpath($path));
 check('foreign_file_denied',Petish_Passport_Video::file_for_owner($key,22)===false);
 check('anonymous_file_denied',Petish_Passport_Video::file_for_owner($key,0)===false);
 check('traversal_file_denied',Petish_Passport_Video::file_for_owner('11/../a.mp4',11)===false);
 check('private_original_mode',(fileperms($path)&0777)===0600);
 check('video_inside_full_backup_root',str_contains($path,'/private/petish-passport/videos/11/'));
 check('foreign_moment_denied',is_wp_error(Petish_Passport_Moments::get($mid,22)));
 check('foreign_update_denied',is_wp_error(Petish_Passport_Moments::update($mid,22,['kind'=>'journal','caption'=>'No'])));
 check('foreign_delete_denied',is_wp_error(Petish_Passport_Moments::delete($mid,22)));
 $events=count(Petish_Passport_Community::$events);
 $edited=Petish_Passport_Moments::update($mid,11,['kind'=>'video','caption'=>'Updated caption']);
 check('caption_edit_keeps_video',!is_wp_error($edited)&&$edited['video']===$key&&$edited['duration']===1.0);
 check('caption_edit_does_not_publish',count(Petish_Passport_Community::$events)===$events);
 $export=Petish_Passport_Moments::export_for_pet($id,11);$row=array_values(array_filter($export,fn($r)=>$r['item_id']==='petish-moment-'.$mid))[0];$values=array_column($row['data'],'value','name');
 check('private_export_authenticated_video',str_contains($values['Video'],'action=petish_pet_moment_video')&&str_contains($values['Video'],'sign in as the owner')&&str_contains($values['Video'],'MP4'));
 check('private_export_duration',$values['Video duration (seconds)']==='1');
 $journaled='';$copy=Petish_Passport_Video::duplicate($key,11,function($candidate)use(&$journaled,$mid){$journaled=$candidate;check('duplicate_callback_before_write',!file_exists(video_path($candidate)));return Petish_Passport_Video::queue_removal($mid,11,$candidate);});
 check('copy_random_independent',is_string($copy)&&$copy!==$key&&$copy===$journaled&&file_get_contents(video_path($copy))===$bytes);
 check('copy_private_mode',(fileperms(video_path($copy))&0777)===0600);
 $count=count(glob(dirname($path).'/*.mp4'));
 check('copy_callback_failure_no_file',is_wp_error(Petish_Passport_Video::duplicate($key,11,fn()=>false))&&count(glob(dirname($path).'/*.mp4'))===$count);
 check('copy_foreign_owner_denied',is_wp_error(Petish_Passport_Video::duplicate($key,22)));
 $GLOBALS['deny_delete']=[video_path($copy)];
 check('failed_copy_cleanup_retained',is_wp_error(Petish_Passport_Video::flush_removals($mid,11,$key))&&get_post_meta($mid,'_petish_video_retirements',true)===[$copy]&&is_file(video_path($copy)));
 $GLOBALS['deny_delete']=[];
 check('copy_cleanup_retry',Petish_Passport_Video::flush_removals($mid,11,$key)===true&&!is_file(video_path($copy))&&is_file($path));
 $GLOBALS['fail_meta']='_petish_video_retirements';$count=count(glob(dirname($path).'/*.mp4'));
 check('journal_failure_blocks_file_creation',is_wp_error(Petish_Passport_Video::duplicate($key,11,fn($candidate)=>Petish_Passport_Video::queue_removal($mid,11,$candidate)))&&count(glob(dirname($path).'/*.mp4'))===$count);
 $GLOBALS['fail_meta']='';
 $linkkey='11/'.str_repeat('b',48).'.mp4';symlink($path,video_path($linkkey));
 check('symlink_read_denied',Petish_Passport_Video::file_for_owner($linkkey,11)===false);
 check('symlink_remove_denied',Petish_Passport_Video::remove($linkkey)===false&&is_file($path));unlink(video_path($linkkey));
 link($path,video_path($linkkey));
 check('hardlink_read_denied',Petish_Passport_Video::file_for_owner($key,11)===false);
 check('hardlink_remove_denied',Petish_Passport_Video::remove($linkkey)===false);unlink(video_path($linkkey));clearstatcache();
 $full=streamed($path);check('stream_full_bytes_returns',$full===[true,200,$bytes]);
 $part=streamed($path,'GET','bytes=8-19');check('stream_range_exact_bytes',$part===[true,206,substr($bytes,8,12)]);
 $suffix=streamed($path,'GET','bytes=-13');check('stream_suffix_exact_bytes',$suffix===[true,206,substr($bytes,-13)]);
 $open=streamed($path,'GET','bytes=20-');check('stream_open_range_exact_bytes',$open===[true,206,substr($bytes,20)]);
 check('stream_head_no_body',streamed($path,'HEAD','bytes=1-7')===[true,206,'']);
 check('stream_invalid_range_no_body',streamed($path,'GET','bytes=99999999-')===[false,416,'']);
 check('stream_multi_range_rejected',streamed($path,'GET','bytes=0-1,4-5')===[false,416,'']);
 check('stream_if_range_full_response',streamed($path,'GET','bytes=0-3','unknown')===[true,200,$bytes]);
 check('stream_nonread_method_denied',streamed($path,'POST')===[false,405,'']);
 foreach(['bytes=-0','bytes=-','bytes=8-2','bytes=99999999999-','items=0-2',[]]as$i=>$range)check('bad_range_'.$i,is_wp_error(Petish_Passport_Video::parse_range($range,100)));
 check('range_end_clamped',Petish_Passport_Video::parse_range('bytes=90-999',100)===['start'=>90,'end'=>99,'partial'=>true]);
 Petish_Passport_Community::$fail=true;
 check('unpublish_failure_preserves_video',is_wp_error(Petish_Passport_Moments::update($mid,11,['kind'=>'journal','caption'=>'Text']))&&Petish_Passport_Moments::get($mid,11)['video']===$key&&is_file($path));
 Petish_Passport_Community::$fail=false;$GLOBALS['fail_update']=true;
 check('database_failure_preserves_video',is_wp_error(Petish_Passport_Moments::update($mid,11,['kind'=>'journal','caption'=>'Text']))&&Petish_Passport_Moments::get($mid,11)['video']===$key&&is_file($path));
 $GLOBALS['fail_update']=false;$GLOBALS['deny_delete']=[$path];
 check('old_video_delete_failure_reported',is_wp_error(Petish_Passport_Moments::update($mid,11,['kind'=>'journal','caption'=>'Text'])));
 check('old_video_key_retained_after_pointer_change',get_post_meta($mid,'_petish_video_retirements',true)===[$key]&&Petish_Passport_Moments::get($mid,11)['video']===''&&is_file($path));
 $GLOBALS['deny_delete']=[];$updated=Petish_Passport_Moments::update($mid,11,['kind'=>'journal','caption'=>'Retried']);
 check('old_video_delete_retry_complete',!is_wp_error($updated)&&!is_file($path)&&get_post_meta($mid,'_petish_video_retirements',true)===[]);
 $second=video_moment($id,11,'c');$secondpath=video_path($second['video']);$GLOBALS['deny_delete']=[$secondpath];
 check('delete_failure_retains_record',is_wp_error(Petish_Passport_Moments::delete($second['id'],11))&&!is_wp_error(Petish_Passport_Moments::get($second['id'],11)));
 $GLOBALS['deny_delete']=[];check('delete_retry_removes_record_and_file',Petish_Passport_Moments::delete($second['id'],11)===true&&!is_file($secondpath));
 $third=video_moment($id,11,'d');$thirdpath=video_path($third['video']);
 check('pet_delete_cascades_videos',Petish_Passport_Store::delete($id,11)===true&&!is_file($thirdpath)&&is_wp_error(Petish_Passport_Moments::get($third['id'],11)));
 $orphan=clip(11,'e');$foreign=clip(22,'f');
 $GLOBALS['deny_delete']=[video_path($orphan)];$erased=Petish_Passport_Store::erase('person11@example.invalid');
 check('erasure_reports_orphan_failure',$erased['items_retained']&&is_file(video_path($orphan)));
 $GLOBALS['deny_delete']=[];$erased=Petish_Passport_Store::erase('person11@example.invalid');
 check('erasure_retry_cleans_orphan_video',!$erased['items_retained']&&$erased['done']&&!is_file(video_path($orphan))&&is_file(video_path($foreign)));
 $orphan=clip(11,'e');$GLOBALS['deny_delete']=[video_path($orphan)];$paused=false;
 try{Petish_Passport_Store::delete_user(11);}catch(RuntimeException $e){$paused=str_contains($e->getMessage(),'private video cleanup');}
 check('user_deletion_pauses_for_video_failure',$paused&&is_file(video_path($orphan)));
 $GLOBALS['deny_delete']=[];Petish_Passport_Store::delete_user(11);
 check('user_deletion_sweeps_own_video_only',!is_file(video_path($orphan))&&is_file(video_path($foreign)));
 $concurrent_key=clip(11,'e');$concurrent_id=null;
 $wpdb->on_acquire=static function()use(&$concurrent_id){
  // Simulate a completed create just before erasure obtains the user lock.
  $concurrent_id=wp_insert_post(['post_type'=>'petish_pet','post_status'=>'private','post_author'=>11,
   'post_title'=>'Concurrent pet','post_content'=>'','meta_input'=>['_petish_species'=>'rabbit']]);
 };
 $erased=Petish_Passport_Store::erase('person11@example.invalid');
 check('erasure_rechecks_pets_after_user_lock',!$erased['done']&&is_file(video_path($concurrent_key))&&!is_wp_error(Petish_Passport_Store::get($concurrent_id,11)));
 Petish_Passport_Store::erase('person11@example.invalid');
 $quota_pet=Petish_Passport_Store::create(33,fields());$quota_video=video_moment($quota_pet['id'],33,'a');
 for($i=1;$i<12;$i++)Petish_Passport_Moments::create($quota_pet['id'],33,['kind'=>'journal','caption'=>'Entry']);
 $limit=Petish_Passport_Moments::create($quota_pet['id'],33,['kind'=>'video'], $fake);
 check('mixed_moments_share_twelve_limit',is_wp_error($limit)&&$limit->code==='petish_moment_limit'&&count(Petish_Passport_Moments::all_for_pet($quota_pet['id'],33))===12);
 check('all_locks_released',$wpdb->acquired===$wpdb->released);
 echo json_encode(['status'=>'success','checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
}finally{cleanup_tree($fixture);}
