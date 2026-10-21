<?php
/** Loopback-only integration router; paths are supplied by the owning test process. */
error_reporting(E_ALL);
$public=getenv('PETISH_TEST_VIDEO_PUBLIC');$library=getenv('PETISH_TEST_GETID3_DIR');
if(!$public||!$library||!is_dir($public)||!is_file($library.'/getid3.php')){http_response_code(503);exit;}
define('ABSPATH',rtrim($public,'/').'/');
class WP_Error {function __construct(public $code,public $message='',public $data=null){}}
function is_wp_error($v){return $v instanceof WP_Error;}
function absint($v){return abs((int)$v);}
function untrailingslashit($v){return rtrim($v,'/');}
function wp_mkdir_p($path){return is_dir($path)||mkdir($path,0700,true);}
function wp_delete_file($path){if(is_file($path))unlink($path);}
require $library.'/getid3.php';
require dirname(__DIR__).'/wordpress/wp-content/plugins/petish-passport/includes/class-petish-passport-video-inspector.php';
require dirname(__DIR__).'/wordpress/wp-content/plugins/petish-passport/includes/class-petish-passport-video.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(200);echo 'ready';exit;}
$reserved=null;$before_write=null;
$result=Petish_Passport_Video::save($_FILES['video']??null,11,static function($key)use(&$reserved,&$before_write){
 $reserved=$key;$before_write=!file_exists(dirname(rtrim(ABSPATH,'/')).'/private/petish-passport/videos/'.$key);return true;
});
if(is_wp_error($result))$response=['status'=>'error','code'=>$result->code,'message'=>$result->message];
else{
 $path=Petish_Passport_Video::file_for_owner($result['key'],11);
 $response=['status'=>'saved','duration'=>$result['duration'],'bytes'=>$result['bytes'],'mode'=>fileperms($path)&0777,
  'reserved_before_write'=>$before_write,'sha256'=>hash_file('sha256',$path),'key'=>$result['key']];
}
if($reserved)Petish_Passport_Video::remove($reserved);
$response['remaining']=count(glob(dirname(rtrim(ABSPATH,'/')).'/private/petish-passport/videos/11/*.mp4')?:[]);
header('Content-Type: application/json');echo json_encode($response,JSON_THROW_ON_ERROR);
