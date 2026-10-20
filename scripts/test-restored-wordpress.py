#!/usr/bin/env python3
"""Boot an actual restored Petish installation in a disconnected Docker sandbox.

Imported by test-full-backup-restore.py while its disposable SQL container lives.
Never uses the original wp-config.php, publishes ports, connects external
networks, sends mail, or performs payment/order/supplier actions. Diagnostics and
HTTP bodies remain private. Only fixed booleans/counts are returned to the caller.
"""

import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import time
import uuid


IMAGE = "wordpress:cli-php8.3@sha256:36243da432a0f0a3b56dc3240ecee9b159108d71305e7c064d987a20a465bfde"
DOCKER = ["docker", "--host", "unix:///var/run/docker.sock"]
SITE = "/srv/restore/site-files"
ORIGIN = "http://petish-recovery.invalid:8080"


class ApplicationRecoveryFailure(Exception):
    """Only a fixed stage identifier is retained, never a private diagnostic."""

    def __init__(self, stage):
        self.stage = stage
        super().__init__("Isolated WordPress application recovery failed.")


def private_write(path, value):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, "w") as target:
        target.write(value)
    path.chmod(0o600)


def docker(arguments, work, *, input_bytes=None, capture=False, timeout=180):
    """Every child output is captured privately, including failure messages."""
    with (work / "application-docker.stderr").open("ab") as errors:
        if capture:
            result = subprocess.run(DOCKER + arguments, input=input_bytes, stdout=subprocess.PIPE,
                                    stderr=errors, timeout=timeout,
                                    env={"PATH": os.environ.get("PATH", os.defpath), "LANG": "C.UTF-8"})
        else:
            with (work / "application-docker.stdout").open("ab") as output:
                result = subprocess.run(DOCKER + arguments, input=input_bytes, stdout=output,
                                        stderr=errors, timeout=timeout,
                                        env={"PATH": os.environ.get("PATH", os.defpath), "LANG": "C.UTF-8"})
    if result.returncode:
        raise ApplicationRecoveryFailure("container_command")
    return result.stdout if capture else None


PREFIX_PROBE = r'''<?php
$tokens=token_get_all(file_get_contents('/srv/restore/site-files/wp-config.php'));
$clean=array_values(array_filter($tokens,static function($t){return !is_array($t)||!in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true);}));
$values=[];
foreach($clean as $i=>$t){
 if(!is_array($t)||$t[0]!==T_VARIABLE||$t[1]!=='$table_prefix')continue;
 if(($clean[$i+1]??null)!=='='||!is_array($clean[$i+2]??null)||$clean[$i+2][0]!==T_CONSTANT_ENCAPSED_STRING||($clean[$i+3]??null)!==';')exit(2);
 $literal=$clean[$i+2][1];$value=substr($literal,1,-1);
 if(!preg_match('/^[A-Za-z0-9_]{1,128}$/D',$value))exit(2);
 $values[]=$value;
}
if(count($values)!==1)exit(2);
echo $values[0];
'''


GUARD = r'''<?php
/** Disposable recovery sandbox guard. Never deployed to any hosted website. */
defined('ABSPATH')||exit;
add_filter('pre_http_request',static function(){return new WP_Error('petish_recovery_network_blocked','Recovery sandbox outbound request blocked.');},PHP_INT_MAX);
add_filter('pre_wp_mail',static function(){return true;},PHP_INT_MAX);
add_filter('action_scheduler_allow_async_request_runner','__return_false',PHP_INT_MAX);
add_filter('action_scheduler_queue_runner_concurrent_batches','__return_zero',PHP_INT_MAX);
add_filter('automatic_updater_disabled','__return_true',PHP_INT_MAX);
add_filter('pre_option_woocommerce_coming_soon',static function(){return 'no';},PHP_INT_MAX);
add_filter('pre_option_woocommerce_store_pages_only',static function(){return 'no';},PHP_INT_MAX);
add_filter('pre_option_woocommerce_force_ssl_checkout',static function(){return 'no';},PHP_INT_MAX);
add_filter('pre_option_blog_public','__return_zero',PHP_INT_MAX);
add_action('wp_loaded',static function(){remove_all_actions('action_scheduler_run_queue');},PHP_INT_MAX);
'''


