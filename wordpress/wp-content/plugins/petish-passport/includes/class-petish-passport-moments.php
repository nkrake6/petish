<?php
/** Owner-only photos, short videos and journal entries. Publication uses a separate snapshot. */
defined('ABSPATH') || exit;
final class Petish_Passport_Moments {
 const TYPE='petish_pet_moment';
 const MAX_MOMENTS=12;
 const MAX_CAPTION=240;

 public static function boot(){
  add_action('init',array(__CLASS__,'register'));
  add_action('admin_post_petish_pet_moment_photo',array(__CLASS__,'serve'));
  add_action('admin_post_nopriv_petish_pet_moment_photo',array(__CLASS__,'serve'));
  add_action('admin_post_petish_pet_moment_video',array(__CLASS__,'serve_video'));
  add_action('admin_post_nopriv_petish_pet_moment_video',array(__CLASS__,'serve_video'));
 }
 public static function register(){
  register_post_type(self::TYPE,array(
   'label'=>'Private Pet Moments','public'=>false,'publicly_queryable'=>false,
   'exclude_from_search'=>true,'show_ui'=>false,'show_in_menu'=>false,
   'show_in_nav_menus'=>false,'show_in_admin_bar'=>false,'show_in_rest'=>false,
   'query_var'=>false,'rewrite'=>false,'has_archive'=>false,'can_export'=>false,
   'delete_with_user'=>false,'supports'=>array(),'map_meta_cap'=>false,
   'capabilities'=>array_fill_keys(array('edit_post','read_post','delete_post','edit_posts',
    'edit_others_posts','publish_posts','read_private_posts','create_posts','delete_posts'),'do_not_allow'),
  ));
  register_post_meta(self::TYPE,'_petish_photo_retirements',array('type'=>'array','single'=>true,'show_in_rest'=>false,'auth_callback'=>'__return_false'));
  foreach(array('_petish_video_retirements','_petish_moment_video_info') as $key)register_post_meta(self::TYPE,$key,array('type'=>'array','single'=>true,'show_in_rest'=>false,'auth_callback'=>'__return_false'));
  foreach(array('kind','photo','video') as $field)register_post_meta(self::TYPE,'_petish_moment_'.$field,
   array('type'=>'string','single'=>true,'show_in_rest'=>false,'auth_callback'=>'__return_false'));
 }
 private static function missing(){
  return new WP_Error('petish_moment_not_found','This moment is not available in your account.',array('status'=>404));
 }
 private static function ids($pet_id,$uid){
  return get_posts(array('post_type'=>self::TYPE,'post_status'=>'any','post_parent'=>absint($pet_id),
   'author'=>absint($uid),'posts_per_page'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'DESC',
   'suppress_filters'=>true,'no_found_rows'=>true));
 }
 private static function record($post){
  $video=get_post_meta($post->ID,'_petish_moment_video',true);$info=get_post_meta($post->ID,'_petish_moment_video_info',true);
  $duration=is_array($info)&&is_numeric($info['duration']??null)?(float)$info['duration']:0.0;
  return array('id'=>(int)$post->ID,'pet_id'=>(int)$post->post_parent,
   'kind'=>(string)get_post_meta($post->ID,'_petish_moment_kind',true),
   'caption'=>$post->post_content,'photo'=>(string)get_post_meta($post->ID,'_petish_moment_photo',true),
   'video'=>is_string($video)?$video:'','duration'=>is_finite($duration)&&$duration>0&&$duration<=20?$duration:0.0,
   'created'=>$post->post_date_gmt,'updated'=>$post->post_modified_gmt);
 }
 public static function get($id,$uid){
  $id=absint($id);$uid=absint($uid);if(!$id||!$uid)return self::missing();
  $post=get_post($id);
  if(!$post||self::TYPE!==$post->post_type||'private'!==$post->post_status
   ||$uid!==(int)$post->post_author||is_wp_error(Petish_Passport_Store::get($post->post_parent,$uid)))return self::missing();
  return self::record($post);
 }
 public static function all_for_pet($pet_id,$uid){
  if(is_wp_error(Petish_Passport_Store::get($pet_id,$uid)))return array();
  $rows=array();foreach(self::ids($pet_id,$uid) as $id){
   $row=self::get($id,$uid);if(!is_wp_error($row))$rows[]=$row;
  }return $rows;
 }
 private static function validate($data){
  if(!is_array($data)||!is_string($data['kind']??null)||!is_string($data['caption']??''))
   return new WP_Error('petish_moment_invalid','Please check the moment details.');
  $kind=$data['kind'];
  if(!in_array($kind,array('photo','video','journal'),true))return new WP_Error('petish_moment_kind','Choose a photo, video or journal entry.');
  $caption=trim(sanitize_textarea_field($data['caption']??''));$length=preg_match_all('/./us',$caption);
  if(false===$length||$length>self::MAX_CAPTION||('journal'===$kind&&''===$caption))
   return new WP_Error('petish_moment_caption','Write up to 240 characters. Journal entries need some text.');
  return compact('kind','caption');
 }
 private static function has_file($file){
  return is_array($file)&&isset($file['error'])&&UPLOAD_ERR_NO_FILE!==$file['error'];
 }
 private static function unpublish($pet_id,$uid){
  if(!class_exists('Petish_Passport_Community'))return true;
  $result=Petish_Passport_Community::unpublish($pet_id,$uid);
  return is_wp_error($result)||true===$result?$result:new WP_Error('petish_unpublish','The public profile could not be removed. Please try again.');
 }
 private static function media_meta($kind,$photo,$video,$info){
  return array('_petish_moment_kind'=>$kind,'_petish_moment_photo'=>$photo,
   '_petish_moment_video'=>$video,'_petish_moment_video_info'=>$info);
 }
 private static function flush_media($id,$uid,$photo='',$video=''){
  $cleaned=Petish_Passport_Media::flush_removals($id,$uid,$photo);if(is_wp_error($cleaned))return $cleaned;
  return class_exists('Petish_Passport_Video')?Petish_Passport_Video::flush_removals($id,$uid,$video):true;
 }
 private static function save_upload($id,$uid,$kind,$file){
  $service='video'===$kind?'Petish_Passport_Video':'Petish_Passport_Media';
  if(!class_exists($service))return new WP_Error('petish_moment_upload','This upload is unavailable. Please try again later.');
  return $service::save($file,$uid,static function($key)use($service,$id,$uid){return $service::queue_removal($id,$uid,$key);});
 }
 public static function create($pet_id,$uid,$data,$file=null){
  $pet=Petish_Passport_Store::get($pet_id,$uid);if(is_wp_error($pet))return self::missing();
  return Petish_Passport_Store::with_pet_lock($pet['id'],static function()use($pet_id,$uid,$data,$file){
   if(is_wp_error(Petish_Passport_Store::get($pet_id,$uid))||!Petish_Passport_Store::may_write($uid))return self::missing();
   if(count(self::ids($pet_id,$uid))>=self::MAX_MOMENTS)return new WP_Error('petish_moment_limit','Each pet can keep up to 12 moments. Remove one before adding another.');
   $fields=self::validate($data);if(is_wp_error($fields))return $fields;
   if('journal'!==$fields['kind']&&!self::has_file($file))return new WP_Error('petish_moment_upload','Choose a photo or video for this moment.');
   if('journal'===$fields['kind']&&self::has_file($file))return new WP_Error('petish_moment_upload','Choose a photo or video moment to attach a file.');
   $id=wp_insert_post(wp_slash(array('post_type'=>self::TYPE,'post_status'=>'private',
    'post_author'=>absint($uid),'post_parent'=>absint($pet_id),'post_title'=>'',
    'post_content'=>$fields['caption'],'post_excerpt'=>'','comment_status'=>'closed','ping_status'=>'closed',
    'meta_input'=>self::media_meta($fields['kind'],'','',array()))),true);
   if(is_wp_error($id)||!$id)return new WP_Error('petish_moment_save','Your moment could not be saved. Please try again.');
   if('journal'!==$fields['kind']){
    $saved=self::save_upload($id,$uid,$fields['kind'],$file);
    if(is_wp_error($saved)){
     $cleaned=self::flush_media($id,$uid);if(!is_wp_error($cleaned))wp_delete_post($id,true);
     return is_wp_error($cleaned)?$cleaned:$saved;
    }
    $photo='photo'===$fields['kind']?$saved:'';$video='video'===$fields['kind']?$saved['key']:'';
    $info='video'===$fields['kind']?array_diff_key($saved,array('key'=>true)):array();
    foreach(self::media_meta($fields['kind'],$photo,$video,$info) as $key=>$value)update_post_meta($id,$key,$value);
    if(get_post_meta($id,'_petish_moment_photo',true)!==$photo||get_post_meta($id,'_petish_moment_video',true)!==$video
     ||get_post_meta($id,'_petish_moment_video_info',true)!==$info){
     $cleaned=self::flush_media($id,$uid);if(!is_wp_error($cleaned))wp_delete_post($id,true);
     return is_wp_error($cleaned)?$cleaned:new WP_Error('petish_moment_save','Your upload could not be saved. Please try again.');
    }
    $cleaned=self::flush_media($id,$uid,$photo,$video);if(is_wp_error($cleaned))return $cleaned;
   }
   return self::get($id,$uid);
  });
 }
 public static function update($id,$uid,$data,$file=null){
  $moment=self::get($id,$uid);if(is_wp_error($moment))return $moment;
  return Petish_Passport_Store::with_pet_lock($moment['pet_id'],static function()use($id,$uid,$data,$file){
   clean_post_cache(absint($id));wp_cache_delete(absint($id),'post_meta');
   $moment=self::get($id,$uid);if(is_wp_error($moment))return $moment;
   if(!Petish_Passport_Store::may_write($uid))return self::missing();
   $cleaned=self::flush_media($moment['id'],$uid,$moment['photo'],$moment['video']);if(is_wp_error($cleaned))return $cleaned;
   $fields=self::validate($data);if(is_wp_error($fields))return $fields;
   $photo='photo'===$fields['kind']?$moment['photo']:'';
   $video='video'===$fields['kind']?$moment['video']:'';
   $info=$video?get_post_meta($moment['id'],'_petish_moment_video_info',true):array();
   $replacement=false;
   if('journal'!==$fields['kind']){
    if(self::has_file($file)){
     $saved=self::save_upload($moment['id'],$uid,$fields['kind'],$file);if(is_wp_error($saved))return $saved;
     if('photo'===$fields['kind'])$photo=$saved;
     else{$video=$saved['key'];$info=array_diff_key($saved,array('key'=>true));}
     $replacement=true;
    }
    if(('photo'===$fields['kind']&&!$photo)||('video'===$fields['kind']&&(!$video||!is_array($info)||empty($info['duration']))))
     return new WP_Error('petish_moment_upload','Choose a photo or video for this moment.');
   }elseif(self::has_file($file))return new WP_Error('petish_moment_upload','Choose a photo or video moment to attach a file.');
   if($photo!==$moment['photo']||$video!==$moment['video']){
    if($moment['photo']){$queued=Petish_Passport_Media::queue_removal($moment['id'],$uid,$moment['photo']);if(is_wp_error($queued))return $queued;}
    if($moment['video']){$queued=Petish_Passport_Video::queue_removal($moment['id'],$uid,$moment['video']);if(is_wp_error($queued))return $queued;}
    $unpublished=self::unpublish($moment['pet_id'],$uid);if(is_wp_error($unpublished))return $unpublished;
   }
   $result=wp_update_post(wp_slash(array('ID'=>$moment['id'],'post_content'=>$fields['caption'],
    'post_modified'=>current_time('mysql'),'post_modified_gmt'=>current_time('mysql',true),
    'meta_input'=>self::media_meta($fields['kind'],$photo,$video,$info))),true);
   $stored=self::get($moment['id'],$uid);
   if(is_wp_error($result)||!$result||is_wp_error($stored)||$stored['photo']!==$photo||$stored['video']!==$video
    ||$stored['kind']!==$fields['kind']||get_post_meta($moment['id'],'_petish_moment_video_info',true)!==$info){
    if($replacement&&!is_wp_error($stored)){
     if($photo&&$stored['photo']!==$photo)Petish_Passport_Media::remove($photo);
     if($video&&$stored['video']!==$video)Petish_Passport_Video::remove($video);
    }
    return new WP_Error('petish_moment_save','Your changes could not be saved. Please try again.');
   }
   $cleaned=self::flush_media($moment['id'],$uid,$photo,$video);if(is_wp_error($cleaned))return $cleaned;
   return self::get($moment['id'],$uid);
  });
 }
 public static function delete($id,$uid){
  $moment=self::get($id,$uid);if(is_wp_error($moment))return $moment;
  return Petish_Passport_Store::with_pet_lock($moment['pet_id'],static function()use($id,$uid){
   clean_post_cache(absint($id));wp_cache_delete(absint($id),'post_meta');
   $moment=self::get($id,$uid);if(is_wp_error($moment))return $moment;
   $unpublished=self::unpublish($moment['pet_id'],$uid);if(is_wp_error($unpublished))return $unpublished;
   $cleaned=self::flush_media($moment['id'],$uid,$moment['photo'],$moment['video']);if(is_wp_error($cleaned))return $cleaned;
   if($moment['photo']&&!Petish_Passport_Media::remove($moment['photo']))
    return new WP_Error('petish_moment_photo_delete','This private photo could not be removed. Please try again or contact the site administrator.');
   if($moment['video']&&!Petish_Passport_Video::remove($moment['video']))
    return new WP_Error('petish_moment_video_delete','This private video could not be removed. Please try again or contact the site administrator.');
   if(!wp_delete_post($moment['id'],true))return new WP_Error('petish_moment_delete','This moment could not be deleted. Please try again.');
   return true;
  });
 }
 public static function delete_for_pet($pet_id,$uid){
  if(is_wp_error(Petish_Passport_Store::get($pet_id,$uid)))return self::missing();
  return Petish_Passport_Store::with_pet_lock($pet_id,static function()use($pet_id,$uid){
   foreach(self::ids($pet_id,$uid) as $id){$result=self::delete($id,$uid);if(is_wp_error($result))return $result;}
   return true;
  });
 }
 public static function url($id,$version=''){
  $query=array('action'=>'petish_pet_moment_photo','moment_id'=>absint($id));
  if(''!==$version)$query['v']=sanitize_text_field((string)$version);
  return add_query_arg($query,admin_url('admin-post.php'));
 }
 public static function video_url($id,$version=''){
  $query=array('action'=>'petish_pet_moment_video','moment_id'=>absint($id));
  if(''!==$version)$query['v']=sanitize_text_field((string)$version);
  return add_query_arg($query,admin_url('admin-post.php'));
 }
 public static function serve_video(){
  $uid=get_current_user_id();$raw=isset($_GET['moment_id'])&&is_string($_GET['moment_id'])?$_GET['moment_id']:'';
  $id=preg_match('/^[1-9][0-9]*$/D',$raw)?(int)$raw:0;$moment=$uid&&$id?self::get($id,$uid):false;
  $path=$moment&&!is_wp_error($moment)&&$moment['kind']==='video'&&$moment['video']?
   Petish_Passport_Video::file_for_owner($moment['video'],$uid):false;
  if(!$path){nocache_headers();header('Cache-Control: private, no-store, max-age=0, must-revalidate');status_header(404);header('Content-Type: text/plain; charset=utf-8');echo 'Not found.';exit;}
  Petish_Passport_Video::stream($path);exit;
 }
 public static function serve(){
  nocache_headers();header('Cache-Control: private, no-store, max-age=0, must-revalidate');
  header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');header('Cross-Origin-Resource-Policy: same-origin');
  $uid=get_current_user_id();$raw=isset($_GET['moment_id'])&&is_string($_GET['moment_id'])?$_GET['moment_id']:'';
  $id=preg_match('/^[1-9][0-9]*$/D',$raw)?(int)$raw:0;
  $moment=$uid&&$id?self::get($id,$uid):false;
  $path=$moment&&!is_wp_error($moment)&&$moment['photo']?Petish_Passport_Media::file_for_owner($moment['photo'],$uid):false;
  if(!$path){status_header(404);header('Content-Type: text/plain; charset=utf-8');echo 'Not found.';exit;}
  $types=array('jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp');
  header('Content-Type: '.$types[pathinfo($path,PATHINFO_EXTENSION)]);header('Content-Length: '.filesize($path));
  header('Content-Disposition: inline; filename="pet-moment.'.pathinfo($path,PATHINFO_EXTENSION).'"');
  if('HEAD'!==($_SERVER['REQUEST_METHOD']??'GET'))readfile($path);exit;
 }
 public static function export_for_pet($pet_id,$uid){
  $rows=array();foreach(self::all_for_pet($pet_id,$uid) as $moment){
   $data=array(array('name'=>'Pet Passport ID','value'=>(string)$moment['pet_id']),
    array('name'=>'Type','value'=>$moment['kind']),array('name'=>'Caption','value'=>$moment['caption']),
    array('name'=>'Created (UTC)','value'=>$moment['created']),array('name'=>'Updated (UTC)','value'=>$moment['updated']),
    array('name'=>'Visibility','value'=>'Private original'),
    array('name'=>'Video','value'=>$moment['video']?self::video_url($moment['id']).' (MP4; sign in as the owner to download)':'No video stored'),
    array('name'=>'Video duration (seconds)','value'=>$moment['video']?(string)$moment['duration']:''),
    array('name'=>'Photo','value'=>$moment['photo']?self::url($moment['id']).' (sign in as the owner to download)':'No photo stored'));
   $rows[]=array('group_id'=>'petish-passport-moments','group_label'=>'Petish Passport moments',
    'item_id'=>'petish-moment-'.$moment['id'],'data'=>$data);
  }return $rows;
 }
}
