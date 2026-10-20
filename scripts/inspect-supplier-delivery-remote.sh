#!/usr/bin/env bash
# Fixed SELECTs over published product metadata and two merchant price books.
# No WordPress bootstrap, supplier requests, customer/order reads or site writes.
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
export PETISH_DB_PREFIX=$prefix PETISH_SUPPLIER_AUDIT_SITE=$site_path
PETISH_DB_CLIENT_FLAGS=0
if connection_flags=$(wp --path="$site_path" --no-color config get MYSQL_CLIENT_FLAGS 2>/dev/null); then PETISH_DB_CLIENT_FLAGS=$connection_flags; fi
export PETISH_DB_CLIENT_FLAGS
unset connection_flags
php -d allow_url_fopen=0 <<'PETISH_SUPPLIER_DELIVERY'
<?php
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0);
$category='database_driver';
function supplier_audit_count($value) {
    if((!is_string($value)&&!is_int($value))||!preg_match('/\A[0-9]{1,10}\z/D',(string)$value)||(int)$value>1000000000)throw new RuntimeException();
    return (int)$value;
}
function supplier_audit_name($name) {
    return is_string($name)&&preg_match('/\A[A-Za-z_][A-Za-z0-9_-]{0,79}\z/D',$name)
        &&preg_match('/supplier|shipping|freight|dsers|ali/i',$name)
        &&!preg_match('/token|password|secret|credential|auth|session|customer|email|phone|address/i',$name);
}
function supplier_audit_utc($value) {
    if(!is_string($value))return false;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new DateTimeZone('UTC'));
    return $date&&$date->format('Y-m-d\TH:i:s\Z')===$value?$date->getTimestamp():false;
}
function supplier_audit_zero($value) {
    return is_string($value)&&preg_match('/\A0(?:\.0{1,6})?\z/D',$value)===1;
}
function supplier_audit_book($value,$kind,$now,$present) {
    $result=['present'=>$present,'schema_valid'=>false,'rows'=>0,'zero_rows'=>0];
    if($kind==='automatic')$result+=['unexpired_zero_rows'=>0,'explicit_free_unexpired_zero_rows'=>0];
    else $result+=['current_zero_rows'=>0];
    if($value===null)return $result;
    if(!is_string($value)||strlen($value)>1048576)return $result;
    $book=unserialize($value,['allowed_classes'=>false,'max_depth'=>16]);
    $field=$kind==='automatic'?'rules':'quotes';
    if(!is_array($book)||($book['schema_version']??null)!==1||!is_array($book[$field]??null)
        ||!array_is_list($book[$field])||count($book[$field])>10000)return $result;
    $result['schema_valid']=true;$result['rows']=count($book[$field]);
    foreach($book[$field]as$row){
        if(!is_array($row))continue;
        $amount=$row[$kind==='automatic'?'per_unit_customer_shipping_ex_tax_aud':'customer_shipping_ex_tax_aud']??null;
        if(!supplier_audit_zero($amount))continue;
        $result['zero_rows']++;
        $expiry=$row['expires_utc']??null;
        if($kind==='automatic'){
            $valid=$expiry===null||$expiry===''||(($timestamp=supplier_audit_utc($expiry))!==false&&$timestamp>$now);
            if($valid){$result['unexpired_zero_rows']++;if(($row['free_shipping']??null)===true)$result['explicit_free_unexpired_zero_rows']++;}
        }else{
            $quoted=supplier_audit_utc($row['quote_utc']??null);$expires=supplier_audit_utc($expiry);
            if($quoted!==false&&$expires!==false&&$quoted<=$now&&$expires>$now&&$expires>$quoted&&$expires-$quoted<=7*86400)$result['current_zero_rows']++;
        }
    }
    return $result;
}
try {
    if(!function_exists('mysqli_init'))throw new RuntimeException();
    $category='database_configuration';$prefix=getenv('PETISH_DB_PREFIX');$site=getenv('PETISH_SUPPLIER_AUDIT_SITE');
    if(!is_string($prefix)||!preg_match('/\A[A-Za-z0-9_]{1,128}\z/D',$prefix)||!is_string($site))throw new RuntimeException();
    $host=getenv('PETISH_DB_HOST');$socket=null;$port=3306;
    if(!is_string($host)||$host===''||preg_match('/[\x00-\x20]/',$host))throw new RuntimeException();
    $offset=strpos($host,':/');if($offset!==false){$socket=substr($host,$offset+1);$host=substr($host,0,$offset);}
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
    $category='database_connection';mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$database=mysqli_init();
    $database->options(MYSQLI_OPT_CONNECT_TIMEOUT,10);
    if(defined('MYSQLI_CLIENT_SSL')&&($flags&MYSQLI_CLIENT_SSL))$database->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT,true);
    $database->real_connect($host,getenv('PETISH_DB_USER'),getenv('PETISH_DB_PASSWORD'),getenv('PETISH_DB_NAME'),$port,$socket,$flags);
    $database->set_charset('utf8mb4');
    $category='published_products';
    $public="p.post_status='publish' AND (p.post_type='product' OR (p.post_type='product_variation' AND parent.post_type='product' AND parent.post_status='publish'))";
    $parents="LEFT JOIN `{$prefix}posts` parent ON p.post_type='product_variation' AND parent.ID=p.post_parent";
    $rows=$database->query("SELECT p.post_type,COUNT(*) AS row_count FROM `{$prefix}posts` p {$parents} WHERE {$public} GROUP BY p.post_type");
    $counts=['products'=>0,'variations'=>0];
    while($row=$rows->fetch_assoc()){
        if(!in_array($row['post_type'],['product','product_variation'],true))throw new RuntimeException();
        $counts[$row['post_type']==='product'?'products':'variations']=supplier_audit_count($row['row_count']);
    }$rows->free();
    $category='metadata_fields';
    // Values are classified in SQL. Only names and aggregate counts are selected.
    $rows=$database->query("SELECT m.meta_key AS name,COUNT(*) AS rows_count,
        COUNT(DISTINCT CASE WHEN p.post_type='product' THEN p.ID ELSE NULL END) AS products,
        COUNT(DISTINCT CASE WHEN p.post_type='product_variation' THEN p.ID ELSE NULL END) AS variations,
        SUM(m.meta_value IS NULL OR TRIM(m.meta_value)='') AS empty_count,
        SUM(LOWER(TRIM(m.meta_value)) IN ('true','yes','1')) AS true_like,
        SUM(LOWER(TRIM(m.meta_value)) IN ('false','no','0')) AS false_like,
        SUM(TRIM(m.meta_value) REGEXP '^0+([.]0+)?$') AS decimal_zero,
        SUM(LEFT(TRIM(m.meta_value),1) IN ('[','{') OR TRIM(m.meta_value) REGEXP '^a:[0-9]+:') AS structured
        FROM `{$prefix}postmeta` m INNER JOIN `{$prefix}posts` p ON p.ID=m.post_id {$parents}
        WHERE {$public} AND LOWER(m.meta_key) REGEXP 'supplier|shipping|freight|dsers|ali'
        GROUP BY m.meta_key ORDER BY m.meta_key LIMIT 129");
    $fields=[];$excluded=0;$seen=0;
    while($row=$rows->fetch_assoc()){
        if(++$seen>128)throw new RuntimeException();
        if(!supplier_audit_name($row['name']??null)){$excluded++;continue;}
        $field=['name'=>$row['name'],'rows'=>supplier_audit_count($row['rows_count'])];
        foreach(['products','variations','true_like','false_like','decimal_zero','structured']as$name)$field[$name]=supplier_audit_count($row[$name]??'0');
        $field['empty']=supplier_audit_count($row['empty_count']??'0');$fields[]=$field;
    }$rows->free();
    $category='supplier_orders';
    $rows=$database->query("SELECT counts.order_count,observations.observed FROM `{$prefix}posts` p
        LEFT JOIN (SELECT post_id,MAX(CASE WHEN meta_value REGEXP '^[0-9]{1,12}$' THEN CAST(meta_value AS UNSIGNED) ELSE NULL END) AS order_count
          FROM `{$prefix}postmeta` WHERE meta_key='_petish_supplier_order_count' GROUP BY post_id) counts ON counts.post_id=p.ID
        LEFT JOIN (SELECT post_id,MAX(CASE WHEN meta_value REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN meta_value ELSE NULL END) AS observed
          FROM `{$prefix}postmeta` WHERE meta_key='_petish_supplier_order_observed' GROUP BY post_id) observations ON observations.post_id=p.ID
        WHERE p.post_type='product' AND p.post_status='publish' LIMIT 10001");
    $orders=['products_with_numeric_count'=>0,'products_with_fresh_observation'=>0,'hot_eligible_products'=>0];$seen=0;$now=time();
    while($row=$rows->fetch_assoc()){
        if(++$seen>10000)throw new RuntimeException();
        $numeric=is_string($row['order_count']??null)&&preg_match('/\A[0-9]{1,12}\z/D',$row['order_count']);
        if($numeric)$orders['products_with_numeric_count']++;
        $observed=$row['observed']??null;$date=is_string($observed)?DateTimeImmutable::createFromFormat('!Y-m-d',$observed,new DateTimeZone('UTC')):false;
        $fresh=$date&&$date->format('Y-m-d')===$observed&&$date->getTimestamp()<=$now&&$now-$date->getTimestamp()<=30*86400;
        if($fresh)$orders['products_with_fresh_observation']++;
        if($fresh&&$numeric&&(int)$row['order_count']>=500)$orders['hot_eligible_products']++;
    }$rows->free();
    $category='shipping_policy';
    // The two books describe merchant-set CUSTOMER prices, not supplier freight.
    // Private destinations/references are read in memory and never enter output.
    $rows=$database->query("SELECT option_name,CASE WHEN OCTET_LENGTH(option_value)<=1048576 THEN option_value ELSE NULL END AS option_value
        FROM `{$prefix}options` WHERE option_name IN ('petish_shipping_mode','petish_automatic_shipping_rates','petish_supplier_shipping_quotes') ORDER BY option_name LIMIT 3");
    $options=[];while($row=$rows->fetch_assoc())$options[$row['option_name']]=$row['option_value'];$rows->free();$database->close();
    $mode=$options['petish_shipping_mode']??null;$mode=in_array($mode,['automatic','quote'],true)?$mode:($mode===null?'missing':'other');
    $policy=['mode'=>$mode,'automatic'=>supplier_audit_book($options['petish_automatic_shipping_rates']??null,'automatic',$now,array_key_exists('petish_automatic_shipping_rates',$options)),
        'quotes'=>supplier_audit_book($options['petish_supplier_shipping_quotes']??null,'quotes',$now,array_key_exists('petish_supplier_shipping_quotes',$options))];
    unset($options,$row,$rows);
    $category='theme_files';$theme=$site.'/wp-content/themes/dt-the7-child';$hashes=[];
    foreach(['front-page.php','includes/supplier-popularity.php','assets/css/home.css']as$name){
        $path=$theme.'/'.$name;$info=@lstat($path);
        if(!$info||!is_file($path)||is_link($path)||$info['nlink']!==1||realpath($path)!==$path||$info['size']>1048576)throw new RuntimeException();
        $hashes[$name]=hash_file('sha256',$path);
    }
    echo json_encode(['status'=>'success','category'=>'complete','published_counts'=>$counts,'metadata_fields'=>$fields,
        'excluded_metadata_field_count'=>$excluded,'supplier_orders'=>$orders,'shipping_policy'=>$policy,'live_theme_hashes'=>$hashes],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
}catch(Throwable$ignored){echo json_encode(['status'=>'unavailable','category'=>$category],JSON_THROW_ON_ERROR),"\n";exit(1);}
?>
PETISH_SUPPLIER_DELIVERY