BOOTSTRAP = r'''<?php
$_SERVER['HTTP_HOST']='petish-recovery.invalid:8080';$_SERVER['SERVER_NAME']='petish-recovery.invalid';
$_SERVER['SERVER_PORT']='8080';$_SERVER['REQUEST_URI']='/';$_SERVER['REQUEST_METHOD']='GET';
ob_start();require '/srv/restore/site-files/wp-load.php';ob_end_clean();
if(!function_exists('WC')||!class_exists('WooCommerce')||!class_exists('Petish_Passport_Store')||!class_exists('Petish_Shipping_Quote_Requests'))exit(2);
if(get_stylesheet()!=='dt-the7-child'||get_template()!=='dt-the7'||!wp_get_theme()->exists()||wp_get_theme()->errors())exit(2);
if(!defined('PETISH_RECOVERY_GUARD_TOKEN')||PETISH_RECOVERY_GUARD_TOKEN!=='__RECOVERY_GUARD_TOKEN__')exit(2);
$blocked=wp_remote_get('https://petish-recovery-outbound.invalid/');
if(!is_wp_error($blocked)||$blocked->get_error_code()!=='petish_recovery_network_blocked')exit(2);
if(apply_filters('pre_wp_mail',null,[])!==true)exit(2);
$active=(array)get_option('active_plugins',[]);
foreach(['woocommerce/woocommerce.php','petish-passport/petish-passport.php','petish-fulfilment/petish-fulfilment.php'] as $required)if(!in_array($required,$active,true))exit(2);
$included=array_map('realpath',get_included_files());
foreach($active as $plugin){if(!is_string($plugin)||str_contains($plugin,'..')||!is_file(WP_PLUGIN_DIR.'/'.$plugin)||!in_array(realpath(WP_PLUGIN_DIR.'/'.$plugin),$included,true))exit(2);}
$products=get_posts(['post_type'=>'product','post_status'=>'publish','numberposts'=>1,'orderby'=>'ID','order'=>'ASC']);
$count=(int)wp_count_posts('product')->publish;if(!$products||$count<1)exit(2);
$product=wc_get_product($products[0]->ID);if(!$product)exit(2);
global $wpdb;
$photos=$wpdb->get_results("SELECT p.ID,p.post_author,m.meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON p.ID=m.post_id WHERE p.post_type='petish_pet' AND m.meta_key='_petish_photo' AND m.meta_value<>''");
$photo_count=0;$photo_pet=1;
foreach($photos as $photo){
 if(!preg_match('#^([1-9][0-9]*)/([a-f0-9]{48}\.(?:jpg|png|webp))$#D',$photo->meta_value,$parts)||(int)$parts[1]!== (int)$photo->post_author)exit(2);
 $path='/srv/restore/private/petish-passport/'.$photo->meta_value;
 if(is_link($path)||!is_file($path)||!is_readable($path)||!getimagesize($path))exit(2);
 $photo_count++;$photo_pet=(int)$photo->ID;
}
$attachments=get_posts(['post_type'=>'attachment','post_status'=>'inherit','numberposts'=>30,'orderby'=>'ID','order'=>'DESC']);
$media='';
foreach($attachments as $attachment){$file=get_attached_file($attachment->ID);if($file&&is_file($file)&&filesize($file)>0){$url=wp_get_attachment_url($attachment->ID);$media=wp_parse_url($url,PHP_URL_PATH);if(str_starts_with($media,'/wp-content/uploads/'))break;$media='';}}
if(!$media)exit(2);
$routes=['homepage'=>'/','shop'=>wp_parse_url(wc_get_page_permalink('shop'),PHP_URL_PATH),'product'=>wp_parse_url(get_permalink($product->get_id()),PHP_URL_PATH),'cart'=>wp_parse_url(wc_get_cart_url(),PHP_URL_PATH),'account'=>wp_parse_url(wc_get_page_permalink('myaccount'),PHP_URL_PATH),'media'=>$media,'private_photo'=>'/wp-admin/admin-post.php?action=petish_pet_photo&pet_id='.$photo_pet];
foreach($routes as $route)if(!is_string($route)||!str_starts_with($route,'/')||str_contains($route,"\n")||str_contains($route,"\r"))exit(2);
$result=['wordpress_bootstrap_verified'=>true,'restored_theme_verified'=>true,'restored_custom_plugins_verified'=>true,'active_plugins_bootstrapped'=>count($active),'published_products'=>$count,'private_photo_references_verified'=>$photo_count,'routes'=>$routes];
file_put_contents('/checks/bootstrap-result.json',json_encode($result,JSON_THROW_ON_ERROR));
'''


