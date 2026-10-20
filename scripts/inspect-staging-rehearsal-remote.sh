#!/usr/bin/env bash
# Read-only source/configuration/catalogue audit. No WordPress bootstrap,
# settings changes, order, payment, SMTP connection or message submission.
set +x
set -euo pipefail
export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1
export LC_ALL=C
staging_path=$HOME/domains/staging.petish.biz/public_html
production_path=$HOME/domains/petish.biz/public_html
private_config=$HOME/.petish-staging/config.php
export PETISH_REHEARSAL_HOME=$HOME
export PETISH_REHEARSAL_CONFIG_READY=1
export PETISH_REHEARSAL_PRODUCTION_COMPARISON_READY=1
export PETISH_REHEARSAL_WPCLI_AVAILABLE=0

# WP-CLI's config command parses PHP; it does not include wp-config.php or
# bootstrap WordPress. The minimal staging loader may require a second file,
# which config get cannot necessarily follow. Parse that fixed file explicitly.
read_staging_value() {
    local name=$1 type=${2:-constant} value
    if value=$(wp --path="$staging_path" --no-color config get "$name" --type="$type" 2>/dev/null); then
        printf '%s' "$value"
    elif [[ -f $private_config && ! -L $private_config ]] && value=$(wp --path="$staging_path" --no-color config get "$name" --type="$type" --config-file="$private_config" 2>/dev/null); then
        printf '%s' "$value"
    else
        return 1
    fi
}
if command -v wp >/dev/null 2>&1; then
    PETISH_REHEARSAL_WPCLI_AVAILABLE=1
    for name in DB_HOST DB_NAME DB_USER DB_PASSWORD; do
        if value=$(read_staging_value "$name"); then
            printf -v "PETISH_REHEARSAL_$name" '%s' "$value"
            export "PETISH_REHEARSAL_$name"
        else
            PETISH_REHEARSAL_CONFIG_READY=0
        fi
    done
    if value=$(read_staging_value table_prefix variable); then
        export PETISH_REHEARSAL_DB_PREFIX=$value
    else
        PETISH_REHEARSAL_CONFIG_READY=0
    fi
    export PETISH_REHEARSAL_DB_CLIENT_FLAGS=0
    if value=$(read_staging_value MYSQL_CLIENT_FLAGS); then export PETISH_REHEARSAL_DB_CLIENT_FLAGS=$value; fi
    for name in DB_HOST DB_NAME; do
        if value=$(wp --path="$production_path" --no-color config get "$name" --type=constant 2>/dev/null); then
            printf -v "PETISH_REHEARSAL_PRODUCTION_$name" '%s' "$value"
            export "PETISH_REHEARSAL_PRODUCTION_$name"
        else
            PETISH_REHEARSAL_PRODUCTION_COMPARISON_READY=0
        fi
    done
else
    PETISH_REHEARSAL_CONFIG_READY=0
    PETISH_REHEARSAL_PRODUCTION_COMPARISON_READY=0
fi
unset value
if ! command -v php >/dev/null 2>&1; then
    printf '%s\n' '{"status":"unavailable","category":"php_unavailable","source_only":true,"runtime_isolation_verified":false}'
    exit 1
fi

