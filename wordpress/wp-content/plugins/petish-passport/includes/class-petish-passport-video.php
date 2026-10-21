<?php
/** Original MP4 clips in private storage. No transcoding or metadata stripping. */
defined('ABSPATH') || exit;
final class Petish_Passport_Video {
 const MAX_BYTES=Petish_Passport_Video_Inspector::MAX_BYTES;
 const MAX_SECONDS=20;
 private static function error($code='petish_video',$message=''){
  return new WP_Error($code,$message?:'Choose a supported MP4 video with H.264 video and optional AAC audio, up to 20 seconds.');
 }
 private static function directory($uid,$create=false){
  $uid=absint($uid);$public=realpath(ABSPATH);$app=realpath(dirname(untrailingslashit(ABSPATH)));
  if(!$uid||!$public||!$app)return self::error();
  // Keep clips inside the existing full-backup root, separate from photo folders.
  $paths=array($app.'/private',$app.'/private/petish-passport',$app.'/private/petish-passport/videos',
   $app.'/private/petish-passport/videos/'.$uid);
  foreach($paths as $path){
   if(is_link($path))return self::error();
   if(!is_dir($path)&&(!$create||!wp_mkdir_p($path)))return self::error();
   $real=realpath($path);
   if(!$real||$real===$public||str_starts_with($real.'/',$public.'/'))return self::error();
   if($create&&!chmod($path,0700))return self::error();
  }
  return end($paths);
 }
 private static function key_parts($key){
  return is_string($key)&&preg_match('#^([1-9][0-9]*)/([a-f0-9]{48}[.]mp4)$#D',$key,$parts)?$parts:false;
 }
 public static function file_for_owner($key,$uid){
  $parts=self::key_parts($key);$uid=absint($uid);
  if(!$parts||!$uid||(int)$parts[1]!==$uid)return false;
  $dir=self::directory($uid);if(is_wp_error($dir))return false;
  $path=$dir.'/'.$parts[2];if(is_link($path)||!is_file($path))return false;
  $real=realpath($path);$stat=lstat($path);
  return $real&&dirname($real)===realpath($dir)&&$stat&&1===$stat['nlink']?$real:false;
 }
 /** Structural timing is checked before the mature WordPress analyzer reads metadata. */
 public static function inspect($path){
  if(!is_string($path)||!is_file($path)||is_link($path))return self::error();
  $bytes=filesize($path);
  if(!$bytes||$bytes>self::MAX_BYTES)return self::error('petish_video_size','Choose a video no larger than 50 MB.');
  $info=Petish_Passport_Video_Inspector::inspect($path);if(is_wp_error($info))return $info;
  if(!class_exists('getID3')){
   $library=ABSPATH.WPINC.'/ID3/getid3.php';
   if(!is_readable($library))return self::error('petish_video_reader','Video inspection is unavailable. Please try again later.');
   require_once $library;
  }
  try{
   $reader=new getID3();
   $reader->option_tag_id3v1=false;$reader->option_tag_id3v2=false;
   $reader->option_tag_lyrics3=false;$reader->option_tag_apetag=false;
   $reader->option_tags_process=false;$reader->option_tags_html=false;
   $reader->option_extra_info=false;$reader->option_save_attachments=getID3::ATTACHMENTS_NONE;
   $reader->option_md5_data=false;$reader->option_md5_data_source=false;$reader->option_sha1_data=false;
   $analyzed=$reader->analyze($path);
  }catch(Throwable $error){return self::error();}
  if(!is_array($analyzed)||!empty($analyzed['error']))return self::error();
  foreach((array)($analyzed['warning']??array()) as $warning){
   // WordPress getID3 does not recognize the standard bitrate box inside AAC
   // entries. Its exact structure is already checked by our bounded inspector.
   if(!is_string($warning)||(!preg_match('/^skipping check for (?:id3v1|id3v2|lyrics3|apetag) tags since option_tag_[a-z0-9_]+=FALSE$/D',$warning)
    &&!preg_match('/^Unknown QuickTime atom type: "btrt" [(]62 74 72 74[)], 20 bytes at offset [0-9]+$/D',$warning)))return self::error();
  }
  $duration=$analyzed['playtime_seconds']??null;
  if(!is_numeric($duration)||!is_finite((float)$duration)||(float)$duration<=0||(float)$duration>self::MAX_SECONDS)
   return self::error('petish_video_duration','Choose a video no longer than 20 seconds.');
  if(($analyzed['fileformat']??'')!=='mp4'||($analyzed['video']['fourcc']??'')!=='avc1'
   ||(int)($analyzed['video']['resolution_x']??0)!==($info['coded_width']??$info['width'])
   ||(int)($analyzed['video']['resolution_y']??0)!==($info['coded_height']??$info['height']))return self::error();
  return $info;
 }
 public static function save($file,$uid,$before_create=null){
  if(!is_array($file)||!isset($file['error'],$file['tmp_name'],$file['name'])||!is_int($file['error'])
   ||!is_string($file['tmp_name'])||!is_string($file['name']))return self::error();
  if(UPLOAD_ERR_OK!==$file['error'])return self::error('petish_video_upload',
   in_array($file['error'],array(UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE),true)?'Choose a video no larger than 50 MB.':'The video upload did not finish. Please try again.');
  if(!is_uploaded_file($file['tmp_name']))return self::error();
  if(strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))!=='mp4')return self::error('petish_video_type','Choose an MP4 video.');
  $info=self::inspect($file['tmp_name']);if(is_wp_error($info))return $info;
  $dir=self::directory($uid,true);if(is_wp_error($dir))return $dir;
  try{$key=absint($uid).'/'.bin2hex(random_bytes(24)).'.mp4';}catch(Throwable $error){return self::error();}
  if(null!==$before_create){
   if(!is_callable($before_create))return self::error();$prepared=call_user_func($before_create,$key);
   if(true!==$prepared)return is_wp_error($prepared)?$prepared:self::error();
  }
  $path=$dir.'/'.basename($key);
  if(file_exists($path)||!move_uploaded_file($file['tmp_name'],$path))return self::error();
  if(!chmod($path,0600)||filesize($path)!==$info['bytes']){self::remove($key);return self::error();}
  return array('key'=>$key,'duration'=>$info['duration'],'width'=>$info['width'],'height'=>$info['height'],'bytes'=>$info['bytes']);
 }
 public static function duplicate($key,$uid,$before_create=null){
  $source=self::file_for_owner($key,$uid);if(!$source)return self::error();
  $stat=lstat($source);$bytes=filesize($source);
  if(!$stat||!$bytes||$bytes>self::MAX_BYTES)return self::error();
  $dir=self::directory($uid,true);if(is_wp_error($dir))return $dir;
  try{$copy_key=absint($uid).'/'.bin2hex(random_bytes(24)).'.mp4';}catch(Throwable $error){return self::error();}
  if(null!==$before_create){
   if(!is_callable($before_create))return self::error();$prepared=call_user_func($before_create,$copy_key);
   if(true!==$prepared)return is_wp_error($prepared)?$prepared:self::error();
  }
  $target=$dir.'/'.basename($copy_key);$input=@fopen($source,'rb');$output=@fopen($target,'x+b');
  if(!$input||!$output){if(is_resource($input))fclose($input);if(is_resource($output)){fclose($output);self::remove($copy_key);}return self::error();}
  $valid=false;
  try{
   if(!chmod($target,0600))return self::error();
   $copied=stream_copy_to_stream($input,$output,self::MAX_BYTES+1);fflush($output);$after=fstat($input);$destination=fstat($output);
   $valid=$copied===$bytes&&$after&&$destination&&$after['size']===$bytes&&$after['ino']===$stat['ino']
    &&$after['dev']===$stat['dev']&&1===$destination['nlink'];
  }finally{fclose($input);fclose($output);if(!$valid)self::remove($copy_key);}
  return $valid?$copy_key:self::error();
 }
 public static function remove($key){
  $parts=self::key_parts($key);if(!$parts)return false;
  $dir=self::directory((int)$parts[1],true);if(is_wp_error($dir))return false;
  $candidate=$dir.'/'.$parts[2];if(!file_exists($candidate)&&!is_link($candidate))return true;
  $path=self::file_for_owner($key,(int)$parts[1]);if(!$path)return false;
  wp_delete_file($path);return !file_exists($path)&&!is_link($path);
 }
 private static function journal($id,$uid){
  $post=get_post(absint($id));$uid=absint($uid);
  if(!$post||!$uid||$uid!==(int)$post->post_author||$post->post_status!=='private'||$post->post_type!=='petish_pet_moment')return self::error();
  $keys=get_post_meta($id,'_petish_video_retirements',true);if(''===$keys)$keys=array();
  if(!is_array($keys)||count($keys)>16)return self::error();
  foreach($keys as $key){$parts=self::key_parts($key);if(!$parts||(int)$parts[1]!==$uid)return self::error();}
  return array_values(array_unique($keys));
 }
 public static function queue_removal($id,$uid,$key){
  $keys=self::journal($id,$uid);if(is_wp_error($keys))return $keys;
  $parts=self::key_parts($key);if(!$parts||(int)$parts[1]!==absint($uid))return self::error();
  if(!in_array($key,$keys,true))$keys[]=$key;if(count($keys)>16)return self::error();
  update_post_meta($id,'_petish_video_retirements',$keys);
  return get_post_meta($id,'_petish_video_retirements',true)===$keys?true:self::error('petish_video_cleanup','The video could not be queued for removal. Please try again.');
 }
 public static function flush_removals($id,$uid,$current=''){
  $keys=self::journal($id,$uid);if(is_wp_error($keys))return $keys;if(!$keys)return true;
  $retained=array();foreach($keys as $key)if($key!==$current&&!self::remove($key))$retained[]=$key;
  update_post_meta($id,'_petish_video_retirements',$retained);
  return !$retained&&get_post_meta($id,'_petish_video_retirements',true)===$retained?true:
   self::error('petish_video_cleanup','An old private video is awaiting removal. Please retry or contact the site administrator.');
 }
 public static function cleanup_user($uid){
  $uid=absint($uid);if(!$uid)return false;
  $candidate=dirname(untrailingslashit(ABSPATH)).'/private/petish-passport/videos/'.$uid;
  if(!file_exists($candidate)&&!is_link($candidate))return true;
  $dir=self::directory($uid);if(is_wp_error($dir))return false;
  foreach(glob($dir.'/*')?:array() as $path)if(!preg_match('/^[a-f0-9]{48}[.]mp4$/D',basename($path))||!self::remove($uid.'/'.basename($path)))return false;
  return !array_diff(scandir($dir),array('.','..'))&&rmdir($dir);
 }
 /** Single byte ranges only. Authorization must happen before even a 416 response. */
 public static function parse_range($header,$size){
  if(!is_int($size)||$size<1||$size>self::MAX_BYTES)return self::error('petish_video_range');
  if(null===$header||''===$header)return array('start'=>0,'end'=>$size-1,'partial'=>false);
  if(!is_string($header)||strlen($header)>80||!preg_match('/^bytes=([0-9]{0,10})-([0-9]{0,10})$/D',$header,$parts)
   ||(''===$parts[1]&&''===$parts[2]))return self::error('petish_video_range');
  if(''===$parts[1]){$suffix=(int)$parts[2];if(!$suffix)return self::error('petish_video_range');$start=max(0,$size-$suffix);$end=$size-1;}
  else{$start=(int)$parts[1];$end=''===$parts[2]?$size-1:min((int)$parts[2],$size-1);if($start>=$size||$end<$start)return self::error('petish_video_range');}
  return array('start'=>$start,'end'=>$end,'partial'=>true);
 }
 /** Trusted routes call this after owner/snapshot authorization; return to release their locks. */
 public static function stream($path){
  nocache_headers();header('Cache-Control: private, no-store, max-age=0, must-revalidate');
  header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');header('Cross-Origin-Resource-Policy: same-origin');
  $method=$_SERVER['REQUEST_METHOD']??'GET';
  if(!in_array($method,array('GET','HEAD'),true)){status_header(405);header('Allow: GET, HEAD');return false;}
  if(!is_string($path)||is_link($path)||!is_file($path)){status_header(404);return false;}
  $input=@fopen($path,'rb');if(!$input){status_header(404);return false;}
  try{
   $stat=fstat($input);$size=$stat['size']??0;
   if(!$size||$size>self::MAX_BYTES){status_header(404);return false;}
   // No persistent validators are issued: an If-Range request gets the complete representation.
   $range=self::parse_range(isset($_SERVER['HTTP_IF_RANGE'])?'':($_SERVER['HTTP_RANGE']??''),$size);
   header('Accept-Ranges: bytes');
   if(is_wp_error($range)){status_header(416);header('Content-Range: bytes */'.$size);header('Content-Length: 0');return false;}
   $length=$range['end']-$range['start']+1;status_header($range['partial']?206:200);
   header('Content-Type: video/mp4');header('Content-Disposition: inline; filename="pet-moment.mp4"');header('Content-Length: '.$length);
   if($range['partial'])header('Content-Range: bytes '.$range['start'].'-'.$range['end'].'/'.$size);
   if('HEAD'===$method)return true;if(0!==fseek($input,$range['start']))return false;
   while($length>0&&!feof($input)){$chunk=fread($input,min(65536,$length));if(false===$chunk||''===$chunk)return false;echo $chunk;$length-=strlen($chunk);}
   return 0===$length;
  }finally{fclose($input);}
 }
}