ROUTER = r'''<?php
$uri=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$root='/srv/restore/site-files';
if(!is_string($uri)||str_contains(rawurldecode($uri),'..')||str_contains($uri,"\0")){http_response_code(404);exit;}
$path=realpath($root.$uri);
if($path&&str_starts_with($path,$root.'/')&&is_file($path)){
 if(strtolower(pathinfo($path,PATHINFO_EXTENSION))==='php'){require $path;return true;}
 return false;
}
$_SERVER['SCRIPT_NAME']='/index.php';require $root.'/index.php';
'''


HTTP_CHECK = r'''<?php
$fixture=json_decode(file_get_contents('/checks/bootstrap-result.json'),true,512,JSON_THROW_ON_ERROR);
$verified=[];
foreach($fixture['routes'] as $kind=>$original){
 $path=$original;$body=false;$status=0;
 for($hop=0;$hop<4;$hop++){
  $context=stream_context_create(['http'=>['method'=>'GET','header'=>"Host: petish-recovery.invalid:8080\r\nConnection: close\r\n",'ignore_errors'=>true,'follow_location'=>0,'timeout'=>45]]);
  $body=@file_get_contents('http://127.0.0.1:8080'.$path,false,$context);
  $headers=$http_response_header??[];$status=0;$location='';
  foreach($headers as $header){if(preg_match('#^HTTP/\S+\s+(\d+)#',$header,$m))$status=(int)$m[1];if(stripos($header,'Location:')===0)$location=trim(substr($header,9));}
  if(!in_array($status,[301,302,303,307,308],true))break;
  $parts=parse_url($location);if($parts===false||isset($parts['host'])&&$parts['host']!=='petish-recovery.invalid')exit(2);
  $path=($parts['path']??'/').(isset($parts['query'])?'?'.$parts['query']:'');if(!str_starts_with($path,'/')||str_contains($path,"\r")||str_contains($path,"\n"))exit(2);
 }
 if($kind==='private_photo'){if($status!==404)exit(2);$verified['anonymous_private_photo_denied']=true;continue;}
 if($status!==200||!is_string($body)||strlen($body)<1)exit(2);
 if($kind==='media'){$verified['restored_media_http_verified']=true;continue;}
 $lower=strtolower($body);if(str_contains($lower,'there has been a critical error')||str_contains($lower,'fatal error:')||str_contains($lower,'error establishing a database connection'))exit(2);
 if(!str_contains($lower,'<html'))exit(2);
 $markers=['homepage'=>['petish'],'shop'=>['woocommerce','product'],'product'=>['woocommerce','product'],'cart'=>['woocommerce','cart'],'account'=>['woocommerce','login']];
 foreach($markers[$kind] as $marker)if(!str_contains($lower,$marker))exit(2);
 $verified[$kind.'_http_verified']=true;
}
file_put_contents('/checks/http-result.json',json_encode($verified,JSON_THROW_ON_ERROR));
'''


