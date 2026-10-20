#!/usr/bin/env bash
# Metadata-only mail readiness check: no WordPress bootstrap or mail delivery.
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
[[ $prefix =~ ^[A-Za-z0-9_]+$ ]] || unavailable database_configuration
for constant in DB_HOST DB_NAME DB_USER DB_PASSWORD; do
    if ! connection_value=$(wp --path="$site_path" --no-color config get "$constant" 2>/dev/null); then unavailable database_configuration; fi
    printf -v "PETISH_$constant" '%s' "$connection_value"
    export "PETISH_$constant"
done
unset connection_value
export PETISH_DB_PREFIX=$prefix PETISH_EMAIL_SITE_PATH=$site_path
PETISH_DB_CLIENT_FLAGS=0
if connection_flags=$(wp --path="$site_path" --no-color config get MYSQL_CLIENT_FLAGS 2>/dev/null); then PETISH_DB_CLIENT_FLAGS=$connection_flags; fi
export PETISH_DB_CLIENT_FLAGS
unset connection_flags
php <<'PETISH_EMAIL_READINESS'
<?php
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0);
$category='database_driver';
try {
    if(!function_exists('mysqli_init')) throw new RuntimeException();
    $category='database_configuration';
    $prefix=getenv('PETISH_DB_PREFIX'); $site=getenv('PETISH_EMAIL_SITE_PATH');
    if(!is_string($prefix)||!preg_match('/\A[A-Za-z0-9_]+\z/D',$prefix)||!is_string($site)) throw new RuntimeException();
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
    $database->set_charset('utf8mb4');$category='selected_options';
    $rows=$database->query("SELECT option_name, option_value FROM `{$prefix}options` WHERE option_name IN ('woocommerce_email_from_address','woocommerce_email_from_name','active_plugins') ORDER BY option_name");
    $options=[];while($row=$rows->fetch_assoc())$options[$row['option_name']]=$row['option_value'];
    $rows->free();$database->close();
    $active=isset($options['active_plugins'])?unserialize($options['active_plugins'],['allowed_classes'=>false]):[];
    if(!is_array($active))throw new RuntimeException();
    $known=['wp-mail-smtp','easy-wp-smtp','fluent-smtp','post-smtp','smtp-mailer','wp-smtp','gosmtp','mailpoet','petish-mailer'];$plugins=[];
    foreach($known as $slug){
        $directory=$site.'/wp-content/plugins/'.$slug;
        if(!is_dir($directory)||is_link($directory))continue;
        $enabled=false;foreach($active as $plugin)if(is_string($plugin)&&strpos($plugin,$slug.'/')===0)$enabled=true;
        $plugins[]=['slug'=>$slug,'active'=>$enabled];
    }
    $constants=[];$tokens=token_get_all(file_get_contents($site.'/wp-config.php'));
    foreach($tokens as $i=>$token){
        if(!is_array($token)||$token[0]!==T_STRING||strtolower($token[1])!=='define')continue;
        $following=[];for($j=$i+1;$j<count($tokens)&&count($following)<2;$j++)if(!is_array($tokens[$j])||!in_array($tokens[$j][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))$following[]=$tokens[$j];
        if(($following[0]??null)!=='('||!is_array($following[1]??null)||$following[1][0]!==T_CONSTANT_ENCAPSED_STRING)continue;
        $name=trim($following[1][1],"\"'");
        if(preg_match('/\A(?:SMTP_[A-Z0-9_]+|WPMS_[A-Z0-9_]+|PETISH_[A-Z0-9_]*SMTP[A-Z0-9_]*)\z/D',$name))$constants[]=$name;
    }
    $constants=array_values(array_unique($constants));sort($constants);
    $file=dirname($site).'/private/petish-mailer/smtp.json';$exists=file_exists($file)||is_link($file);$plain=true;
    for($cursor=$file;$cursor!=='/'&&$cursor!=='.';$cursor=dirname($cursor))if(is_link($cursor))$plain=false;
    $regular=$exists&&$plain&&is_file($file);$private=$regular&&((fileperms($file)&0077)===0)&&((fileperms(dirname($file))&0077)===0)&&fileowner($file)===fileowner($site.'/wp-config.php');
    echo json_encode(['status'=>'success','sender'=>[
        'address_option_present'=>isset($options['woocommerce_email_from_address']),
        'name_option_present'=>isset($options['woocommerce_email_from_name']),
        'address_is_orders_petish'=>isset($options['woocommerce_email_from_address'])&&strtolower(trim($options['woocommerce_email_from_address']))==='orders@petish.biz',
        'name_is_petish'=>isset($options['woocommerce_email_from_name'])&&strtolower(trim($options['woocommerce_email_from_name']))==='petish'
    ],'mail_plugins'=>$plugins,'smtp_constant_names'=>$constants,'private_mailer'=>[
        'exists'=>$exists,'regular_without_symlink'=>$regular,'owner_only_permissions'=>$private
    ]],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
}catch(Throwable $error){echo json_encode(['status'=>'unavailable','category'=>$category]),"\n";exit(1);}
PETISH_EMAIL_READINESS
