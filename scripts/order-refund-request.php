<?php
/** Temporary request keys and exact selectors only. Never boot WordPress. */
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0); umask(0077);
function ori_fail() { throw new RuntimeException(); }
function ori_run($value) { if (!is_string($value)||!preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$value)) ori_fail(); return $value; }
function ori_path($path,$directory=false) {
 $cursor=''; if (!is_string($path)||$path===''||$path[0]!=='/') ori_fail();
 foreach(explode('/',substr($path,1)) as $part) {if($part===''||$part==='.'||$part==='..')ori_fail();$cursor.='/'.$part;if(is_link($cursor)||realpath($cursor)!==$cursor)ori_fail();}
 $info=lstat($path);if(!$info||($directory?!is_dir($path):(!is_file($path)||$info['nlink']!==1)))ori_fail();
 return $path;
}
function ori_dir($path) {ori_path($path,true);if((fileperms($path)&07777)!==0700)ori_fail();return $path;}
function ori_read($path,$max=16384) {ori_path($path);if((fileperms($path)&07777)!==0600||filesize($path)<1||filesize($path)>$max)ori_fail();$value=file_get_contents($path);if(!is_string($value)||strlen($value)>$max)ori_fail();return $value;}
function ori_json($path,$max=16384) {$v=json_decode(ori_read($path,$max),true,16,JSON_THROW_ON_ERROR);if(!is_array($v))ori_fail();return $v;}
function ori_write($path,$value) {ori_dir(dirname($path));$h=fopen($path,'xb');if(!$h)ori_fail();try{if(!chmod($path,0600)||fwrite($h,$value)!==strlen($value)||!fflush($h))ori_fail();}finally{fclose($h);}}
function ori_fingerprint($pem,$private=false) {
 $key=$private?openssl_pkey_get_private($pem):openssl_pkey_get_public($pem);if(!$key)ori_fail();$d=openssl_pkey_get_details($key);
 if(!is_array($d)||$d['type']!==OPENSSL_KEYTYPE_RSA||$d['bits']!==4096||($d['rsa']['e']??'')!=="\x01\x00\x01")ori_fail();
 $der=base64_decode(preg_replace('~-----[^-]+-----|\s~','',$d['key']),true);if(!is_string($der))ori_fail();return hash('sha256',$der);
}
function ori_root($kind,$run) {$home=getenv('HOME');ori_path($home,true);return $home.'/.petish-order-refund-'.$kind.'-'.ori_run($run);}
function ori_owned($kind,$run) {$root=ori_dir(ori_root($kind,$run));if(ori_read($root.'/.owned',128)!==$run)ori_fail();return $root;}
function ori_issued($root,$prep,$allow_expired=false) {
 $v=ori_json($root.'/issued.json',1024);
 if(array_keys($v)!==['run_id','source_commit','issued']||$v['run_id']!==$prep||!preg_match('/\A[a-f0-9]{40}\z/D',$v['source_commit']??'')||!is_int($v['issued'])||$v['issued']>time()+300||(!$allow_expired&&time()>$v['issued']+3600))ori_fail();return $v;
}
function ori_cleanup($run,$prep,$prepare=false) {
 $key=ori_root('request',$prep);
 if(file_exists($key)||is_link($key)) {
  ori_owned('request',$prep);
  if(file_exists($key.'/.consumed')||is_link($key.'/.consumed')) {if(ori_read($key.'/.consumed',128)!==$run)ori_fail();}
  elseif(!$prepare)ori_write($key.'/.consumed',$run);
  $allowed=['.owned','.consumed','request-key.pem','request-public.pem','issued.json','order-refund-request.php','tools.sha256'];
  foreach(new DirectoryIterator($key) as $f) {if($f->isDot())continue;if(!in_array($f->getFilename(),$allowed,true))ori_fail();ori_read($f->getPathname(),16384);}
  foreach($allowed as $name)if(file_exists($key.'/'.$name)&&!unlink($key.'/'.$name))ori_fail();if(!rmdir($key))ori_fail();
 }
 if(!$prepare) {
  $tools=ori_root('inspect',$run);
  if(file_exists($tools)||is_link($tools)) {
   ori_owned('inspect',$run);
   $allowed=['.owned','order-refund-request.php','inspect-order-refund.php','request-envelope.json','selector.enc','selector.private.json','request.json','report.private.json','report-public.pem','tools.sha256'];
   foreach(new DirectoryIterator($tools) as $f) {if($f->isDot())continue;if(!in_array($f->getFilename(),$allowed,true))ori_fail();ori_read($f->getPathname(),131072);}
   foreach($allowed as $name)if(file_exists($tools.'/'.$name)&&!unlink($tools.'/'.$name))ori_fail();if(!rmdir($tools))ori_fail();
  }
 }
}
try {
 $mode=$argv[1]??'';$run=ori_run($argv[2]??'');
 if($mode==='prepare') {
  $source=$argv[3]??'';if(count($argv)!==4||!preg_match('/\A[a-f0-9]{40}\z/D',$source))ori_fail();
  $root=ori_owned('request',$run);if(realpath(__DIR__)!==$root)ori_fail();
  $public=ori_read($root.'/request-public.pem',8192);$fingerprint=ori_fingerprint($public);
  if(!hash_equals($fingerprint,ori_fingerprint(ori_read($root.'/request-key.pem',8192),true)))ori_fail();
  $issued=time();ori_write($root.'/issued.json',json_encode(['run_id'=>$run,'source_commit'=>$source,'issued'=>$issued],JSON_THROW_ON_ERROR));
  echo json_encode(['format'=>'petish-order-refund-prepared-v1','run_id'=>$run,'source_commit'=>$source,'issued_utc'=>gmdate('Y-m-d\TH:i:s\Z',$issued),'expires_utc'=>gmdate('Y-m-d\TH:i:s\Z',$issued+3600),'request_key_sha256'=>$fingerprint,'public_key_pem'=>$public],JSON_THROW_ON_ERROR)."\n";
 } elseif(in_array($mode,['claim','selector','cleanup'],true)) {
  if(count($argv)!==4)ori_fail();$prep=ori_run($argv[3]);
  if($mode==='cleanup') {ori_cleanup($run,$prep,$run===$prep);exit(0);}
  $tools=ori_owned('inspect',$run);if(realpath(__DIR__)!==$tools)ori_fail();$key=ori_owned('request',$prep);
  if($mode==='claim') {ori_write($key.'/.consumed',$run);ori_issued($key,$prep);ori_read($key.'/request-key.pem',8192);}
  else {
   ori_issued($key,$prep);if(ori_read($key.'/.consumed',128)!==$run)ori_fail();
   $input=ori_json($tools.'/selector.private.json',1024);$request=ori_json($tools.'/request-envelope.json',4096);
   if(array_keys($input)!==['prepare_run_id','report_recipient_sha256','schema','selector']||$input['schema']!==1||$input['prepare_run_id']!==$prep)ori_fail();
   $recipient=ori_fingerprint(ori_read($tools.'/report-public.pem',8192));
   if(($request['prepare_run_id']??null)!==$prep||($request['report_recipient_sha256']??null)!==$recipient||$input['report_recipient_sha256']!==$recipient||($request['request_key_sha256']??null)!==ori_fingerprint(ori_read($key.'/request-public.pem',8192)))ori_fail();
   $selector=$input['selector'];if(!is_array($selector)||!in_array(array_keys($selector),[['order_id','schema'],['expected_billing_first_name','order_id','schema']],true)||($selector['schema']??null)!==1||!is_int($selector['order_id']??null)||$selector['order_id']<1||$selector['order_id']>9007199254740991)ori_fail();
   if(isset($selector['expected_billing_first_name'])&&(!is_string($selector['expected_billing_first_name'])||trim($selector['expected_billing_first_name'])===''||strlen($selector['expected_billing_first_name'])>320||preg_match('/[\x00-\x1f\x7f]/',$selector['expected_billing_first_name'])))ori_fail();
   ori_write($tools.'/request.json',json_encode($selector,JSON_THROW_ON_ERROR));
  }
 } else ori_fail();
} catch(Throwable $ignored) {fwrite(STDERR,"Private inspection request failed.\n");exit(1);}
