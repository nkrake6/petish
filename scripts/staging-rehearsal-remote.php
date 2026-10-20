<?php
/** Private staging rehearsal. Native TEST payment/refund only; no suppliers. */
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
    'structure'=>['credentials'=>[],'checkout_fixture'=>[],'captured_mail'=>[],'cached_account'=>[],'provider_charge'=>[]], 'guard_allowed_hosts'=>[],
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
function rehearsal_cached_account() {
    $service=WC_Payments::get_account_service();
    $cached=is_callable([$service,'get_cached_account_data'])?$service->get_cached_account_data():null;
    if(!is_array($cached)||!$cached){$raw=get_option('wcpay_account_data',[]);$cached=is_array($raw)&&is_array($raw['data']??null)?$raw['data']:[];}
    return $cached;
}
function rehearsal_account_fingerprint($account) {
    if(!is_array($account)||($account['is_live']??null)!==false||($account['payments_enabled']??null)!==true)return null;
    $ids=[];
    foreach(['id','account_id','stripe_account_id','test_drive_account_id']as$key){
        $value=$account[$key]??null;
        if(is_string($value)&&preg_match('/\Aacct_[A-Za-z0-9_]{1,180}\z/D',$value))$ids[$key]=$value;
    }
    if(!$ids)return null;
    ksort($ids);return hash('sha256',json_encode($ids,JSON_THROW_ON_ERROR));
}
function rehearsal_provider_assert($gateway,$fingerprint) {
    if(wp_get_environment_type()!=='staging'||!defined('WCPAY_DEV_MODE')||WCPAY_DEV_MODE!==true
        ||!defined('PETISH_STAGING_GUARD_ACTIVE')||PETISH_STAGING_GUARD_ACTIVE!==true
        ||!WC_Payments::mode()->is_dev()||!WC_Payments::mode()->is_test()
        ||!WC_Payments_Onboarding_Service::is_test_mode_enabled()
        ||!is_callable([$gateway,'is_connected'])||$gateway->is_connected()!==true
        ||!is_string($fingerprint)||!hash_equals($fingerprint,rehearsal_account_fingerprint(rehearsal_cached_account())??''))rehearsal_fail();
}
function rehearsal_replace_private($path,$value) {
    if(file_exists($path)&&(!rehearsal_path($path)||(fileperms($path)&07777)!==0600))rehearsal_fail();
    if(is_link($path))rehearsal_fail();
    $temporary=dirname($path).'/.input-'.bin2hex(random_bytes(8));
    rehearsal_write_private($temporary,$value);
    if(!rename($temporary,$path)){@unlink($temporary);rehearsal_fail();}
}


/**
 * Verify a raw Stripe-shaped charge returned by the native provider client.
 * Pure validation only: no requests, order changes, refunds, or private output.
 */
function rehearsal_provider_charge(array $charge, string $charge_id, int $expected_minor): array {
    $reject = static function (): never {
        throw new UnexpectedValueException('Provider charge verification failed.');
    };
    if ($expected_minor < 1 || !preg_match('/\A[A-Za-z0-9_]{1,255}\z/D', $charge_id)) {
        $reject();
    }
    foreach (['id', 'livemode', 'paid', 'captured', 'currency', 'amount', 'amount_refunded', 'refunded'] as $field) {
        if (!array_key_exists($field, $charge)) $reject();
    }
    if (!is_string($charge['id']) || $charge['id'] !== $charge_id
        || $charge['livemode'] !== false || $charge['paid'] !== true || $charge['captured'] !== true
        || $charge['currency'] !== 'aud' || !is_int($charge['amount']) || $charge['amount'] !== $expected_minor
        || !is_int($charge['amount_refunded']) || $charge['amount_refunded'] < 0
        || $charge['amount_refunded'] > $charge['amount'] || !is_bool($charge['refunded'])) {
        $reject();
    }
    // A charge's refunded flag denotes a full refund; inconsistent flags are
    // never used as evidence that another financial operation is appropriate.
    if ($charge['refunded'] !== ($charge['amount_refunded'] === $charge['amount'])) $reject();

    $refunds = [];
    if (array_key_exists('refunds', $charge)) {
        if (!is_array($charge['refunds']) || !array_key_exists('data', $charge['refunds'])
            || !is_array($charge['refunds']['data']) || !array_is_list($charge['refunds']['data'])) {
            $reject();
        }
        $refunds = $charge['refunds']['data'];
    } elseif ($charge['amount_refunded'] !== 0) {
        $reject();
    }
    $sum = 0;
    $seen_ids = [];
    foreach ($refunds as $refund) {
        if (!is_array($refund) || ($refund['status'] ?? null) !== 'succeeded'
            || ($refund['currency'] ?? null) !== 'aud' || ($refund['charge'] ?? null) !== $charge_id
            || !isset($refund['amount']) || !is_int($refund['amount']) || $refund['amount'] < 1
            || $refund['amount'] > $charge['amount'] || $sum > PHP_INT_MAX - $refund['amount']) {
            $reject();
        }
        if (array_key_exists('id', $refund)) {
            if (!is_string($refund['id']) || !preg_match('/\A[A-Za-z0-9_]{1,255}\z/D', $refund['id'])
                || isset($seen_ids[$refund['id']])) $reject();
            $seen_ids[$refund['id']] = true;
        }
        $sum += $refund['amount'];
        if ($sum > $charge['amount']) $reject();
    }
    if ($sum !== $charge['amount_refunded']) $reject();
    if ($charge['refunded'] && count($refunds) === 0) $reject();

    return [
        'livemode' => false,
        'paid' => true,
        'captured' => true,
        'currency' => 'aud',
        'amount_minor' => $charge['amount'],
        'amount_refunded_minor' => $charge['amount_refunded'],
        'order_reference_verified' => true,
        'refund_succeeded' => $charge['refunded'] && $sum === $charge['amount'],
    ];
}