def configuration(prefix, username, password):
    if not re.fullmatch(r"[A-Za-z0-9_]{1,128}", prefix):
        raise ApplicationRecoveryFailure("table_prefix")
    constants = {"DB_NAME": "petish_restore", "DB_USER": username, "DB_PASSWORD": password,
                 "DB_HOST": "localhost:/run/mysqld/mysqld.sock", "DB_CHARSET": "utf8mb4",
                 "DB_COLLATE": "", "WP_HOME": ORIGIN, "WP_SITEURL": ORIGIN,
                 "WP_ENVIRONMENT_TYPE": "local", "WP_ACCESSIBLE_HOSTS": ""}
    text = "<?php\n// Generated throwaway recovery config; original is never executed.\n"
    for key, value in constants.items():
        text += "define(" + repr(key) + "," + repr(value) + ");\n"
    for key in ("DISABLE_WP_CRON", "DISALLOW_FILE_MODS", "AUTOMATIC_UPDATER_DISABLED",
                "WP_HTTP_BLOCK_EXTERNAL", "DONOTCACHEPAGE", "DONOTCACHEOBJECT", "WP_REDIS_DISABLED"):
        text += "define(" + repr(key) + ",true);\n"
    for key in ("WP_CACHE", "WP_DEBUG", "WP_DEBUG_DISPLAY", "WP_AUTO_UPDATE_CORE", "FORCE_SSL_ADMIN"):
        text += "define(" + repr(key) + ",false);\n"
    for key in ("AUTH_KEY", "SECURE_AUTH_KEY", "LOGGED_IN_KEY", "NONCE_KEY", "AUTH_SALT",
                "SECURE_AUTH_SALT", "LOGGED_IN_SALT", "NONCE_SALT"):
        text += "define(" + repr(key) + "," + repr(secrets.token_hex(32)) + ");\n"
    text += "$table_prefix=" + repr(prefix) + ";\ndefine('ABSPATH',__DIR__.'/');\nrequire_once ABSPATH.'wp-settings.php';\n"
    return text


