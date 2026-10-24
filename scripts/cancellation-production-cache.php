<?php
/** Run-owned, signed HTTP purge for only cancellation/review page and CSS URLs. */
if (PHP_SAPI !== 'cli') exit(1);

function cxl_release_cache_path($root) {
    return $root . '/wp-content/mu-plugins/zz-petish-cancellation-cache.php';
}
function cxl_release_cache_receipt($release, $run) {
    $record = cxl_release_json($release . '/cache.json');
    if (!is_array($record) || array_keys($record) !== array('run_id','sha256') || $record['run_id'] !== $run
        || !is_string($record['sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $record['sha256']) !== 1) cxl_release_fail();
    return $record;
}
function cxl_release_cache_code($run) {
    if (!is_string($run) || preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D', $run) !== 1) cxl_release_fail();
    $inspection = cxl_release_json(__DIR__ . '/inspection.json');
    if (($inspection['run'] ?? null) !== $run || !is_int($inspection['delivery_id'] ?? null) || $inspection['delivery_id'] < 1) cxl_release_fail();
    $token = __DIR__ . '/.probe-token'; cxl_release_path($token);
    $secret = file_get_contents($token);
    if ((fileperms($token) & 07777) !== 0600 || !is_string($secret) || preg_match('/\A[a-f0-9]{64}\n\z/D', $secret) !== 1) cxl_release_fail();
    $created = filemtime($token);
    if (!is_int($created) || $created < 1) cxl_release_fail();
    $hash = hash('sha256', trim($secret)); unset($secret);
    $prefix = "<?php\n// Temporary signed cancellation cache probe; normal requests are unchanged.\n(function(){\n";
    $prefix .= "if (PHP_SAPI === 'cli' || !defined('ABSPATH') || ABSPATH !== '/home/u839205597/domains/petish.biz/public_html/' || !function_exists('wp_get_environment_type') || wp_get_environment_type() !== 'production' || (\$_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || count(\$_GET) !== 2 || parse_url(\$_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/wp-admin/admin-post.php' || (\$_GET['action'] ?? null) !== 'petish_cancellation_release' || (\$_GET['run'] ?? null) !== '" . $run . "' || time() < " . ($created - 300) . " || time() > " . ($created + 3600) . ") return;\n";
    $prefix .= "\$token = \$_SERVER['HTTP_X_PETISH_RELEASE'] ?? ''; if (!is_string(\$token) || preg_match('/\\A[a-f0-9]{64}\\z/D', \$token) !== 1 || !hash_equals('" . $hash . "', hash('sha256', \$token)) || !empty(\$_SERVER['HTTP_COOKIE'])) return; unset(\$token);\n";
    $prefix .= "\$delivery_id = " . $inspection['delivery_id'] . ";\n";
    return $prefix . <<<'PHP'
if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
$no=static fn()=>false;$yes=static fn()=>true;$zero=static fn()=>0;
$http=static fn()=>new WP_Error('petish_cancellation_release_probe','Outbound requests are blocked for this release probe.');
$read=static function($sql){
 $sql=preg_replace('~\A(?:\s+|/\*.*?\*/|--[^\r\n]*(?:\r?\n|$)|\#[^\r\n]*(?:\r?\n|$))*~s','',$sql);
 return is_string($sql)&&strlen($sql)<=8388608&&!str_contains($sql,"\0")&&preg_match('/\A(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i',$sql)
  &&!preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i',$sql)&&!str_contains(rtrim($sql,"; \r\n\t"),';')?$sql:'SELECT 0 WHERE 1=0';
};
$hooks=['query'=>[$read,1],'pre_http_request'=>[$http,3],'pre_wp_mail'=>[$yes,2],
 'jetpack_sync_listener_should_load'=>[$no,1],'jetpack_sync_sender_should_load'=>[$no,1],
 'pre_option_jetpack_sync_settings_disable'=>[$yes,1],'action_scheduler_allow_async_request_runner'=>[$no,1],
 'action_scheduler_queue_runner_concurrent_batches'=>[$zero,1],'litespeed_conf_load_option_purge-post_all'=>[$no,1]];
foreach($hooks as$hook=>$entry)add_filter($hook,$entry[0],PHP_INT_MAX,$entry[1]);
add_action('all',static function($hook)use($hooks){if(isset($hooks[$hook])){remove_filter($hook,$hooks[$hook][0],PHP_INT_MAX);add_filter($hook,$hooks[$hook][0],PHP_INT_MAX,$hooks[$hook][1]);}},PHP_INT_MAX);
add_action('phpmailer_init',static function(){throw new RuntimeException('Mail transport is disabled for this release probe.');},PHP_INT_MAX);
add_action('wp_loaded',static function()use($delivery_id){
 remove_all_actions('action_scheduler_run_queue');
 $good=class_exists('LiteSpeed\Purge')&&class_exists('LiteSpeed\Tag')&&defined('LSWCP_TAG_PREFIX')
  &&is_string(LSWCP_TAG_PREFIX)&&preg_match('/\A[a-f0-9]{3}\z/D',LSWCP_TAG_PREFIX)===1&&!headers_sent()&&!defined('LITESPEED_CLI');
 $paths=['/delivery-shipping/','/my-account/','/my-account/?my-reviews=1',
  '/wp-content/plugins/petish-fulfilment/assets/cancellation-faq.css',
  '/wp-content/plugins/petish-fulfilment/assets/order-cancellation.css',
  '/wp-content/plugins/petish-fulfilment/assets/shipment-tracking.css',
  '/wp-content/plugins/petish-passport/assets/customer-reviews.css'];
 $allowed=[];
 try{
  if($good){
   $purger=LiteSpeed\Purge::cls();
   $good=is_callable([$purger,'purge_url'])&&is_callable(['LiteSpeed\Tag','get_uri_tag']);
  }
  if($good){
   global $wpdb;
   if(!is_object($wpdb)||!is_string($wpdb->posts)||!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->posts))throw new RuntimeException();
   $row=$wpdb->get_row($wpdb->prepare("SELECT ID,post_name,post_type,post_status,post_content FROM `{$wpdb->posts}` WHERE ID=%d AND post_name=%s AND post_type='page' AND post_status='publish'",$delivery_id,'delivery-shipping'),ARRAY_A);
   if(!is_array($row)||(int)($row['ID']??0)!==$delivery_id||($row['post_name']??null)!=='delivery-shipping'||($row['post_type']??null)!=='page'||($row['post_status']??null)!=='publish'||!is_string($row['post_content']??null)||strlen($row['post_content'])>1048576)throw new RuntimeException();
   // Refresh only this known page's object cache after the exact SQL FAQ append.
   // Site-specific invalidation hooks cannot broaden the later HTTP purge.
   remove_all_actions('clean_post_cache');remove_all_actions('clean_page_cache');remove_all_actions('clean_object_term_cache');
   clean_post_cache($delivery_id);
   $post=get_post($delivery_id);
   if(!$post instanceof WP_Post||(int)$post->ID!==$delivery_id||$post->post_name!=='delivery-shipping'||$post->post_content!==$row['post_content'])throw new RuntimeException();
   remove_all_filters('litespeed_purge_tags');remove_all_actions('litespeed_purged_link');
   foreach($paths as$path){
    $tag=LiteSpeed\Tag::get_uri_tag($path);
    if(!is_string($tag)||preg_match('/\AURL\.[a-f0-9]{32}\z/D',$tag)!==1)throw new RuntimeException();
    $allowed[]=LSWCP_TAG_PREFIX.'_'.$tag;
    $purger->purge_url($path,false,true);
   }
   $purge=LiteSpeed\Purge::output();
   if(!is_string($purge)||strlen($purge)>4096||!preg_match('/\AX-LiteSpeed-Purge:\s*(.*)\z/iD',$purge,$match)||preg_match('/[\r\n*]/',$purge))throw new RuntimeException();
   $tags=array_map('trim',explode(',',$match[1]));
   if(array_shift($tags)!=='public'||!$tags)throw new RuntimeException();
   $expected=array_values(array_unique($allowed));$actual=array_values(array_unique($tags));sort($expected);sort($actual);
   if($actual!==$expected)throw new RuntimeException();
   header($purge);
  }
 }catch(Throwable$ignored){$good=false;}
 header('Cache-Control: private, no-store, max-age=0');header('Content-Type: text/plain; charset=UTF-8');
 header('X-Robots-Tag: noindex, nofollow, noarchive');header('X-Content-Type-Options: nosniff');
 header('X-Petish-Cancellation-Cache-Purge: '.($good?'dispatched':'failed'));
 echo "Petish cancellation release cache probe.\n";exit;
},PHP_INT_MAX);
})();
PHP;
}
function cxl_release_probe($root, $release, $run, $remove = false) {
    $path = cxl_release_cache_path($root); $receipt = $release . '/cache.json';
    if ($remove) {
        if (file_exists($receipt) || is_link($receipt)) {
            $record = cxl_release_cache_receipt($release, $run);
            if (file_exists($path) || is_link($path)) {
                cxl_release_path($path);
                if (!hash_equals($record['sha256'], hash_file('sha256', $path)) || !unlink($path)) cxl_release_fail();
            }
        } elseif (file_exists($path) || is_link($path)) cxl_release_fail();
        cxl_release_mark('cache_probe_removed'); return;
    }
    $code = cxl_release_cache_code($run); $digest = hash('sha256', $code);
    if (file_exists($receipt) || is_link($receipt)) {
        $record = cxl_release_cache_receipt($release, $run);
        if (!hash_equals($record['sha256'], $digest)) cxl_release_fail();
    } else {
        if (file_exists($path) || is_link($path)) cxl_release_fail();
        cxl_release_write($receipt, json_encode(array('run_id'=>$run,'sha256'=>$digest), JSON_THROW_ON_ERROR));
    }
    if (file_exists($path) || is_link($path)) {
        cxl_release_path($path);
        if (!hash_equals($digest, hash_file('sha256', $path))) cxl_release_fail();
    } else cxl_release_write($path, $code, 0644);
    cxl_release_mark('cache_probe_installed');
}
function cxl_release_ensure_probe($root, $release, $run) {
    cxl_release_probe($root, $release, $run);
}
function cxl_release_purge_ready($root, $release, $run) {
    $record = cxl_release_cache_receipt($release, $run); $path = cxl_release_cache_path($root);
    cxl_release_path($path);
    if (!hash_equals($record['sha256'], hash_file('sha256', $path))) cxl_release_fail();
    cxl_release_mark('cache_purge_probe_ready');
}
