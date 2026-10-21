<?php
/** Owned cart fixture and request guard for private staging only. */
const CART_BADGE_HOME = '/home/u839205597';
const CART_BADGE_ROOT = '/home/u839205597/domains/staging.petish.biz/public_html';
const CART_BADGE_GUARD_HASH = 'f7615da9180aff6b8b85d81f5821ed1bd4933970adeee3462dd252a502681ee6';
const CART_BADGE_FIXTURE_CHECKS = [
 'target_paths_verified','target_environment_verified','woocommerce_runtime_verified','staging_database_separate',
 'staging_guard_verified','jetpack_sync_isolated','all_outbound_http_blocked','mail_calls_blocked',
 'remote_file_access_disabled','cron_and_async_runner_disabled','fixed_fixture_paths','configuration_isolated',
 'fixture_journal_written','owned_customer_created','owned_product_created','virtual_product_stock_verified',
 'product_hidden_from_catalogue','test_mode_verified','browser_guard_installed','browser_input_written',
];
const CART_BADGE_CLEANUP_CHECKS = [
 'target_paths_verified','target_environment_verified','woocommerce_runtime_verified','staging_database_separate',
 'staging_guard_verified','jetpack_sync_isolated','all_outbound_http_blocked','mail_calls_blocked',
 'remote_file_access_disabled','cron_and_async_runner_disabled','fixed_fixture_paths','configuration_isolated',
 'fixture_ownership_verified','owned_session_removed','owned_product_removed','owned_customer_removed',
 'orders_unchanged','users_and_authentication_unchanged','product_prices_and_stock_unchanged','settings_unchanged',
 'existing_passports_unchanged','private_photo_files_unchanged','existing_sessions_unchanged','comments_unchanged',
 'protected_files_unchanged','browser_guard_removed','browser_input_removed','fixture_cleanup_verified',
];