php <<'PETISH_STAGING_REHEARSAL_AUDIT'
<?php
ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(0);
$ids=[246,153,2137,858,1546];
$report=[
    'status'=>'unavailable','category'=>'input','source_only'=>true,'runtime_isolation_verified'=>false,
    'paths'=>['root_canonical'=>false,'loader_canonical'=>false,'private_directory_canonical'=>false,'private_directory_0700'=>false,'private_config_canonical'=>false,'private_config_0600'=>false],
    'configuration'=>['loader_requires_private'=>false,'environment_staging_literal'=>false,'wcpay_dev_mode_true_literal'=>false,'disable_wp_cron_true_literal'=>false,'defined_constant_names'=>[]],
    'guard'=>['present'=>false,'canonical'=>false,'sha256'=>null,'hooks_registered'=>[],'direct_webhook_false_callback'=>false,'declared_function_names'=>[]],
    'database'=>['configuration_read'=>false,'name_differs_from_production'=>null,'host_differs_from_production'=>null,'identity_differs_from_production'=>null],
    'woocommerce'=>['active_plugins'=>[],'active_plugins_outside_allowlist'=>[],'enabled_setting'=>null,'test_mode_setting'=>null,'checkout_mode'=>'unverified','published_products'=>null,'active_webhooks'=>null],
    'variations'=>array_map(static function($id){return ['variation_id'=>$id,'published'=>false,'parent_published'=>false,'stock_quantity'=>null,'manage_stock'=>null,'stock_status'=>'unknown'];},$ids)
];
function rehearsal_reject(){throw new RuntimeException();}
function rehearsal_canonical($path,$directory=false){
    if(!is_string($path)||$path===''||$path[0]!=='/'||strpos($path,"\0")!==false)return false;
    $cursor='';foreach(explode('/',substr($path,1))as$part){
        if($part===''||$part==='.'||$part==='..')return false;
        $cursor.='/'.$part;if(is_link($cursor)||realpath($cursor)!==$cursor)return false;
    }
    $stat=@lstat($path);return $stat&&($directory?is_dir($path):(is_file($path)&&$stat['nlink']===1));
}
function rehearsal_tokens($source){
    return array_values(array_filter(token_get_all($source,TOKEN_PARSE),static function($token){return !is_array($token)||!in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true);}));
}
function rehearsal_string($token){
    if(!is_array($token)||$token[0]!==T_CONSTANT_ENCAPSED_STRING)return null;
    $raw=$token[1];if(strlen($raw)<2||!in_array($raw[0],["'",'"'],true)||substr($raw,-1)!==$raw[0]||strpos($raw,'\\')!==false)return null;
    return substr($raw,1,-1);
}
function rehearsal_literal($sources,$name,$expected){
    $mentions=0;$proof=0;
    foreach($sources as$tokens){$depth=0;
        foreach($tokens as$i=>$token){
            if($token==='{')$depth++;if($token==='}')$depth--;
            if(rehearsal_string($token)!==$name)continue;$mentions++;
            $start=$i-2;$previous=$tokens[$start-1]??null;
            if($depth!==0||!is_array($tokens[$start]??null)||$tokens[$start][0]!==T_STRING||strtolower($tokens[$start][1])!=='define'||($tokens[$i-1]??null)!=='('||($tokens[$i+1]??null)!==','||($tokens[$i+3]??null)!==')'||($tokens[$i+4]??null)!==';')continue;
            if($previous!==';'&&!(is_array($previous)&&$previous[0]===T_OPEN_TAG))continue;
            $value=$tokens[$i+2]??null;
            if($expected===true&&is_array($value)&&$value[0]===T_STRING&&strtolower($value[1])==='true')$proof++;
            elseif(is_string($expected)&&rehearsal_string($value)===$expected)$proof++;
        }
    }
    return $mentions===1&&$proof===1;
}
function rehearsal_loader($tokens,$private){
    $depth=0;$count=0;
    foreach($tokens as$i=>$token){
        if($token==='{')$depth++;if($token==='}')$depth--;
        if(!is_array($token)||!in_array($token[0],[T_REQUIRE,T_REQUIRE_ONCE],true))continue;
        $previous=$tokens[$i-1]??null;
        if($depth===0&&($previous===';'||(is_array($previous)&&$previous[0]===T_OPEN_TAG))&&rehearsal_string($tokens[$i+1]??null)===$private&&($tokens[$i+2]??null)===';')$count++;
    }
    return $count===1;
}
function rehearsal_security_structure($sources,$guard){
    $names=[];$functions=[];$registered=[];$direct=false;
    $known=['pre_http_request','pre_wp_mail','woocommerce_webhook_should_deliver','woocommerce_webhook_deliver_async','action_scheduler_allow_async_request_runner','action_scheduler_queue_runner_concurrent_batches','action_scheduler_run_queue','pre_schedule_event','pre_reschedule_event','cron_request','jetpack_sync_sender_should_load','jetpack_sync_should_run','rest_authentication_errors','rest_pre_dispatch','template_redirect','init','login_init','admin_init','send_headers','wp_headers','wp_robots','pre_option_blog_public','xmlrpc_enabled'];
    foreach($sources as$tokens)foreach($tokens as$i=>$token){
        if(!is_array($token)||$token[0]!==T_STRING||strtolower($token[1])!=='define'||($tokens[$i+1]??null)!=='(')continue;
        $name=rehearsal_string($tokens[$i+2]??null);
        if(is_string($name)&&preg_match('/\A[A-Z][A-Z0-9_]{0,99}\z/D',$name))$names[]=$name;
    }
    foreach($guard as$i=>$token){
        if(is_array($token)&&$token[0]===T_FUNCTION){
            $next=$guard[$i+1]??null;if($next==='&')$next=$guard[$i+2]??null;
            if(is_array($next)&&$next[0]===T_STRING&&preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,99}\z/D',$next[1]))$functions[]=$next[1];
        }
        if(!is_array($token)||$token[0]!==T_STRING||!in_array(strtolower($token[1]),['add_filter','add_action'],true)||($guard[$i+1]??null)!=='(')continue;
        $hook=rehearsal_string($guard[$i+2]??null);if(!in_array($hook,$known,true))continue;
        $registered[]=$hook;
        if($hook==='woocommerce_webhook_should_deliver'&&($guard[$i+3]??null)===','&&rehearsal_string($guard[$i+4]??null)==='__return_false')$direct=true;
    }
    $names=array_values(array_unique($names));sort($names);$registered=array_values(array_unique($registered));sort($registered);$functions=array_values(array_unique($functions));sort($functions);
    return [$names,$registered,$direct,$functions];
}
function rehearsal_unserialize($raw){if(!is_string($raw)||strlen($raw)>1048576)rehearsal_reject();$value=unserialize($raw,['allowed_classes'=>false]);if(!is_array($value))rehearsal_reject();return$value;}
function rehearsal_quantity($value){if(!is_string($value)||!preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/D',$value)||abs((float)$value)>1000000000)return null;return strpos($value,'.')===false?(int)$value:(float)$value;}
try{
    $home=getenv('PETISH_REHEARSAL_HOME');if(!is_string($home)||!rehearsal_canonical($home,true))rehearsal_reject();
    $root=$home.'/domains/staging.petish.biz/public_html';$live=$home.'/domains/petish.biz/public_html';
    $loader=$root.'/wp-config.php';$private_directory=$home.'/.petish-staging';$private=$private_directory.'/config.php';$guard=$root.'/wp-content/mu-plugins/petish-staging-guard.php';
    $report['paths']['root_canonical']=rehearsal_canonical($root,true);
    $report['paths']['loader_canonical']=rehearsal_canonical($loader);
    $report['paths']['private_directory_canonical']=rehearsal_canonical($private_directory,true);
    $report['paths']['private_directory_0700']=$report['paths']['private_directory_canonical']&&((fileperms($private_directory)&07777)===0700);
    $report['paths']['private_config_canonical']=rehearsal_canonical($private);
    $report['paths']['private_config_0600']=$report['paths']['private_config_canonical']&&((fileperms($private)&07777)===0600);
    $report['guard']['present']=file_exists($guard)||is_link($guard);$report['guard']['canonical']=rehearsal_canonical($guard);
    $report['category']='staging_path_validation';
    if(in_array(false,$report['paths'],true)||!$report['guard']['canonical'])rehearsal_reject();
    if(rehearsal_canonical($live,true)){ $a=stat($live);$b=stat($root);if($a['dev']===$b['dev']&&$a['ino']===$b['ino'])rehearsal_reject(); }
    $report['category']='php_tokenizer_unavailable';if(!function_exists('token_get_all'))rehearsal_reject();
    $report['category']='source_structure';
    $loader_source=file_get_contents($loader);$private_source=file_get_contents($private);$guard_source=file_get_contents($guard);
    if(!is_string($loader_source)||!is_string($private_source)||!is_string($guard_source)||strlen($guard_source)>1048576||strlen($private_source)>1048576)rehearsal_reject();
    $report['guard']['sha256']=hash('sha256',$guard_source);
    $loader_tokens=rehearsal_tokens($loader_source);$private_tokens=rehearsal_tokens($private_source);$guard_tokens=rehearsal_tokens($guard_source);
    unset($loader_source,$private_source,$guard_source);
    $sources=[$loader_tokens,$private_tokens];
    $report['configuration']['loader_requires_private']=rehearsal_loader($loader_tokens,$private);
    $report['configuration']['environment_staging_literal']=rehearsal_literal($sources,'WP_ENVIRONMENT_TYPE','staging');
    $report['configuration']['wcpay_dev_mode_true_literal']=rehearsal_literal($sources,'WCPAY_DEV_MODE',true);
    $report['configuration']['disable_wp_cron_true_literal']=rehearsal_literal($sources,'DISABLE_WP_CRON',true);
    [$constants,$hooks,$direct,$functions]=rehearsal_security_structure([$loader_tokens,$private_tokens,$guard_tokens],$guard_tokens);
    $report['configuration']['defined_constant_names']=$constants;$report['guard']['hooks_registered']=$hooks;$report['guard']['direct_webhook_false_callback']=$direct;$report['guard']['declared_function_names']=$functions;
    // These are source predicates, not evidence that a hook executed. Keep
    // runtime_isolation_verified false until a separate protected runtime probe.
    $report['category']='staging_configuration_proof';
    if(!$report['configuration']['loader_requires_private']||!$report['configuration']['environment_staging_literal']||!$report['configuration']['wcpay_dev_mode_true_literal'])rehearsal_reject();
    $report['category']='wpcli_unavailable';if(getenv('PETISH_REHEARSAL_WPCLI_AVAILABLE')!=='1')rehearsal_reject();
    $report['category']='database_configuration';
    $ready=getenv('PETISH_REHEARSAL_CONFIG_READY')==='1'&&getenv('PETISH_REHEARSAL_PRODUCTION_COMPARISON_READY')==='1';
    $report['database']['configuration_read']=$ready;if(!$ready)rehearsal_reject();
    $host=getenv('PETISH_REHEARSAL_DB_HOST');$name=getenv('PETISH_REHEARSAL_DB_NAME');$production_host=getenv('PETISH_REHEARSAL_PRODUCTION_DB_HOST');$production_name=getenv('PETISH_REHEARSAL_PRODUCTION_DB_NAME');
    foreach([$host,$name,$production_host,$production_name]as$value)if(!is_string($value)||$value==='')rehearsal_reject();
    $report['database']['name_differs_from_production']=$name!==$production_name;
    $report['database']['host_differs_from_production']=$host!==$production_host;
    $report['database']['identity_differs_from_production']=$name!==$production_name||$host!==$production_host;
    // Different host spellings can alias the same database. Require distinct
    // database names before reading even the allowlisted staging catalogue.
    $report['category']='database_separation';if(!$report['database']['name_differs_from_production'])rehearsal_reject();
    $report['category']='database_driver';if(!function_exists('mysqli_init'))rehearsal_reject();
    $prefix=getenv('PETISH_REHEARSAL_DB_PREFIX');if(!is_string($prefix)||!preg_match('/\A[A-Za-z0-9_]+\z/D',$prefix))rehearsal_reject();
    $report['category']='database_configuration';$socket=null;$port=3306;
    if(preg_match('/[\x00-\x20]/',$host))rehearsal_reject();
    $offset=strpos($host,':/');if($offset!==false){$socket=substr($host,$offset+1);$host=substr($host,0,$offset);}
    if(preg_match('/\A\[([^\]]+)\](?::([0-9]+))?\z/D',$host,$parts)){
        if(!filter_var($parts[1],FILTER_VALIDATE_IP,FILTER_FLAG_IPV6))rehearsal_reject();$host=$parts[1];$port=isset($parts[2])?(int)$parts[2]:3306;
    }elseif(preg_match('/\A([A-Za-z0-9_.-]+):([0-9]+)\z/D',$host,$parts)){$host=$parts[1];$port=(int)$parts[2];}
    elseif(!preg_match('/\A[A-Za-z0-9_.-]+\z/D',$host)&&!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6))rehearsal_reject();
    if($port<1||$port>65535)rehearsal_reject();
    $setting=trim((string)getenv('PETISH_REHEARSAL_DB_CLIENT_FLAGS'));
    if(preg_match('/\A[0-9]+\z/D',$setting))$flags=(int)$setting;elseif($setting==='MYSQLI_CLIENT_SSL'&&defined('MYSQLI_CLIENT_SSL'))$flags=MYSQLI_CLIENT_SSL;else rehearsal_reject();
    if(defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT')&&($flags&MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT))rehearsal_reject();
    $report['category']='database_connection';mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$database=mysqli_init();$database->options(MYSQLI_OPT_CONNECT_TIMEOUT,10);
    if(defined('MYSQLI_CLIENT_SSL')&&($flags&MYSQLI_CLIENT_SSL))$database->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT,true);
    $database->real_connect($host,getenv('PETISH_REHEARSAL_DB_USER'),getenv('PETISH_REHEARSAL_DB_PASSWORD'),$name,$port,$socket,$flags);$database->set_charset('utf8mb4');
    $report['category']='staging_options';
    $rows=$database->query("SELECT option_name,option_value FROM `{$prefix}options` WHERE option_name IN ('active_plugins','woocommerce_woocommerce_payments_settings','woocommerce_checkout_page_id')");$options=[];
    while($row=$rows->fetch_assoc())$options[$row['option_name']]=$row['option_value'];$rows->free();
    $plugins=rehearsal_unserialize($options['active_plugins']??'a:0:{}');$slugs=[];
    foreach($plugins as$plugin){if(!is_string($plugin)||!preg_match('/\A([a-z0-9][a-z0-9_-]{0,99})(?:\/[A-Za-z0-9_.-]+)?\.php\z/D',$plugin,$match))rehearsal_reject();$slugs[]=$match[1];}
    $slugs=array_values(array_unique($slugs));sort($slugs);$report['woocommerce']['active_plugins']=$slugs;
    $outside=array_values(array_diff($slugs,['woocommerce','woocommerce-payments','petish-passport','petish-fulfilment']));sort($outside);$report['woocommerce']['active_plugins_outside_allowlist']=$outside;
    if(isset($options['woocommerce_woocommerce_payments_settings'])){
        $gateway=rehearsal_unserialize($options['woocommerce_woocommerce_payments_settings']);
        foreach(['enabled'=>'enabled_setting','test_mode'=>'test_mode_setting']as$key=>$field)if(isset($gateway[$key])&&in_array($gateway[$key],['yes','no'],true))$report['woocommerce'][$field]=$gateway[$key]==='yes';
    }
    $report['category']='checkout_page';$report['woocommerce']['checkout_mode']='missing';
    $checkout=$options['woocommerce_checkout_page_id']??'';
    if(is_string($checkout)&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$checkout)){
        $rows=$database->query("SELECT post_content FROM `{$prefix}posts` WHERE ID=".(int)$checkout." AND post_type='page' AND post_status='publish'");
        if($row=$rows->fetch_assoc()){$content=$row['post_content'];$report['woocommerce']['checkout_mode']=strpos($content,'wp:woocommerce/checkout')!==false?'block':(strpos($content,'[woocommerce_checkout]')!==false?'classic':'other');}$rows->free();
    }
    $report['category']='catalogue_counts';$rows=$database->query("SELECT COUNT(*) AS total FROM `{$prefix}posts` WHERE post_type='product' AND post_status='publish'");$row=$rows->fetch_assoc();$report['woocommerce']['published_products']=(int)$row['total'];$rows->free();
    $rows=$database->query("SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='{$prefix}wc_webhooks'");$row=$rows->fetch_assoc();$rows->free();
    if((int)$row['total']===1){$rows=$database->query("SELECT COUNT(*) AS total FROM `{$prefix}wc_webhooks` WHERE status='active'");$row=$rows->fetch_assoc();$report['woocommerce']['active_webhooks']=(int)$row['total'];$rows->free();}
    $report['category']='sample_variations';
    $rows=$database->query("SELECT v.ID,v.post_status,p.post_status AS parent_status,p.post_type AS parent_type FROM `{$prefix}posts` v LEFT JOIN `{$prefix}posts` p ON p.ID=v.post_parent WHERE v.ID IN (246,153,2137,858,1546) AND v.post_type='product_variation'");$product_rows=[];
    while($row=$rows->fetch_assoc())$product_rows[(int)$row['ID']]=$row;$rows->free();
    $rows=$database->query("SELECT post_id,meta_key,meta_value FROM `{$prefix}postmeta` WHERE post_id IN (246,153,2137,858,1546) AND meta_key IN ('_stock','_manage_stock','_stock_status')");$meta=[];
    while($row=$rows->fetch_assoc()){$id=(int)$row['post_id'];$key=$row['meta_key'];if(isset($meta[$id][$key]))rehearsal_reject();$meta[$id][$key]=$row['meta_value'];}$rows->free();
    foreach($report['variations']as&$variation){$id=$variation['variation_id'];$row=$product_rows[$id]??null;$values=$meta[$id]??[];
        $variation['published']=$row&&$row['post_status']==='publish';$variation['parent_published']=$row&&$row['parent_type']==='product'&&$row['parent_status']==='publish';
        $variation['stock_quantity']=isset($values['_stock'])?rehearsal_quantity($values['_stock']):null;
        $variation['manage_stock']=isset($values['_manage_stock'])&&in_array($values['_manage_stock'],['yes','no'],true)?$values['_manage_stock']==='yes':null;
        $variation['stock_status']=isset($values['_stock_status'])&&in_array($values['_stock_status'],['instock','outofstock','onbackorder'],true)?$values['_stock_status']:'unknown';
    }unset($variation);$database->close();$report['status']='success';$report['category']='complete';
}catch(Throwable$error){$report['status']='unavailable';}
echo json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
exit($report['status']==='success'?0:1);
PETISH_STAGING_REHEARSAL_AUDIT
