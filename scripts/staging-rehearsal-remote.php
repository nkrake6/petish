<?php
/** Protected staging preflight only. No orders, payments or supplier purchases. */
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors', '0'); ini_set('log_errors', '0'); error_reporting(0);

const PETISH_REHEARSAL_GUARD_SHA256 = 'f7615da9180aff6b8b85d81f5821ed1bd4933970adeee3462dd252a502681ee6';
$report = [
    'status'=>'unavailable', 'category'=>'input', 'run_id'=>null,
    'checks'=>[
        'paths_verified'=>false, 'source_guard_sha_verified'=>false, 'database_separate'=>false,
        'runtime_database_staging'=>false, 'environment_staging'=>false, 'woocommerce_test_mode'=>false,
        'wcpay_dev_mode'=>false, 'account_is_live'=>null, 'guard_runtime_verified'=>false,
        'suppliers_blocked'=>false, 'mail_capture_verified'=>false, 'webhooks_blocked'=>false,
        'only_allowlisted_plugins'=>false, 'no_active_webhooks'=>false,
        'all_http_forced_blocked'=>false, 'browser_input_written'=>false,
        'loader_requires_private'=>false,'source_environment_staging'=>false,'source_wcpay_dev_mode'=>false,
        'source_wp_cron_disabled'=>false,'source_staging_database_literal'=>false,'source_production_database_literal'=>false,
        'source_production_database_read'=>false,
    ],
    'native'=>['mode_class'=>null,'mode_methods'=>[], 'account_service_class'=>null,'account_service_methods'=>[],
               'gateway_class'=>null,'gateway_methods'=>[], 'api_client_class'=>null,'api_client_methods'=>[], 'api_method_signatures'=>[], 'helper_method_calls'=>[]],
    'account'=>['cached_account_present'=>false,'account_is_live'=>null,'native_connected'=>null,
                'payments_enabled'=>null,'test_onboarding'=>null,'dev_mode'=>null,'test_mode'=>null,'gateway_test_mode'=>null],
    'structure'=>['credentials'=>[],'checkout_fixture'=>[],'captured_mail'=>[]], 'guard_allowed_hosts'=>[],
];
// A native staging denial may call exit instead of throwing. Keep all native
// output private and emit this finite report even on that shutdown path.
ob_start(static function($ignored,$phase)use(&$report){
    return ($phase&PHP_OUTPUT_HANDLER_FINAL)?json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n":'';
},0,0);

