#!/usr/bin/env bash
# Read-only DSers/Woo tracking inventory. Never bootstrap WordPress or send mail.
set +x
set -euo pipefail
export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1
export LC_ALL=C
site_path=$HOME/domains/petish.biz/public_html
unavailable() { printf '{"status":"unavailable","category":"%s"}\n' "$1"; exit 1; }
[[ -f $site_path/wp-config.php ]] || unavailable site_unavailable
command -v wp >/dev/null 2>&1 || unavailable wpcli_unavailable
command -v php >/dev/null 2>&1 || unavailable php_unavailable
if ! prefix=$(wp --path="$site_path" --no-color config get table_prefix --type=variable 2>/dev/null); then unavailable database_configuration; fi
[[ $prefix =~ ^[A-Za-z0-9_]+$ && ${#prefix} -le 128 ]] || unavailable database_configuration
for constant in DB_HOST DB_NAME DB_USER DB_PASSWORD; do
    if ! connection_value=$(wp --path="$site_path" --no-color config get "$constant" 2>/dev/null); then unavailable database_configuration; fi
    printf -v "PETISH_$constant" '%s' "$connection_value"
    export "PETISH_$constant"
done
unset connection_value
export PETISH_DB_PREFIX=$prefix PETISH_TRACKING_SITE_PATH=$site_path
PETISH_DB_CLIENT_FLAGS=0
if connection_flags=$(wp --path="$site_path" --no-color config get MYSQL_CLIENT_FLAGS 2>/dev/null); then PETISH_DB_CLIENT_FLAGS=$connection_flags; fi
export PETISH_DB_CLIENT_FLAGS
unset connection_flags
php <<'PETISH_TRACKING_READINESS'
<?php
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0);
$category='database_driver';
try {
    if(!function_exists('mysqli_init')) throw new RuntimeException();
    $category='database_configuration';
    $prefix=getenv('PETISH_DB_PREFIX'); $site=getenv('PETISH_TRACKING_SITE_PATH');
    if(!is_string($prefix)||!preg_match('/\A[A-Za-z0-9_]{1,128}\z/D',$prefix)||!is_string($site)) throw new RuntimeException();
    $host=getenv('PETISH_DB_HOST'); $socket=null; $port=3306;
    if(!is_string($host)||$host===''||preg_match('/[\x00-\x20]/',$host)) throw new RuntimeException();
    $offset=strpos($host,':/'); if($offset!==false){$socket=substr($host,$offset+1);$host=substr($host,0,$offset);}
    if(preg_match('/\A\[([^\]]+)\](?::([0-9]+))?\z/D',$host,$parts)){
        if(!filter_var($parts[1],FILTER_VALIDATE_IP,FILTER_FLAG_IPV6))throw new RuntimeException();
        $host=$parts[1];$port=isset($parts[2])?(int)$parts[2]:3306;
    }elseif(preg_match('/\A([A-Za-z0-9_.-]+):([0-9]+)\z/D',$host,$parts)){$host=$parts[1];$port=(int)$parts[2];}
    elseif(!preg_match('/\A[A-Za-z0-9_.-]+\z/D',$host)&&!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6))throw new RuntimeException();
    if($port<1||$port>65535)throw new RuntimeException();
    $setting=trim((string)getenv('PETISH_DB_CLIENT_FLAGS'));
    if(preg_match('/\A[0-9]+\z/D',$setting))$flags=(int)$setting;
    elseif($setting==='MYSQLI_CLIENT_SSL'&&defined('MYSQLI_CLIENT_SSL'))$flags=MYSQLI_CLIENT_SSL;
    else throw new RuntimeException();
    if(defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT')&&($flags&MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT))throw new RuntimeException();
    $category='database_connection';
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$database=mysqli_init();
    $database->options(MYSQLI_OPT_CONNECT_TIMEOUT,10);
    if(defined('MYSQLI_CLIENT_SSL')&&($flags&MYSQLI_CLIENT_SSL))$database->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT,true);
    $database->real_connect($host,getenv('PETISH_DB_USER'),getenv('PETISH_DB_PASSWORD'),getenv('PETISH_DB_NAME'),$port,$socket,$flags);
    $database->set_charset('utf8mb4');
    function tracking_count($value) {
        if(!is_int($value)&&!is_string($value))throw new RuntimeException();
        $text=(string)$value;
        if(!preg_match('/\A[0-9]{1,10}\z/D',$text)||(int)$text>1000000000)throw new RuntimeException();
        return (int)$text;
    }
    function tracking_boolean($value) {
        if(in_array($value,[true,1,'1','yes'],true))return true;
        if(in_array($value,[false,0,'0','no'],true))return false;
        return null;
    }
    $category='table_metadata';
    $suffixes=['options','woocommerce_api_keys','wc_webhooks','postmeta','posts','wc_orders_meta','wc_orders'];
    $allowed_tables=[];foreach($suffixes as $suffix)$allowed_tables[]=$prefix.$suffix;
    $table_literals="'".implode("','",$allowed_tables)."'";
    $rows=$database->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ({$table_literals})");
    $tables=[];while($row=$rows->fetch_assoc())if(in_array($row['TABLE_NAME'],$allowed_tables,true))$tables[$row['TABLE_NAME']]=true;
    $rows->free();if(!isset($tables[$prefix.'options']))throw new RuntimeException();
    $category='selected_options';
    $rows=$database->query("SELECT option_name, CASE WHEN OCTET_LENGTH(option_value)<=131072 THEN option_value ELSE NULL END AS option_value FROM `{$prefix}options` WHERE option_name IN ('active_plugins','woocommerce_custom_orders_table_enabled','woocommerce_custom_orders_table_data_sync_enabled','wcast_enable_tracking_info','wcast_enable_tracking_info_in_email','wcast_enable_tracking_page','woocommerce_customer_completed_order_settings','woocommerce_customer_processing_order_settings') ORDER BY option_name LIMIT 8");
    $options=[];while($row=$rows->fetch_assoc())$options[$row['option_name']]=$row['option_value'];$rows->free();
    $category='known_plugin_metadata';
    $active=isset($options['active_plugins'])?unserialize($options['active_plugins'],['allowed_classes'=>false,'max_depth'=>16]):[];
    if(!is_array($active))throw new RuntimeException();
    $known=['woo-shipment-tracking','woocommerce-shipment-tracking','woo-advanced-shipment-tracking','advanced-shipment-tracking','aftership-woocommerce-tracking','aftership','trackship-for-woocommerce','dsers'];
    $plugins=[];
    foreach($known as $slug){
        $installed=is_dir($site.'/wp-content/plugins/'.$slug)||is_file($site.'/wp-content/plugins/'.$slug.'.php');
        $enabled=false;foreach($active as $plugin)if(is_string($plugin)&&($plugin===$slug.'.php'||strpos($plugin,$slug.'/')===0))$enabled=true;
        if($installed||$enabled)$plugins[]=['slug'=>$slug,'installed'=>$installed,'active'=>$enabled];
    }
    $category='api_key_metadata';
    $api=['table_present'=>isset($tables[$prefix.'woocommerce_api_keys']),'count'=>0,'permissions'=>[],'last_access_present'=>false];
    if($api['table_present']){
        // No IDs, usernames, descriptions, keys or access dates are selected.
        $rows=$database->query("SELECT CASE WHEN permissions IN ('read','write','read_write') THEN permissions ELSE 'other' END AS safe_permission, CASE WHEN last_access IS NULL OR CAST(last_access AS CHAR) LIKE '0000-%' THEN 0 ELSE 1 END AS accessed, COUNT(*) AS row_count FROM `{$prefix}woocommerce_api_keys` WHERE LOWER(description) LIKE '%dsers%' GROUP BY safe_permission,accessed LIMIT 8");
        while($row=$rows->fetch_assoc()){
            if(!in_array($row['safe_permission'],['read','write','read_write','other'],true)||!in_array((string)$row['accessed'],['0','1'],true))throw new RuntimeException();
            $count=tracking_count($row['row_count']);$api['count']+=$count;
            if($count>0){$api['permissions'][]=$row['safe_permission'];$api['last_access_present']=$api['last_access_present']||$row['accessed']=='1';}
        }$rows->free();$api['permissions']=array_values(array_unique($api['permissions']));sort($api['permissions']);
    }
    $category='webhook_metadata';
    $topics=['order.created','order.updated','order.deleted','order.restored','product.created','product.updated','product.deleted','product.restored','customer.created','customer.updated','customer.deleted','customer.restored','coupon.created','coupon.updated','coupon.deleted','coupon.restored','action.woocommerce_order_status_changed','action.woocommerce_order_status_completed','action.woocommerce_order_status_processing','other'];
    $webhooks=['table_present'=>isset($tables[$prefix.'wc_webhooks']),'total'=>0,'dsers_active_count'=>0,'dsers_topics'=>[],'callback_classifiers'=>[]];
    if($webhooks['table_present']){
        // Strict host boundary rejects lookalike domains and credentials in URLs.
        // URLs/names are classified in SQL and never leave the database.
        $callback="LOWER(delivery_url) REGEXP '^https?://([a-z0-9-]+[.])*dsers[.]com(:[0-9]{1,5})?([/?#]|$)'";
        $topic_literals="'".implode("','",$topics)."'";
        $rows=$database->query("SELECT CASE WHEN status='active' THEN 1 ELSE 0 END AS safe_active, CASE WHEN topic IN ({$topic_literals}) THEN topic ELSE 'other' END AS safe_topic, CASE WHEN {$callback} THEN 'dsers' ELSE 'other' END AS safe_callback, CASE WHEN LOWER(name) LIKE '%dsers%' OR {$callback} THEN 1 ELSE 0 END AS dsers_match, COUNT(*) AS row_count FROM `{$prefix}wc_webhooks` GROUP BY safe_active,safe_topic,safe_callback,dsers_match LIMIT 160");
        while($row=$rows->fetch_assoc()){
            if(!in_array((string)$row['safe_active'],['0','1'],true)||!in_array((string)$row['dsers_match'],['0','1'],true)||!in_array($row['safe_topic'],$topics,true)||!in_array($row['safe_callback'],['dsers','other'],true))throw new RuntimeException();
            $count=tracking_count($row['row_count']);$webhooks['total']+=$count;
            if($count>0)$webhooks['callback_classifiers'][]=$row['safe_callback'];
            if($row['dsers_match']=='1'&&$count>0){$webhooks['dsers_topics'][]=$row['safe_topic'];if($row['safe_active']=='1')$webhooks['dsers_active_count']+=$count;}
        }$rows->free();
        $webhooks['dsers_topics']=array_values(array_unique($webhooks['dsers_topics']));sort($webhooks['dsers_topics']);
        $webhooks['callback_classifiers']=array_values(array_unique($webhooks['callback_classifiers']));sort($webhooks['callback_classifiers']);
    }
    $category='tracking_metadata';
    $keys=['_wc_shipment_tracking_items','_tracking_number','_tracking_provider','_tracking_link','_tracking_url','_date_shipped','_dsers_tracking','_dsers_tracking_number','_dsers_tracking_provider','_dsers_tracking_url','_aftership_tracking_number','_aftership_tracking_provider','_aftership_tracking_items','_wc_ast_tracking_number','_wc_ast_tracking_provider','_trackship_tracking_number'];
    $key_literals="'".implode("','",$keys)."'";
    $metadata=['legacy_table_present'=>isset($tables[$prefix.'postmeta'],$tables[$prefix.'posts']),'hpos_table_present'=>isset($tables[$prefix.'wc_orders_meta'],$tables[$prefix.'wc_orders']),'legacy'=>[],'hpos'=>[]];
    foreach(['legacy','hpos'] as $storage){
        if(!$metadata[$storage==='legacy'?'legacy_table_present':'hpos_table_present'])continue;
        // Exact meta_key IN permits indexed key lookups. Only matching order
        // keys/counts are read; no order IDs or tracking values are selected.
        $query=$storage==='legacy'
            ? "SELECT m.meta_key,COUNT(*) AS row_count FROM `{$prefix}postmeta` m INNER JOIN `{$prefix}posts` o ON o.ID=m.post_id WHERE m.meta_key IN ({$key_literals}) AND o.post_type='shop_order' GROUP BY m.meta_key ORDER BY m.meta_key LIMIT 16"
            : "SELECT m.meta_key,COUNT(*) AS row_count FROM `{$prefix}wc_orders_meta` m INNER JOIN `{$prefix}wc_orders` o ON o.id=m.order_id WHERE m.meta_key IN ({$key_literals}) AND o.type='shop_order' GROUP BY m.meta_key ORDER BY m.meta_key LIMIT 16";
        $rows=$database->query($query);
        while($row=$rows->fetch_assoc()){
            if(!in_array($row['meta_key'],$keys,true))throw new RuntimeException();
            $metadata[$storage][]=['key'=>$row['meta_key'],'count'=>tracking_count($row['row_count'])];
        }$rows->free();
    }
    $category='tracking_settings';
    $flag_options=['woocommerce_custom_orders_table_enabled'=>'hpos_enabled','woocommerce_custom_orders_table_data_sync_enabled'=>'hpos_compatibility_sync_enabled','wcast_enable_tracking_info'=>'ast_tracking_info_enabled','wcast_enable_tracking_info_in_email'=>'ast_tracking_in_order_email_enabled','wcast_enable_tracking_page'=>'ast_tracking_page_enabled'];
    $settings=[];
    foreach($flag_options as $option=>$flag){
        if(!array_key_exists($option,$options))continue;$enabled=tracking_boolean($options[$option]);
        if($enabled!==null)$settings[]=['flag'=>$flag,'enabled'=>$enabled];
    }
    foreach(['completed','processing'] as $kind){
        $option='woocommerce_customer_'.$kind.'_order_settings';
        if(!isset($options[$option])||strlen($options[$option])>8192)continue;
        $values=unserialize($options[$option],['allowed_classes'=>false,'max_depth'=>8]);
        if(!is_array($values)||!array_key_exists('enabled',$values))continue;
        $enabled=tracking_boolean($values['enabled']);
        if($enabled!==null)$settings[]=['flag'=>'customer_'.$kind.'_order_email_enabled','enabled'=>$enabled];
    }
    $database->close();
    echo json_encode(['status'=>'success','tracking_plugins'=>$plugins,'dsers_api_keys'=>$api,'webhooks'=>$webhooks,'tracking_metadata'=>$metadata,'tracking_settings'=>$settings],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
}catch(Throwable $ignored){echo json_encode(['status'=>'unavailable','category'=>$category]),"\n";exit(1);}
PETISH_TRACKING_READINESS
