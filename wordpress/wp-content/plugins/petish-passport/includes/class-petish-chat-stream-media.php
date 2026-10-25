<?php
/** Pet Chat attachments: normalized photos and structurally validated original MP4s.
 * Separate from Passport/review limits. No broadcast, transcoding or external fetch.
 */
defined('ABSPATH') || exit;
final class Petish_Chat_Stream_Media {
 const MAX_BYTES=262144000; // 250 MiB, per attachment and request.
 const MAX_FILES=1;
 const MAX_PIXELS=16000000;
 const MAX_OWNER_BYTES=5368709120; // 5 GiB; private per-member capacity guard.
 const JOURNAL='_petish_chat_stream_media_journal';
 const ATTEMPTS='_petish_chat_stream_media_attempts';
 const ACTION='petish_chat_stream_media';
 private static function error($code='petish_chat_media',$message='') {
  return new WP_Error($code,$message?:'The attachment could not be stored. Please try another supported file.');
 }
 public static function enabled() {
  return class_exists('Petish_Chat_Stream') && Petish_Chat_Stream::enabled();
 }
 public static function boot() {
  add_action('admin_post_'.self::ACTION,array(__CLASS__,'serve'));
  add_action('admin_post_nopriv_'.self::ACTION,array(__CLASS__,'serve'));
 }
 private static function ini_bytes($value) {
  if(!is_string($value)||!preg_match('/^\s*([0-9]+)([KMG]?)\s*$/iD',$value,$m))return 0;
  $number=(int)$m[1];$factor=array(''=>1,'K'=>1024,'M'=>1048576,'G'=>1073741824)[strtoupper($m[2])];
  return $number>intdiv(PHP_INT_MAX,$factor)?PHP_INT_MAX:$number*$factor;
 }
 public static function capabilities() {
  $upload=self::ini_bytes((string)ini_get('upload_max_filesize'));$post=self::ini_bytes((string)ini_get('post_max_size'));
  $gd=extension_loaded('gd') && function_exists('imagecreatetruecolor');
  return array('enabled'=>self::enabled(),'max_bytes'=>self::MAX_BYTES,'max_files'=>self::MAX_FILES,
   'photo_mimes'=>$gd?array('image/jpeg','image/png','image/webp'):array(),'video_mimes'=>array('video/mp4'),
   'accept'=>$gd?'.jpg,.jpeg,.png,.webp,.mp4':'.mp4','video_requires'=>'H.264 video; optional AAC audio',
   'duration_limit'=>null,'metadata'=>'Photos are normalized and location tags removed. Original videos can retain device/location metadata.',
   'server_upload_max_bytes'=>$upload,'server_post_max_bytes'=>$post,
   'upload_ready'=>self::enabled()&&$upload>=self::MAX_BYTES&&$post>=self::MAX_BYTES+1048576,
   'effective_max_bytes'=>min(self::MAX_BYTES,$upload,max(0,$post-1048576)));
 }
 /** Every component is a fixed child of the resolved application, outside public_html. */
 private static function directory($uid,$message=0,$create=false) {
  $uid=absint($uid);$message=absint($message);$public=realpath(ABSPATH);$app=realpath(dirname(untrailingslashit(ABSPATH)));
  if(!$uid||!$public||!$app||dirname($public)!==$app)return self::error();
  $paths=array($app.'/private',$app.'/private/petish-chat-stream',$app.'/private/petish-chat-stream/'.$uid);
  if($message)$paths[]=$app.'/private/petish-chat-stream/'.$uid.'/'.$message;
  foreach($paths as $path) {
   if(is_link($path)||(!is_dir($path)&&(!$create||!wp_mkdir_p($path))))return self::error();
   $real=realpath($path);if($real!==$path||$real===$public||str_starts_with($real.'/',$public.'/'))return self::error();
   if($create&&!@chmod($path,0700))return self::error();
  }
  return end($paths);
 }
 private static function parts($key) {
  return is_string($key)&&preg_match('#^([1-9][0-9]*)/([1-9][0-9]*)/([a-f0-9]{48})[.](jpg|png|webp|mp4)$#D',$key,$m)?$m:false;
 }
 /** Validate owner/message identity before resolving any server-side path. */
 public static function valid_record($record,$uid,$message) {
  if(!is_array($record)||!is_int($record['owner_id']??null)||!is_int($record['message_id']??null)
   ||$record['owner_id']!==absint($uid)||$record['message_id']!==absint($message))return false;
  $p=self::parts($record['key']??null);
  if(!$p||(int)$p[1]!==$record['owner_id']||(int)$p[2]!==$record['message_id']||($record['token']??'')!==$p[3])return false;
  $types=array('jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','mp4'=>'video/mp4');
  if(($record['mime']??'')!==$types[$p[4]]||($record['kind']??'')!==('mp4'===$p[4]?'video':'photo')
   ||!is_int($record['bytes']??null)||$record['bytes']<1||$record['bytes']>self::MAX_BYTES
   ||!is_int($record['width']??null)||!is_int($record['height']??null)||$record['width']<1||$record['height']<1
   ||$record['width']>8192||$record['height']>8192||$record['width']*$record['height']>self::MAX_PIXELS
   ||!is_string($record['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$record['sha256']))return false;
  if('video'===$record['kind']&&(!is_numeric($record['duration']??null)||!is_finite((float)$record['duration'])||(float)$record['duration']<=0))return false;
  return true;
 }
 public static function file_for_record($record,$uid,$message) {
  if(!self::valid_record($record,$uid,$message))return false;
  $dir=self::directory($uid,$message);if(is_wp_error($dir))return false;
  $p=self::parts($record['key']);$path=$dir.'/'.$p[3].'.'.$p[4];
  if(is_link($path)||!is_file($path))return false;$stat=@lstat($path);$real=realpath($path);
  return $real&&dirname($real)===$dir&&$stat&&1===$stat['nlink']&&$stat['size']===$record['bytes']?$real:false;
 }
 private static function owner_lock($uid) {
  $dir=self::directory($uid,0,true);if(is_wp_error($dir))return $dir;$path=$dir.'/.lock';
  if(is_link($path)||(file_exists($path)&&!is_file($path)))return self::error();
  $h=@fopen($path,'c+b');if(!$h)return self::error();$stat=fstat($h);$now=@lstat($path);
  if(!$stat||!$now||$stat['ino']!==$now['ino']||$stat['dev']!==$now['dev']||1!==$stat['nlink']||!@chmod($path,0600)||!flock($h,LOCK_EX|LOCK_NB)) {fclose($h);return self::error('petish_chat_media_busy','An attachment is already being processed. Please wait and try again.');}
  return $h;
 }
 /** Bound disk usage without following a symlink or accepting unknown file names. */
 private static function owner_bytes($uid,$enforce_quota=true) {
  $dir=self::directory($uid);if(is_wp_error($dir))return $dir;$bytes=0;$count=0;
  foreach(new DirectoryIterator($dir) as $entry) {
   if($entry->isDot()||$entry->getFilename()==='.lock')continue;
   if($entry->isLink()||!$entry->isDir()||!preg_match('/^[1-9][0-9]*$/D',$entry->getFilename()))return self::error();
   foreach(new DirectoryIterator($entry->getPathname()) as $file) {
    if($file->isDot())continue;
    if(++$count>20000||$file->isLink()||!$file->isFile()||!preg_match('/^[a-f0-9]{48}[.](?:jpg|png|webp|mp4)$/D',$file->getFilename()))return self::error();
    $stat=$file->getFileInfo()->getRealPath();if(!$stat)return self::error();$st=lstat($stat);if(!$st||1!==$st['nlink'])return self::error();
    $bytes+=$st['size'];if($enforce_quota&&$bytes>self::MAX_OWNER_BYTES)return self::error('petish_chat_media_quota','Your Chat attachment storage is full. Please contact the Petish team.');
   }
  }
  return $bytes;
 }
 private static function journal($uid) {
  $items=get_user_meta($uid,self::JOURNAL,true);if(''===$items)return array();
  if(!is_array($items)||count($items)>32)return self::error();
  foreach($items as $entry)if(!is_array($entry)||!self::parts($entry['key']??null)||!is_int($entry['created']??null)||(int)self::parts($entry['key'])[1]!==absint($uid))return self::error();
  return $items;
 }
 private static function write_journal($uid,$items) {
  update_user_meta($uid,self::JOURNAL,array_values($items));
  return get_user_meta($uid,self::JOURNAL,true)===array_values($items);
 }
 private static function journal_remove($uid,$key) {
  $items=self::journal($uid);if(is_wp_error($items))return false;
  return self::write_journal($uid,array_filter($items,static fn($entry)=>$entry['key']!==$key));
 }
 private static function remove_key($key,$uid,$message) {
  $p=self::parts($key);if(!$p||(int)$p[1]!==absint($uid)||(int)$p[2]!==absint($message))return false;
  $candidate=dirname(untrailingslashit(ABSPATH)).'/private/petish-chat-stream/'.$uid.'/'.$message.'/'.$p[3].'.'.$p[4];
  if(!file_exists($candidate)&&!is_link($candidate))return true;
  $dir=self::directory($uid,$message);if(is_wp_error($dir)||is_link($candidate)||!is_file($candidate))return false;
  $real=realpath($candidate);$stat=lstat($candidate);
  if(!$real||dirname($real)!==$dir||!$stat||1!==$stat['nlink'])return false;
  wp_delete_file($real);clearstatcache(true,$real);return !file_exists($real)&&!is_link($real);
 }
 /** Trusted service must revoke publication before calling deletion. Works while disabled. */
 public static function remove($record,$uid,$message) {
  if(!self::valid_record($record,$uid,$message))return false;
  $h=self::owner_lock($uid);if(is_wp_error($h))return false;
  try {
   // Keep a durable retry handle even after the service has revoked its DB manifest.
   $journal=self::journal($uid);if(is_wp_error($journal))return false;
   if(!in_array($record['key'],array_column($journal,'key'),true)) {
    if(count($journal)>=32)return false;$journal[]=array('key'=>$record['key'],'created'=>time());
    if(!self::write_journal($uid,$journal))return false;
   }
   return self::remove_key($record['key'],$uid,$message)&&self::journal_remove($uid,$record['key']);
  }finally{flock($h,LOCK_UN);fclose($h);}
 }
 public static function delete_manifest($records,$uid,$message) {
  if(!is_array($records)||count($records)>self::MAX_FILES)return false;$ok=true;
  foreach($records as $record)$ok=self::remove($record,$uid,$message)&&$ok;return $ok;
 }
 public static function commit_manifest($records,$uid,$message) {
  if(!self::valid_manifest($records,$uid,$message))return self::error();
  $h=self::owner_lock($uid);if(is_wp_error($h))return $h;
  try {
   foreach($records as $record) {
    if(!method_exists('Petish_Chat_Stream','media_is_attached')||true!==Petish_Chat_Stream::media_is_attached($uid,$message,$record['token'],$record['key']))return self::error();
    if(!self::journal_remove($uid,$record['key']))return self::error('petish_chat_media_cleanup','Attachment housekeeping is pending.');
   }
   return true;
  }finally{flock($h,LOCK_UN);fclose($h);}
 }
 public static function valid_manifest($records,$uid,$message) {
  if(!is_array($records)||array_values($records)!==$records||count($records)>self::MAX_FILES)return false;
  foreach($records as $record)if(!self::valid_record($record,$uid,$message))return false;return true;
 }
 private static function cleanup_locked($uid) {
  $items=self::journal($uid);if(is_wp_error($items))return false;$keep=array();$ok=true;
  foreach($items as $entry) {
   if($entry['created']>time()-HOUR_IN_SECONDS){$keep[]=$entry;continue;}
   $p=self::parts($entry['key']);
   // A moderation denial is not proof that a file is unbound. Use the dedicated DB binding check.
   if(!method_exists('Petish_Chat_Stream','media_is_attached')){$keep[]=$entry;$ok=false;continue;}
   $bound=Petish_Chat_Stream::media_is_attached($uid,(int)$p[2],$p[3],$entry['key']);
   if(true===$bound)continue;if(false!==$bound){$keep[]=$entry;$ok=false;continue;}
   if(!self::remove_key($entry['key'],$uid,(int)$p[2])){$keep[]=$entry;$ok=false;}
  }
  return self::write_journal($uid,$keep)&&$ok;
 }
 public static function cleanup_unattached($uid) {
  $h=self::owner_lock($uid);if(is_wp_error($h))return false;
  try{return self::cleanup_locked($uid);}finally{flock($h,LOCK_UN);fclose($h);}
 }
 /** Erasure service invokes after removing all Chat rows for this member. */
 public static function cleanup_user($uid) {
  $uid=absint($uid);if(!$uid)return false;
  $candidate=dirname(untrailingslashit(ABSPATH)).'/private/petish-chat-stream/'.$uid;
  if(!file_exists($candidate)&&!is_link($candidate))return true;
  $h=self::owner_lock($uid);if(is_wp_error($h))return false;$ok=true;
  try {
   $size=self::owner_bytes($uid,false);if(is_wp_error($size))return false;
   $dir=self::directory($uid);if(is_wp_error($dir))return false;
   foreach(new DirectoryIterator($dir) as $entry) {
    if($entry->isDot()||$entry->getFilename()==='.lock')continue;$mid=(int)$entry->getFilename();
    foreach(new DirectoryIterator($entry->getPathname()) as $file)if(!$file->isDot())$ok=self::remove_key($uid.'/'.$mid.'/'.$file->getFilename(),$uid,$mid)&&$ok;
    if(!array_diff(scandir($entry->getPathname()),array('.','..')))$ok=@rmdir($entry->getPathname())&&$ok;
   }
   if($ok){delete_user_meta($uid,self::JOURNAL);delete_user_meta($uid,self::ATTEMPTS);}
   return $ok;
  }finally{flock($h,LOCK_UN);fclose($h);}
 }
 private static function upload_file($files) {
  if(is_array($files)&&isset($files['error'],$files['tmp_name'],$files['name']))$file=$files;
  elseif(is_array($files)&&count($files)===1&&isset($files['upload']))$file=$files['upload'];
  else return self::error('petish_chat_media_fields','Choose one photo or MP4 video per message.');
  if(!is_array($file)||!is_int($file['error']??null)||!is_string($file['tmp_name']??null)||!is_string($file['name']??null))return self::error();
  if(UPLOAD_ERR_OK!==$file['error'])return self::error('petish_chat_media_upload',in_array($file['error'],array(UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE),true)?'This upload exceeds the server limit. Chat supports files up to 250 MiB; please try a smaller file.':'The attachment upload did not finish. Please try again.');
  $tmp=$file['tmp_name'];if(is_link($tmp)||!is_uploaded_file($tmp))return self::error();
  $stat=@lstat($tmp);if(!$stat||1!==$stat['nlink']||$stat['size']<1||$stat['size']>self::MAX_BYTES)return self::error('petish_chat_media_size','Choose an attachment no larger than 250 MiB.');
  return $file;
 }
 public static function save_uploads($files,$uid,$message) {
  $uid=absint($uid);$message=absint($message);
  if(!self::enabled()||!$uid||!$message||get_current_user_id()!==$uid)return self::error('petish_chat_media_denied','Please sign in to attach a file.');
  $permission=Petish_Chat_Stream::may_upload($message,$uid);if(true!==$permission)return is_wp_error($permission)?$permission:self::error();
  $file=self::upload_file($files);if(is_wp_error($file))return $file;
  $h=self::owner_lock($uid);if(is_wp_error($h))return $h;$key='';
  try {
   self::cleanup_locked($uid);$journal=self::journal($uid);if(is_wp_error($journal)||count($journal)>=16)return self::error('petish_chat_media_cleanup','Earlier attachments need housekeeping. Please contact the Petish team.');
   $attempts=get_user_meta($uid,self::ATTEMPTS,true);if(!is_array($attempts))$attempts=array();
   $attempts=array_values(array_filter($attempts,static fn($at)=>is_int($at)&&$at>time()-HOUR_IN_SECONDS));
   if(count($attempts)>=20||count(array_filter($attempts,static fn($at)=>$at>time()-MINUTE_IN_SECONDS))>=3)return self::error('petish_chat_media_rate','Please wait before uploading another attachment.');
   $attempts[]=time();update_user_meta($uid,self::ATTEMPTS,$attempts);if(get_user_meta($uid,self::ATTEMPTS,true)!==$attempts)return self::error();
   $size=self::owner_bytes($uid);if(is_wp_error($size))return $size;
   if($size+filesize($file['tmp_name'])>self::MAX_OWNER_BYTES)return self::error('petish_chat_media_quota','Your Chat attachment storage is full. Please contact the Petish team.');
   $dir=self::directory($uid,$message,true);if(is_wp_error($dir))return $dir;
   $free=@disk_free_space($dir);if(false===$free||$free<1073741824+filesize($file['tmp_name']))return self::error('petish_chat_media_capacity','Attachment storage is temporarily unavailable. Please try again later.');
   $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));$video='mp4'===$ext;
   if(!$video&&!in_array($ext,array('jpg','jpeg','png','webp'),true))return self::error('petish_chat_media_type','Choose a JPEG, PNG, WebP photo or an H.264/AAC MP4 video. MOV, HEIC and WebM are not supported by this server.');
   $info=$video?self::inspect_video($file['tmp_name']):self::inspect_photo($file);
   if(is_wp_error($info))return $info;$out_ext=$video?'mp4':array('image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp')[$info['mime']];
   $token=bin2hex(random_bytes(24));$key=$uid.'/'.$message.'/'.$token.'.'.$out_ext;$path=$dir.'/'.$token.'.'.$out_ext;
   $journal[]=array('key'=>$key,'created'=>time());if(!self::write_journal($uid,$journal))return self::error();
   $mask=umask(0077);try{$written=$video?self::copy_uploaded($file['tmp_name'],$path,$info['bytes']):self::write_photo($file['tmp_name'],$path,$info); }finally{umask($mask);}
   if(is_wp_error($written)||true!==$written){if(self::remove_key($key,$uid,$message))self::journal_remove($uid,$key);return is_wp_error($written)?$written:self::error();}
   clearstatcache(true,$path);$bytes=@filesize($path);$dimensions=$video?array($info['width'],$info['height']):wp_getimagesize($path);
   if(!$dimensions||!$bytes||$bytes>self::MAX_BYTES||!@chmod($path,0600)){if(self::remove_key($key,$uid,$message))self::journal_remove($uid,$key);return self::error();}
   $record=array('owner_id'=>$uid,'message_id'=>$message,'token'=>$token,'key'=>$key,'kind'=>$video?'video':'photo','mime'=>$video?'video/mp4':$info['mime'],
    'bytes'=>$bytes,'width'=>(int)$dimensions[0],'height'=>(int)$dimensions[1],'duration'=>$video?(float)$info['duration']:0.0,'sha256'=>hash_file('sha256',$path));
   if(!self::file_for_record($record,$uid,$message)){if(self::remove_key($key,$uid,$message))self::journal_remove($uid,$key);return self::error();}
   return array($record);
  }catch(Throwable $e){if($key){if(self::remove_key($key,$uid,$message))self::journal_remove($uid,$key);}return self::error();}
  finally{flock($h,LOCK_UN);fclose($h);}
 }
 private static function inspect_photo($file) {
  $types=array('jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp');$mime=wp_get_image_mime($file['tmp_name']);
  $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));$checked=wp_check_filetype_and_ext($file['tmp_name'],$file['name'],array('jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'));
  if(!isset($types[$ext])||$types[$ext]!==$mime||empty($checked['ext'])||($checked['type']??'')!==$mime)return self::error('petish_chat_photo_type','Choose a JPEG, PNG or WebP photo.');
  $size=wp_getimagesize($file['tmp_name']);if(!$size||$size[0]<1||$size[1]<1||$size[0]>8192||$size[1]>8192||$size[0]*$size[1]>self::MAX_PIXELS)return self::error('petish_chat_photo_dimensions','Choose a photo up to 16 megapixels and 8192 pixels on either side.');
  return array('mime'=>$mime,'width'=>(int)$size[0],'height'=>(int)$size[1]);
 }
 private static function write_photo($tmp,$path,$info) {
  $gd=static fn()=>array('WP_Image_Editor_GD');add_filter('wp_image_editors',$gd,PHP_INT_MAX);
  try{$editor=wp_get_image_editor($tmp);}finally{remove_filter('wp_image_editors',$gd,PHP_INT_MAX);}
  if(is_wp_error($editor)||is_wp_error($editor->maybe_exif_rotate()))return self::error('petish_chat_photo_editor','This photo could not be processed. Please try another JPEG, PNG or WebP.');
  if(($info['width']>2048||$info['height']>2048)&&is_wp_error($editor->resize(2048,2048,false)))return self::error();
  if(is_wp_error($editor->set_quality(85)))return self::error();
  // A fresh GD encode discards EXIF/GPS/text metadata even when no resize is necessary.
  $out=$editor->save($path,$info['mime']);return !is_wp_error($out)&&is_file($path)?true:self::error();
 }
 private static function copy_uploaded($tmp,$path,$bytes) {
  $before=lstat($tmp);$in=@fopen($tmp,'rb');$out=@fopen($path,'x+b');$ok=false;
  if(!$in||!$out){if(is_resource($in))fclose($in);if(is_resource($out)){fclose($out);@unlink($path);}return self::error();}
  try {
   $opened=fstat($in);if(!$opened||!$before||$opened['ino']!==$before['ino']||$opened['dev']!==$before['dev']||1!==$opened['nlink']||!@chmod($path,0600))return self::error();
   $copied=stream_copy_to_stream($in,$out,self::MAX_BYTES+1);fflush($out);$after=fstat($in);$target=fstat($out);
   $ok=$copied===$bytes&&$after&&$target&&$after['size']===$bytes&&$after['ino']===$opened['ino']&&$after['dev']===$opened['dev']&&$after['mtime']===$opened['mtime']&&$after['ctime']===$opened['ctime']&&1===$target['nlink'];
  }finally{fclose($in);fclose($out);if(!$ok)@unlink($path);}
  return $ok?true:self::error();
 }
 public static function inspect_video($path) {
  if(!is_string($path)||is_link($path)||!is_file($path))return self::error('petish_chat_video_type');
  if(!class_exists('finfo'))return self::error('petish_chat_video_reader','Video inspection is unavailable.');
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);if(!in_array($mime,array('video/mp4','application/mp4'),true))return self::error('petish_chat_video_type','Choose an MP4 video with H.264 picture and optional AAC sound.');
  return Petish_Chat_Stream_MP4_Inspector::inspect($path);
 }
 public static function media_url($message,$token) {
  if(!absint($message)||!is_string($token)||!preg_match('/^[a-f0-9]{48}$/D',$token))return '';
  return add_query_arg(array('action'=>self::ACTION,'message_id'=>absint($message),'token'=>$token),admin_url('admin-post.php'));
 }
 public static function public_record($record,$message) {
  if(!is_array($record)||!self::valid_record($record,$record['owner_id']??0,$message))return array();
  return array('token'=>$record['token'],'kind'=>$record['kind'],'mime'=>$record['mime'],'bytes'=>$record['bytes'],'width'=>$record['width'],'height'=>$record['height'],
   'duration'=>(float)($record['duration']??0),'url'=>self::media_url($message,$record['token']));
 }
 public static function parse_range($header,$size) {
  if(!is_int($size)||$size<1||$size>self::MAX_BYTES)return self::error('petish_chat_media_range');
  if(null===$header||''===$header)return array('start'=>0,'end'=>$size-1,'partial'=>false);
  if(!is_string($header)||strlen($header)>80||!preg_match('/^bytes=([0-9]{0,10})-([0-9]{0,10})$/D',$header,$p)||(''===$p[1]&&''===$p[2]))return self::error('petish_chat_media_range');
  if(''===$p[1]){$suffix=(int)$p[2];if(!$suffix)return self::error('petish_chat_media_range');$start=max(0,$size-$suffix);$end=$size-1;}
  else{$start=(int)$p[1];$end=''===$p[2]?$size-1:min((int)$p[2],$size-1);if($start>=$size||$end<$start)return self::error('petish_chat_media_range');}
  return array('start'=>$start,'end'=>$end,'partial'=>true);
 }
 private static function headers() {
  nocache_headers();header('Cache-Control: private, no-store, max-age=0, must-revalidate');header('Pragma: no-cache');header('Vary: Cookie');
  header('X-Content-Type-Options: nosniff');header('Cross-Origin-Resource-Policy: same-origin');header("Content-Security-Policy: default-src 'none'; sandbox");header('Referrer-Policy: no-referrer');
 }
 /** Authorization precedes range parsing, size disclosure and every file read. */
 public static function serve() {
  self::headers();$method=$_SERVER['REQUEST_METHOD']??'GET';
  if(!in_array($method,array('GET','HEAD'),true)){status_header(405);header('Allow: GET, HEAD');exit;}
  $raw=$_GET['message_id']??'';$token=$_GET['token']??'';
  if(!self::enabled()||!is_string($raw)||!preg_match('/^[1-9][0-9]*$/D',$raw)||!is_string($token)||!preg_match('/^[a-f0-9]{48}$/D',$token)){status_header(404);exit;}
  $record=Petish_Chat_Stream::media_for_viewer((int)$raw,$token,get_current_user_id());
  if(is_wp_error($record)||!is_array($record)||!hash_equals($token,(string)($record['token']??''))){status_header(404);exit;}
  $path=self::file_for_record($record,$record['owner_id']??0,(int)$raw);if(!$path){status_header(404);exit;}
  self::stream_record($path,$record);exit;
 }
 /** Internal, validated record only; HTTP route already enforces publication state. */
 private static function stream_record($path,$record) {
  $in=@fopen($path,'rb');if(!$in){status_header(404);return false;}
  try {
   $before=@lstat($path);$stat=fstat($in);$size=$record['bytes'];
   if(!$before||!$stat||$stat['ino']!==$before['ino']||$stat['dev']!==$before['dev']||1!==$stat['nlink']||$stat['size']!==$size){status_header(404);return false;}
   $range=self::parse_range(isset($_SERVER['HTTP_IF_RANGE'])?'':($_SERVER['HTTP_RANGE']??''),$size);header('Accept-Ranges: bytes');
   if(is_wp_error($range)){status_header(416);header('Content-Range: bytes */'.$size);header('Content-Length: 0');return false;}
   $length=$range['end']-$range['start']+1;status_header($range['partial']?206:200);$ext=pathinfo($path,PATHINFO_EXTENSION);
   header('Content-Type: '.$record['mime']);header('Content-Disposition: inline; filename="petish-chat-attachment.'.$ext.'"');header('Content-Length: '.$length);
   if($range['partial'])header('Content-Range: bytes '.$range['start'].'-'.$range['end'].'/'.$size);
   if('HEAD'===($_SERVER['REQUEST_METHOD']??'GET'))return true;if(0!==fseek($in,$range['start']))return false;
   while($length>0&&!feof($in)){if(connection_aborted())return false;$chunk=fread($in,min(65536,$length));if(!is_string($chunk)||''===$chunk)return false;echo $chunk;$length-=strlen($chunk);}
   return 0===$length;
  }finally{fclose($in);}
 }
}