function rehearsal_fail() { throw new RuntimeException(); }
function rehearsal_path($path, $directory=false) {
    if (!is_string($path) || $path==='' || $path[0]!=='/' || strpos($path,"\0")!==false) return false;
    $cursor='';
    foreach (explode('/',substr($path,1)) as $part) {
        if ($part==='' || $part==='.' || $part==='..') return false;
        $cursor.='/'.$part;
        if (is_link($cursor) || realpath($cursor)!==$cursor) return false;
    }
    $stat=@lstat($path);
    return $stat && ($directory ? is_dir($path) : (is_file($path) && $stat['nlink']===1));
}
function rehearsal_tokens($source) {
    return array_values(array_filter(token_get_all($source,TOKEN_PARSE),static function($token) {
        return !is_array($token) || !in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true);
    }));
}
function rehearsal_string($token) {
    if (!is_array($token) || $token[0]!==T_CONSTANT_ENCAPSED_STRING) return null;
    $value=$token[1];
    if (strlen($value)<2 || !in_array($value[0],["'",'"'],true) || substr($value,-1)!==$value[0] || strpos($value,'\\')!==false) return null;
    return substr($value,1,-1);
}
function rehearsal_literal_defines($tokens, $wanted) {
    $found=[]; $mentions=[]; $depth=0;
    foreach ($tokens as $i=>$token) {
        if ($token==='{') $depth++; if ($token==='}') $depth--;
        $name=rehearsal_string($token);
        if (!in_array($name,$wanted,true)) continue;
        $mentions[$name]=($mentions[$name]??0)+1;
        $start=$i-2; $previous=$tokens[$start-1]??null;
        if ($depth!==0 || !is_array($tokens[$start]??null) || $tokens[$start][0]!==T_STRING || strtolower($tokens[$start][1])!=='define'
            || ($tokens[$i-1]??null)!=='(' || ($tokens[$i+1]??null)!==',' || ($tokens[$i+3]??null)!==')' || ($tokens[$i+4]??null)!==';') continue;
        if ($previous!==';' && !(is_array($previous)&&$previous[0]===T_OPEN_TAG)) continue;
        $raw=$tokens[$i+2]??null; $value=rehearsal_string($raw);
        if (is_array($raw)&&$raw[0]===T_STRING&&in_array(strtolower($raw[1]),['true','false'],true)) $value=strtolower($raw[1])==='true';
        if ($value!==null) $found[$name]=$value;
    }
    foreach ($wanted as $name) if (($mentions[$name]??0)!==1) unset($found[$name]);
    return $found;
}
function rehearsal_loader_requires($tokens, $private) {
    $depth=0; $count=0;
    foreach ($tokens as $i=>$token) {
        if ($token==='{') $depth++; if ($token==='}') $depth--;
        if (!is_array($token)||!in_array($token[0],[T_REQUIRE,T_REQUIRE_ONCE],true)) continue;
        $previous=$tokens[$i-1]??null;
        if ($depth===0 && ($previous===';'||(is_array($previous)&&$previous[0]===T_OPEN_TAG))
            && rehearsal_string($tokens[$i+1]??null)===$private && ($tokens[$i+2]??null)===';') $count++;
    }
    return $count===1;
}
function rehearsal_private_json($path) {
    if (!rehearsal_path($path) || (fileperms($path)&07777)!==0600 || filesize($path)>1048576) rehearsal_fail();
    $value=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if (!is_array($value)) rehearsal_fail();
    return $value;
}
function rehearsal_structure($value, $prefix='', $depth=0) {
    $out=[]; if (!is_array($value)||$depth>3) return $out;
    foreach ($value as $key=>$child) {
        if (!is_string($key)||!preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,79}\z/D',$key)) continue;
        $path=$prefix===''?$key:$prefix.'.'.$key;
        $type=gettype($child); $type=['integer'=>'int','double'=>'float','boolean'=>'bool','NULL'=>'null'][$type]??$type;
        $out[]=['path'=>$path,'type'=>$type];
        if (is_array($child)) $out=array_merge($out,rehearsal_structure($child,$path,$depth+1));
        if (count($out)>100) break;
    }
    return array_slice($out,0,100);
}
function rehearsal_public_methods($object) {
    if (!is_object($object)) return [];
    $names=array_filter(get_class_methods($object),static function($name){return preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,99}\z/D',$name);});
    sort($names); return array_slice(array_values($names),0,150);
}
function rehearsal_helper_calls($private) {
    $out=[];
    foreach (['verify-staging.php','verify-test-payments.php','verify-provider-test-payment.php'] as $name) {
        $path=$private.'/'.$name;
        if (!rehearsal_path($path)||filesize($path)>1048576) continue;
        $tokens=rehearsal_tokens(file_get_contents($path));
        foreach ($tokens as $i=>$token) {
            if (!is_array($token)||$token[0]!==T_STRING||($tokens[$i+1]??null)!=='('||!preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,99}\z/D',$token[1])) continue;
            $operator=$tokens[$i-1]??null;
            if (is_array($operator)&&$operator[0]===T_OBJECT_OPERATOR) $out[]='->'.$token[1];
            elseif (is_array($operator)&&$operator[0]===T_DOUBLE_COLON) {
                $class=$tokens[$i-2]??null;
                if (is_array($class)&&in_array($class[0],[T_STRING,T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED],true)&&preg_match('/\A[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,149}\z/D',$class[1])) $out[]=$class[1].'::'.$token[1];
            }
        }
    }
    $out=array_values(array_unique($out)); sort($out); return array_slice($out,0,150);
}
function rehearsal_write_private($path,$value) {
    if (file_exists($path)||is_link($path)) rehearsal_fail();
    $handle=fopen($path,'xb'); if (!$handle) rehearsal_fail();
    try { if (!chmod($path,0600)||fwrite($handle,$value)!==strlen($value)||!fflush($handle)) rehearsal_fail(); }
    finally { fclose($handle); }
}

try {
    if ($argc!==3 || $argv[1]!=='preflight' || !preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$argv[2])) rehearsal_fail();
    $run=$argv[2]; $report['run_id']=$run;
    $home=getenv('HOME'); if (!is_string($home)||!rehearsal_path($home,true)) rehearsal_fail();
    $root=$home.'/domains/staging.petish.biz/public_html'; $production=$home.'/domains/petish.biz/public_html';
    $private=$home.'/.petish-staging'; $config=$private.'/config.php'; $loader=$root.'/wp-config.php';
    $guard=$root.'/wp-content/mu-plugins/petish-staging-guard.php';
    $report['category']='paths';
    foreach ([$root,$production,$private] as $path) if (!rehearsal_path($path,true)) rehearsal_fail();
    foreach ([$config,$loader,$guard,$production.'/wp-config.php',$root.'/wp-includes/plugin.php',$root.'/wp-load.php'] as $path) if (!rehearsal_path($path)) rehearsal_fail();
    if ((fileperms($private)&07777)!==0700||(fileperms($config)&07777)!==0600) rehearsal_fail();
    $report['checks']['paths_verified']=true;
    $report['category']='guard_hash';
    if (!hash_equals(PETISH_REHEARSAL_GUARD_SHA256,hash_file('sha256',$guard))) rehearsal_fail();
    $report['checks']['source_guard_sha_verified']=true;
    $report['category']='source_configuration';
    $private_tokens=rehearsal_tokens(file_get_contents($config)); $loader_tokens=rehearsal_tokens(file_get_contents($loader));
    $definitions=rehearsal_literal_defines(array_merge($loader_tokens,$private_tokens),['DB_NAME','DB_HOST','WP_ENVIRONMENT_TYPE','WCPAY_DEV_MODE','DISABLE_WP_CRON']);
    $production_definitions=rehearsal_literal_defines(rehearsal_tokens(file_get_contents($production.'/wp-config.php')),['DB_NAME','DB_HOST']);
    $report['checks']['loader_requires_private']=rehearsal_loader_requires($loader_tokens,$config);
    $report['checks']['source_environment_staging']=($definitions['WP_ENVIRONMENT_TYPE']??null)==='staging';
    $report['checks']['source_wcpay_dev_mode']=($definitions['WCPAY_DEV_MODE']??null)===true;
    $report['checks']['source_wp_cron_disabled']=($definitions['DISABLE_WP_CRON']??null)===true;
    $report['checks']['source_staging_database_literal']=is_string($definitions['DB_NAME']??null)&&is_string($definitions['DB_HOST']??null);
    $report['checks']['source_production_database_literal']=is_string($production_definitions['DB_NAME']??null)&&is_string($production_definitions['DB_HOST']??null);
    if(!$report['checks']['source_production_database_literal']){
        // The fixed SSH wrapper uses WP-CLI's non-executing config parser for
        // Hostinger's generated production config. No WordPress is loaded and
        // only these two comparison fields are supplied; no database secret.
        $parsed_production=[];
        foreach(['DB_NAME','DB_HOST']as$name){
            $value=getenv('PETISH_REHEARSAL_PRODUCTION_'.$name);
            if(!is_string($value)||$value===''||strlen($value)>255||preg_match('/[\x00-\x1f\x7f]/',$value))rehearsal_fail();
            $parsed_production[$name]=$value;
        }
        $production_definitions=$parsed_production;unset($parsed_production,$value);
    }
    $report['checks']['source_production_database_read']=is_string($production_definitions['DB_NAME']??null)&&is_string($production_definitions['DB_HOST']??null);
    foreach(['loader_requires_private','source_environment_staging','source_wcpay_dev_mode','source_wp_cron_disabled']as$key)if(!$report['checks'][$key])rehearsal_fail();
    foreach (['DB_NAME','DB_HOST'] as $name) if (!is_string($definitions[$name]??null)||!is_string($production_definitions[$name]??null)||$definitions[$name]==='') rehearsal_fail();
    if ($definitions['DB_NAME']===$production_definitions['DB_NAME']) rehearsal_fail();
    $report['checks']['database_separate']=true;
    $guard_tokens=rehearsal_tokens(file_get_contents($guard));
    foreach ($guard_tokens as $token) {
        $host=rehearsal_string($token);
        if (is_string($host)&&preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,200})\.(?:wordpress\.com|woocommerce\.com|wp\.com|stripe\.com|wcpayments\.com)\z/D',$host)) $report['guard_allowed_hosts'][]=$host;
    }
    $report['guard_allowed_hosts']=array_values(array_unique($report['guard_allowed_hosts']));sort($report['guard_allowed_hosts']);
    $credentials=rehearsal_private_json($private.'/credentials.json');
    $report['structure']['credentials']=rehearsal_structure($credentials);
    $fixture=$private.'/checkout-fixture.json';
    if (rehearsal_path($fixture)&&(fileperms($fixture)&07777)===0600) $report['structure']['checkout_fixture']=rehearsal_structure(rehearsal_private_json($fixture));
    $report['native']['helper_method_calls']=rehearsal_helper_calls($private);
    $customer=$credentials['checkout_customer']??null;
    if (!is_array($customer)||!is_string($customer['login']??null)||!is_string($customer['password']??null)
        || !preg_match('/\A[A-Za-z0-9_.@-]{1,100}\z/D',$customer['login'])||strlen($customer['password'])<1||strlen($customer['password'])>2048||preg_match('/[\x00-\x1f\x7f]/',$customer['password'])) rehearsal_fail();
    $login=$customer['login']; $password=$customer['password']; unset($credentials,$customer);
    $work=$private.'/rehearsal-'.$run;
    if (!file_exists($work) && !mkdir($work,0700)) rehearsal_fail();
    if (!rehearsal_path($work,true)||(fileperms($work)&07777)!==0700||fileowner($work)!==fileowner($config)) rehearsal_fail();
    if (file_exists($work.'/browser-input.json')||is_link($work.'/browser-input.json')) rehearsal_fail();

    $report['category']='protected_bootstrap';
    // A second, request-local barrier sits outside the installed guard. There
    // is no allowlisted provider HTTP in this preflight phase.
    if (defined('WP_HTTP_BLOCK_EXTERNAL')||defined('WP_ACCESSIBLE_HOSTS')) rehearsal_fail();
    define('WP_HTTP_BLOCK_EXTERNAL',true); define('WP_ACCESSIBLE_HOSTS',''); define('WP_USE_THEMES',false);
    $_SERVER['HTTP_HOST']='staging.petish.biz'; $_SERVER['SERVER_NAME']='staging.petish.biz';
    $_SERVER['HTTPS']='on'; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/';
    $_SERVER['SERVER_PROTOCOL']='HTTP/1.1'; $_SERVER['REMOTE_ADDR']='127.0.0.1';
    require_once $root.'/wp-includes/plugin.php';
    $block_http=static function(){return new WP_Error('petish_rehearsal_http_blocked','Private rehearsal preflight blocks all outbound HTTP.');};
    $block_mail=static function(){return true;};
    $stop_delivery=static function(){throw new \PHPMailer\PHPMailer\Exception('Private rehearsal delivery is blocked.');};
    add_filter('pre_http_request',$block_http,PHP_INT_MAX,3);
    add_filter('pre_wp_mail',$block_mail,PHP_INT_MAX,2);
    add_action('phpmailer_init',$stop_delivery,PHP_INT_MAX);
    $testing_native_http=false;$testing_native_mail=false;
    // The all hook runs before a target hook starts iterating. Keep these
    // request-local barriers last even when a plugin registers at MAX priority
    // during bootstrap. Only the explicit native-guard proofs suspend them.
    add_action('all',static function($hook)use($block_http,$block_mail,$stop_delivery,&$testing_native_http,&$testing_native_mail){
        if($hook==='pre_http_request'&&!$testing_native_http){remove_filter($hook,$block_http,PHP_INT_MAX);add_filter($hook,$block_http,PHP_INT_MAX,3);}
        if($hook==='pre_wp_mail'&&!$testing_native_mail){remove_filter($hook,$block_mail,PHP_INT_MAX);add_filter($hook,$block_mail,PHP_INT_MAX,2);}
        if($hook==='phpmailer_init'){remove_action($hook,$stop_delivery,PHP_INT_MAX);add_action($hook,$stop_delivery,PHP_INT_MAX);}
    },PHP_INT_MAX,1);
    $synthetic_user=null;
    add_action('plugins_loaded',static function()use($login,&$synthetic_user){
        if (!function_exists('get_user_by')||!function_exists('wp_set_current_user')) rehearsal_fail();
        $user=get_user_by('login',$login);
        if (!$user||!is_array($user->roles)||!in_array('customer',$user->roles,true)||in_array('administrator',$user->roles,true)
            ||!is_string($user->user_email)||!filter_var($user->user_email,FILTER_VALIDATE_EMAIL)||!preg_match('/\.invalid\z/Di',$user->user_email)) rehearsal_fail();
        wp_set_current_user($user->ID); $synthetic_user=$user;
    },PHP_INT_MIN);
    require $root.'/wp-load.php';
    // Re-register last after all installed guard/plugin registrations.
    remove_filter('pre_http_request',$block_http,PHP_INT_MAX);add_filter('pre_http_request',$block_http,PHP_INT_MAX,3);
    remove_filter('pre_wp_mail',$block_mail,PHP_INT_MAX);add_filter('pre_wp_mail',$block_mail,PHP_INT_MAX,2);
    remove_action('phpmailer_init',$stop_delivery,PHP_INT_MAX);add_action('phpmailer_init',$stop_delivery,PHP_INT_MAX);
    $report['checks']['all_http_forced_blocked']=is_wp_error(apply_filters('pre_http_request',false,[],'https://example.invalid/petish-readiness'));
    $report['checks']['runtime_database_staging']=defined('DB_NAME')&&DB_NAME===$definitions['DB_NAME']&&defined('DB_HOST')&&DB_HOST===$definitions['DB_HOST'];
    $report['checks']['environment_staging']=function_exists('wp_get_environment_type')&&wp_get_environment_type()==='staging';
    $guard_active=defined('PETISH_STAGING_GUARD_ACTIVE')&&PETISH_STAGING_GUARD_ACTIVE===true;
    if (!$report['checks']['runtime_database_staging']||!$report['checks']['environment_staging']||!$guard_active||!defined('WCPAY_DEV_MODE')||WCPAY_DEV_MODE!==true
        ||!defined('DISABLE_WP_CRON')||DISABLE_WP_CRON!==true||!$synthetic_user) rehearsal_fail();
    $report['category']='runtime_isolation';
    $slugs=[];foreach((array)get_option('active_plugins',[])as$plugin){if(!is_string($plugin)||!preg_match('/\A([a-z0-9][a-z0-9_-]*)\/[A-Za-z0-9_.-]+\.php\z/D',$plugin,$match))rehearsal_fail();$slugs[]=$match[1];}
    sort($slugs);$expected=['petish-fulfilment','petish-passport','woocommerce','woocommerce-payments'];sort($expected);
    $report['checks']['only_allowlisted_plugins']=$slugs===$expected;
    global $wpdb;
    $table=$wpdb->prefix.'wc_webhooks';if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$table))rehearsal_fail();
    $webhooks=$wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE status='active'");
    $report['checks']['no_active_webhooks']=is_numeric($webhooks)&&(int)$webhooks===0;
    if (!$report['checks']['only_allowlisted_plugins']||!$report['checks']['no_active_webhooks']) rehearsal_fail();
    $testing_native_http=true;remove_filter('pre_http_request',$block_http,PHP_INT_MAX);
    try {$supplier=apply_filters('pre_http_request',false,['method'=>'GET','timeout'=>1],'https://www.dsers.com/petish-readiness');}
    finally {$testing_native_http=false;add_filter('pre_http_request',$block_http,PHP_INT_MAX,3);}
    $report['checks']['suppliers_blocked']=is_wp_error($supplier);
    $report['checks']['webhooks_blocked']=apply_filters('woocommerce_webhook_should_deliver',true,null,'')===false;
    $capture=$private.'/captured-mail.jsonl';
    if (file_exists($capture)&&!rehearsal_path($capture)) rehearsal_fail();
    $before=is_file($capture)?filesize($capture):0;
    $marker='Petish staging readiness '.$run; $recipient='petish-readiness@petish.invalid';
    $testing_native_mail=true;remove_filter('pre_wp_mail',$block_mail,PHP_INT_MAX);
    try {$captured=wp_mail($recipient,$marker,'Synthetic private staging readiness check. No order, payment or supplier action.');}
    finally {$testing_native_mail=false;add_filter('pre_wp_mail',$block_mail,PHP_INT_MAX,2);}
    clearstatcache(true,$capture);$append='';
    if(rehearsal_path($capture)&&(fileperms($capture)&07777)===0600&&filesize($capture)>$before&&filesize($capture)-$before<=1048576){
        $handle=fopen($capture,'rb');if($handle){fseek($handle,$before);$append=stream_get_contents($handle,1048577);fclose($handle);}
    }
    $report['checks']['mail_capture_verified']=$captured===true&&is_string($append)&&strpos($append,$marker)!==false&&strpos($append,$recipient)!==false;
    if(is_string($append))foreach(explode("\n",$append)as$line){
        if(strpos($line,$marker)===false||strpos($line,$recipient)===false)continue;
        $record=json_decode($line,true);if(is_array($record))$report['structure']['captured_mail']=rehearsal_structure($record);
    }
    unset($append);
    $report['checks']['guard_runtime_verified']=$guard_active&&$report['checks']['suppliers_blocked']&&$report['checks']['webhooks_blocked']&&$report['checks']['mail_capture_verified'];

    $report['category']='native_capabilities';
    if (!class_exists('WC_Payments')||!is_callable(['WC_Payments','mode'])||!is_callable(['WC_Payments','get_account_service'])) rehearsal_fail();
    $mode=WC_Payments::mode();$service=WC_Payments::get_account_service();
    $report['native']['mode_class']=is_object($mode)?get_class($mode):null;$report['native']['mode_methods']=rehearsal_public_methods($mode);
    $report['native']['account_service_class']=is_object($service)?get_class($service):null;$report['native']['account_service_methods']=rehearsal_public_methods($service);
    $client_getter=is_callable(['WC_Payments','get_payments_api_client'])?'get_payments_api_client':(is_callable(['WC_Payments','get_api_client'])?'get_api_client':null);
    if($client_getter!==null){
        $client=WC_Payments::$client_getter();$report['native']['api_client_class']=is_object($client)?get_class($client):null;
        $report['native']['api_client_methods']=rehearsal_public_methods($client);
        foreach($report['native']['api_client_methods']as$method){
            if(!preg_match('/charge|intent|refund/i',$method))continue;
            $reflection=new ReflectionMethod($client,$method);
            foreach($reflection->getParameters()as$index=>$parameter){
                $type=$parameter->hasType()?(string)$parameter->getType():'untyped';
                $type=str_replace(['?','|','&'],['nullable_','_or_','_and_'],$type);
                $signature=$method.':'.$index.':'.$parameter->getName().':'.$type.':'.($parameter->isOptional()?'optional':'required');
                if(strlen($signature)<=150&&preg_match('/\A[A-Za-z_\\\\][A-Za-z0-9_\\\\:>\-]{0,149}\z/D',$signature))$report['native']['api_method_signatures'][]=$signature;
            }
        }
        $report['native']['api_method_signatures']=array_slice($report['native']['api_method_signatures'],0,150);unset($client);
    }
    foreach(['is_dev'=>'dev_mode','is_test'=>'test_mode']as$method=>$field)if(is_callable([$mode,$method])){$value=$mode->$method();if(is_bool($value))$report['account'][$field]=$value;}
    if(is_callable([$service,'is_connected'])){$value=$service->is_connected();if(is_bool($value))$report['account']['native_connected']=$value;}
    $cached=is_callable([$service,'get_cached_account_data'])?$service->get_cached_account_data():null;
    $raw_cache=get_option('wcpay_account_data',[]);$option_cache=is_array($raw_cache)&&is_array($raw_cache['data']??null)?$raw_cache['data']:[];
    if(!is_array($cached)||!$cached)$cached=$option_cache;
    $report['account']['cached_account_present']=is_array($cached)&&count($cached)>0;
    if(is_array($cached))foreach(['is_live'=>'account_is_live','payments_enabled'=>'payments_enabled']as$key=>$field)if(isset($cached[$key])&&is_bool($cached[$key]))$report['account'][$field]=$cached[$key];
    unset($raw_cache,$option_cache,$cached);
    if(is_callable(['WC_Payments_Onboarding_Service','is_test_mode_enabled'])){$value=WC_Payments_Onboarding_Service::is_test_mode_enabled();if(is_bool($value))$report['account']['test_onboarding']=$value;}
    $gateway=null;
    if(function_exists('WC')&&WC()&&is_callable([WC(),'payment_gateways'])){$gateways=WC()->payment_gateways()->payment_gateways();$gateway=$gateways['woocommerce_payments']??null;}
    $report['native']['gateway_class']=is_object($gateway)?get_class($gateway):null;$report['native']['gateway_methods']=rehearsal_public_methods($gateway);
    if(is_callable([$gateway,'is_in_test_mode'])){$value=$gateway->is_in_test_mode();if(is_bool($value))$report['account']['gateway_test_mode']=$value;}
    if($report['account']['native_connected']===null&&is_callable([$gateway,'is_connected'])){$value=$gateway->is_connected();if(is_bool($value))$report['account']['native_connected']=$value;}
    $report['checks']['wcpay_dev_mode']=$report['account']['dev_mode']===true&&WCPAY_DEV_MODE===true;
    $report['checks']['woocommerce_test_mode']=$report['account']['test_mode']===true&&$report['account']['gateway_test_mode']!==false;
    $report['checks']['account_is_live']=$report['account']['account_is_live'];
    $safety=[];foreach(['environment_staging','woocommerce_test_mode','wcpay_dev_mode','account_is_live','guard_runtime_verified','suppliers_blocked','mail_capture_verified','webhooks_blocked']as$key)$safety[$key]=$report['checks'][$key];
    $safe=$safety['account_is_live']===false;
    foreach($safety as$key=>$value)if($key!=='account_is_live'&&$value!==true)$safe=false;
    $safe=$safe&&$report['account']['native_connected']===true&&$report['account']['test_onboarding']===true&&$report['account']['payments_enabled']===true;
    $report['category']='native_test_mode_unverified';if(!$safe)rehearsal_fail();
    $report['category']='sample_product';$product=wc_get_product(246);
    if(!$product||!$product->is_type('variation')||(int)$product->get_parent_id()!==242||$product->get_status()!=='publish')rehearsal_fail();
    $color_verified=false;
    foreach($product->get_attributes()as$key=>$value)if(in_array($key,['color','pa_color','colour','pa_colour'],true)&&is_string($value)&&strtolower(trim($value))==='blue')$color_verified=true;
    if(!$color_verified)rehearsal_fail();
    $report['category']='browser_input';
    $input=['safety'=>$safety,'url'=>'https://staging.petish.biz','login'=>$login,'password'=>$password,
        'variation_id'=>246,'parent_id'=>242,'attribute_pa_color'=>'blue','run_id'=>$run,
        'customer_email'=>$synthetic_user->user_email,'customer_id'=>(int)$synthetic_user->ID];
    if($input['customer_id']<1)rehearsal_fail();
    rehearsal_write_private($work.'/browser-input.json',json_encode($input,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
    unset($input,$password,$login);
    $report['checks']['browser_input_written']=true;$report['status']='success';$report['category']='complete';
} catch(Throwable $ignored) { $report['status']='unavailable'; }
exit($report['status']==='success'?0:1);