try {
    if ($argc!==3 || !in_array($argv[1],['preflight','prepare','verify','refund','cleanup'],true) || !preg_match('/\A[1-9][0-9]{0,31}-[1-9][0-9]{0,8}\z/D',$argv[2])) rehearsal_fail();
    $operation=$argv[1];$run=$argv[2]; $report['run_id']=$run;$report['phase']=$operation;
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
    if($operation==='preflight'&&(file_exists($work.'/browser-input.json')||is_link($work.'/browser-input.json')))rehearsal_fail();
    if(is_link($work.'/browser-input.json'))rehearsal_fail();

    $report['category']='protected_bootstrap';
    // A second, request-local barrier sits outside the installed guard. There
    // is no allowlisted provider HTTP in this preflight phase.
    if (defined('WP_HTTP_BLOCK_EXTERNAL')||defined('WP_ACCESSIBLE_HOSTS')) rehearsal_fail();
    $provider_hosts=['api.wordpress.com','jetpack.wordpress.com','payments.woocommerce.com','public-api.wordpress.com'];
    define('WP_HTTP_BLOCK_EXTERNAL',true); define('WP_ACCESSIBLE_HOSTS',$operation==='preflight'?'':implode(',',$provider_hosts)); define('WP_USE_THEMES',false);
    $_SERVER['HTTP_HOST']='staging.petish.biz'; $_SERVER['SERVER_NAME']='staging.petish.biz';
    $_SERVER['HTTPS']='on'; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/';
    $_SERVER['SERVER_PROTOCOL']='HTTP/1.1'; $_SERVER['REMOTE_ADDR']='127.0.0.1';
    require_once $root.'/wp-includes/plugin.php';
    $provider_window=false;
    $provider_url=static function($url)use($provider_hosts){
        if(!is_string($url))return false;$parsed=parse_url($url);
        return is_array($parsed)&&($parsed['scheme']??null)==='https'&&in_array($parsed['host']??null,$provider_hosts,true)
            &&!isset($parsed['user'])&&!isset($parsed['pass'])&&!isset($parsed['fragment'])&&(!isset($parsed['port'])||$parsed['port']===443);
    };
    $block_http=static function($preempt=false,$args=[],$url='')use(&$provider_window,$provider_url){
        if($provider_window&&$provider_url($url)&&is_array($args)&&in_array(strtoupper($args['method']??'GET'),['GET','POST'],true))return $preempt;
        return new WP_Error('petish_rehearsal_http_blocked','Private rehearsal blocks outbound HTTP outside its verified provider operation.');
    };
    add_filter('http_request_args',static function($args,$url)use(&$provider_window,$provider_url){
        if($provider_window&&$provider_url($url)){$args['redirection']=0;$args['sslverify']=true;$args['reject_unsafe_urls']=true;}
        return $args;
    },PHP_INT_MAX,2);
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
    $http_proof=apply_filters('pre_http_request',false,[],'https://public-api.wordpress.com/petish-readiness');
    $report['checks']['all_http_forced_blocked']=is_wp_error($http_proof)&&$http_proof->get_error_code()==='petish_rehearsal_http_blocked';
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
    if(is_array($cached))$report['structure']['cached_account']=rehearsal_structure($cached);
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
        'variation_id'=>246,'parent_id'=>242,'attribute_color'=>'Blue','run_id'=>$run,
        'customer_email'=>$synthetic_user->user_email,'customer_id'=>(int)$synthetic_user->ID];
    if($input['customer_id']<1)rehearsal_fail();
    if($operation==='preflight'){
        rehearsal_write_private($work.'/browser-input.json',json_encode($input,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
        $report['checks']['browser_input_written']=true;
    }else{
        $report['category']=$operation;
        foreach(['staging-rehearsal-order.php','staging-rehearsal-fixture.php']as$name){
            $path=__DIR__.'/'.$name;if(!rehearsal_path($path)||filesize($path)>1048576)rehearsal_fail();
        }
        require_once __DIR__.'/staging-rehearsal-order.php';
        $report['category']='provider_identity';
        $identity_path=$work.'/provider-account.sha256';
        if($operation==='prepare'){
            $fingerprint=rehearsal_account_fingerprint(rehearsal_cached_account());
            if(!is_string($fingerprint))rehearsal_fail();
            rehearsal_provider_assert($gateway,$fingerprint);
            rehearsal_write_private($identity_path,$fingerprint."\n");
        }else{
            if(!rehearsal_path($identity_path)||(fileperms($identity_path)&07777)!==0600||filesize($identity_path)!==65)rehearsal_fail();
            $fingerprint=trim(file_get_contents($identity_path));
            if(!preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint))rehearsal_fail();
            rehearsal_provider_assert($gateway,$fingerprint);
        }
        $with_provider=static function($callback)use(&$provider_window,$operation,$gateway,$fingerprint,&$report){
            if(!in_array($operation,['verify','refund'],true)||$provider_window||!is_callable($callback))rehearsal_fail();
            $previous_category=$report['category'];$report['category']='provider_identity';
            rehearsal_provider_assert($gateway,$fingerprint);
            $provider_window=true;$report['category']='provider_request';
            try{$result=$callback();}
            finally{$provider_window=false;rehearsal_provider_assert($gateway,$fingerprint);}
            $report['category']=$previous_category;return $result;
        };
        $provider_probe=static function($order)use($with_provider,$client_getter,&$report){
            $previous_category=$report['category'];$report['category']='provider_order_reference';
            if(!is_object($order)||!is_callable([$order,'get_id'])||in_array((int)$order->get_id(),[2139,2140],true)
                ||!is_callable(['WC_Payments','get_order_service'])||$client_getter===null)rehearsal_fail();
            $order_service=WC_Payments::get_order_service();
            if(!is_callable([$order_service,'get_charge_id_for_order']))rehearsal_fail();
            $charge_id=$order_service->get_charge_id_for_order($order);
            if(!is_string($charge_id)||!preg_match('/\Ach_[A-Za-z0-9_]{1,180}\z/D',$charge_id)||strtolower($order->get_currency())!=='aud')rehearsal_fail();
            $expected_minor=(int)round((float)$order->get_total()*100);
            $client=WC_Payments::$client_getter();
            if(!is_callable([$client,'get_charge']))rehearsal_fail();
            $charge=$with_provider(static function()use($client,$charge_id){return $client->get_charge($charge_id);});
            $report['category']='native_provider_response';
            if(!is_array($charge))rehearsal_fail();
            $report['structure']['provider_charge']=rehearsal_structure($charge);
            $proof=rehearsal_provider_charge($charge,$charge_id,$expected_minor);
            $report['category']=$previous_category;return $proof;
        };
        $context=['run_id'=>$run,'home'=>$home,'root'=>$root,'private'=>$private,'work'=>$work,
            'customer_id'=>(int)$synthetic_user->ID,'customer_email'=>$synthetic_user->user_email,
            'login'=>$login,'password'=>$password,'browserinput'=>$input,'capture_file'=>$capture,
            'fixture_template'=>__DIR__.'/staging-rehearsal-fixture.php',
            'with_provider'=>$with_provider,'provider_probe'=>$provider_probe];
        $report['category']=$operation;
        if($operation==='prepare'){
            $result=petish_rehearsal_prepare($context);
            if(($result['shipping_method_id']??null)!=='petish_rehearsal_shipping:'.$run)rehearsal_fail();
            $input['shipping_method_id']=$result['shipping_method_id'];
            rehearsal_replace_private($work.'/browser-input.json',json_encode($input,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
            $report['checks']['browser_input_written']=true;
        }elseif($operation==='verify'){
            $browser=rehearsal_private_json($work.'/browser-result.json');
            $result=petish_rehearsal_verify($context,$browser);unset($browser);
        }elseif($operation==='refund'){$result=petish_rehearsal_refund($context);}
        else{$result=petish_rehearsal_cleanup($context);}
        $public_checks=['prepared','shipping_simulated','paid_test_order_verified','decline_stock_unchanged',
            'paid_stock_reduced_once','provider_test_payment_verified','native_order_emails_captured',
            'native_test_refund_verified','provider_full_test_refund_verified','completed_email_captured','refund_email_captured',
            'stock_restored_to_baseline','synthetic_tracking_note_only','supplier_action_performed',
            'shipping_fixture_removed','qa_cart_cleared','order_refunded_before_cleanup','recovery_required',
            'native_email_contents_verified','customer_note_email_captured'];
        foreach($result as$key=>$value)if(in_array($key,$public_checks,true)&&is_bool($value))$report['checks'][$key]=$value;
        if(($result['recovery_required']??null)===true)rehearsal_fail();
        unset($context,$result,$fingerprint,$provider_probe,$with_provider);
    }
    unset($input,$password,$login);
    $report['status']='success';$report['category']='complete';
} catch(Throwable $ignored) { $report['status']='unavailable'; }
exit($report['status']==='success'?0:1);
