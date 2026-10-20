<?php
/** Reencoded photos outside the web root, delivered only to their authenticated owner. */
defined('ABSPATH')||exit;
final class Petish_Passport_Media {
 const MAX_BYTES=5242880; const MAX_PIXELS=16000000;
 public static function boot(){add_action('admin_post_petish_pet_photo',array(__CLASS__,'serve'));add_action('admin_post_nopriv_petish_pet_photo',array(__CLASS__,'serve'));}
 private static function error($code='petish_photo',$message=''){return new WP_Error($code,$message?:'Your photo could not be stored. Please try another image.');}
 private static function directory($uid,$create=false){
 $uid=absint($uid);$public=realpath(ABSPATH);$app=realpath(dirname(untrailingslashit(ABSPATH)));if(!$uid||!$public||!$app)return self::error();$private=$app.'/private';$base=$private.'/petish-passport';$dir=$base.'/'.$uid;
 foreach(array($private,$base,$dir) as $path){if(is_link($path))return self::error();if(!is_dir($path)){if(!$create||!wp_mkdir_p($path))return self::error();}$resolved=realpath($path);if(!$resolved||$resolved===$public||str_starts_with($resolved.'/',$public.'/'))return self::error();if($create&&!chmod($path,0700))return self::error();}return $dir;}
 public static function save($file,$uid,$before_create=null){
 if(!is_array($file)||!isset($file['error'],$file['tmp_name'],$file['name'])||!is_int($file['error'])||!is_string($file['tmp_name'])||!is_string($file['name']))return self::error();if(UPLOAD_ERR_OK!==$file['error'])return self::error('petish_photo_upload',in_array($file['error'],array(UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE),true)?'Choose a photo smaller than 5 MB.':'The photo upload did not finish. Please try again.');
 $tmp=$file['tmp_name'];if(!is_uploaded_file($tmp))return self::error();$bytes=filesize($tmp);if(!$bytes||$bytes>self::MAX_BYTES)return self::error('petish_photo_size','Choose a photo smaller than 5 MB.');
 $allowed=array('jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp');$type=wp_check_filetype_and_ext($tmp,$file['name'],$allowed);$mime=wp_get_image_mime($tmp);$extension=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));$extension_mimes=array('jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp');if(!isset($extension_mimes[$extension])||$extension_mimes[$extension]!==$mime)return self::error('petish_photo_type','Choose a JPEG, PNG or WebP photo.');if(empty($type['ext'])||empty($type['type'])||!in_array($mime,$allowed,true)||$mime!==$type['type'])return self::error('petish_photo_type','Choose a JPEG, PNG or WebP photo.');
 $size=wp_getimagesize($tmp);if(!$size||$size[0]<1||$size[1]<1||$size[0]>8192||$size[1]>8192||$size[0]*$size[1]>self::MAX_PIXELS)return self::error('petish_photo_dimensions','Choose a photo up to 16 megapixels and 8192 pixels on either side.');
 $dir=self::directory($uid,true);if(is_wp_error($dir))return $dir;
 // Fresh GD raster output discards original EXIF/GPS, including when no resize is needed.
 $gd_only=static function(){return array('WP_Image_Editor_GD');};add_filter('wp_image_editors',$gd_only,PHP_INT_MAX);try{$editor=wp_get_image_editor($tmp);}finally{remove_filter('wp_image_editors',$gd_only,PHP_INT_MAX);}if(is_wp_error($editor))return self::error('petish_photo_editor','This photo could not be processed. Please try another JPEG, PNG or WebP image.');
 $rotated=$editor->maybe_exif_rotate();if(is_wp_error($rotated))return self::error();if($size[0]>1200||$size[1]>1200){$resized=$editor->resize(1200,1200,false);if(is_wp_error($resized))return self::error();}$editor->set_quality(85);$ext=array('image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp')[$mime];try{$key=absint($uid).'/'.bin2hex(random_bytes(24)).'.'.$ext;}catch(Exception $exception){return self::error();}$path=$dir.'/'.basename($key);if(null!==$before_create){if(!is_callable($before_create))return self::error();$prepared=call_user_func($before_create,$key);if(true!==$prepared)return is_wp_error($prepared)?$prepared:self::error();}$saved=$editor->save($path,$mime);if(is_wp_error($saved)||!is_file($path)){if(is_file($path))wp_delete_file($path);return self::error();}if(!chmod($path,0600)){wp_delete_file($path);return self::error();}return $key;
 }
 private static function path($key,$uid=0){if(!is_string($key)||!preg_match('#^([1-9][0-9]*)/([a-f0-9]{48}\.(?:jpg|png|webp))$#D',$key,$parts))return false;if($uid&&(int)$parts[1]!==(int)$uid)return false;$dir=self::directory((int)$parts[1]);if(is_wp_error($dir))return false;$path=$dir.'/'.$parts[2];if(is_link($path)||!is_file($path))return false;$real=realpath($path);return $real&&dirname($real)===realpath($dir)?$real:false;}
 public static function remove($key){if(!is_string($key)||!preg_match('#^([1-9][0-9]*)/([a-f0-9]{48}\.(?:jpg|png|webp))$#D',$key,$parts))return false;$dir=self::directory((int)$parts[1],true);if(is_wp_error($dir))return false;$candidate=$dir.'/'.$parts[2];if(!file_exists($candidate)&&!is_link($candidate))return true;$path=self::path($key);if(!$path)return false;wp_delete_file($path);return !file_exists($path)&&!is_link($path);}
 /** Trusted server-side access only; callers must separately authorize the profile. */
 public static function file_for_owner($key,$uid){$uid=absint($uid);return $uid?self::path($key,$uid):false;}
 /** Copy an already reencoded owner photo to a separate private publication file. */
 public static function duplicate($key,$uid,$before_create=null){
  $uid=absint($uid);$source=$uid?self::path($key,$uid):false;if(!$source)return self::error();
  $info=@lstat($source);$bytes=@filesize($source);
  if(!$info||1!==$info['nlink']||!$bytes||$bytes>self::MAX_BYTES)return self::error();
  $dir=self::directory($uid,true);if(is_wp_error($dir))return $dir;
  $ext=pathinfo($source,PATHINFO_EXTENSION);
  try{$copy_key=$uid.'/'.bin2hex(random_bytes(24)).'.'.$ext;}catch(Exception $exception){return self::error();}
  if(null!==$before_create){
   if(!is_callable($before_create))return self::error();
   $prepared=call_user_func($before_create,$copy_key);
   if(true!==$prepared)return is_wp_error($prepared)?$prepared:self::error();
  }
  $target=$dir.'/'.basename($copy_key);$input=@fopen($source,'rb');$output=@fopen($target,'x+b');
  if(!$input||!$output){if(is_resource($input))fclose($input);if(is_resource($output)){fclose($output);@unlink($target);}return self::error();}
  $valid=false;
  try{
   // Never let the publication copy inherit a permissive process umask.
   if(!chmod($target,0600))return self::error();
   $copied=stream_copy_to_stream($input,$output,self::MAX_BYTES+1);fflush($output);
   $after=fstat($input);$destination=fstat($output);
   $valid=$copied===$bytes&&$after&&$destination&&$after['size']===$bytes
    &&$after['ino']===$info['ino']&&$after['dev']===$info['dev']&&1===$destination['nlink'];
  }finally{fclose($input);fclose($output);if(!$valid)@unlink($target);}
  return $valid?$copy_key:self::error();
 }
 /** A private cleanup journal keeps old files reachable across database/filesystem failures. */
 private static function removal_journal($post_id,$uid){
  $post=get_post(absint($post_id));$uid=absint($uid);
  if(!$uid||!$post||$uid!==(int)$post->post_author||'private'!==$post->post_status
   ||!in_array($post->post_type,array('petish_pet','petish_pet_moment'),true))return self::error();
  $keys=get_post_meta($post->ID,'_petish_photo_retirements',true);
  if(''===$keys)$keys=array();
  if(!is_array($keys)||count($keys)>16)return self::error();
  foreach($keys as $key)if(!is_string($key)||!preg_match('#^'.preg_quote((string)$uid,'#').'/[a-f0-9]{48}[.](?:jpg|png|webp)$#D',$key))return self::error();
  return array_values(array_unique($keys));
 }
 public static function queue_removal($post_id,$uid,$key){
  $keys=self::removal_journal($post_id,$uid);if(is_wp_error($keys))return $keys;
  if(!is_string($key)||!preg_match('#^'.preg_quote((string)absint($uid),'#').'/[a-f0-9]{48}[.](?:jpg|png|webp)$#D',$key))return self::error();
  if(!in_array($key,$keys,true))$keys[]=$key;
  if(count($keys)>16)return self::error();
  update_post_meta($post_id,'_petish_photo_retirements',$keys);
  return get_post_meta($post_id,'_petish_photo_retirements',true)===$keys?true:self::error('petish_photo_cleanup','The old photo could not be queued for removal. Please try again.');
 }
 public static function flush_removals($post_id,$uid,$current_key=''){
  $keys=self::removal_journal($post_id,$uid);if(is_wp_error($keys))return $keys;
  if(!$keys)return true;
  $retained=array();
  foreach($keys as $key){
   // A failed database update can leave the old image as the current photo.
   if($key===$current_key)continue;
   if(!self::remove($key))$retained[]=$key;
  }
  update_post_meta($post_id,'_petish_photo_retirements',$retained);
  $saved=get_post_meta($post_id,'_petish_photo_retirements',true)===$retained;
  return !$retained&&$saved?true:self::error('petish_photo_cleanup','An old private photo is awaiting removal. Please retry or contact the site administrator.');
 }
 public static function cleanup_user($uid){
  $uid=absint($uid);if(!$uid)return false;
  $candidate=dirname(untrailingslashit(ABSPATH)).'/private/petish-passport/'.$uid;
  if(!file_exists($candidate)&&!is_link($candidate))return true;
  $dir=self::directory($uid);if(is_wp_error($dir))return false;
  foreach(glob($dir.'/*')?:array() as $path){
   if(!preg_match('/^[a-f0-9]{48}\.(?:jpg|png|webp)$/D',basename($path))||!self::remove($uid.'/'.basename($path)))return false;
  }
  if(array_diff(scandir($dir),array('.','..')))return false;
  return rmdir($dir);
 }
 public static function url($pet_id,$version=''){$query=array('action'=>'petish_pet_photo','pet_id'=>absint($pet_id));if(''!==$version)$query['v']=sanitize_text_field((string)$version);return add_query_arg($query,admin_url('admin-post.php'));}
 public static function serve(){
 nocache_headers();header('Cache-Control: private, no-store, max-age=0, must-revalidate');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');header('Cross-Origin-Resource-Policy: same-origin');$uid=get_current_user_id();$raw=isset($_GET['pet_id'])&&is_string($_GET['pet_id'])?$_GET['pet_id']:'';$id=preg_match('/^[1-9][0-9]*$/D',$raw)?(int)$raw:0;$pet=$uid&&$id?Petish_Passport_Store::get($id,$uid):false;$path=$pet&&!is_wp_error($pet)&&$pet['photo']?self::path($pet['photo'],$uid):false;
 if(!$path){status_header(404);header('Content-Type: text/plain; charset=utf-8');echo 'Not found.';exit;}$types=array('jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp');header('Content-Type: '.$types[pathinfo($path,PATHINFO_EXTENSION)]);header('Content-Length: '.filesize($path));header('Content-Disposition: inline; filename="pet-photo.'.pathinfo($path,PATHINFO_EXTENSION).'"');if('HEAD'!==($_SERVER['REQUEST_METHOD']??'GET'))readfile($path);exit;
 }
}