def verify_application(database_container, socket_volume, restored_root, work, table_prefix=None):
    restored_root, work = Path(restored_root).resolve(), Path(work).resolve()
    name = "petish-recovery-app-" + uuid.uuid4().hex
    checks = work / "application-checks"
    stage = "prepare"
    cleanup_ok = False
    try:
        checks.mkdir(mode=0o700)
        private_root = restored_root / "private-files"
        site_root = restored_root / "site-files"
        if not (private_root / "petish-passport").is_dir() or not (site_root / "wp-load.php").is_file():
            raise ApplicationRecoveryFailure(stage)
        guard_token = secrets.token_hex(24)
        for filename, value in [("prefix.php", PREFIX_PROBE), ("bootstrap.php", BOOTSTRAP.replace("__RECOVERY_GUARD_TOKEN__", guard_token)),
                                ("router.php", ROUTER), ("http-check.php", HTTP_CHECK)]:
            private_write(checks / filename, value)
        stage = "image"
        private_write(work / "application-stage.txt", stage)
        docker(["pull", IMAGE], work, timeout=300)
        stage = "container"
        private_write(work / "application-stage.txt", stage)
        docker(["run", "--detach", "--name", name, "--label", "org.petish.restore-smoke=true",
                "--network", "none", "--user", str(os.getuid()) + ":" + str(os.getgid()),
                "--cap-drop", "ALL", "--security-opt", "no-new-privileges:true", "--pids-limit", "128",
                "--memory", "2g", "--cpus", "2", "--env", "HOME=/tmp", "--env", "WP_CLI_CACHE_DIR=/tmp/wp-cli-cache",
                "--mount", "type=bind,src=" + str(site_root) + ",dst=" + SITE,
                "--mount", "type=bind,src=" + str(private_root) + ",dst=/srv/restore/private,readonly",
                "--mount", "type=bind,src=" + str(checks) + ",dst=/checks",
                "--mount", "type=volume,src=" + socket_volume + ",dst=/run/mysqld,readonly",
                "--entrypoint", "php", IMAGE, "-r", "sleep(1800);"], work)
        if docker(["inspect", "--format", "{{.HostConfig.NetworkMode}}", name], work, capture=True).strip() != b"none":
            raise ApplicationRecoveryFailure(stage)
        stage = "table_prefix"
        private_write(work / "application-stage.txt", stage)
        if table_prefix is None:
            table_prefix = docker(["exec", name, "php", "/checks/prefix.php"], work, capture=True).decode("ascii")
        username = "recovery_" + secrets.token_hex(8)
        password = secrets.token_hex(32)
        stage = "database_user"
        private_write(work / "application-stage.txt", stage)
        sql = ("CREATE USER '" + username + "'@'localhost' IDENTIFIED BY '" + password + "';"
               "GRANT ALL PRIVILEGES ON petish_restore.* TO '" + username + "'@'localhost';")
        docker(["exec", "--interactive", database_container, "mariadb",
                "--defaults-extra-file=/tmp/petish-restore-client.cnf", "--binary-mode=1"],
               work, input_bytes=sql.encode())
        # Original file was tokenized as data only. Overwrite the sandbox copy
        # before boot; all original plugins/theme remain selected in the dump.
        private_write(site_root / "wp-config.php", configuration(table_prefix, username, password))
        # Host-specific cache/database drop-ins can run before MU guards. Keep
        # their recovered bytes privately but use native WordPress DB/cache in
        # this relocated recovery runtime; active plugins/theme are untouched.
        quarantined = 0
        for dropin in ("db.php", "object-cache.php", "advanced-cache.php"):
            original = site_root / "wp-content" / dropin
            if original.is_file():
                original.rename(checks / ("quarantined-" + dropin))
                quarantined += 1
        private_write(site_root / "wp-content/mu-plugins/000-petish-recovery-guard.php",
                      GUARD + "\ndefine('PETISH_RECOVERY_GUARD_TOKEN','" + guard_token + "');\n")
        stage = "bootstrap"
        private_write(work / "application-stage.txt", stage)
        php = ["php", "-d", "display_errors=0", "-d", "log_errors=1", "-d",
               "error_log=/checks/php-errors.log", "-d", "sendmail_path=/bin/false", "-d", "memory_limit=768M",
               "-d", "user_ini.filename=", "-d", "auto_prepend_file=", "-d", "auto_append_file="]
        docker(["exec", name] + php + ["/checks/bootstrap.php"], work, timeout=240)
        bootstrap = json.loads((checks / "bootstrap-result.json").read_text())
        stage = "http"
        private_write(work / "application-stage.txt", stage)
        docker(["exec", "--detach", name] + php + ["-S", "127.0.0.1:8080", "-t", SITE, "/checks/router.php"], work)
        for attempt in range(20):
            try:
                docker(["exec", name, "php", "-r",
                        "$s=@fsockopen('127.0.0.1',8080,$e,$m,1);if(!$s)exit(2);fclose($s);"],
                       work, capture=True)
                break
            except ApplicationRecoveryFailure:
                if attempt == 19:
                    raise
            time.sleep(0.2)
        docker(["exec", name] + php + ["/checks/http-check.php"], work, timeout=300)
        http = json.loads((checks / "http-result.json").read_text())
        expected = {"homepage_http_verified", "shop_http_verified", "product_http_verified",
                    "cart_http_verified", "account_http_verified", "restored_media_http_verified",
                    "anonymous_private_photo_denied"}
        if set(http) != expected or any(value is not True for value in http.values()):
            raise ApplicationRecoveryFailure(stage)
        result = {key: bootstrap[key] for key in ("wordpress_bootstrap_verified", "restored_theme_verified",
                  "restored_custom_plugins_verified", "active_plugins_bootstrapped", "published_products", "private_photo_references_verified")}
        result.update(http)
        result.update({"wordpress_application_bootstrap_tested": True, "application_network": "none",
                       "application_outbound_http_blocked": True, "application_email_blocked": True,
                       "application_original_config_executed": False,
                       "host_specific_dropins_quarantined": quarantined})
        stage = "complete"
        private_write(work / "application-stage.txt", stage)
    except Exception:
        private_write(work / "application-stage.txt", "failed_" + stage)
        raise ApplicationRecoveryFailure(stage) from None
    finally:
        try:
            docker(["rm", "--force", "--volumes", name], work, timeout=60)
            cleanup_ok = True
        except (ApplicationRecoveryFailure, subprocess.TimeoutExpired, OSError):
            try:
                cleanup_ok = not docker(["ps", "--all", "--quiet", "--filter", "name=^/" + name + "$"],
                                        work, capture=True).strip()
            except (ApplicationRecoveryFailure, subprocess.TimeoutExpired, OSError):
                pass
        if not cleanup_ok:
            private_write(work / "application-stage.txt", "failed_cleanup")
            raise ApplicationRecoveryFailure("cleanup") from None
    result["application_container_removed"] = True
    return result