/** Independent bounded progressive MP4 parser. No duration policy is applied.
 * Sample-table/metadata budgets bound CPU and memory; unsupported containers and
 * codecs fail closed. Original MP4 metadata is retained and disclosed to members.
 * The Passport parser and its 20-second/50-MiB limits are deliberately untouched.
 */
final class Petish_Chat_Stream_MP4_Inspector {
 const MAX_BYTES=262144000;
 const MAX_SAMPLES=250000;
 const MAX_BOXES=2048;
 const MAX_DEPTH=10;
 const MAX_MOOV_BYTES=8388608;
 const MAX_TIMESCALE=10000000; // Bounded timescales; Chat comparisons divide before multiplying.
 private $file;
 private $bytes;
 private $box_count=0;
 private $mdat=array();
 private $used=array();

 /** @return array|WP_Error Technical properties only; never returns embedded tags or filenames. */
 public static function inspect($path){
  $handle=false;
  try{
   if(!is_string($path)||''===$path||is_link($path)||!is_file($path))throw new RuntimeException();
   $before=lstat($path);
   if(!$before||1!==$before['nlink']||$before['size']<32||$before['size']>self::MAX_BYTES)throw new RuntimeException();
   $handle=fopen($path,'rb');if(!$handle)throw new RuntimeException();
   $opened=fstat($handle);
   if(!$opened||$opened['ino']!==$before['ino']||$opened['dev']!==$before['dev']||$opened['size']!==$before['size'])throw new RuntimeException();
   $reader=new self();$reader->file=$handle;$reader->bytes=$opened['size'];
   $result=$reader->movie();$after=fstat($handle);
   if(!$after||$after['size']!==$opened['size']||$after['mtime']!==$opened['mtime']||$after['ctime']!==$opened['ctime'])throw new RuntimeException();
   return $result;
  }catch(Throwable $error){
   return new WP_Error('petish_chat_video_format','Choose a regular progressive MP4 video with H.264 picture and optional AAC sound, no larger than 250 MiB. This server does not transcode videos.');
  }finally{if(is_resource($handle))fclose($handle);}
 }
 private function reject(){throw new RuntimeException();}
 private function read($at,$length){
  if(!is_int($at)||!is_int($length)||$at<0||$length<0||$length>self::MAX_MOOV_BYTES||$at>$this->bytes-$length)$this->reject();
  if(0===$length)return '';
  if(0!==fseek($this->file,$at))$this->reject();
  $value=fread($this->file,$length);if(!is_string($value)||strlen($value)!==$length)$this->reject();return $value;
 }
 private function u16($s,$at){if($at<0||strlen($s)<$at+2)$this->reject();return unpack('n',substr($s,$at,2))[1];}
 private function u32($s,$at){if($at<0||strlen($s)<$at+4)$this->reject();return unpack('N',substr($s,$at,4))[1];}
 private function i32($s,$at){$n=$this->u32($s,$at);return $n>=2147483648?$n-4294967296:$n;}
 private function u64($s,$at){
  $high=$this->u32($s,$at);$low=$this->u32($s,$at+4);
  // All accepted durations, offsets and table sizes are safely below 2^53.
  if($high>2097151)$this->reject();return $high*4294967296+$low;
 }
 private function body($box){return $this->read($box['body'],$box['end']-$box['body']);}
 private function boxes($start,$end,$depth){
  if($depth>self::MAX_DEPTH||$start<0||$end>$this->bytes||$start>$end)$this->reject();
  $items=array();
  while($start<$end){
   if(++$this->box_count>self::MAX_BOXES||$end-$start<8)$this->reject();
   $header=$this->read($start,8);$size=$this->u32($header,0);$head=8;$type=substr($header,4,4);
   if(1===$size){if($end-$start<16)$this->reject();$size=$this->u64($this->read($start+8,8),0);$head=16;}
   // Size-zero/end-of-file and ambiguous partial boxes are outside this progressive subset.
   if($size<$head||$size>$end-$start)$this->reject();
   $items[]=array('type'=>$type,'start'=>$start,'body'=>$start+$head,'end'=>$start+$size,'size'=>$size,'depth'=>$depth);
   $start+=$size;
  }
  return $items;
 }
 private function children($box,$skip=0){return $this->boxes($box['body']+$skip,$box['end'],$box['depth']+1);}
 private function only($items,$types){foreach($items as $box)if(!in_array($box['type'],$types,true))$this->reject();}
 private function one($items,$type,$required=true){
  $found=null;foreach($items as $box)if($box['type']===$type){if(null!==$found)$this->reject();$found=$box;}
  if($required&&null===$found)$this->reject();return $found;
 }
 private function full($data,$versions=array(0)){
  if(strlen($data)<4||!in_array(ord($data[0]),$versions,true))$this->reject();
  return array(ord($data[0]),$this->u32($data,0)&16777215);
 }
 private function metadata($box){
  // Explicitly bound the metadata tree before getID3, including numeric key containers.
  // In particular, never pass compressed movie headers or arbitrary vendor containers onward.
  if($box['size']>self::MAX_MOOV_BYTES)$this->reject();$type=$box['type'];
  $text=array("\xa9nam","\xa9ART","\xa9alb","\xa9too","\xa9day","\xa9cmt","\xa9xyz",'cprt','desc');
  if('udta'===$type){
   $items=$this->children($box);$this->only($items,array_merge(array('meta','free'),$text));
   foreach($items as $item)if('meta'===$item['type'])$this->metadata($item);
  }elseif('meta'===$type){
   if("\0\0\0\0"!==$this->read($box['body'],4))$this->reject();
   $items=$this->children($box,4);$this->only($items,array('hdlr','keys','ilst','free'));
   foreach(array('hdlr','keys','ilst') as $name)$this->one($items,$name,false);
   foreach($items as $item){
    if('ilst'===$item['type'])$this->metadata($item);
    elseif('hdlr'===$item['type']){if($item['size']<32||$item['size']>1024)$this->reject();}
    elseif('keys'===$item['type']){
     $data=$this->body($item);list($version,$flags)=$this->full($data);$count=$this->u32($data,4);$at=8;
     if($flags||$count>128)$this->reject();
     for($i=0;$i<$count;$i++){$size=$this->u32($data,$at);if($size<8||$size>4096||$size>strlen($data)-$at)$this->reject();$at+=$size;}
     if($at!==strlen($data))$this->reject();
    }
   }
  }elseif('ilst'===$type){
   foreach($this->children($box) as $item){
    $numeric=substr($item['type'],0,3)==="\0\0\0"&&ord($item['type'][3])>0;
    if(!$numeric&&!in_array($item['type'],array_merge($text,array('----','covr')),true))$this->reject();
    $values=$this->children($item);$this->only($values,'----'===$item['type']?array('data','mean','name'):array('data'));
    if(!$values)$this->reject();foreach($values as $value)if($value['size']<16)$this->reject();
   }
  }else $this->reject();
 }
 private function movie(){
  $top=$this->boxes(0,$this->bytes,0);
  $this->only($top,array('ftyp','moov','mdat','free','skip','wide'));
  $ftyp=$this->one($top,'ftyp');$moov=$this->one($top,'moov');
  if($ftyp['start']!==0||$ftyp['size']>256||$moov['size']>self::MAX_MOOV_BYTES)$this->reject();
  $brands=$this->body($ftyp);
  if(strlen($brands)<8||strlen($brands)%4||!in_array(substr($brands,0,4),array('isom','iso2','iso4','iso5','iso6','iso8','mp41','mp42','avc1','M4V ','MSNV'),true))$this->reject();
  foreach($top as $box){
   if('mdat'===$box['type']){if($box['end']===$box['body'])$this->reject();$this->mdat[]=array($box['body'],$box['end']);}
  }
  if(!$this->mdat||count($this->mdat)>8)$this->reject();
  $children=$this->children($moov);$this->only($children,array('mvhd','trak','udta','meta','free','skip'));
  $mvhd=$this->body($this->one($children,'mvhd'));list($version,$flags)=$this->full($mvhd,array(0,1));
  if($flags||strlen($mvhd)!==($version?112:100))$this->reject();
  $scale=$this->u32($mvhd,$version?20:12);$duration=$version?$this->u64($mvhd,24):$this->u32($mvhd,16);
  if($scale<1||$scale>self::MAX_TIMESCALE||$duration<1||$this->u32($mvhd,$version?32:20)!==65536)$this->reject();
  $tracks=array();$ids=array();$maximum=0;
  foreach($children as $child){
   if(in_array($child['type'],array('meta','udta'),true))$this->metadata($child);
   if('trak'!==$child['type'])continue;
   if(count($tracks)>=2)$this->reject();
   $track=$this->track($child,$scale);
   if(isset($tracks[$track['kind']])||isset($ids[$track['id']]))$this->reject();
   $tracks[$track['kind']]=$track;$ids[$track['id']]=true;$maximum=max($maximum,$track['duration']);
  }
  if(!isset($tracks['vide'])||abs($maximum-$duration)>1)$this->reject();
  usort($this->used,static function($a,$b){return $a[0]<=>$b[0];});$last=-1;
  foreach($this->used as $range){if($range[0]<$last)$this->reject();$last=$range[1];}
  return array('duration'=>$duration/$scale,'width'=>$tracks['vide']['width'],'height'=>$tracks['vide']['height'],
   'coded_width'=>$tracks['vide']['coded_width'],'coded_height'=>$tracks['vide']['coded_height'],
   'video_codec'=>'avc1','audio_codec'=>isset($tracks['soun'])?'mp4a':null,'bytes'=>$this->bytes);
 }
 private function track($box,$movie_scale){
  $parts=$this->children($box);$this->only($parts,array('tkhd','edts','mdia','udta','meta','free'));
  foreach($parts as $part)if(in_array($part['type'],array('meta','udta'),true))$this->metadata($part);
  $tkhd=$this->body($this->one($parts,'tkhd'));list($version,$flags)=$this->full($tkhd,array(0,1));
  if(strlen($tkhd)!==($version?96:84)||($flags&3)!==3||($flags&~7))$this->reject();
  $id=$this->u32($tkhd,$version?20:12);$duration=$version?$this->u64($tkhd,28):$this->u32($tkhd,20);
  if(!$id||!$duration)$this->reject();
  $matrix_offset=$version?52:40;$matrix=array();for($i=0;$i<9;$i++)$matrix[]=$this->i32($tkhd,$matrix_offset+4*$i);
  // Permit ordinary quarter-turn rotation, without arbitrary scaling or perspective.
  if($matrix[2]!==0||$matrix[5]!==0||$matrix[8]!==1073741824)$this->reject();
  $rotation=array($matrix[0],$matrix[1],$matrix[3],$matrix[4]);
  if(!in_array($rotation,array(array(65536,0,0,65536),array(0,65536,-65536,0),array(-65536,0,0,-65536),array(0,-65536,65536,0)),true))$this->reject();
  $display_w=$this->u32($tkhd,$matrix_offset+36);$display_h=$this->u32($tkhd,$matrix_offset+40);
  $mdia=$this->children($this->one($parts,'mdia'));$this->only($mdia,array('mdhd','hdlr','minf','elng'));
  $mdhd=$this->body($this->one($mdia,'mdhd'));list($version,$flags)=$this->full($mdhd,array(0,1));
  if($flags||strlen($mdhd)!==($version?36:24))$this->reject();
  $scale=$this->u32($mdhd,$version?20:12);$media_duration=$version?$this->u64($mdhd,24):$this->u32($mdhd,16);
  if(!$scale||$scale>self::MAX_TIMESCALE||!$media_duration)$this->reject();
  $hdlr=$this->body($this->one($mdia,'hdlr'));list($version,$flags)=$this->full($hdlr);
  if($flags||strlen($hdlr)<24||strlen($hdlr)>1024)$this->reject();$kind=substr($hdlr,8,4);
  if(!in_array($kind,array('vide','soun'),true))$this->reject();
  $minf=$this->children($this->one($mdia,'minf'));$this->only($minf,array('vmhd','smhd','dinf','stbl','free'));
  $media_header=$this->body($this->one($minf,'vide'===$kind?'vmhd':'smhd'));
  list($v,$f)=$this->full($media_header);
  if(('vide'===$kind&&(strlen($media_header)!==12||$f!==1||$this->one($minf,'smhd',false)))
   ||('soun'===$kind&&(strlen($media_header)!==8||$f!==0||$this->one($minf,'vmhd',false))))$this->reject();
  $this->data_reference($this->one($minf,'dinf'));
  $tables=$this->children($this->one($minf,'stbl'));
  $this->only($tables,array('stsd','stts','ctts','stsc','stsz','stco','co64','stss','sgpd','sbgp','free'));
  $entry=$this->description($this->one($tables,'stsd'),$kind);
  if('vide'===$kind){
   if($display_w!==$entry['width']*65536||$display_h!==$entry['height']*65536)$this->reject();
   $width=$matrix[0]===0?$entry['height']:$entry['width'];$height=$matrix[0]===0?$entry['width']:$entry['height'];
   if($width>3840||$height>2160)$this->reject();
  }else{if($display_w||$display_h)$this->reject();$width=0;$height=0;}
  $sizes=$this->sample_sizes($this->one($tables,'stsz'));
  $deltas=$this->time_table($this->one($tables,'stts'),count($sizes),false,$scale);
  $ctts=$this->one($tables,'ctts',false);$offsets=$ctts?$this->time_table($ctts,count($sizes),true,$scale):array_fill(0,count($sizes),0);
  $dts=0;$start=null;$end=0;
  foreach($deltas as $i=>$delta){$pts=$dts+$offsets[$i];$start=null===$start?$pts:min($start,$pts);$end=max($end,$pts+$delta);$dts+=$delta;}
  if($dts!==$media_duration)$this->reject();
  $edit=$this->one($parts,'edts',false);
  if($edit){
   $edits=$this->children($edit);$this->only($edits,array('elst'));$elst=$this->body($this->one($edits,'elst'));
   list($version,$flags)=$this->full($elst,array(0,1));
   if($flags||strlen($elst)!==($version?28:20)||$this->u32($elst,4)!==1)$this->reject();
   $segment=$version?$this->u64($elst,8):$this->u32($elst,8);
   $media_time=$version?$this->u64($elst,16):$this->i32($elst,12);$rate=$this->u32($elst,$version?24:16);
   if($segment!==$duration||$media_time<0||$media_time<$start||$media_time>=$end||$rate!==65536)$this->reject();
   // One movie timescale tick permits only integer conversion rounding, never extra duration.
   if($duration/$movie_scale>($end-$media_time)/$scale+1/$movie_scale)$this->reject();
  }else{
   if($start<0||abs($duration/$movie_scale-$end/$scale)>1/$movie_scale)$this->reject();
  }
  $stss=$this->one($tables,'stss',false);if($stss)$this->sync_samples($stss,count($sizes),$kind);
  $sgpd=$this->one($tables,'sgpd',false);$sbgp=$this->one($tables,'sbgp',false);
  if((bool)$sgpd!==(bool)$sbgp)$this->reject();if($sgpd)$this->roll_group($sgpd,$sbgp,count($sizes),$kind);
  $this->chunks($tables,$sizes);
  $coded_width=$entry['width']??0;$coded_height=$entry['height']??0;
  return compact('id','kind','duration','width','height','coded_width','coded_height');
 }
 private function data_reference($dinf){
  $items=$this->children($dinf);$this->only($items,array('dref'));$dref=$this->one($items,'dref');$head=$this->read($dref['body'],8);
  list($v,$flags)=$this->full($head);if($flags||$this->u32($head,4)!==1)$this->reject();
  $refs=$this->children($dref,8);if(count($refs)!==1||$refs[0]['type']!=='url '||$this->body($refs[0])!=="\0\0\0\1")$this->reject();
 }
 private function description($stsd,$kind){
  $head=$this->read($stsd['body'],8);list($v,$flags)=$this->full($head);
  if($flags||$this->u32($head,4)!==1)$this->reject();$entries=$this->children($stsd,8);
  if(count($entries)!==1)$this->reject();$entry=$entries[0];$data=$this->body($entry);
  if(substr($data,0,6)!==str_repeat("\0",6)||$this->u16($data,6)!==1)$this->reject();
  if('vide'===$kind){
   if($entry['type']!=='avc1'||strlen($data)<78)$this->reject();
   $width=$this->u16($data,24);$height=$this->u16($data,26);
   if(!$width||!$height||$width>3840||$height>2160||$width*$height>8294400)$this->reject();
   $configs=$this->children($entry,78);$this->only($configs,array('avcC','pasp','btrt','colr','fiel'));
   $this->bitrate_box($configs);
   $this->avcc($this->body($this->one($configs,'avcC')),$width,$height);
   $pasp=$this->one($configs,'pasp',false);
   if($pasp){$ratio=$this->body($pasp);if(strlen($ratio)!==8||$this->u32($ratio,0)!==$this->u32($ratio,4)||!$this->u32($ratio,0))$this->reject();}
   return compact('width','height');
  }
  if($entry['type']!=='mp4a'||strlen($data)<28||$this->u16($data,8)!==0)$this->reject();
  $channels=$this->u16($data,16);$rate=$this->u32($data,24);
  if(!in_array($channels,array(1,2),true)||$this->u16($data,18)!==16||($rate&65535)||!($rate>>16)||($rate>>16)>96000)$this->reject();
  $configs=$this->children($entry,28);$this->only($configs,array('esds','btrt'));
  $this->bitrate_box($configs);
  $this->esds($this->body($this->one($configs,'esds')),$channels,$rate>>16);return array();
 }
 private function bitrate_box($configs){$box=$this->one($configs,'btrt',false);if($box&&strlen($this->body($box))!==12)$this->reject();}
 private function sample_sizes($box){
  $data=$this->body($box);list($v,$flags)=$this->full($data);$fixed=$this->u32($data,4);$count=$this->u32($data,8);
  if($flags||$count<1||$count>self::MAX_SAMPLES||$fixed>self::MAX_BYTES||strlen($data)!==12+($fixed?0:4*$count))$this->reject();
  $sizes=array();for($i=0;$i<$count;$i++){$size=$fixed?:$this->u32($data,12+4*$i);if(!$size||$size>self::MAX_BYTES)$this->reject();$sizes[]=$size;}return $sizes;
 }
 private function time_table($box,$samples,$composition,$scale){
  $data=$this->body($box);list($version,$flags)=$this->full($data,$composition?array(0,1):array(0));$count=$this->u32($data,4);
  if($flags||$count<1||$count>self::MAX_SAMPLES||strlen($data)!==8+8*$count)$this->reject();$values=array();$total=0;
  for($i=0;$i<$count;$i++){
   $n=$this->u32($data,8+8*$i);$value=$version?$this->i32($data,12+8*$i):$this->u32($data,12+8*$i);
   if($n<1||$n>$samples-count($values)||(!$composition&&$value<1))$this->reject();
   if(!$composition){$total+=$n*$value;if(!is_finite((float)$total)||$total>9007199254740991)$this->reject();}
   for($j=0;$j<$n;$j++)$values[]=$value;
  }
  if(count($values)!==$samples)$this->reject();return $values;
 }
 private function sync_samples($box,$samples,$kind){
  if('vide'!==$kind)$this->reject();$data=$this->body($box);list($v,$flags)=$this->full($data);$count=$this->u32($data,4);
  if($flags||$count<1||$count>$samples||strlen($data)!==8+4*$count||$this->u32($data,8)!==1)$this->reject();$last=0;
  for($i=0;$i<$count;$i++){$sample=$this->u32($data,8+4*$i);if($sample<=$last||$sample>$samples)$this->reject();$last=$sample;}
 }
 private function roll_group($sgpd,$sbgp,$samples,$kind){
  if('soun'!==$kind)$this->reject();$desc=$this->body($sgpd);list($v,$flags)=$this->full($desc,array(1));
  if($flags||strlen($desc)!==18||substr($desc,4,4)!=='roll'||$this->u32($desc,8)!==2||$this->u32($desc,12)!==1)$this->reject();
  $distance=$this->u16($desc,16);if($distance<65520)$this->reject(); // Bounded AAC decoder priming only.
  $groups=$this->body($sbgp);list($v,$flags)=$this->full($groups);$count=$this->u32($groups,8);
  if($flags||substr($groups,4,4)!=='roll'||$count<1||$count>$samples||strlen($groups)!==12+8*$count)$this->reject();$total=0;
  for($i=0;$i<$count;$i++){$n=$this->u32($groups,12+8*$i);$index=$this->u32($groups,16+8*$i);if(!$n||$index>1||$n>$samples-$total)$this->reject();$total+=$n;}
  if($total!==$samples)$this->reject();
 }
 private function chunks($tables,$sizes){
  $stco=$this->one($tables,'stco',false);$co64=$this->one($tables,'co64',false);
  if((bool)$stco===(bool)$co64)$this->reject();$data=$this->body($stco?:$co64);list($v,$flags)=$this->full($data);$count=$this->u32($data,4);$step=$co64?8:4;
  if($flags||$count<1||$count>count($sizes)||strlen($data)!==8+$step*$count)$this->reject();$offsets=array();
  for($i=0;$i<$count;$i++)$offsets[]=$co64?$this->u64($data,8+$step*$i):$this->u32($data,8+$step*$i);
  $map=$this->body($this->one($tables,'stsc'));list($v,$flags)=$this->full($map);$entries=$this->u32($map,4);
  if($flags||$entries<1||$entries>$count||strlen($map)!==8+12*$entries)$this->reject();$runs=array();$previous=0;
  for($i=0;$i<$entries;$i++){
   $first=$this->u32($map,8+12*$i);$n=$this->u32($map,12+12*$i);$description=$this->u32($map,16+12*$i);
   if($first<=$previous||$first>$count||(!$i&&$first!==1)||!$n||$n>count($sizes)||$description!==1)$this->reject();
   $runs[]=array($first,$n);$previous=$first;
  }
  $sample=0;$run=0;
  foreach($offsets as $i=>$offset){
   if(isset($runs[$run+1])&&$runs[$run+1][0]===$i+1)$run++;
   $n=$runs[$run][1];if($n>count($sizes)-$sample)$this->reject();$bytes=0;
   for($j=0;$j<$n;$j++)$bytes+=$sizes[$sample++];
   if($bytes>$this->bytes||$offset>$this->bytes-$bytes)$this->reject();$end=$offset+$bytes;$inside=false;
   foreach($this->mdat as $range)if($offset>=$range[0]&&$end<=$range[1]){$inside=true;break;}
   if(!$inside)$this->reject();$this->used[]=array($offset,$end);
  }
  if($sample!==count($sizes))$this->reject();
 }
 private function bits($data,&$position,$count){
  if($count<0||$count>32||$position<0||$position+$count>strlen($data)*8)$this->reject();$value=0;
  for($i=0;$i<$count;$i++,$position++)$value=$value*2+((ord($data[intdiv($position,8)])>>(7-$position%8))&1);
  return $value;
 }
 private function ue($data,&$position){
  $zeros=0;while(0===$this->bits($data,$position,1)){if(++$zeros>20)$this->reject();}
  return (1<<$zeros)-1+$this->bits($data,$position,$zeros);
 }
 private function se($data,&$position){$value=$this->ue($data,$position);return $value%2?intdiv($value+1,2):-intdiv($value,2);}
 private function sps($nal,$width,$height,$profile,$level){
  if(strlen($nal)<5||strlen($nal)>4096||(ord($nal[0])&159)!==7||ord($nal[1])!==$profile||ord($nal[3])!==$level)$this->reject();
  $rbsp='';$zeros=0;
  for($i=1;$i<strlen($nal);$i++){
   $byte=ord($nal[$i]);if($zeros>=2&&$byte===3){if(!isset($nal[$i+1])||ord($nal[$i+1])>3)$this->reject();$zeros=0;continue;}
   $rbsp.=$nal[$i];$zeros=$byte===0?$zeros+1:0;
  }
  $position=24;$id=$this->ue($rbsp,$position);if($id>31)$this->reject();
  if($profile===100){
   if($this->ue($rbsp,$position)!==1||$this->ue($rbsp,$position)!==0||$this->ue($rbsp,$position)!==0)$this->reject();
   $this->bits($rbsp,$position,1);if($this->bits($rbsp,$position,1))$this->reject(); // Custom scaling lists are outside this subset.
  }
  if($this->ue($rbsp,$position)>12)$this->reject();$order=$this->ue($rbsp,$position);
  if($order===0){if($this->ue($rbsp,$position)>12)$this->reject();}
  elseif($order===1){$this->bits($rbsp,$position,1);$this->se($rbsp,$position);$this->se($rbsp,$position);$cycles=$this->ue($rbsp,$position);if($cycles>256)$this->reject();for($i=0;$i<$cycles;$i++)$this->se($rbsp,$position);}
  elseif($order!==2)$this->reject();
  if($this->ue($rbsp,$position)>16)$this->reject();$this->bits($rbsp,$position,1);
  $mbw=$this->ue($rbsp,$position)+1;$mbh=$this->ue($rbsp,$position)+1;
  if($mbw>256||$mbh>256||$this->bits($rbsp,$position,1)!==1)$this->reject();$this->bits($rbsp,$position,1);
  $left=$right=$top=$bottom=0;
  if($this->bits($rbsp,$position,1)){$left=$this->ue($rbsp,$position);$right=$this->ue($rbsp,$position);$top=$this->ue($rbsp,$position);$bottom=$this->ue($rbsp,$position);}
  if($mbw*16-2*($left+$right)!==$width||$mbh*16-2*($top+$bottom)!==$height)$this->reject();return $id;
 }
 private function avcc($data,$width,$height){
  if(strlen($data)<7||strlen($data)>65536||ord($data[0])!==1||!in_array(ord($data[1]),array(66,77,100),true)
   ||ord($data[3])<10||ord($data[3])>52||ord($data[4])!==255||(ord($data[5])&224)!==224)$this->reject();
  $profile=ord($data[1]);$level=ord($data[3]);$count=ord($data[5])&31;if($count<1||$count>4)$this->reject();$at=6;$ids=array();
  for($i=0;$i<$count;$i++){$size=$this->u16($data,$at);$at+=2;if($size<5||$size>4096||$size>strlen($data)-$at)$this->reject();$id=$this->sps(substr($data,$at,$size),$width,$height,$profile,$level);if(isset($ids[$id]))$this->reject();$ids[$id]=true;$at+=$size;}
  if($at>=strlen($data))$this->reject();$count=ord($data[$at++]);if($count<1||$count>8)$this->reject();
  for($i=0;$i<$count;$i++){$size=$this->u16($data,$at);$at+=2;if($size<2||$size>4096||$size>strlen($data)-$at)$this->reject();$nal=substr($data,$at,$size);if((ord($nal[0])&159)!==8)$this->reject();$pos=8;if($this->ue($nal,$pos)>255||!isset($ids[$this->ue($nal,$pos)]))$this->reject();$at+=$size;}
  if($at<strlen($data)){
   if($profile!==100||strlen($data)-$at!==4||substr($data,$at)!=="\xfd\xf8\xf8\0")$this->reject();
  }
 }
 private function descriptor($data,&$at,$tag){
  if($at>=strlen($data)||ord($data[$at++])!==$tag)$this->reject();$size=0;
  for($i=0;$i<4;$i++){if($at>=strlen($data))$this->reject();$byte=ord($data[$at++]);$size=$size*128+($byte&127);if(!($byte&128))break;if($i===3)$this->reject();}
  if($size>strlen($data)-$at)$this->reject();$result=substr($data,$at,$size);$at+=$size;return $result;
 }
 private function esds($data,$channels,$rate){
  if(strlen($data)>4096)$this->reject();list($v,$flags)=$this->full($data);if($flags)$this->reject();$at=4;$es=$this->descriptor($data,$at,3);
  if($at!==strlen($data)||strlen($es)<3||(ord($es[2])&224))$this->reject();$at=3;$config=$this->descriptor($es,$at,4);
  if(strlen($config)<13||ord($config[0])!==64||ord($config[1])!==21)$this->reject();$decoder=13;$specific=$this->descriptor($config,$decoder,5);
  if($decoder!==strlen($config)||strlen($specific)<2||strlen($specific)>8)$this->reject();
  $position=0;if($this->bits($specific,$position,5)!==2)$this->reject();$index=$this->bits($specific,$position,4);
  $rates=array(96000,88200,64000,48000,44100,32000,24000,22050,16000,12000,11025,8000,7350);
  $frequency=$index===15?$this->bits($specific,$position,24):($rates[$index]??0);
  if($frequency!==$rate||$this->bits($specific,$position,4)!==$channels||$this->bits($specific,$position,3)!==0)$this->reject();
  if(strlen($specific)*8-$position>=16){
   if($this->bits($specific,$position,11)!==695||$this->bits($specific,$position,5)!==5||$this->bits($specific,$position,1)!==0)$this->reject();
  }
  while($position<strlen($specific)*8)if($this->bits($specific,$position,1)!==0)$this->reject();
  $sl=$this->descriptor($es,$at,6);if($sl!=="\2"||$at!==strlen($es))$this->reject();
 }
}