function cart_badge_fail() { throw new RuntimeException('Cart fixture verification failed.'); }
function cart_badge_mark($key,$passed) {
 global $report;
 if(!in_array($key,array_merge(CART_BADGE_FIXTURE_CHECKS,CART_BADGE_CLEANUP_CHECKS),true)||!is_bool($passed))cart_badge_fail();
 $report['checks'][$key]=$passed;if(!$passed)cart_badge_fail();
}
function cart_badge_path($path,$directory=false,$mode=null) {
 if(!is_string($path)||$path===''||$path[0]!=='/'||str_contains($path,"\0"))cart_badge_fail();
 $cursor='';foreach(explode('/',substr($path,1))as$part){if($part===''||$part==='.'||$part==='..')cart_badge_fail();$cursor.='/'.$part;if(is_link($cursor)||realpath($cursor)!==$cursor)cart_badge_fail();}
 clearstatcache(true,$path);$st=lstat($path);
 if(!$st||($st['mode']&0170000)!==($directory?0040000:0100000)||(!$directory&&$st['nlink']!==1)||($mode!==null&&($st['mode']&07777)!==$mode))cart_badge_fail();return$st;
}
function cart_badge_write($path,$value) {
 cart_badge_path(dirname($path),true,0700);if(file_exists($path)||is_link($path))cart_badge_path($path,false,0600);
 $pending=$path.'.pending';
 // Every caller holds this run's database lock. A prior interrupted write has
 // not replaced the committed journal and may be discarded only as an ordinary
 // private file in the already-attested directory, never through a link.
 if(file_exists($pending)||is_link($pending)){$st=cart_badge_path($pending,false,0600);if($st['size']>1048576||!unlink($pending))cart_badge_fail();}
 $bytes=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";if(strlen($bytes)>1048576)cart_badge_fail();
 $file=fopen($pending,'xb');if(!$file)cart_badge_fail();try{if(!chmod($pending,0600)||fwrite($file,$bytes)!==strlen($bytes)||!fflush($file))cart_badge_fail();}finally{fclose($file);}
 if(!rename($pending,$path))cart_badge_fail();
}
function cart_badge_read($path) {
 $st=cart_badge_path($path,false,0600);if($st['size']>1048576)cart_badge_fail();$v=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);if(!is_array($v))cart_badge_fail();return$v;
}
function cart_badge_paths($run,$create=false) {
 if(!is_string($run)||!preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$run)||(PHP_SAPI==='cli'&&getenv('HOME')!==CART_BADGE_HOME))cart_badge_fail();
 $tools=CART_BADGE_HOME.'/.petish-homepage-deploy-'.$run;
 if(__DIR__!==$tools)cart_badge_fail();cart_badge_path($tools,true,0700);cart_badge_path($tools.'/.homepage-owned',false,0600);
 if(file_get_contents($tools.'/.homepage-owned')!==$run."\n")cart_badge_fail();
 $base=CART_BADGE_HOME.'/.petish-staging';cart_badge_path($base,true,0700);
 $parent=$base.'/rehearsal-'.$run;$work=$parent.'/cart-badge';
 foreach([$parent,$work]as$dir){if(!file_exists($dir)&&!is_link($dir)&&$create&&!mkdir($dir,0700))cart_badge_fail();cart_badge_path($dir,true,0700);}
 cart_badge_path(CART_BADGE_ROOT,true);cart_badge_path(CART_BADGE_ROOT.'/wp-content/mu-plugins',true);
 return[$tools,$work];
}
/** Only plain, top-level literal declarations select the staging database. */
function cart_badge_definitions($tokens,$names) {
 $found=[];$mentions=[];$depth=0;
 $literal=static fn($t)=>is_array($t)&&$t[0]===T_CONSTANT_ENCAPSED_STRING&&!str_contains($t[1],'\\')?substr($t[1],1,-1):null;
 foreach($tokens as$i=>$token){if($token==='{')$depth++;if($token==='}')$depth--;$name=$literal($token);if(!in_array($name,$names,true))continue;$mentions[$name]=($mentions[$name]??0)+1;
  $start=$i-2;$previous=$tokens[$start-1]??null;
  if($depth!==0||!is_array($tokens[$start]??null)||$tokens[$start][0]!==T_STRING||strtolower($tokens[$start][1])!=='define'||($tokens[$i-1]??null)!=='('||($tokens[$i+1]??null)!==','||($tokens[$i+3]??null)!==')'||($tokens[$i+4]??null)!==';'||($previous!==';'&&!(is_array($previous)&&$previous[0]===T_OPEN_TAG)))continue;
  $raw=$tokens[$i+2]??null;$value=$literal($raw);if(is_array($raw)&&$raw[0]===T_STRING&&in_array(strtolower($raw[1]),['true','false'],true))$value=strtolower($raw[1])==='true';if($value!==null)$found[$name]=$value;
 }foreach($names as$name)if(($mentions[$name]??0)!==1)unset($found[$name]);return$found;
}
function cart_badge_configuration() {
 $loader=CART_BADGE_ROOT.'/wp-config.php';$private=CART_BADGE_HOME.'/.petish-staging/config.php';$guard=CART_BADGE_ROOT.'/wp-content/mu-plugins/petish-staging-guard.php';
 foreach([$loader,$private,$guard]as$p)cart_badge_path($p);if(!hash_equals(CART_BADGE_GUARD_HASH,hash_file('sha256',$guard)))cart_badge_fail();
 $tokens=static fn($p)=>array_values(array_filter(token_get_all(file_get_contents($p),TOKEN_PARSE),static fn($t)=>!is_array($t)||!in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)));
 $a=$tokens($loader);$requires=0;$depth=0;foreach($a as$i=>$t){if($t==='{')$depth++;if($t==='}')$depth--;if($depth===0&&is_array($t)&&in_array($t[0],[T_REQUIRE,T_REQUIRE_ONCE],true)&&is_array($a[$i+1]??null)&&$a[$i+1][0]===T_CONSTANT_ENCAPSED_STRING&&substr($a[$i+1][1],1,-1)===$private&&($a[$i+2]??null)===';')$requires++;}
 $defs=cart_badge_definitions(array_merge($a,$tokens($private)),['DB_NAME','DB_HOST','WP_ENVIRONMENT_TYPE','WCPAY_DEV_MODE','DISABLE_WP_CRON']);
 $production=getenv('PETISH_REHEARSAL_PRODUCTION_DB_NAME');$host=getenv('PETISH_REHEARSAL_PRODUCTION_DB_HOST');
 if($requires!==1||!is_string($production)||$production===''||!is_string($host)||$host===''||($defs['WP_ENVIRONMENT_TYPE']??null)!=='staging'||($defs['WCPAY_DEV_MODE']??null)!==true||($defs['DISABLE_WP_CRON']??null)!==true||!is_string($defs['DB_NAME']??null)||$defs['DB_NAME']===''||$defs['DB_NAME']===$production||!is_string($defs['DB_HOST']??null)||$defs['DB_HOST']==='')cart_badge_fail();return$defs;
}
/** Tokenize only the small SQL grammar emitted by wpdb for owned sessions. */
function cart_badge_sql_tokens($sql) {
 if(!is_string($sql)||strlen($sql)>4194304||str_contains($sql,"\0"))return null;$out=[];$length=strlen($sql);
 for($i=0;$i<$length;){$c=$sql[$i];if(str_contains(" \t\r\n\v\f",$c)){++$i;continue;}
  if($c==="'"||$c==='"'){$q=$c;$value='';$closed=false;for(++$i;$i<$length;++$i){$c=$sql[$i];if($c==='\\'){if(++$i>=$length)return null;$value.=$sql[$i];continue;}if($c===$q){if(($sql[$i+1]??'')===$q){$value.=$q;++$i;continue;}$closed=true;++$i;break;}$value.=$c;}if(!$closed)return null;$out[]=['s',$value];continue;}
  if($c==='`'){$end=strpos($sql,'`',$i+1);if($end===false)return null;$name=substr($sql,$i+1,$end-$i-1);if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$name))return null;$out[]=['i',$name];$i=$end+1;continue;}
  if(preg_match('/\G([A-Za-z_][A-Za-z0-9_]*|[0-9]+)/A',$sql,$m,0,$i)){$out[]=[preg_match('/\A[0-9]+\z/D',$m[1])?'n':'i',$m[1]];$i+=strlen($m[1]);continue;}
  if(!str_contains('(),=;.*+-<>!@/',$c))return null;if(($c==='-'&&($sql[$i+1]??'')==='-')||($c==='/'&&($sql[$i+1]??'')==='*'))return null;$out[]=['p',$c];++$i;
 }if(end($out)===['p',';'])array_pop($out);foreach($out as$t)if($t===['p',';'])return null;return$out;
}
function cart_badge_where($tokens) {
 $out=[];$i=0;$length=count($tokens);
 while($i<$length){if(($tokens[$i][0]??'')!=='i'||($tokens[$i+1]??null)!==['p','=']||!in_array($tokens[$i+2][0]??'',['s','n'],true))return null;
  $key=strtolower($tokens[$i][1]);if(isset($out[$key]))return null;$out[$key]=$tokens[$i+2][1];$i+=3;if($i===$length)break;if(strtoupper($tokens[$i][1]??'')!=='AND')return null;++$i;
 }return$out?:null;
}
function cart_badge_runtime_sql_allowed($sql,$prefix,$uid,$meta_owner) {
 $t=cart_badge_sql_tokens($sql);if(!$t)return false;$verb=strtoupper($t[0][1]);
 if(in_array($verb,['SELECT','SHOW','DESCRIBE','DESC','EXPLAIN'],true)){
  foreach($t as$i=>$token)if($token[0]==='i'&&(in_array(strtoupper($token[1]),['OUTFILE','DUMPFILE','SLEEP','BENCHMARK'],true)||strtoupper($token[1])==='INTO'))return false;return true;
 }
 if(!is_int($uid)||$uid<1||!preg_match('/\A[A-Za-z0-9_]+\z/D',$prefix))return false;
 $table='';$values=null;$where=null;$sets=[];
 if(in_array($verb,['INSERT','REPLACE'],true)&&strtoupper($t[1][1]??'')==='INTO'){
  $table=$t[2][1]??'';$i=3;$columns=[];$row=[];if(($t[$i++]??null)!==['p','('])return false;
  do{if(($t[$i][0]??'')!=='i')return false;$columns[]=$t[$i++][1];$end=$t[$i++]??null;}while($end===['p',',']);
  if($end!==['p',')']||strtoupper($t[$i++][1]??'')!=='VALUES'||($t[$i++]??null)!==['p','('])return false;
  do{if(!in_array($t[$i][0]??'',['s','n'],true))return false;$row[]=$t[$i++][1];$end=$t[$i++]??null;}while($end===['p',',']);
  if($end!==['p',')']||count($columns)!==count($row)||count($columns)!==count(array_unique($columns)))return false;$values=array_combine($columns,$row);
  if($i<count($t)){
   $tail=implode(' ',array_column(array_slice($t,$i),1));
   if($table!==$prefix.'woocommerce_sessions'||!preg_match('/\AON DUPLICATE KEY UPDATE session_value = VALUES \( session_value \) , session_expiry = VALUES \( session_expiry \)\z/iD',$tail))return false;
  }
 }elseif($verb==='UPDATE'||($verb==='DELETE'&&strtoupper($t[1][1]??'')==='FROM')){
  $table=$t[$verb==='UPDATE'?1:2][1]??'';$start=$verb==='UPDATE'?2:3;$at=null;
  for($i=$start;$i<count($t);++$i)if(($t[$i][0]??'')==='i'&&strtoupper($t[$i][1])==='WHERE'){$at=$i;break;}
  if($at===null)return false;$where=cart_badge_where(array_slice($t,$at+1));if(!$where)return false;
  if($verb==='UPDATE'){
   if(strtoupper($t[$start++][1]??'')!=='SET')return false;
   for($i=$start;$i<$at;){if(($t[$i][0]??'')!=='i'||($t[$i+1]??null)!==['p','=']||!in_array($t[$i+2][0]??'',['s','n'],true))return false;$sets[]=$t[$i][1];$i+=3;if($i<$at&&($t[$i++]??null)!==['p',','])return false;}
  }elseif($at!==$start)return false;
 }else return false;
 if($table===$prefix.'woocommerce_sessions'){
  if($values!==null)return isset($values['session_key'],$values['session_value'],$values['session_expiry'])&&count($values)===3&&$values['session_key']===(string)$uid&&preg_match('/\A[0-9]+\z/D',$values['session_expiry'])===1;
  return $where===['session_key'=>(string)$uid]&&!array_diff($sets,['session_value','session_expiry']);
 }
 if($table!==$prefix.'usermeta')return false;
 $forbidden=['_petish_cart_badge_owned',$prefix.'capabilities',$prefix.'user_level'];
 if($values!==null)return array_keys($values)===['user_id','meta_key','meta_value']&&$values['user_id']===(string)$uid&&!in_array($values['meta_key'],$forbidden,true);
 if(array_diff($sets,['meta_value']))return false;
 if(isset($where['user_id'])&&$where['user_id']===(string)$uid&&count($where)===2&&isset($where['meta_key'])&&!in_array($where['meta_key'],$forbidden,true))return true;
 if(count($where)!==1||!isset($where['umeta_id'])||preg_match('/\A[0-9]+\z/D',$where['umeta_id'])!==1)return false;
 $owned=$meta_owner((int)$where['umeta_id']);return is_array($owned)&&($owned['user_id']??null)===$uid&&!in_array($owned['meta_key']??'', $forbidden,true);
}
function cart_badge_query_guard($sql) {
 if(isset($GLOBALS['cart_badge_exact_sql'])&&hash_equals($GLOBALS['cart_badge_exact_sql'],$sql))return$sql;
 global$wpdb;$uid=$GLOBALS['cart_badge_runtime_customer']??0;
 $owner=static function($id)use($wpdb){$row=$wpdb->get_row($wpdb->prepare("SELECT user_id,meta_key FROM `{$wpdb->usermeta}` WHERE umeta_id=%d",$id),ARRAY_A);if(!$row||$wpdb->last_error)return null;return['user_id'=>(int)$row['user_id'],'meta_key'=>$row['meta_key']];};
 return cart_badge_runtime_sql_allowed($sql,$wpdb->prefix??'',(int)$uid,$owner)?$sql:'SELECT 0 WHERE 1=0';
}
function cart_badge_query($sql) {
 global$wpdb;$GLOBALS['cart_badge_exact_sql']=$sql;
 try{$value=$wpdb->query($sql);if($value===false||$wpdb->last_error!=='')cart_badge_fail();return$value;}
 finally{unset($GLOBALS['cart_badge_exact_sql']);}
}
function cart_badge_isolate() {
 $no=static fn()=>false;$yes=static fn()=>true;$zero=static fn()=>0;$http=static fn()=>new WP_Error('petish_cart_fixture_http_blocked','Staging cart verification blocks outbound requests.');
 $smtp=static function(){throw new \PHPMailer\PHPMailer\Exception('Private staging cart fixture blocks delivery.');};
 $async=static function(){remove_all_actions('action_scheduler_run_queue');if(class_exists('ActionScheduler_QueueRunner',false)&&is_callable([ActionScheduler_QueueRunner::instance(),'unhook_dispatch_async_request']))ActionScheduler_QueueRunner::instance()->unhook_dispatch_async_request();};
 $map=['query'=>['cart_badge_query_guard',1],'pre_http_request'=>[$http,3],'pre_wp_mail'=>[$yes,2],'phpmailer_init'=>[$smtp,1],
  'jetpack_sync_listener_should_load'=>[$no,1],'jetpack_sync_sender_should_load'=>[$no,1],'pre_option_jetpack_sync_settings_disable'=>[$yes,1],
  'action_scheduler_allow_async_request_runner'=>[$no,1],'action_scheduler_queue_runner_concurrent_batches'=>[$zero,1],
  'pre_as_enqueue_async_action'=>[$zero,1],'pre_as_schedule_single_action'=>[$zero,1],'pre_as_schedule_recurring_action'=>[$zero,1],'pre_as_schedule_cron_action'=>[$zero,1]];
 foreach($map as$hook=>$entry)add_filter($hook,$entry[0],PHP_INT_MAX,$entry[1]);
 add_action('wp_loaded',$async,PHP_INT_MAX);add_action('all',static function($hook)use($map,$async){if($hook==='shutdown')$async();if(isset($map[$hook])){remove_filter($hook,$map[$hook][0],PHP_INT_MAX);add_filter($hook,$map[$hook][0],PHP_INT_MAX,$map[$hook][1]);}},PHP_INT_MAX);
}
/** Drain native cart/session writes before cleanup, including PHP shutdown work. */
function cart_badge_lock($run) {
 global$wpdb;$name='petish-cart-'.substr(hash('sha256',CART_BADGE_ROOT.'|'.$run),0,32);
 if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,15)',$name))!=='1'||$wpdb->last_error!=='')cart_badge_fail();
 register_shutdown_function(static function()use($wpdb,$name){try{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));}catch(Throwable$ignored){}});
}
function cart_badge_store_request($path,$method,$body,$key,$depth=0) {
 if($depth>1||!is_array($body))return false;
 if($path==='/wc/store/v1/cart')return $method==='GET'&&!$body;
 if($path==='/wc/store/v1/cart/update-item')return $method==='POST'&&count($body)===2&&!array_diff(array_keys($body),['key','quantity'])&&($body['key']??null)===$key&&is_int($body['quantity']??null)&&$body['quantity']>=1&&$body['quantity']<=3;
 if($path==='/wc/store/v1/cart/remove-item')return $method==='POST'&&array_keys($body)===['key']&&$body['key']===$key;
 if($path!=='/wc/store/v1/batch'||$method!=='POST'||$depth!==0||array_diff(array_keys($body),['requests','validation'])||!is_array($body['requests']??null)||!array_is_list($body['requests'])||count($body['requests'])<1||count($body['requests'])>3||isset($body['validation'])&&$body['validation']!=='require-all-validate')return false;
 foreach($body['requests']as$item)if(!is_array($item)||array_diff(array_keys($item),['path','method','body','headers'])||!isset($item['path'],$item['method'])||!is_string($item['path'])||!is_string($item['method'])||!cart_badge_store_request($item['path'],$item['method'],$item['body']??[],$key,1)||isset($item['headers'])&&$item['headers']!==[])return false;return true;
}
/** Finite allowlist; WooCommerce retains its native nonce and cart validation. */
function cart_badge_request_allowed($state,$method,$path,$query,$post,$json,$uid) {
 if(!in_array($method,['GET','HEAD','POST'],true)||!is_array($query)||!is_array($post))return false;
 $customer=$state['customer'];$product=$state['product'];$key=md5((string)$product['id']);$owned=$uid===$customer['id'];
 if($path==='/wp-login.php'){
  if($method!=='POST')return !array_diff(array_keys($query),['redirect_to','reauth'])&&(!isset($query['redirect_to'])||in_array($query['redirect_to'],['https://staging.petish.biz/my-account/','/my-account/'],true))&&(!isset($query['reauth'])||$query['reauth']==='1');
  return !$query&&!array_diff(array_keys($post),['log','pwd','wp-submit','redirect_to','testcookie','rememberme'])&&($post['log']??null)===$customer['login']&&is_string($post['pwd']??null)&&hash_equals($customer['password'],$post['pwd'])&&(!isset($post['redirect_to'])||in_array($post['redirect_to'],['https://staging.petish.biz/my-account/','/my-account/'],true));
 }
 // Anonymous privacy check never receives permission to persist a session.
 if(!$owned)return $uid===0&&$path==='/'&&$query===['wc-ajax'=>'petish_cart_count']&&$method==='POST'&&!$post;
 if($path==='/'&&array_keys($query)===['wc-ajax']){
  if($method!=='POST')return false;$action=$query['wc-ajax'];
  if(in_array($action,['petish_cart_count','get_refreshed_fragments'],true))return !$post;
  if($action==='add_to_cart')return !array_diff(array_keys($post),['product_id','quantity'])&&($post['product_id']??null)===(string)$product['id']&&preg_match('/\A[1-3]\z/D',(string)($post['quantity']??''));
  if($action==='remove_from_cart')return array_keys($post)===['cart_item_key']&&$post['cart_item_key']===$key;return false;
 }
 if(str_starts_with($path,'/wp-json/'))return !array_diff(array_keys($query),['_locale'])&&(!isset($query['_locale'])||$query['_locale']==='user')&&cart_badge_store_request(substr($path,8),$method,$method==='POST'?$json:[],$key);
 if($method!=='POST'){
  if($path==='/cart/'&&isset($query['remove_item']))return !array_diff(array_keys($query),['remove_item','_wpnonce'])&&$query['remove_item']===$key&&is_string($query['_wpnonce']??null)&&preg_match('/\A[A-Za-z0-9]{8,20}\z/D',$query['_wpnonce']);
  return !$query&&in_array($path,['/','/my-account/','/cart/',$product['path']],true);
 }
 if($path===$product['path'])return !$query&&!array_diff(array_keys($post),['add-to-cart','quantity'])&&($post['add-to-cart']??null)===(string)$product['id']&&preg_match('/\A[1-3]\z/D',(string)($post['quantity']??''));
 if($path!=='/cart/'||$query||array_diff(array_keys($post),['cart','update_cart','woocommerce-cart-nonce','_wp_http_referer','coupon_code'])||!isset($post['update_cart'])||!is_array($post['cart']??null)||array_keys($post['cart'])!==[$key]||array_keys($post['cart'][$key])!==['qty']||!preg_match('/\A[1-3]\z/D',(string)$post['cart'][$key]['qty'])||!is_string($post['woocommerce-cart-nonce']??null)||!preg_match('/\A[A-Za-z0-9]{8,20}\z/D',$post['woocommerce-cart-nonce'])||isset($post['coupon_code'])&&$post['coupon_code']!==''||isset($post['_wp_http_referer'])&&$post['_wp_http_referer']!=='/cart/')return false;
 return true;
}
function cart_badge_state($work,$run) {
 $s=cart_badge_read($work.'/journal.json');
 if(($s['format']??null)!=='petish-cart-badge-fixture-v1'||($s['run']??null)!==$run||!preg_match('/\A[a-f0-9]{32}\z/D',$s['token']??'')||!is_array($s['before']??null)||!is_array($s['customer']??null)||!is_array($s['product']??null)||!is_int($s['created']??null)||!is_int($s['expires']??null))cart_badge_fail();
 $u=$s['customer'];$p=$s['product'];if(!is_int($u['id']??null)||$u['id']<0||($u['login']??'')!=='petish-cart-'.$s['token']||($u['email']??'')!==$u['login'].'@example.invalid'||!preg_match('/\A[a-f0-9]{48}\z/D',$u['password']??'')||!is_int($p['id']??null)||$p['id']<0||($p['slug']??'')!=='petish-cart-'.$s['token']||!is_string($p['path']??null))cart_badge_fail();return$s;
}
function cart_badge_web_start($run,$work,$script_hash) {
 try{
  if(!defined('ABSPATH')||rtrim(ABSPATH,'/')!==CART_BADGE_ROOT||wp_get_environment_type()!=='staging'||!defined('PETISH_STAGING_GUARD_ACTIVE')||PETISH_STAGING_GUARD_ACTIVE!==true||!defined('WCPAY_DEV_MODE')||WCPAY_DEV_MODE!==true||!defined('DISABLE_WP_CRON')||DISABLE_WP_CRON!==true||!hash_equals($script_hash,hash_file('sha256',__FILE__)))cart_badge_fail();
  [$tools,$expected]=cart_badge_paths($run);if($work!==$expected)cart_badge_fail();$s=cart_badge_state($work,$run);$guard=CART_BADGE_ROOT.'/wp-content/mu-plugins/zz-petish-cart-badge-fixture.php';cart_badge_path($guard,false,0600);
  if(!is_string($s['guard_hash']??null)||!hash_equals($s['guard_hash'],hash_file('sha256',$guard)))cart_badge_fail();
  $GLOBALS['cart_badge_runtime_customer']=0;cart_badge_isolate();
  if(wp_using_ext_object_cache()===true)cart_badge_fail();
  cart_badge_lock($run);$s=cart_badge_state($work,$run);
  add_action('init',static function()use($s,$run){
   $deny=static function(){if(!headers_sent()){status_header(403);nocache_headers();header('Content-Type: text/plain');}exit('Private staging cart fixture request denied.');};
   if(!empty($s['closing'])||!empty($s['cleaned'])||time()>$s['expires']||time()<$s['created']-60)$deny();
   $uri=parse_url($_SERVER['REQUEST_URI']??'');if(!is_array($uri)||isset($uri['host'],$uri['scheme'])||!is_string($uri['path']??null))$deny();
   $body=[];if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&str_starts_with($uri['path'],'/wp-json/')){$raw=file_get_contents('php://input',false,null,0,65537);if(!is_string($raw)||strlen($raw)>65536)$deny();$body=json_decode($raw,true);if(!is_array($body))$deny();}
   $uid=(int)wp_validate_auth_cookie('','logged_in');
   if(!cart_badge_request_allowed($s,$_SERVER['REQUEST_METHOD']??'GET',$uri['path'],$_GET,$_POST,$body,$uid))$deny();
   // Native login owns its new authentication token; all other identities stay read-only.
   $GLOBALS['cart_badge_runtime_customer']=$uid===$s['customer']['id']||($uri['path']==='/wp-login.php'&&($_SERVER['REQUEST_METHOD']??'')==='POST')?$s['customer']['id']:0;
   if(!headers_sent()){header('X-Petish-Cart-Fixture: '.$run);nocache_headers();header('Cache-Control: private, no-store, max-age=0');}
  },-PHP_INT_MAX);
  add_filter('woocommerce_add_to_cart_validation',static fn($valid,$id,$quantity)=>$valid&&(int)$id===$s['product']['id']&&$quantity>=1&&$quantity<=3,PHP_INT_MAX,3);
  add_filter('woocommerce_update_cart_validation',static fn($valid,$key,$item,$quantity)=>$valid&&(int)($item['product_id']??0)===$s['product']['id']&&$quantity>=0&&$quantity<=3,PHP_INT_MAX,4);
  add_filter('woocommerce_payment_gateways',static fn()=>[],PHP_INT_MAX);
  add_action('woocommerce_checkout_create_order',static function(){cart_badge_fail();},-PHP_INT_MAX);
 }catch(Throwable$ignored){if(!headers_sent())http_response_code(403);exit('Private staging cart fixture unavailable.');}
}
function cart_badge_protected() {
 $out=[];foreach([CART_BADGE_ROOT.'/wp-config.php',CART_BADGE_HOME.'/.petish-staging/config.php']as$p){cart_badge_path($p);$out[$p]=hash_file('sha256',$p);}
 foreach(new DirectoryIterator(CART_BADGE_ROOT.'/wp-content/mu-plugins')as$item){if($item->isDot()||$item->getFilename()==='zz-petish-cart-badge-fixture.php')continue;$p=$item->getPathname();if($item->isDir())continue;cart_badge_path($p);$out[$p]=hash_file('sha256',$p);}ksort($out);return$out;
}
function cart_badge_snapshot() {
 global$wpdb;$state=petish_club_verify_state(CART_BADGE_ROOT);
 $state['sessions']=petish_club_verify_table_state($wpdb->prefix.'woocommerce_sessions');
 $state['comments']=[petish_club_verify_table_state($wpdb->comments),petish_club_verify_table_state($wpdb->commentmeta)];
 $state['protected']=cart_badge_protected();return$state;
}
function cart_badge_guard_install(&$s,$work,$tools) {
 $path=CART_BADGE_ROOT.'/wp-content/mu-plugins/zz-petish-cart-badge-fixture.php';if(file_exists($path)||is_link($path))cart_badge_fail();
 $source=$tools.'/cart-badge-staging-proof.php';cart_badge_path($source);$hash=hash_file('sha256',$source);
 $code="<?php\n// Owned private staging cart fixture.\nif (PHP_SAPI === 'cli') return;\nrequire_once ".var_export($source,true).";\ncart_badge_web_start(".var_export($s['run'],true).','.var_export($work,true).','.var_export($hash,true).");\n";
 $s['guard_hash']=hash('sha256',$code);cart_badge_write($work.'/journal.json',$s);
 $pending=$work.'/guard.pending';if(file_exists($pending)||is_link($pending))cart_badge_fail();$f=fopen($pending,'xb');if(!$f)cart_badge_fail();try{if(!chmod($pending,0600)||fwrite($f,$code)!==strlen($code)||!fflush($f))cart_badge_fail();}finally{fclose($f);}
 if(!hash_equals($s['guard_hash'],hash_file('sha256',$pending))||file_exists($path)||is_link($path)||!link($pending,$path))cart_badge_fail();
 if(!unlink($pending))cart_badge_fail();
 cart_badge_mark('browser_guard_installed',hash_equals($s['guard_hash'],hash_file('sha256',$path)));
}
function cart_badge_prepare($run,$work,$tools) {
 global$wpdb;if(file_exists($work.'/journal.json')||is_link($work.'/journal.json')||file_exists($work.'/browser-input.json')||is_link($work.'/browser-input.json'))cart_badge_fail();
 $token=bin2hex(random_bytes(16));$s=['format'=>'petish-cart-badge-fixture-v1','run'=>$run,'token'=>$token,'created'=>time(),'expires'=>time()+3600,'before'=>cart_badge_snapshot(),
  'customer'=>['id'=>0,'login'=>'petish-cart-'.$token,'email'=>'petish-cart-'.$token.'@example.invalid','password'=>bin2hex(random_bytes(24))],
  'product'=>['id'=>0,'slug'=>'petish-cart-'.$token,'path'=>''],'guard_hash'=>null,'cleaned'=>false];
 cart_badge_write($work.'/journal.json',$s);cart_badge_mark('fixture_journal_written',true);
 $names=[$wpdb->users,$wpdb->usermeta,$wpdb->posts,$wpdb->postmeta,$wpdb->term_relationships,$wpdb->prefix.'woocommerce_sessions'];
 foreach($names as$table){$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));if($engine!=='InnoDB')cart_badge_fail();}
 $triggers=$wpdb->get_results('SHOW TRIGGERS',ARRAY_A);if($wpdb->last_error!==''||!is_array($triggers)||$triggers)cart_badge_fail();
 $u=$s['customer'];if(username_exists($u['login'])||email_exists($u['email'])||get_page_by_path($s['product']['slug'],OBJECT,'product'))cart_badge_fail();
 $taxonomy=[];foreach([['product_type','simple'],['product_visibility','exclude-from-catalog'],['product_visibility','exclude-from-search']]as[$tax,$slug]){$term=get_term_by('slug',$slug,$tax);if(!$term||is_wp_error($term))cart_badge_fail();$taxonomy[]=(int)$term->term_taxonomy_id;}
 $transaction=false;
 try{
  cart_badge_query('START TRANSACTION');$transaction=true;$registered=gmdate('Y-m-d H:i:s');
  cart_badge_query($wpdb->prepare("INSERT INTO `{$wpdb->users}` (user_login,user_pass,user_nicename,user_email,user_url,user_registered,user_activation_key,user_status,display_name) VALUES (%s,%s,%s,%s,'',%s,'',0,'Petish cart fixture')",$u['login'],wp_hash_password($u['password']),$u['login'],$u['email'],$registered));
  $uid=(int)$wpdb->insert_id;if(!$uid)cart_badge_fail();$s['customer']['id']=$uid;
  foreach([$wpdb->prefix.'capabilities'=>serialize(['customer'=>true]),$wpdb->prefix.'user_level'=>'0','_petish_cart_badge_owned'=>$token]as$key=>$value)cart_badge_query($wpdb->prepare("INSERT INTO `{$wpdb->usermeta}` (user_id,meta_key,meta_value) VALUES (%d,%s,%s)",$uid,$key,$value));
  cart_badge_query($wpdb->prepare("INSERT INTO `{$wpdb->posts}` (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) VALUES (%d,%s,%s,'','Petish isolated cart fixture','','publish','closed','closed','',%s,'','',%s,%s,'',0,%s,0,'product','',0)",$uid,$registered,$registered,$s['product']['slug'],$registered,$registered,'https://staging.petish.biz/?petish-cart-fixture='.$token));
  $pid=(int)$wpdb->insert_id;if(!$pid)cart_badge_fail();$s['product']['id']=$pid;
  foreach(['_petish_cart_badge_owned'=>$token,'_regular_price'=>'1','_price'=>'1','_virtual'=>'yes','_downloadable'=>'no','_manage_stock'=>'yes','_stock'=>'20','_stock_status'=>'instock','_backorders'=>'no','_sold_individually'=>'no','_tax_status'=>'none']as$key=>$value)cart_badge_query($wpdb->prepare("INSERT INTO `{$wpdb->postmeta}` (post_id,meta_key,meta_value) VALUES (%d,%s,%s)",$pid,$key,$value));
  foreach($taxonomy as$id)cart_badge_query($wpdb->prepare("INSERT INTO `{$wpdb->term_relationships}` (object_id,term_taxonomy_id,term_order) VALUES (%d,%d,0)",$pid,$id));
  $s['product']['path']=(string)parse_url(get_permalink($pid),PHP_URL_PATH);if(!str_starts_with($s['product']['path'],'/product/')||!str_contains($s['product']['path'],$s['product']['slug']))cart_badge_fail();
  cart_badge_write($work.'/journal.json',$s);cart_badge_query('COMMIT');$transaction=false;
 }finally{if($transaction)cart_badge_query('ROLLBACK');}
 $user=get_user_by('id',$uid);$product=wc_get_product($pid);
 cart_badge_mark('owned_customer_created',$user&&$user->roles===['customer']&&get_user_meta($uid,'_petish_cart_badge_owned',true)===$token);
 cart_badge_mark('owned_product_created',$product&&$product->is_type('simple')&&$product->get_status()==='publish'&&get_post_meta($pid,'_petish_cart_badge_owned',true)===$token);
 cart_badge_mark('virtual_product_stock_verified',$product->is_virtual()&&$product->get_stock_quantity()===20&&(float)$product->get_price()===1.0&&!$product->needs_shipping());
 cart_badge_mark('product_hidden_from_catalogue',$product->get_catalog_visibility()==='hidden');
 $mode=class_exists('WC_Payments')?WC_Payments::mode():null;$service=is_callable(['WC_Payments','get_account_service'])?WC_Payments::get_account_service():null;
 $cached=is_callable([$service,'get_cached_account_data'])?$service->get_cached_account_data():null;if(!is_array($cached)||!$cached){$raw=get_option('wcpay_account_data',[]);$cached=is_array($raw['data']??null)?$raw['data']:[];}
 cart_badge_mark('test_mode_verified',is_object($mode)&&is_callable([$mode,'is_dev'])&&is_callable([$mode,'is_test'])&&$mode->is_dev()===true&&$mode->is_test()===true&&($cached['is_live']??null)===false);
 cart_badge_guard_install($s,$work,$tools);
 $input=['format'=>'petish-cart-badge-browser-v1','url'=>'https://staging.petish.biz','run'=>$run,'customer_id'=>$uid,'login'=>$u['login'],'password'=>$u['password'],'customer_email'=>$u['email'],'product_id'=>$pid,'product_url'=>'https://staging.petish.biz'.$s['product']['path'],'cart_key'=>md5((string)$pid),
  'safety'=>['environment_staging'=>true,'woocommerce_test_mode'=>true,'wcpay_dev_mode'=>true,'account_is_live'=>false,'guard_runtime_verified'=>true,'suppliers_blocked'=>true,'mail_capture_verified'=>true,'webhooks_blocked'=>true]];
 cart_badge_write($work.'/browser-input.json',$input);cart_badge_mark('browser_input_written',true);
}
function cart_badge_cleanup($run,$work) {
 global$wpdb;$guard=CART_BADGE_ROOT.'/wp-content/mu-plugins/zz-petish-cart-badge-fixture.php';$input=$work.'/browser-input.json';
 $no_journal=!file_exists($work.'/journal.json')&&!is_link($work.'/journal.json');
 if($no_journal){
  if(file_exists($guard)||is_link($guard)||file_exists($input)||is_link($input))cart_badge_fail();
  // No database write can precede the first journal publication.
  $s=['before'=>cart_badge_snapshot(),'customer'=>['id'=>0,'login'=>'','email'=>'','password'=>''],
   'product'=>['id'=>0,'slug'=>''],'token'=>'','guard_hash'=>null];
 }else{$s=cart_badge_state($work,$run);$s['closing']=true;cart_badge_write($work.'/journal.json',$s);}
 $u=$s['customer'];$p=$s['product'];$user=$u['id']?get_user_by('login',$u['login']):false;$product=$p['id']?get_post($p['id']):null;
 if(!$user&&$u['id']&&get_user_by('id',$u['id']))cart_badge_fail();
 if($user&&((int)$user->ID!==$u['id']||$user->user_email!==$u['email']||!wp_check_password($u['password'],$user->user_pass,$user->ID)||$user->roles!==['customer']||get_user_meta($user->ID,'_petish_cart_badge_owned',true)!==$s['token']))cart_badge_fail();
 if($product&&($product->post_type!=='product'||$product->post_name!==$p['slug']||(int)$product->post_author!==$u['id']||get_post_meta($p['id'],'_petish_cart_badge_owned',true)!==$s['token']))cart_badge_fail();
 cart_badge_mark('fixture_ownership_verified',true);
 $session=$wpdb->prefix.'woocommerce_sessions';if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$session))cart_badge_fail();
 $transaction=false;
 try{
 cart_badge_query('START TRANSACTION');$transaction=true;
 if($u['id']>0&&$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($session)))===$session)cart_badge_query($wpdb->prepare("DELETE FROM `{$session}` WHERE session_key=%s",(string)$u['id']));
 cart_badge_mark('owned_session_removed',$u['id']===0||!$wpdb->get_var($wpdb->prepare("SELECT session_key FROM `{$session}` WHERE session_key=%s",(string)$u['id'])));
 if($product){if($wpdb->get_var($wpdb->prepare("SELECT comment_ID FROM `{$wpdb->comments}` WHERE comment_post_ID=%d LIMIT 1",$p['id'])))cart_badge_fail();
  foreach([$wpdb->term_relationships=>'object_id',$wpdb->postmeta=>'post_id',$wpdb->posts=>'ID']as$table=>$key)cart_badge_query($wpdb->prepare("DELETE FROM `{$table}` WHERE `{$key}`=%d",$p['id']));
  clean_post_cache($p['id']);}
 cart_badge_mark('owned_product_removed',!$p['id']||!$wpdb->get_var($wpdb->prepare("SELECT ID FROM `{$wpdb->posts}` WHERE ID=%d",$p['id'])));
 if($user){cart_badge_query($wpdb->prepare("DELETE FROM `{$wpdb->usermeta}` WHERE user_id=%d",$u['id']));cart_badge_query($wpdb->prepare("DELETE FROM `{$wpdb->users}` WHERE ID=%d",$u['id']));clean_user_cache($u['id']);}
 cart_badge_mark('owned_customer_removed',!get_user_by('login',$u['login']));
 $after=cart_badge_snapshot();$before=$s['before'];petish_club_verify_state_equal($before,$after);
 cart_badge_mark('existing_sessions_unchanged',$before['sessions']===$after['sessions']);cart_badge_mark('comments_unchanged',$before['comments']===$after['comments']);cart_badge_mark('protected_files_unchanged',$before['protected']===$after['protected']);
 cart_badge_query('COMMIT');$transaction=false;
 }finally{if($transaction)cart_badge_query('ROLLBACK');}
 // Interrupted link publication can leave exactly two names for this same
 // complete, journal-attested inode. Remove only its owned private name first.
 $pending=$work.'/guard.pending';
 if(file_exists($guard)&&file_exists($pending)&&!is_link($guard)&&!is_link($pending)){
  $a=lstat($guard);$b=lstat($pending);
  if($a['dev']!==$b['dev']||$a['ino']!==$b['ino']||$a['nlink']!==2||($a['mode']&07777)!==0600||!is_string($s['guard_hash'])||!hash_equals($s['guard_hash'],hash_file('sha256',$guard))||!unlink($pending))cart_badge_fail();
 }
 if(file_exists($guard)||is_link($guard)){cart_badge_path($guard,false,0600);if(!is_string($s['guard_hash'])||!hash_equals($s['guard_hash'],hash_file('sha256',$guard))||!unlink($guard))cart_badge_fail();}
 if(file_exists($pending)||is_link($pending)){$st=cart_badge_path($pending,false,0600);if($st['size']>16384||!unlink($pending))cart_badge_fail();}
 cart_badge_mark('browser_guard_removed',!file_exists($guard)&&!is_link($guard));
 if(file_exists($input)||is_link($input)){cart_badge_path($input,false,0600);$value=cart_badge_read($input);if(($value['run']??null)!==$run||($value['customer_id']??null)!==$u['id']||($value['product_id']??null)!==$p['id']||!unlink($input))cart_badge_fail();}
 cart_badge_mark('browser_input_removed',!file_exists($input)&&!is_link($input));
 if(!$no_journal){$s['cleaned']=true;cart_badge_write($work.'/journal.json',$s);}cart_badge_mark('fixture_cleanup_verified',true);
}
function cart_badge_main($argv) {
 if(PHP_SAPI!=='cli')return 1;define('PETISH_CLUB_VERIFY_LIBRARY',true);require_once __DIR__.'/verify-pet-club.php';
 global$report;petish_club_verify_report_start();
 try{
  if(count($argv)!==3||!in_array($argv[1],['prepare','cleanup'],true))cart_badge_fail();[$script,$phase,$run]=$argv;
  [$tools,$work]=cart_badge_paths($run,true);cart_badge_mark('fixed_fixture_paths',true);
  $defs=cart_badge_configuration();cart_badge_mark('configuration_isolated',true);
  $GLOBALS['cart_badge_runtime_customer']=0;require_once CART_BADGE_ROOT.'/wp-includes/plugin.php';
  // CLI uses the established bootstrap HTTP/mail checks; only our SQL barrier
  // is added here so the expected finite error codes remain unchanged.
  add_filter('query','cart_badge_query_guard',PHP_INT_MAX);add_action('all',static function($hook){if($hook==='query'){remove_filter('query','cart_badge_query_guard',PHP_INT_MAX);add_filter('query','cart_badge_query_guard',PHP_INT_MAX);}},PHP_INT_MAX);
  $root=petish_club_verify_bootstrap('staging',false);if($root!==CART_BADGE_ROOT||DB_NAME!==$defs['DB_NAME']||DB_HOST!==$defs['DB_HOST'])cart_badge_fail();cart_badge_lock($run);
  $report['category']=$phase==='prepare'?'fixture':'cleanup';
  if($phase==='prepare')cart_badge_prepare($run,$work,$tools);else cart_badge_cleanup($run,$work);
  $expected=$phase==='prepare'?CART_BADGE_FIXTURE_CHECKS:CART_BADGE_CLEANUP_CHECKS;
  if(array_diff($expected,array_keys($report['checks']))||array_diff(array_keys($report['checks']),$expected))cart_badge_fail();
  $report['status']='success';$report['category']='complete';
 }catch(Throwable$ignored){$report['status']='failure';}
 return$report['status']==='success'?0:1;
}
if(PHP_SAPI==='cli'&&!defined('CART_BADGE_PROOF_LIBRARY'))exit(cart_badge_main($argv));
